<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Rfm;

use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * RFM adaptado a LATAM (inteligencia.md §3). Se recalcula cada noche.
 *
 * - Población: contactos con ≥ 1 pedido válido en los últimos N meses (24).
 * - R: quintiles de días desde la última compra (5 = más reciente). Con menos
 *   de 200 clientes, cortes fijos de 0,5×, 1×, 2× y 4× el tiempo mediano entre compras.
 * - F: valores absolutos (1, 2, 3–4, 5–9, 10+), configurables por industria.
 * - M: quintiles del gasto en la ventana.
 *
 * Los contactos que salen de la población quedan sin RFM (null).
 */
final class RfmCalculator
{
    public const MIN_FOR_QUINTILES = 200;

    public function __construct(private readonly CurrentWorkspace $current) {}

    /** @return array<string, mixed> métricas del cálculo (se guardan en prediction_runs) */
    public function run(Workspace $workspace, ?CarbonImmutable $now = null): array
    {
        return $this->current->run($workspace, fn () => $this->compute($workspace, $now ?? CarbonImmutable::now()));
    }

    /** @return array<string, mixed> */
    private function compute(Workspace $workspace, CarbonImmutable $now): array
    {
        $months = (int) $workspace->setting('rfm.window_months', 24);
        $since = $now->subMonths($months);
        /** @var list<int> $fCuts */
        $fCuts = (array) $workspace->setting('rfm.f_cuts', [1, 2, 3, 5, 10]);

        $rows = DB::table('orders')
            ->where('workspace_id', $workspace->id)
            ->where('is_paid', true)
            ->whereNotNull('contact_id')
            ->where('created_at', '>=', $since)
            ->groupBy('contact_id')
            ->selectRaw('contact_id, MAX(created_at) AS last_at, COUNT(*) AS n, COALESCE(SUM(total_ws), 0) AS spend')
            ->get();

        $customers = [];
        foreach ($rows as $row) {
            $customers[(string) $row->contact_id] = [
                'days' => max(0, (int) floor(($now->getTimestamp() - CarbonImmutable::parse((string) $row->last_at)->getTimestamp()) / 86400)),
                'n' => (int) $row->n,
                'spend' => (float) $row->spend,
            ];
        }
        $count = count($customers);
        $method = $count >= self::MIN_FOR_QUINTILES ? 'quintiles' : 'fixed_cuts';

        $rScore = $method === 'quintiles'
            ? $this->quintiles(array_map(fn ($c) => -$c['days'], $customers))   // menos días = mejor
            : $this->fixedRecency($workspace, $customers);
        $mScore = $this->quintiles(array_map(fn ($c) => $c['spend'], $customers));

        $groups = [];
        $bySegment = [];
        foreach ($customers as $id => $c) {
            $r = $rScore[$id];
            $f = $this->frequency($c['n'], $fCuts);
            $m = $mScore[$id];
            $segment = RfmSegment::classify($r, $f, $m)->value;
            $groups["{$r}|{$f}|{$m}|{$segment}"][] = $id;
            $bySegment[$segment] = ($bySegment[$segment] ?? 0) + 1;
        }

        DB::transaction(function () use ($workspace, $groups, $customers, $now): void {
            // Fuera de la población: sin RFM.
            DB::table('contacts')->where('workspace_id', $workspace->id)->whereNotNull('rfm_segment')
                ->when($customers !== [], fn ($q) => $q->whereNotIn('id', array_keys($customers)))
                ->update(['rfm_r' => null, 'rfm_f' => null, 'rfm_m' => null, 'rfm_segment' => null, 'rfm_computed_at' => $now]);
            foreach ($groups as $key => $ids) {
                [$r, $f, $m, $segment] = explode('|', (string) $key);
                foreach (array_chunk($ids, 1000) as $chunk) {
                    DB::table('contacts')->where('workspace_id', $workspace->id)->whereIn('id', $chunk)->update([
                        'rfm_r' => (int) $r, 'rfm_f' => (int) $f, 'rfm_m' => (int) $m, 'rfm_segment' => $segment, 'rfm_computed_at' => $now,
                    ]);
                }
            }
        });

        ksort($bySegment);
        $metrics = ['population' => $count, 'method' => $method, 'window_months' => $months, 'segments' => $bySegment];
        DB::table('prediction_runs')->insert([
            'id' => strtoupper((string) Str::ulid()),
            'workspace_id' => $workspace->id,
            'model' => 'rfm',
            'trained_at' => $now,
            'metrics' => json_encode($metrics),
            'params' => json_encode(['f_cuts' => $fCuts]),
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $metrics;
    }

    /**
     * Puntajes 1–5 por quintiles (5 = valor más alto). Los empates quedan en
     * el mismo quintil: el corte se hace por valor, no por posición.
     *
     * @param  array<string, float|int>  $values
     * @return array<string, int>
     */
    public function quintiles(array $values): array
    {
        if ($values === []) {
            return [];
        }
        $sorted = array_values($values);
        sort($sorted);
        $n = count($sorted);
        $cuts = [];
        for ($q = 1; $q <= 4; $q++) {
            $cuts[] = $sorted[(int) min($n - 1, (int) floor($q * $n / 5))];
        }
        $out = [];
        foreach ($values as $id => $v) {
            $score = 1;
            foreach ($cuts as $cut) {
                if ($v >= $cut) {
                    $score++;
                }
            }
            $out[$id] = min(5, $score);
        }

        return $out;
    }

    /** @param list<int> $cuts umbrales mínimos de pedidos para F = 1..5 */
    public function frequency(int $orders, array $cuts): int
    {
        $score = 1;
        foreach ($cuts as $i => $min) {
            if ($orders >= $min) {
                $score = $i + 1;
            }
        }

        return max(1, min(5, $score));
    }

    /**
     * R con cortes fijos según el ciclo del negocio: múltiplos 0,5×, 1×, 2× y
     * 4× del tiempo mediano entre compras (90 días si aún no hay recompras).
     *
     * @param  array<string, array{days: int, n: int, spend: float}>  $customers
     * @return array<string, int>
     */
    private function fixedRecency(Workspace $workspace, array $customers): array
    {
        $gaps = DB::table('contacts')->where('workspace_id', $workspace->id)->whereNotNull('avg_days_between_orders')
            ->orderBy('avg_days_between_orders')->pluck('avg_days_between_orders')->map(fn ($v) => (float) $v)->all();
        $median = $gaps === [] ? 90.0 : max(7.0, $gaps[intdiv(count($gaps), 2)]);
        $out = [];
        foreach ($customers as $id => $c) {
            $d = $c['days'];
            $out[$id] = match (true) {
                $d <= 0.5 * $median => 5,
                $d <= $median => 4,
                $d <= 2 * $median => 3,
                $d <= 4 * $median => 2,
                default => 1,
            };
        }

        return $out;
    }
}
