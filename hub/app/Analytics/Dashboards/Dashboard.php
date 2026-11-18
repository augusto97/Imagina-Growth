<?php

declare(strict_types=1);

namespace App\Analytics\Dashboards;

use App\Analytics\Semantic\Filters;
use App\Analytics\Semantic\Period;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Un tablero de M05. Cada uno arma su respuesta con consultas propias o con
 * MetricQueryRunner; todos filtran por el espacio de trabajo.
 */
abstract class Dashboard
{
    /** @return array<string, mixed> */
    abstract public function handle(Workspace $workspace, Period $period, Filters $filters): array;

    /** Pedidos válidos (inteligencia.md §1) del periodo, con los filtros que aplican a pedidos. */
    protected function paidOrders(Workspace $workspace, Period $period, Filters $filters, string $alias = 'orders'): Builder
    {
        return $this->orders($workspace, $period, $filters, $alias)->where("{$alias}.is_paid", true);
    }

    protected function orders(Workspace $workspace, Period $period, Filters $filters, string $alias = 'orders'): Builder
    {
        $q = DB::table("orders as {$alias}")->where("{$alias}.workspace_id", $workspace->id)
            ->where("{$alias}.created_at", '>=', $period->fromUtc())->where("{$alias}.created_at", '<', $period->toUtc());
        if ($filters->site !== null) {
            $q->where("{$alias}.site_id", $filters->site->id);
        }
        if ($filters->channel !== null) {
            $q->where("{$alias}.channel_last", $filters->channel);
        }
        if ($filters->city !== null) {
            $q->where("{$alias}.shipping_city", $filters->city);
        }

        return $q;
    }

    protected static function ratio(float|int $num, float|int $den, int $digits = 4): float
    {
        return $den == 0 ? 0.0 : round($num / $den, $digits);
    }

    /**
     * Parámetros de fecha para ClickHouse.
     *
     * @return array{from: string, to: string}
     */
    protected static function chRange(Period $period): array
    {
        return ['from' => $period->fromUtc(), 'to' => $period->toUtc()];
    }
}
