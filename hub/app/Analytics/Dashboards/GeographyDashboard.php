<?php

declare(strict_types=1);

namespace App\Analytics\Dashboards;

use App\Analytics\Ch;
use App\Analytics\Semantic\Filters;
use App\Analytics\Semantic\Period;
use App\Domain\Workspaces\Models\Workspace;

/**
 * 7. Geografía: ventas por departamento o estado y ciudad, y conversión de las visitas por ciudad.
 *
 * La conversión por ciudad usa la ubicación aproximada de la visita (IP), que no siempre trae ciudad y la escribe
 * distinto que la dirección del pedido («Bogota» frente a «Bogotá D.C.»). Para no mostrar una cifra engañosa
 * (docs/auditoria-2026-11.md F12): las ciudades se comparan sin tildes ni «D.C.», la conversión solo sale con al
 * menos MIN_SESSIONS visitas (si no, `low_sample`) y se informa qué parte de las visitas no tiene ciudad.
 */
final class GeographyDashboard extends Dashboard
{
    /** Con menos visitas que esto, una o dos compras cambian la conversión en decenas de puntos. */
    public const MIN_SESSIONS = 100;

    public function handle(Workspace $workspace, Period $period, Filters $filters): array
    {
        $regions = $this->paidOrders($workspace, $period, $filters)
            ->groupByRaw("COALESCE(shipping_region_code, '')")
            ->selectRaw("COALESCE(shipping_region_code, '') AS region, COUNT(*) AS orders, COALESCE(SUM(total_ws), 0) AS revenue")
            ->orderByDesc('revenue')->get()
            ->map(fn ($r) => ['region' => (string) $r->region, 'orders' => (int) $r->orders, 'revenue' => round((float) $r->revenue, 2), 'aov' => round(self::ratio((float) $r->revenue, (int) $r->orders, 2), 2)])->all();

        $sales = $this->paidOrders($workspace, $period, $filters)
            ->groupByRaw("COALESCE(shipping_city, ''), COALESCE(shipping_region_code, '')")
            ->selectRaw("COALESCE(shipping_city, '') AS city, COALESCE(shipping_region_code, '') AS region, COUNT(*) AS orders, COALESCE(SUM(total_ws), 0) AS revenue")
            ->get();
        [$fsql, $params] = $filters->sessionsSql();
        $visits = Ch::forWorkspace($workspace)->select(
            "SELECT city, count() AS sessions, sum(purchased) AS purchases FROM sessions FINAL
             WHERE workspace_id = {workspace_id:UInt32} AND started_at >= {from:DateTime64(3)} AND started_at < {to:DateTime64(3)}{$fsql}
             GROUP BY city",
            $params + self::chRange($period),
        );
        // Varias grafías de la misma ciudad suman juntas («Bogota», «Bogotá D.C.»).
        $visitBy = [];
        $allSessions = 0;
        $allPurchases = 0;
        $withoutCity = 0;
        foreach ($visits as $v) {
            $n = (int) $v['sessions'];
            $allSessions += $n;
            $allPurchases += (int) $v['purchases'];
            $key = self::cityKey((string) $v['city']);
            if ($key === '') {
                $withoutCity += $n;

                continue;
            }
            $visitBy[$key] = [
                'sessions' => ($visitBy[$key]['sessions'] ?? 0) + $n,
                'purchases' => ($visitBy[$key]['purchases'] ?? 0) + (int) $v['purchases'],
            ];
        }
        // Hay pedidos pero ninguna visita terminó en compra: las compras no quedaron ligadas a la visita (el código de
        // seguimiento no carga en el pago, por ejemplo). Una conversión de 0 % al lado de los pedidos engañaría.
        $unlinked = $allPurchases === 0 && $sales->isNotEmpty();
        $cities = [];
        foreach ($sales as $s) {
            $key = self::cityKey((string) $s->city);
            if (isset($cities[$key.'|'.$s->region])) {
                $cities[$key.'|'.$s->region]['orders'] += (int) $s->orders;
                $cities[$key.'|'.$s->region]['revenue'] = round($cities[$key.'|'.$s->region]['revenue'] + (float) $s->revenue, 2);

                continue;
            }
            $v = $key === '' ? null : ($visitBy[$key] ?? null);
            $lowSample = $v !== null && $v['sessions'] < self::MIN_SESSIONS;
            $cities[$key.'|'.$s->region] = [
                'city' => (string) $s->city, 'region' => (string) $s->region, 'orders' => (int) $s->orders, 'revenue' => round((float) $s->revenue, 2),
                'sessions' => $v !== null ? $v['sessions'] : null,
                'conversion' => $v !== null && ! $lowSample && ! $unlinked ? self::ratio($v['purchases'], $v['sessions']) : null,
                'low_sample' => $lowSample,
            ];
        }
        $cities = array_values($cities);
        usort($cities, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);

        return [
            'regions' => $regions,
            'cities' => array_slice($cities, 0, 50),
            'sessions' => ['total' => $allSessions, 'without_city' => $withoutCity, 'without_city_share' => self::ratio($withoutCity, $allSessions)],
            'min_sessions' => self::MIN_SESSIONS,
            'conversion_unavailable' => $unlinked,
        ];
    }

    /** La ciudad para comparar: minúsculas, sin tildes, sin «D.C.» ni signos («Bogotá D.C.» = «bogota»). */
    public static function cityKey(string $city): string
    {
        $c = mb_strtolower(trim($city));
        $c = (string) preg_replace('/\p{Mn}/u', '', \Normalizer::normalize($c, \Normalizer::FORM_D) ?: $c);
        $c = (string) preg_replace('/\b(d\.?\s?c\.?|distrito capital)$/u', '', $c);

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $c));
    }
}
