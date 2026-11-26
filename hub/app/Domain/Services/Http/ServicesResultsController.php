<?php

declare(strict_types=1);

namespace App\Domain\Services\Http;

use App\Analytics\Semantic\Period;
use App\Domain\Services\Queries\ServicesResults;
use App\Support\Tenancy\CurrentWorkspace;
use App\Support\Tenancy\RedisKey;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Resultados de un negocio sin tienda (rediseño v2 §11.2): `GET services/results?days=30` o `?from=&to=` (en la zona
 * del espacio de trabajo, hasta 400 días) con el embudo, los leads por fuente y por periodo, la primera respuesta, las
 * citas y la asistencia, las cotizaciones enviadas y aceptadas, la tasa de cierre, el valor ganado y lo que trajo cada
 * lead ganado. En caché 2 minutos por espacio de trabajo y periodo.
 */
final class ServicesResultsController
{
    public const CACHE_SECONDS = 120;

    public function __construct(private readonly CurrentWorkspace $current) {}

    public function __invoke(Request $request, ServicesResults $results): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $data = $request->validate([
            'days' => ['nullable', 'integer', 'in:7,30,90,365'],
            'from' => ['nullable', 'date_format:Y-m-d', 'required_with:to'],
            'to' => ['nullable', 'date_format:Y-m-d', 'required_with:from', 'after_or_equal:from'],
        ]);
        $ws = $this->current->get();
        $tz = (string) ($ws->timezone ?: 'America/Bogota');
        if (isset($data['from'], $data['to'])) {
            if (CarbonImmutable::parse($data['from'])->diffInDays(CarbonImmutable::parse($data['to'])) > 400) {
                throw ValidationException::withMessages(['from' => __('El periodo puede ser de hasta 400 días.')]);
            }
            $period = Period::make($tz, $data['from'], $data['to']);
        } else {
            $today = CarbonImmutable::now($tz)->toDateString();
            $period = Period::make($tz, CarbonImmutable::now($tz)->subDays((int) ($data['days'] ?? 30) - 1)->toDateString(), $today);
        }
        $key = RedisKey::for($ws->id, 'services:results:'.$period->from->toDateString().':'.$period->to->toDateString().':'.app()->getLocale());

        return response()->json(Cache::remember($key, self::CACHE_SECONDS, fn () => $results->handle($ws, $period)));
    }
}
