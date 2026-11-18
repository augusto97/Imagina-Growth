<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Insights\Detectors;

use App\Analytics\Ch;
use App\Domain\Intelligence\Insights\Detector;
use App\Domain\Intelligence\Insights\InsightCandidate;
use App\Domain\Intelligence\Insights\Money;
use App\Domain\Intelligence\Insights\Stats;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * #1 Caída de conversión del carrito o del checkout en los últimos 7 días
 * frente a las 4 semanas anteriores (prueba z, p < 0,05 y caída > 15 %),
 * segmentada por dispositivo, navegador y método de pago, y cruzada con los
 * cambios del sitio (site.changed).
 */
final class ConversionDropDetector implements Detector
{
    private const MIN_N = 30;

    public function key(): string
    {
        return 'conversion_drop';
    }

    public function detect(Workspace $workspace, CarbonImmutable $now): array
    {
        $recentEnd = $now->startOfDay();
        $recentStart = $recentEnd->subDays(7);
        $baseStart = $recentStart->subDays(28);
        $fmt = fn (CarbonImmutable $d) => $d->utc()->format('Y-m-d H:i:s.v');

        $rows = Ch::forWorkspace($workspace)->select(<<<'SQL'
            SELECT device_type, browser,
                countIf(started_at < {rs:DateTime64(3)} AND added_to_cart = 1) AS b_cart,
                countIf(started_at < {rs:DateTime64(3)} AND added_to_cart = 1 AND purchased = 1) AS b_cart_buy,
                countIf(started_at < {rs:DateTime64(3)} AND started_checkout = 1) AS b_co,
                countIf(started_at < {rs:DateTime64(3)} AND started_checkout = 1 AND purchased = 1) AS b_co_buy,
                countIf(started_at >= {rs:DateTime64(3)} AND added_to_cart = 1) AS r_cart,
                countIf(started_at >= {rs:DateTime64(3)} AND added_to_cart = 1 AND purchased = 1) AS r_cart_buy,
                countIf(started_at >= {rs:DateTime64(3)} AND started_checkout = 1) AS r_co,
                countIf(started_at >= {rs:DateTime64(3)} AND started_checkout = 1 AND purchased = 1) AS r_co_buy
            FROM sessions FINAL
            WHERE workspace_id = {workspace_id:UInt32} AND started_at >= {bs:DateTime64(3)} AND started_at < {re:DateTime64(3)}
            GROUP BY device_type, browser
            SQL, ['bs' => $fmt($baseStart), 'rs' => $fmt($recentStart), 're' => $fmt($recentEnd)]);

        // Totales por segmento: todo, dispositivo y navegador.
        $segments = [];
        foreach ($rows as $row) {
            foreach ([['all', 'all'], ['device', (string) $row['device_type']], ['browser', (string) $row['browser']]] as [$dim, $val]) {
                if ($val === '') {
                    continue;
                }
                $key = $dim.':'.$val;
                $s = $segments[$key] ?? ['b_cart' => 0, 'b_cart_buy' => 0, 'b_co' => 0, 'b_co_buy' => 0, 'r_cart' => 0, 'r_cart_buy' => 0, 'r_co' => 0, 'r_co_buy' => 0];
                foreach (array_keys($s) as $m) {
                    $s[$m] += (int) $row[$m];
                }
                $segments[$key] = $s;
            }
        }

        $tests = [];
        foreach ($segments as $key => $s) {
            $tests[] = [$key, 'cart', $s['b_cart_buy'], $s['b_cart'], $s['r_cart_buy'], $s['r_cart']];
            $tests[] = [$key, 'checkout', $s['b_co_buy'], $s['b_co'], $s['r_co_buy'], $s['r_co']];
        }
        // Método de pago: pedidos creados que terminan pagados.
        $tz = $workspace->timezone;
        $byMethod = DB::table('orders')->where('workspace_id', $workspace->id)->where('is_cod', false)
            ->where('created_at', '>=', $baseStart->utc())->where('created_at', '<', $recentEnd->utc())
            ->groupBy('payment_method_id')
            ->selectRaw('payment_method_id AS m, SUM(created_at < ?) AS b_n, SUM(created_at < ? AND is_paid = 1) AS b_paid, SUM(created_at >= ?) AS r_n, SUM(created_at >= ? AND is_paid = 1) AS r_paid', array_fill(0, 4, $recentStart->utc()))
            ->get();
        foreach ($byMethod as $m) {
            if ($m->m !== null) {
                $tests[] = ['payment:'.$m->m, 'payment', (int) $m->b_paid, (int) $m->b_n, (int) $m->r_paid, (int) $m->r_n];
            }
        }

        $aov = (float) DB::table('orders')->where('workspace_id', $workspace->id)->where('is_paid', true)
            ->where('created_at', '>=', $baseStart->utc())->avg('total_ws');
        $changes = $this->siteChanges($workspace, $recentStart->subDays(7), $recentEnd);

        $found = [];
        foreach ($tests as [$segment, $metric, $bHits, $bN, $rHits, $rN]) {
            if ($bN < self::MIN_N || $rN < self::MIN_N || $bHits === 0) {
                continue;
            }
            $before = $bHits / $bN;
            $after = $rHits / $rN;
            $drop = ($before - $after) / $before;
            $test = Stats::proportionDrop($bHits, $bN, $rHits, $rN);
            if ($test['p'] >= 0.05 || $drop <= 0.15) {
                continue;
            }
            [$dim, $val] = explode(':', $segment, 2);
            $lostOrders = ($before - $after) * $rN;
            $impact = round($lostOrders * $aov, 2);
            $assumptions = [
                __('Si la conversión hubiera seguido en :before, la última semana habría tenido :orders pedidos más.', ['before' => Money::percent($before), 'orders' => number_format($lostOrders, 1, ',', '.')]),
                __('Ticket promedio de las últimas 5 semanas: :aov.', ['aov' => Money::format($aov, $workspace->currency)]),
            ];
            if ($changes !== []) {
                $assumptions[] = __('Ese periodo coincide con cambios en el sitio: :list.', ['list' => implode(', ', array_map(fn ($c) => $c['name'].' ('.$c['date'].')', $changes))]);
            }
            $found[] = new InsightCandidate(
                fingerprint: "{$metric}:{$segment}",
                severity: $drop > 0.35 ? 'critical' : 'warning',
                title: __('La conversión :metric:segment bajó de :before a :after en la última semana', [
                    'metric' => __('insight.metric.'.$metric),
                    'segment' => $dim === 'all' ? '' : ' ('.$this->segmentLabel($dim, $val).')',
                    'before' => Money::percent($before), 'after' => Money::percent($after),
                ]),
                facts: [
                    'metric' => $metric, 'dimension' => $dim, 'value' => $val,
                    'baseline' => ['from' => $baseStart->toDateString(), 'to' => $recentStart->subDay()->toDateString(), 'hits' => $bHits, 'n' => $bN, 'rate' => round($before, 4)],
                    'recent' => ['from' => $recentStart->toDateString(), 'to' => $recentEnd->subDay()->toDateString(), 'hits' => $rHits, 'n' => $rN, 'rate' => round($after, 4)],
                    'relative_drop' => round($drop, 4), 'z' => round($test['z'], 3), 'p_value' => round($test['p'], 5),
                    'aov' => round($aov, 2), 'lost_orders' => round($lostOrders, 1), 'site_changes' => $changes, 'timezone' => $tz,
                ],
                impact: (string) $impact,
                assumptions: $assumptions,
                action: ['label' => __('Revisa el embudo'), 'to' => 'analitica/embudo'],
                periodStart: $recentStart->toDateString(),
                periodEnd: $recentEnd->subDay()->toDateString(),
            );
        }
        usort($found, fn (InsightCandidate $a, InsightCandidate $b) => (float) $b->impact <=> (float) $a->impact);

        return array_slice($found, 0, 3);
    }

    /** @return list<array{name: string, change: string, to_version: string, date: string}> */
    private function siteChanges(Workspace $workspace, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = Ch::forWorkspace($workspace)->select(<<<'SQL'
            SELECT toString(properties.name) AS name, toString(properties.change) AS change, toString(properties.to_version) AS to_version,
                   toString(toDate(occurred_at)) AS date
            FROM events
            WHERE workspace_id = {workspace_id:UInt32} AND event = 'site.changed' AND occurred_at >= {from:DateTime64(3)} AND occurred_at < {to:DateTime64(3)}
            ORDER BY occurred_at LIMIT 10
            SQL, ['from' => $from->utc()->format('Y-m-d H:i:s.v'), 'to' => $to->utc()->format('Y-m-d H:i:s.v')]);

        return array_map(fn ($r) => ['name' => (string) $r['name'], 'change' => (string) $r['change'], 'to_version' => (string) $r['to_version'], 'date' => (string) $r['date']], $rows);
    }

    private function segmentLabel(string $dim, string $val): string
    {
        return match ($dim) {
            'device' => __('insight.device.'.$val),
            'payment' => $val,
            default => $val,
        };
    }
}
