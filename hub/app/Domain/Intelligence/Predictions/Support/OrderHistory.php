<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Predictions\Support;

use Illuminate\Support\Facades\DB;

/**
 * Compras pagadas por contacto, agrupadas por día (un cliente que paga dos
 * pedidos el mismo día cuenta una transacción, como en BG/NBD): `[día unix, valor]`.
 */
final class OrderHistory
{
    /** @return array<string, list<array{0: int, 1: float}>> */
    public static function daily(string $workspaceId): array
    {
        $out = [];
        $rows = DB::table('orders')->where('workspace_id', $workspaceId)->where('is_paid', true)->whereNotNull('contact_id')
            ->orderBy('contact_id')->orderBy('created_at')
            ->select(['contact_id', 'created_at', 'total_ws'])->cursor();
        foreach ($rows as $r) {
            $day = intdiv((int) strtotime((string) $r->created_at.' UTC'), 86400) * 86400;
            $cid = (string) $r->contact_id;
            $last = isset($out[$cid]) ? count($out[$cid]) - 1 : -1;
            if ($last >= 0 && $out[$cid][$last][0] === $day) {
                $out[$cid][$last][1] += (float) $r->total_ws;
            } else {
                $out[$cid][] = [$day, (float) $r->total_ws];
            }
        }

        return $out;
    }

    /**
     * Resumen BG/NBD de un cliente al corte (unix): null si no había comprado.
     *
     * @param  list<array{0: int, 1: float}>  $days
     * @return array{0: float, 1: float, 2: float, 3: float}|null x, tx, T (días) y valor promedio de las repetidas
     */
    public static function summary(array $days, int $cutoff): ?array
    {
        $before = array_values(array_filter($days, fn (array $d) => $d[0] <= $cutoff));
        if ($before === []) {
            return null;
        }
        $first = $before[0][0];
        $n = count($before);
        $repeat = array_slice($before, 1);
        $mean = $repeat === [] ? 0.0 : array_sum(array_column($repeat, 1)) / count($repeat);

        return [(float) ($n - 1), (float) intdiv($before[$n - 1][0] - $first, 86400), (float) intdiv($cutoff - $first, 86400), round($mean, 2)];
    }
}
