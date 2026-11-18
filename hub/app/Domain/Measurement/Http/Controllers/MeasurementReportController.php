<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Http\Controllers;

use App\Domain\Measurement\Queries\DeliveryLogQuery;
use App\Domain\Measurement\Queries\SignalQualityQuery;
use App\Domain\Measurement\Queries\SpendSummaryQuery;
use App\Support\Ids\PrefixedId;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Throwable;

final class MeasurementReportController
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    /** GET /api/w/{ws}/measurement/deliveries */
    public function deliveries(Request $request, DeliveryLogQuery $query): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $data = $request->validate([
            'destination' => ['nullable', 'string', 'max:40'],
            'status' => ['nullable', 'in:delivered,retrying,failed,skipped'],
            'platform' => ['nullable', 'in:meta,google,tiktok'],
            'days' => ['nullable', 'integer', 'min:1', 'max:90'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        try {
            return response()->json($query->handle($this->current->get(), [
                'destination_id' => isset($data['destination']) ? PrefixedId::decode('dst', $data['destination']) ?? '-' : null,
                'status' => $data['status'] ?? null,
                'platform' => $data['platform'] ?? null,
                'days' => (int) ($data['days'] ?? 7),
                'page' => (int) ($data['page'] ?? 1),
            ]));
        } catch (Throwable) {
            return response()->json(['message' => __('La bitácora de entregas no está disponible en este momento.')], 503);
        }
    }

    /** GET /api/w/{ws}/measurement/quality?days=7 */
    public function quality(Request $request, SignalQualityQuery $query): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $days = (int) $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:90']])['days'] ?: 7;
        try {
            return response()->json(['days' => $days, 'destinations' => (object) $query->handle($this->current->get(), $days)]);
        } catch (Throwable) {
            return response()->json(['message' => __('La calidad de la señal no está disponible en este momento.')], 503);
        }
    }

    /** GET /api/w/{ws}/measurement/spend?from=&to= */
    public function spend(Request $request, SpendSummaryQuery $query): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to']) : CarbonImmutable::yesterday();
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from']) : $to->subDays(29);

        return response()->json($query->handle($from, $to));
    }
}
