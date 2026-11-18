<?php

declare(strict_types=1);

namespace App\Analytics\Dashboards;

use App\Analytics\Ch;
use App\Analytics\Semantic\Filters;
use App\Analytics\Semantic\Period;
use App\Domain\Workspaces\Models\Workspace;

/** 2. Embudo: sesión → producto → carrito → checkout → compra, con caídas por paso y desglose por canal, dispositivo y método de pago. */
final class FunnelDashboard extends Dashboard
{
    private const STEPS = ['sessions', 'product_views', 'add_to_carts', 'checkouts', 'purchases'];

    public function handle(Workspace $workspace, Period $period, Filters $filters): array
    {
        $current = $this->steps($workspace, $period, $filters, null);
        $compare = $period->compare !== null ? $this->steps($workspace, $period->compare, $filters, null) : null;

        // Pagos: pedidos creados que terminan pagados, por método.
        $payments = $this->orders($workspace, $period, $filters)
            ->groupByRaw("COALESCE(payment_method_title, payment_method_id, '')")
            ->selectRaw("COALESCE(payment_method_title, payment_method_id, '') AS method, COUNT(*) AS created, SUM(is_paid) AS paid, SUM(is_cod = 1 AND is_paid = 0 AND status NOT IN ('cancelled','failed','refunded')) AS cod_pending, SUM(status IN ('failed','cancelled')) AS failed")
            ->orderByDesc('created')->get()
            ->map(fn ($r) => ['method' => (string) $r->method, 'created' => (int) $r->created, 'paid' => (int) $r->paid,
                'cod_pending' => (int) $r->cod_pending, 'failed' => (int) $r->failed, 'paid_rate' => self::ratio((int) $r->paid, (int) $r->created)])->all();

        return [
            'steps' => $this->withRates($current[''] ?? array_fill_keys(self::STEPS, 0)),
            'compare' => $compare === null ? null : $this->withRates($compare[''] ?? array_fill_keys(self::STEPS, 0)),
            'by_channel' => $this->breakdown($this->steps($workspace, $period, $filters, 'channel')),
            'by_device' => $this->breakdown($this->steps($workspace, $period, $filters, 'device_type')),
            'by_payment_method' => $payments,
        ];
    }

    /** @return array<string, array<string, int>> */
    private function steps(Workspace $workspace, Period $period, Filters $filters, ?string $dim): array
    {
        [$fsql, $params] = $filters->sessionsSql();
        $select = $dim === null ? "'' AS k" : "{$dim} AS k";
        $rows = Ch::forWorkspace($workspace)->select(
            "SELECT {$select}, count() AS sessions, sum(viewed_product) AS product_views, sum(added_to_cart) AS add_to_carts,
                    sum(started_checkout) AS checkouts, sum(purchased) AS purchases
             FROM sessions FINAL WHERE workspace_id = {workspace_id:UInt32} AND started_at >= {from:DateTime64(3)} AND started_at < {to:DateTime64(3)}{$fsql}
             GROUP BY k ORDER BY sessions DESC LIMIT 50",
            $params + self::chRange($period),
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['k']] = array_map('intval', array_intersect_key($r, array_flip(self::STEPS)));
        }

        return $out;
    }

    /**
     * @param  array<string, int>  $s
     * @return list<array{step: string, value: int, rate_from_previous: float, rate_from_start: float}>
     */
    private function withRates(array $s): array
    {
        $out = [];
        $prev = null;
        foreach (self::STEPS as $step) {
            $v = (int) ($s[$step] ?? 0);
            $out[] = ['step' => $step, 'value' => $v, 'rate_from_previous' => $prev === null ? 1.0 : self::ratio($v, $prev), 'rate_from_start' => self::ratio($v, (int) ($s['sessions'] ?? 0))];
            $prev = $v;
        }

        return $out;
    }

    /**
     * @param  array<string, array<string, int>>  $rows
     * @return list<array<string, mixed>>
     */
    private function breakdown(array $rows): array
    {
        $out = [];
        foreach ($rows as $key => $s) {
            $out[] = ['key' => $key === '' ? 'unknown' : $key] + $s + ['conversion' => self::ratio($s['purchases'], $s['sessions'])];
        }

        return $out;
    }
}
