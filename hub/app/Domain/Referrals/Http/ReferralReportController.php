<?php

declare(strict_types=1);

namespace App\Domain\Referrals\Http;

use App\Domain\Referrals\Queries\ReferralReport;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** GET referrals/report?from=&to= (modulos.md M14, «Reportes»). */
final class ReferralReportController
{
    public function __invoke(Request $request, ReferralReport $report, CurrentWorkspace $current): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $workspace = $current->get();
        $tz = $workspace->timezone ?: 'America/Bogota';
        $to = $request->filled('to') ? CarbonImmutable::parse($request->string('to')->toString(), $tz) : CarbonImmutable::now($tz);
        $from = $request->filled('from') ? CarbonImmutable::parse($request->string('from')->toString(), $tz) : $to->subDays(89);
        if ($from->diffInDays($to) > 731) {
            $from = $to->subDays(731);
        }

        return response()->json($report->build($workspace, $from, $to));
    }
}
