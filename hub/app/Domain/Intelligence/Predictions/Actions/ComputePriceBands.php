<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Predictions\Actions;

use App\Domain\Intelligence\Predictions\Models\PredictionRun;
use App\Domain\Intelligence\Predictions\Support\Bulk;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * Rango de precio (inteligencia.md §4.6): el precio mediano de lo que compró cada
 * cliente frente a los terciles de precio del catálogo → `low`, `mid` o `high`.
 * `coupon_orders_ratio` lo mantiene `RecalculateContactStats` con cada pedido.
 */
final class ComputePriceBands
{
    /** @return array{status: string, reason?: string} */
    public function handle(Workspace $workspace): array
    {
        $prices = DB::table('products')->where('workspace_id', $workspace->id)->whereNull('deleted_at')->where('price', '>', 0)
            ->orderBy('price')->pluck('price')->map(fn ($p) => (float) $p)->all();
        if (count($prices) < 6) {
            $reason = __('El catálogo tiene :n productos con precio; se necesitan al menos 6.', ['n' => count($prices)]);
            PredictionRun::record($workspace->id, 'price_band', 'insufficient_data', ['reason' => $reason]);

            return ['status' => 'insufficient_data', 'reason' => $reason];
        }
        $p33 = $prices[(int) floor(count($prices) / 3)];
        $p66 = $prices[(int) floor(count($prices) * 2 / 3)];
        $byContact = [];
        foreach (DB::table('order_items as i')->join('orders as o', 'o.id', '=', 'i.order_id')
            ->where('o.workspace_id', $workspace->id)->where('o.is_paid', true)->whereNotNull('o.contact_id')->where('i.unit_price', '>', 0)
            ->select(['o.contact_id', 'i.unit_price'])->cursor() as $r) {
            $byContact[(string) $r->contact_id][] = (float) $r->unit_price;
        }
        $rows = [];
        $counts = ['low' => 0, 'mid' => 0, 'high' => 0];
        foreach ($byContact as $cid => $list) {
            $median = (float) ComputeExpectedNextOrder::median($list);
            $band = $median <= $p33 ? 'low' : ($median > $p66 ? 'high' : 'mid');
            $counts[$band]++;
            $rows[$cid] = ['price_band' => $band];
        }
        Bulk::contacts($workspace->id, $rows);
        PredictionRun::record($workspace->id, 'price_band', 'completed', ['contacts' => count($rows), 'bands' => $counts], ['p33' => $p33, 'p66' => $p66]);

        return ['status' => 'completed'];
    }
}
