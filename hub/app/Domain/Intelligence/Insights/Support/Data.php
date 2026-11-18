<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Insights\Support;

use App\Domain\Flows\Models\Flow;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Consultas comunes de los detectores (con el espacio de trabajo activo). */
final class Data
{
    /** Ticket promedio de los pedidos pagados desde `$since`. */
    public static function aov(Workspace $workspace, CarbonImmutable $since): float
    {
        return (float) DB::table('orders')->where('workspace_id', $workspace->id)->where('is_paid', true)
            ->where('created_at', '>=', $since->utc())->avg('total_ws');
    }

    /** Fecha para los parámetros DateTime64(3) de ClickHouse. */
    public static function ch(CarbonImmutable $at): string
    {
        return $at->utc()->format('Y-m-d H:i:s.v');
    }

    /**
     * Estado del flujo de la biblioteca (`template_key`) en este espacio de trabajo:
     * active, paused, draft o null si no existe (los archivados no cuentan).
     */
    public static function libraryFlowStatus(string $templateKey): ?string
    {
        $statuses = Flow::query()->where('template_key', $templateKey)->where('status', '!=', Flow::ARCHIVED)->pluck('status')->all();
        foreach ([Flow::ACTIVE, Flow::PAUSED, Flow::DRAFT] as $status) {
            if (in_array($status, $statuses, true)) {
                return $status;
            }
        }

        return null;
    }

    /**
     * Percentil (0 a 1) de una lista de números, por interpolación.
     *
     * @param  list<float>  $values
     */
    public static function percentile(array $values, float $p): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $pos = ($p * (count($values) - 1));
        $lo = (int) floor($pos);
        $hi = (int) ceil($pos);

        return $values[$lo] + ($values[$hi] - $values[$lo]) * ($pos - $lo);
    }
}
