<?php

declare(strict_types=1);

namespace App\Analytics\Builders;

use App\Analytics\Ch;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;

/**
 * Recalcula `kpi_daily` (tráfico por día, canal y dispositivo en la zona del
 * espacio de trabajo) desde `sessions`. hub/CLAUDE.md §10: recálculo nocturno
 * de los últimos 3 días; con --days se reconstruye más.
 *
 * Las ventas NO salen de aquí: se leen de `orders` en MariaDB (pedido válido
 * de inteligencia.md §1) para que coincidan con WooCommerce.
 */
final class KpiDailyBuilder
{
    public function build(Workspace $workspace, int $days = 3): void
    {
        $tz = $workspace->timezone ?: 'UTC';
        $from = CarbonImmutable::now($tz)->subDays($days)->startOfDay();
        $ch = Ch::forWorkspace($workspace);

        $ch->execute(
            'DELETE FROM kpi_daily WHERE workspace_id = {workspace_id:UInt32} AND date >= {from:Date}',
            ['from' => $from->toDateString()],
            ['mutations_sync' => 2],
        );
        $ch->execute(<<<SQL
            INSERT INTO kpi_daily (workspace_id, date, channel, device_type, sessions, users, product_views, add_to_carts, checkouts, orders, revenue, new_customers, returning_customers)
            SELECT workspace_id, toDate(started_at, '{$this->tz($tz)}') AS d, channel, device_type,
                   count() AS sessions, uniqExact(anonymous_id) AS users,
                   sum(viewed_product), sum(added_to_cart), sum(started_checkout), sum(purchased), sum(revenue), 0, 0
            FROM sessions FINAL
            WHERE workspace_id = {workspace_id:UInt32} AND started_at >= {from_ts:DateTime64(3)}
            GROUP BY workspace_id, d, channel, device_type
            SQL, ['from_ts' => $from->utc()->format('Y-m-d H:i:s.v')]);
    }

    /** Zona horaria validada (va como literal en el SQL de ClickHouse). */
    private function tz(string $tz): string
    {
        return in_array($tz, timezone_identifiers_list(), true) ? $tz : 'UTC';
    }
}
