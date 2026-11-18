<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Predictions\Actions;

use App\Domain\Intelligence\Predictions\Models\PredictionRun;
use App\Domain\Intelligence\Predictions\Support\Bulk;
use App\Domain\Intelligence\Predictions\Support\OrderHistory;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * Fecha esperada de próxima compra (inteligencia.md §4.1):
 * - Con 2 o más compras: `intervalo = w × mediana personal + (1 − w) × mediana de la
 *   categoría principal`, con `w = min(1, (pedidos − 1) / 4)`.
 * - Con 1 compra: la mediana de días hasta la segunda compra de quienes compraron
 *   la misma categoría la primera vez.
 * - `expected_next_order_at = última compra + intervalo`.
 *
 * Se valida con el mismo cálculo hecho hace 90 días: de los clientes que debían
 * volver en esos 90 días, cuántos volvieron, y cuántos días de diferencia hubo.
 * Umbral: 300 clientes con 2 o más compras. Los intervalos quedan en `intervals()`
 * para la heurística de abandono.
 */
final class ComputeExpectedNextOrder
{
    /** @var array<string, float> contacto → intervalo esperado (días) */
    private array $intervals = [];

    /** @return array<string, float> */
    public function intervals(): array
    {
        return $this->intervals;
    }

    /** @return array{status: string, reason?: string} */
    public function handle(Workspace $workspace, bool $write = true): array
    {
        $history = OrderHistory::daily($workspace->id);
        $categories = $this->mainCategories($workspace->id);
        $now = intdiv(now()->getTimestamp(), 86400) * 86400;
        $repeat = count(array_filter($history, fn (array $d) => count($d) >= 2));
        $min = (int) config('igr-predictions.thresholds.edno_repeat_customers', 300);

        $predict = $this->model($history, $categories, $now);
        $this->intervals = array_map(fn (array $p) => $p['interval'], $predict);
        if ($repeat < $min) {
            $reason = __('Hay :n clientes con 2 o más compras; la fecha esperada necesita :min.', ['n' => $repeat, 'min' => $min]);
            PredictionRun::record($workspace->id, 'edno', 'insufficient_data', ['reason' => $reason, 'repeat_customers' => $repeat]);

            return ['status' => 'insufficient_data', 'reason' => $reason];
        }

        if ($write) {
            $rows = [];
            foreach ($predict as $cid => $p) {
                $rows[$cid] = ['expected_next_order_at' => date('Y-m-d H:i:s', $p['next'])];
            }
            Bulk::contacts($workspace->id, $rows);
        }

        // Reserva: lo que se habría predicho hace 90 días contra lo que pasó.
        $holdout = (int) config('igr-predictions.holdout_days', 90);
        $cutoff = $now - $holdout * 86400;
        $past = [];
        foreach ($history as $cid => $days) {
            $before = array_values(array_filter($days, fn (array $d) => $d[0] <= $cutoff));
            if ($before !== []) {
                $past[$cid] = $before;
            }
        }
        $then = $this->model($past, $categories, $cutoff);
        $due = 0;
        $dueBought = 0;
        $bought = 0;
        $errors = [];
        foreach ($then as $cid => $p) {
            $next = null;
            foreach ($history[$cid] as $d) {
                if ($d[0] > $cutoff) {
                    $next = $d[0];
                    break;
                }
            }
            if ($p['next'] <= $now) {
                $due++;
                $dueBought += $next !== null ? 1 : 0;
            }
            if ($next !== null) {
                $bought++;
                $errors[] = abs($next - $p['next']) / 86400;
            }
        }
        sort($errors);
        $metrics = [
            'customers' => count($predict), 'repeat_customers' => $repeat, 'holdout_days' => $holdout,
            'due_in_holdout' => $due, 'due_and_bought' => $dueBought,
            'precision_pct' => $due > 0 ? round($dueBought / $due * 100, 1) : null,
            'bought_in_holdout' => $bought,
            'median_error_days' => $errors === [] ? null : round($errors[intdiv(count($errors), 2)], 1),
            'within_15_days_pct' => $errors === [] ? null : round(count(array_filter($errors, fn ($e) => $e <= 15)) / count($errors) * 100, 1),
        ];
        PredictionRun::record($workspace->id, 'edno', 'completed', $metrics);

        return ['status' => 'completed'];
    }

    /**
     * @param  array<string, list<array{0: int, 1: float}>>  $history
     * @param  array<string, array{main: string|null, first: string|null}>  $categories
     * @return array<string, array{interval: float, next: int}>
     */
    public function model(array $history, array $categories, int $now): array
    {
        $gapsByCat = [];
        $secondByCat = [];
        $allGaps = [];
        $allSecond = [];
        foreach ($history as $cid => $days) {
            $cat = $categories[$cid]['main'] ?? '_';
            $first = $categories[$cid]['first'] ?? '_';
            for ($i = 1; $i < count($days); $i++) {
                $g = ($days[$i][0] - $days[$i - 1][0]) / 86400;
                $gapsByCat[$cat][] = $g;
                $allGaps[] = $g;
            }
            if (count($days) >= 2) {
                $s = ($days[1][0] - $days[0][0]) / 86400;
                $secondByCat[$first][] = $s;
                $allSecond[] = $s;
            }
        }
        $globalGap = self::median($allGaps) ?? 45.0;
        $globalSecond = self::median($allSecond) ?? $globalGap;
        $refGap = fn (string $c): float => count($gapsByCat[$c] ?? []) >= 20 ? (float) self::median($gapsByCat[$c]) : $globalGap;
        $refSecond = fn (string $c): float => count($secondByCat[$c] ?? []) >= 20 ? (float) self::median($secondByCat[$c]) : $globalSecond;

        $out = [];
        foreach ($history as $cid => $days) {
            $n = count($days);
            if ($n === 0) {
                continue;
            }
            if ($n === 1) {
                $interval = $refSecond($categories[$cid]['first'] ?? '_');
            } else {
                $personal = [];
                for ($i = 1; $i < $n; $i++) {
                    $personal[] = ($days[$i][0] - $days[$i - 1][0]) / 86400;
                }
                $w = min(1.0, ($n - 1) / 4);
                $interval = $w * (float) self::median($personal) + (1 - $w) * $refGap($categories[$cid]['main'] ?? '_');
            }
            $interval = max(1.0, round($interval, 1));
            $out[$cid] = ['interval' => $interval, 'next' => $days[$n - 1][0] + (int) round($interval * 86400)];
        }

        return $out;
    }

    /**
     * Categoría principal de cada contacto (la más comprada) y la de su primer pedido.
     *
     * @return array<string, array{main: string|null, first: string|null}>
     */
    public function mainCategories(string $workspaceId): array
    {
        $rows = DB::table('order_items as i')->join('orders as o', 'o.id', '=', 'i.order_id')
            ->where('o.workspace_id', $workspaceId)->where('o.is_paid', true)->whereNotNull('o.contact_id')
            ->orderBy('o.created_at')->select(['o.contact_id', 'o.id as order_id', 'i.category_ids'])->cursor();
        $count = [];
        $first = [];
        $firstOrder = [];
        foreach ($rows as $r) {
            $cats = json_decode((string) $r->category_ids, true);
            $cat = is_array($cats) && $cats !== [] ? (string) reset($cats) : null;
            if ($cat === null) {
                continue;
            }
            $cid = (string) $r->contact_id;
            $count[$cid][$cat] = ($count[$cid][$cat] ?? 0) + 1;
            $firstOrder[$cid] ??= (string) $r->order_id;
            if ($firstOrder[$cid] === (string) $r->order_id) {
                $first[$cid] ??= $cat;
            }
        }
        $out = [];
        foreach ($count as $cid => $cats) {
            arsort($cats);
            $out[$cid] = ['main' => (string) array_key_first($cats), 'first' => $first[$cid] ?? null];
        }

        return $out;
    }

    /** @param list<float> $values */
    public static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $n = count($values);

        return $n % 2 === 1 ? (float) $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
    }
}
