<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Insights\Detectors;

use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Cifras de leads (M18) que usan los detectores de servicios (#21 a #23). */
final class LeadStats
{
    /** Leads creados en el periodo que ya se ganaron ÷ leads creados en el periodo. */
    public static function closeRate(Workspace $workspace, CarbonImmutable $from, CarbonImmutable $to): float
    {
        $row = DB::table('leads')->where('workspace_id', $workspace->id)->where('created_at', '>=', $from->utc())->where('created_at', '<', $to->utc())
            ->selectRaw('COUNT(*) AS n, SUM(won_at IS NOT NULL) AS won')->first();
        $n = (int) ($row->n ?? 0);

        return $n > 0 ? (int) ($row->won ?? 0) / $n : 0.0;
    }

    /** Valor promedio de los leads ganados en el periodo (los que tienen valor). */
    public static function avgWonValue(Workspace $workspace, CarbonImmutable $from, CarbonImmutable $to): float
    {
        return (float) (DB::table('leads')->where('workspace_id', $workspace->id)->whereNotNull('won_at')->whereNotNull('value_ws')
            ->where('won_at', '>=', $from->utc())->where('won_at', '<', $to->utc())->avg('value_ws') ?? 0);
    }
}
