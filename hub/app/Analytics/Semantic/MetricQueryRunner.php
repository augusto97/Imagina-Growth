<?php

declare(strict_types=1);

namespace App\Analytics\Semantic;

use App\Analytics\Ch;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Ejecuta una MetricQuery (hub/CLAUDE.md §10):
 * - métricas de ventas desde `orders` en MariaDB (pedido válido = mismo dato que WooCommerce);
 * - tráfico desde `kpi_daily` cuando alcanza (fecha, canal, dispositivo) y si no desde `sessions`;
 * - siempre filtrado por el espacio de trabajo; caché de 5 minutos que se
 *   invalida sola cuando llega un evento nuevo (la clave lleva el último evento recibido).
 */
final class MetricQueryRunner
{
    public const CACHE_SECONDS = 300;

    /**
     * @return array{rows: list<array<string, mixed>>, totals: array<string, float|int>, compare_totals: array<string, float|int>|null}
     */
    public function run(Workspace $workspace, MetricQuery $query): array
    {
        return self::remember($workspace, 'mq:'.$query->cacheKey(), function () use ($workspace, $query): array {
            $rows = $this->rows($workspace, $query, $query->period);
            $totals = $query->dimensions === [] ? ($rows[0] ?? []) : $this->rows($workspace, new MetricQuery($query->metrics, [], $query->period, $query->filters), $query->period)[0] ?? [];
            $compare = $query->period->compare === null ? null
                : ($this->rows($workspace, new MetricQuery($query->metrics, [], $query->period->compare, $query->filters), $query->period->compare)[0] ?? []);

            return [
                'rows' => $query->dimensions === [] ? [] : $rows,
                'totals' => $this->onlyMetrics($totals, $query->metrics),
                'compare_totals' => $compare === null ? null : $this->onlyMetrics($compare, $query->metrics),
            ];
        });
    }

    /**
     * Caché por espacio de trabajo con invalidación por ingesta.
     *
     * @template T
     *
     * @param  callable(): T  $compute
     * @return T
     */
    public static function remember(Workspace $workspace, string $key, callable $compute): mixed
    {
        if (! config('igr.analytics.cache', true)) {
            return $compute();
        }
        // La versión es el último evento redondeado al minuto: con tráfico, `last_event_at` cambia cada segundo y
        // la caché nunca acertaba (docs/auditoria-2026-11.md R9). Un tablero puede ir hasta un minuto atrás.
        $last = Site::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->max('last_event_at');
        $version = $last === null ? '0' : (string) intdiv((int) strtotime((string) $last), 60);

        return Cache::remember('ws:'.$workspace->id.':analytics:'.$key.':'.$version, self::CACHE_SECONDS, fn () => $compute());
    }

    /** @return list<array<string, mixed>> */
    private function rows(Workspace $workspace, MetricQuery $q, Period $period): array
    {
        $orderMetrics = array_values(array_filter($q->metrics, fn ($m) => Catalog::source($m) === 'orders'));
        $trafficMetrics = array_values(array_filter($q->metrics, fn ($m) => Catalog::source($m) === 'traffic'));
        $needsRevenue = in_array('revenue_per_visitor', $q->metrics, true);
        if ($needsRevenue) {
            $orderMetrics[] = 'revenue';
            $trafficMetrics[] = 'users';
        }

        $byKey = [];
        if ($orderMetrics !== []) {
            foreach ($this->orderRows($workspace, $q, $period, array_values(array_unique($orderMetrics))) as $row) {
                $byKey[$this->rowKey($row, $q->dimensions)] = $row;
            }
        }
        if ($trafficMetrics !== []) {
            foreach ($this->trafficRows($workspace, $q, $period, array_values(array_unique($trafficMetrics))) as $row) {
                $k = $this->rowKey($row, $q->dimensions);
                $byKey[$k] = ($byKey[$k] ?? []) + $row;
            }
        }
        $rows = array_values($byKey);
        foreach ($rows as &$row) {
            foreach ($q->metrics as $m) {
                $row[$m] ??= 0;
            }
            if ($needsRevenue) {
                $row['revenue_per_visitor'] = ($row['users'] ?? 0) > 0 ? round((float) $row['revenue'] / (float) $row['users'], 2) : 0;
            }
        }
        unset($row);

        if ($q->dimensions === ['date']) {
            $index = [];
            foreach ($rows as $row) {
                $index[(string) $row['date']] = $row;
            }
            $rows = [];
            foreach ($period->buckets() as $bucket) {
                $rows[] = $index[$bucket] ?? array_merge(['date' => $bucket], array_fill_keys($q->metrics, 0));
            }
        } elseif ($q->dimensions === []) {
            $rows = [$rows[0] ?? array_fill_keys($q->metrics, 0)];
        } else {
            usort($rows, fn ($a, $b) => ($b[$q->metrics[0]] ?? 0) <=> ($a[$q->metrics[0]] ?? 0));
            $rows = array_slice($rows, 0, $q->limit);
        }

        return $rows;
    }

    /**
     * @param  list<string>  $metrics
     * @return list<array<string, mixed>>
     */
    private function orderRows(Workspace $workspace, MetricQuery $q, Period $period, array $metrics): array
    {
        // El desfase de la zona va como parámetro: cada «?» del SELECT es ese desfase en segundos.
        $local = 'DATE_ADD(created_at, INTERVAL ? SECOND)';
        $select = [];
        $group = [];
        // Se recorre el catálogo (no la consulta) para que los alias salgan de nombres fijos.
        foreach (Catalog::DIMENSIONS as $dim => $definition) {
            if (! in_array($dim, $q->dimensions, true)) {
                continue;
            }
            $expr = $definition['orders'];
            if ($expr === null) {
                $expr = "''";
            }
            if ($dim === 'date') {
                $expr = match ($period->granularity) {
                    'week' => "DATE(DATE_SUB({$local}, INTERVAL WEEKDAY({$local}) DAY))",
                    'month' => "DATE_FORMAT({$local}, '%Y-%m-01')",
                    default => "DATE({$local})",
                };
            }
            $select[] = "{$expr} AS `{$dim}`";
            $group[] = "`{$dim}`";
        }
        $paid = 'is_paid = 1';
        $exprs = [
            'revenue' => "COALESCE(SUM(CASE WHEN {$paid} THEN total_ws END), 0)",
            'orders' => "SUM({$paid})",
            'aov' => "COALESCE(SUM(CASE WHEN {$paid} THEN total_ws END) / NULLIF(SUM({$paid}), 0), 0)",
            'new_customers' => "COUNT(DISTINCT CASE WHEN {$paid} AND is_first_order = 1 THEN contact_id END)",
            'returning_customers' => "COUNT(DISTINCT CASE WHEN {$paid} AND order_index > 1 THEN contact_id END)",
            'cod_pending_orders' => "SUM(is_cod = 1 AND is_paid = 0 AND status NOT IN ('cancelled','failed','refunded','trash'))",
            'cod_pending_revenue' => "COALESCE(SUM(CASE WHEN is_cod = 1 AND is_paid = 0 AND status NOT IN ('cancelled','failed','refunded','trash') THEN total_ws END), 0)",
            'refunded' => 'COALESCE(SUM(refunded), 0)',
            'discount' => "COALESCE(SUM(CASE WHEN {$paid} THEN discount END), 0)",
            'net_revenue' => "COALESCE(SUM(CASE WHEN {$paid} THEN net_ws END), 0)",
        ];
        foreach ($exprs as $m => $expr) {
            if (in_array($m, $metrics, true)) {
                $select[] = $expr." AS `{$m}`";
            }
        }
        $query = DB::table('orders')->where('workspace_id', $workspace->id)
            ->where('created_at', '>=', $period->fromUtc())->where('created_at', '<', $period->toUtc());
        if ($q->filters->site !== null) {
            $query->where('site_id', $q->filters->site->id);
        }
        if ($q->filters->channel !== null) {
            $query->where('channel_last', $q->filters->channel);
        }
        if ($q->filters->city !== null) {
            $query->where('shipping_city', $q->filters->city);
        }
        $selectSql = implode(', ', $select);
        $query->selectRaw($selectSql, array_fill(0, substr_count($selectSql, '?'), $period->offsetSeconds()));
        if ($group !== []) {
            $query->groupByRaw(implode(', ', $group));
        }

        return array_values($query->get()->map(function ($r) use ($metrics): array {
            $row = (array) $r;
            foreach ($metrics as $m) {
                $row[$m] = in_array(Catalog::METRICS[$m]['unit'], ['money'], true) ? round((float) $row[$m], 2) : (int) $row[$m];
            }
            if (isset($row['site'])) {
                $row['site'] = (string) $row['site'];
            }

            return $row;
        })->all());
    }

    /**
     * @param  list<string>  $metrics
     * @return list<array<string, mixed>>
     */
    private function trafficRows(Workspace $workspace, MetricQuery $q, Period $period, array $metrics): array
    {
        $kpiDims = array_diff($q->dimensions, ['date', 'channel', 'device']) === [];
        $useKpi = $kpiDims && $q->filters->site === null && $q->filters->city === null && ! in_array('users', $metrics, true);
        $tz = $period->timezone;
        $select = [];
        $group = [];
        foreach ($q->dimensions as $dim) {
            $expr = Catalog::DIMENSIONS[$dim]['traffic'] ?? "''";
            if ($dim === 'date') {
                $base = $useKpi ? 'date' : "toDate(started_at, '{$tz}')";
                $expr = match ($period->granularity) {
                    'week' => "toString(toMonday({$base}))",
                    'month' => "toString(toStartOfMonth({$base}))",
                    default => "toString({$base})",
                };
            } elseif ($dim === 'site') {
                $expr = 'toString(site_id)';
            }
            // Alias propio: en ClickHouse un alias igual a una columna la tapa en el WHERE.
            $select[] = "{$expr} AS dim_{$dim}";
            $group[] = "dim_{$dim}";
        }
        $sum = fn (string $col) => $useKpi ? "sum({$col})" : 'sum('.$col.')';
        $exprs = $useKpi ? [
            'sessions' => 'sum(sessions)', 'product_views' => 'sum(product_views)', 'add_to_carts' => 'sum(add_to_carts)',
            'checkouts' => 'sum(checkouts)', 'purchase_sessions' => 'sum(orders)', 'conversion_rate' => 'if(sum(sessions) = 0, 0, sum(orders) / sum(sessions))',
        ] : [
            'sessions' => 'count()', 'users' => 'uniqExact(anonymous_id)', 'product_views' => $sum('viewed_product'),
            'add_to_carts' => $sum('added_to_cart'), 'checkouts' => $sum('started_checkout'), 'purchase_sessions' => $sum('purchased'),
            'conversion_rate' => 'if(count() = 0, 0, sum(purchased) / count())',
        ];
        foreach ($metrics as $m) {
            $select[] = $exprs[$m]." AS {$m}";
        }
        [$filterSql, $params] = $q->filters->sessionsSql();
        if ($useKpi) {
            $sql = 'SELECT '.implode(', ', $select).' FROM kpi_daily WHERE workspace_id = {workspace_id:UInt32}'
                .' AND date >= {from_d:Date} AND date < {to_d:Date}'.$filterSql;
            $params += ['from_d' => $period->from->toDateString(), 'to_d' => $period->to->toDateString()];
        } else {
            $sql = 'SELECT '.implode(', ', $select).' FROM sessions FINAL WHERE workspace_id = {workspace_id:UInt32}'
                .' AND started_at >= {from:DateTime64(3)} AND started_at < {to:DateTime64(3)}'.$filterSql;
            $params += ['from' => $period->fromUtc(), 'to' => $period->toUtc()];
        }
        if ($group !== []) {
            $sql .= ' GROUP BY '.implode(', ', $group);
        }
        $rows = array_map(function (array $row) use ($q): array {
            foreach ($q->dimensions as $dim) {
                $row[$dim] = $row['dim_'.$dim];
                unset($row['dim_'.$dim]);
            }

            return $row;
        }, Ch::forWorkspace($workspace)->select($sql, $params));
        foreach ($rows as &$row) {
            foreach ($metrics as $m) {
                $row[$m] = $m === 'conversion_rate' ? round((float) $row[$m], 5) : (int) $row[$m];
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $dims
     */
    private function rowKey(array $row, array $dims): string
    {
        return implode('|', array_map(fn ($d) => (string) ($row[$d] ?? ''), $dims));
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $metrics
     * @return array<string, float|int>
     */
    private function onlyMetrics(array $row, array $metrics): array
    {
        $out = [];
        foreach ($metrics as $m) {
            $v = $row[$m] ?? 0;
            $out[$m] = is_int($v) ? $v : (float) $v;
        }

        return $out;
    }
}
