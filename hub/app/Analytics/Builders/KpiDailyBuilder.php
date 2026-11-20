<?php

declare(strict_types=1);

namespace App\Analytics\Builders;

use App\Analytics\Ch;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Recalcula `kpi_daily` (tráfico por día, canal y dispositivo en la zona del espacio de trabajo) desde `sessions`.
 * hub/CLAUDE.md §10: recálculo nocturno de los últimos 3 días; con --days se reconstruye más.
 *
 * F12 (auditoría): los tableros leen el tráfico de `kpi_daily` siempre que la consulta lo permite (fecha, canal y
 * dispositivo), pero solo el trabajo nocturno lo llenaba; las sesiones se arman cada 10 minutos, así que las de hoy (y
 * las de ayer hasta la madrugada) no aparecían hasta el otro día. Ahora `igr:sessions:build` llama a `refreshRecent()`
 * después de armar las sesiones: compara ayer y hoy contra `sessions` y solo los rehace si cambiaron.
 *
 * Borrar y volver a insertar los días va bajo un candado por espacio de trabajo: en una SummingMergeTree, dos
 * reconstrucciones a la vez (la nocturna y la de cada 10 minutos) sumarían dos veces.
 *
 * Las ventas NO salen de aquí: se leen de `orders` en MariaDB (pedido válido de inteligencia.md §1) para que coincidan
 * con WooCommerce.
 */
final class KpiDailyBuilder
{
    public function build(Workspace $workspace, int $days = 3): void
    {
        $tz = $this->tz($workspace->timezone ?: 'UTC');
        $from = CarbonImmutable::now($tz)->subDays($days)->startOfDay();
        $this->locked($workspace, true, null, fn () => $this->rebuild($workspace, $from, $tz));
    }

    /**
     * Ayer y hoy (en la zona del espacio de trabajo) al día con `sessions`, sin tocarlos si ya coinciden.
     *
     * @return bool si se rehicieron
     */
    public function refreshRecent(Workspace $workspace): bool
    {
        $tz = $this->tz($workspace->timezone ?: 'UTC');
        $from = CarbonImmutable::now($tz)->subDay()->startOfDay();
        $ch = Ch::forWorkspace($workspace);
        $fresh = $ch->select(<<<SQL
            SELECT toString(toDate(started_at, '{$tz}')) AS d, toString(channel) AS channel, toString(device_type) AS device,
                   toUInt64(count()) AS sessions, toUInt64(uniqExact(anonymous_id)) AS users, toUInt64(sum(viewed_product)) AS pv,
                   toUInt64(sum(added_to_cart)) AS atc, toUInt64(sum(started_checkout)) AS co, toUInt64(sum(purchased)) AS orders,
                   toString(toDecimal64(sum(revenue), 2)) AS revenue
            FROM sessions FINAL
            WHERE workspace_id = {workspace_id:UInt32} AND started_at >= {from_ts:DateTime64(3)}
            GROUP BY d, channel, device
            SQL, ['from_ts' => $from->utc()->format('Y-m-d H:i:s.v')]);
        $stored = $ch->select(<<<'SQL'
            SELECT toString(date) AS d, toString(channel) AS channel, toString(device_type) AS device,
                   toUInt64(sum(sessions)) AS sessions, toUInt64(sum(users)) AS users, toUInt64(sum(product_views)) AS pv,
                   toUInt64(sum(add_to_carts)) AS atc, toUInt64(sum(checkouts)) AS co, toUInt64(sum(orders)) AS orders,
                   toString(toDecimal64(sum(revenue), 2)) AS revenue
            FROM kpi_daily
            WHERE workspace_id = {workspace_id:UInt32} AND date >= {from:Date}
            GROUP BY d, channel, device
            SQL, ['from' => $from->toDateString()]);
        if (self::normalize($fresh) === self::normalize($stored)) {
            return false;
        }

        return $this->locked($workspace, false, false, function () use ($workspace, $from, $tz): bool {
            $this->rebuild($workspace, $from, $tz);

            return true;
        });
    }

    private function rebuild(Workspace $workspace, CarbonImmutable $from, string $tz): void
    {
        $ch = Ch::forWorkspace($workspace);
        $ch->execute(
            'DELETE FROM kpi_daily WHERE workspace_id = {workspace_id:UInt32} AND date >= {from:Date}',
            ['from' => $from->toDateString()],
            ['mutations_sync' => 2],
        );
        $ch->execute(<<<SQL
            INSERT INTO kpi_daily (workspace_id, date, channel, device_type, sessions, users, product_views, add_to_carts, checkouts, orders, revenue, new_customers, returning_customers)
            SELECT workspace_id, toDate(started_at, '{$tz}') AS d, channel, device_type,
                   count() AS sessions, uniqExact(anonymous_id) AS users,
                   sum(viewed_product), sum(added_to_cart), sum(started_checkout), sum(purchased), sum(revenue), 0, 0
            FROM sessions FINAL
            WHERE workspace_id = {workspace_id:UInt32} AND started_at >= {from_ts:DateTime64(3)}
            GROUP BY workspace_id, d, channel, device_type
            SQL, ['from_ts' => $from->utc()->format('Y-m-d H:i:s.v')]);
    }

    /**
     * Corre bajo el candado del espacio de trabajo. Si otra reconstrucción lo tiene, espera hasta 2 minutos (la
     * nocturna; si no, falla) o, con `$wait` en false, no hace nada (la de cada 10 minutos vuelve a intentar en la
     * siguiente).
     *
     * @template T
     *
     * @param  T  $busy  lo que se devuelve si no se consiguió el candado
     * @param  callable(): T  $fn
     * @return T
     */
    private function locked(Workspace $workspace, bool $wait, mixed $busy, callable $fn): mixed
    {
        $lock = Cache::lock('ws:'.$workspace->id.':kpi_daily', 600);
        if ($wait) {
            // Si no se libera en 2 minutos lanza LockTimeoutException: el trabajo nocturno falla y se reintenta.
            $lock->block(120);
        } elseif (! $lock->get()) {
            return $busy;
        }
        try {
            return $fn();
        } finally {
            $lock->release();
        }
    }

    /**
     * Filas comparables (mismo orden y mismos tipos de los dos lados).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private static function normalize(array $rows): array
    {
        $out = array_map(fn (array $r) => implode('|', [
            $r['d'], $r['channel'], $r['device'], (int) $r['sessions'], (int) $r['users'], (int) $r['pv'], (int) $r['atc'], (int) $r['co'], (int) $r['orders'],
            number_format((float) $r['revenue'], 2, '.', ''),
        ]), $rows);
        sort($out);

        return $out;
    }

    /** Zona horaria validada (va como literal en el SQL de ClickHouse). */
    private function tz(string $tz): string
    {
        return in_array($tz, timezone_identifiers_list(), true) ? $tz : 'UTC';
    }
}
