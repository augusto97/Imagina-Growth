<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Insights\Detectors;

use App\Domain\Intelligence\Insights\Detector;
use App\Domain\Intelligence\Insights\InsightCandidate;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * #12 Producto de entrada ganador: el producto del primer pedido cuyos clientes
 * valen más a 180 días (al menos 20 clientes y 1,5× el promedio). Sugerencia:
 * usarlo en los anuncios para atraer clientes nuevos.
 */
final class EntryProductDetector implements Detector
{
    private const MIN_CUSTOMERS = 20;

    public function key(): string
    {
        return 'entry_product';
    }

    public function detect(Workspace $workspace, CarbonImmutable $now): array
    {
        $firsts = DB::table('orders as f')
            ->where('f.workspace_id', $workspace->id)->where('f.is_first_order', true)->where('f.is_paid', true)->whereNotNull('f.contact_id')
            ->where('f.created_at', '<', $now->subDays(180)->utc())->where('f.created_at', '>=', $now->subYears(2)->utc());
        $ltv = DB::table('orders as f')
            ->join('orders as o', fn ($j) => $j->on('o.contact_id', '=', 'f.contact_id')->where('o.is_paid', true))
            ->where('f.workspace_id', $workspace->id)->where('f.is_first_order', true)->where('f.is_paid', true)->whereNotNull('f.contact_id')
            ->where('f.created_at', '<', $now->subDays(180)->utc())->where('f.created_at', '>=', $now->subYears(2)->utc())
            ->whereRaw('o.created_at < DATE_ADD(f.created_at, INTERVAL 180 DAY)')
            ->groupBy('f.contact_id')->selectRaw('f.contact_id AS cid, SUM(o.total_ws) AS ltv')->pluck('ltv', 'cid')->map(fn ($v) => (float) $v);
        if ($ltv->count() < 3 * self::MIN_CUSTOMERS) {
            return [];
        }
        $avg = $ltv->avg();
        $pairs = (clone $firsts)->join('order_items as i', 'i.order_id', '=', 'f.id')->distinct()->limit(200000)
            ->get(['i.external_product_id as pid', 'i.name as name', 'f.contact_id as cid']);
        $groups = [];
        foreach ($pairs as $p) {
            $pid = (string) $p->pid;
            $groups[$pid]['name'] = (string) $p->name;
            $groups[$pid]['ids'][(string) $p->cid] = true;
        }
        $best = null;
        foreach ($groups as $pid => $g) {
            $values = array_values(array_filter(array_map(fn ($id) => $ltv[$id] ?? null, array_keys($g['ids'])), fn ($v) => $v !== null));
            if (count($values) < self::MIN_CUSTOMERS) {
                continue;
            }
            $mean = array_sum($values) / count($values);
            if ($best === null || $mean > $best['ltv_180']) {
                $best = ['product_id' => (string) $pid, 'name' => $g['name'], 'customers' => count($values), 'ltv_180' => round($mean, 2)];
            }
        }
        if ($best === null || $avg <= 0) {
            return [];
        }
        $ratio = $best['ltv_180'] / $avg;
        if ($ratio < (float) config('igr-insights.thresholds.ltv_ratio', 1.5)) {
            return [];
        }

        return [new InsightCandidate(
            fingerprint: $best['product_id'],
            severity: 'opportunity',
            title: __('Quienes empiezan comprando :name valen :ratio× más que el promedio', ['name' => $best['name'], 'ratio' => number_format($ratio, 1, ',', '.')]),
            facts: ['product' => $best, 'average_ltv_180' => round($avg, 2), 'ratio' => round($ratio, 2), 'customers_compared' => $ltv->count(), 'window_days' => 180],
            assumptions: [__('Úsalo en tus anuncios para clientes nuevos: atrae a los que más compran después.')],
            action: ['label' => __('Revisa el producto en Analítica'), 'to' => 'analitica/productos'],
            magnitude: $ratio,
        )];
    }
}
