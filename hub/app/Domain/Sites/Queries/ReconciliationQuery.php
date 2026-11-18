<?php

declare(strict_types=1);

namespace App\Domain\Sites\Queries;

use App\Domain\Commerce\Models\Order;
use App\Domain\Sites\Models\Site;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;

/**
 * Compara la conciliación que calculó WooCommerce (pedidos y suma de total por
 * estado) con la proyección de pedidos del hub, sobre el mismo rango
 * (api-connector.md §1.2 y §5.3). Es la prueba del criterio de la Fase 0.
 */
final class ReconciliationQuery
{
    /**
     * @return array{available: bool, ok: bool, rows: list<array<string, mixed>>, totals: array<string, mixed>, from: string|null, until: string|null}|array{available: false}
     */
    public function handle(Site $site): array
    {
        $state = $site->backfill_state ?? [];
        $rec = $state['reconciliation'] ?? null;
        if (! is_array($rec) || ! isset($rec['orders_by_status']) || ! is_array($rec['orders_by_status'])) {
            return ['available' => false];
        }

        $tz = $site->timezone ?: 'UTC';
        $from = isset($state['from']) ? CarbonImmutable::parse((string) $state['from'], $tz)->startOfDay()->utc() : null;
        $until = isset($rec['generated_at']) ? CarbonImmutable::parse((string) $rec['generated_at'])->utc() : null;

        $hubRows = Order::query()
            ->where('site_id', $site->id)
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($until, fn ($q) => $q->where('created_at', '<=', $until))
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) AS c, COALESCE(SUM(total), 0) AS t, COALESCE(SUM(refunded), 0) AS r')
            ->get()
            ->keyBy('status');

        $statuses = array_unique(array_merge(array_keys($rec['orders_by_status']), $hubRows->keys()->all()));
        sort($statuses);

        $rows = [];
        $ok = true;
        $wcCount = 0;
        $hubCount = 0;
        $wcTotal = '0.00';
        $hubTotal = '0.00';
        foreach ($statuses as $status) {
            $wc = $rec['orders_by_status'][$status] ?? ['count' => 0, 'total' => '0.00'];
            $hub = $hubRows->get($status);
            $row = [
                'status' => $status,
                'wc_count' => (int) ($wc['count'] ?? 0),
                'hub_count' => (int) ($hub?->getAttribute('c') ?? 0),
                'wc_total' => Decimal::of((string) ($wc['total'] ?? '0')),
                'hub_total' => Decimal::of((string) ($hub?->getAttribute('t') ?? '0')),
            ];
            $row['ok'] = $row['wc_count'] === $row['hub_count'] && Decimal::equals($row['wc_total'], $row['hub_total']);
            $ok = $ok && $row['ok'];
            $wcCount += $row['wc_count'];
            $hubCount += $row['hub_count'];
            $wcTotal = Decimal::add($wcTotal, $row['wc_total']);
            $hubTotal = Decimal::add($hubTotal, $row['hub_total']);
            $rows[] = $row;
        }

        $totals = ['wc_count' => $wcCount, 'hub_count' => $hubCount, 'wc_total' => $wcTotal, 'hub_total' => $hubTotal];
        if (isset($rec['refunds']) && is_array($rec['refunds'])) {
            $hubRefunded = Decimal::add(...array_map(fn ($r) => Decimal::of((string) $r->getAttribute('r')), $hubRows->values()->all()) ?: ['0']);
            $totals['wc_refunded'] = Decimal::of((string) ($rec['refunds']['total'] ?? '0'));
            $totals['hub_refunded'] = $hubRefunded;
            $totals['refunds_ok'] = Decimal::equals($totals['wc_refunded'], $hubRefunded);
            $ok = $ok && $totals['refunds_ok'];
        }

        return [
            'available' => true,
            'ok' => $ok,
            'currency' => $rec['currency'] ?? $site->currency,
            'rows' => $rows,
            'totals' => $totals,
            'from' => $from?->toIso8601String(),
            'until' => $until?->toIso8601String(),
        ];
    }
}
