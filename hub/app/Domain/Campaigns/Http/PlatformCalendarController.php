<?php

declare(strict_types=1);

namespace App\Domain\Campaigns\Http;

use App\Domain\Campaigns\Support\CommercialCalendar;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Plataforma → Calendario comercial (rediseño v2 §11.5): las fechas de cada país y año según la regla y las excepciones
 * que agrega el superadmin cuando el organizador anuncia otra fecha (Hot Sale, El Buen Fin, CyberLunes) o un momento
 * ese año no se hace. Se guardan en `commercial_calendar_overrides` (nunca en archivos ni por consola) y rigen enseguida
 * en el calendario de cada espacio de trabajo y en los avisos. Solo superadmin con 2FA (rutas en /api/platform).
 */
final class PlatformCalendarController
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    /** GET /api/platform/calendar?country=MX&year=2027 */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'country' => ['nullable', Rule::in(CommercialCalendar::COUNTRIES)],
            'year' => ['nullable', 'integer', 'between:2020,2100'],
        ]);

        return response()->json($this->payload((string) ($data['country'] ?? 'CO'), (int) ($data['year'] ?? now()->year)));
    }

    /** PUT /api/platform/calendar/{country}/{key}/{year} {starts_on?, ends_on?, disabled?, note?} */
    public function update(Request $request, string $country, string $key, int $year): JsonResponse
    {
        $country = $this->target($country, $key, $year);
        $data = $request->validate([
            'starts_on' => ['nullable', 'date_format:Y-m-d', Rule::requiredIf(fn () => ! $request->boolean('disabled'))],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'disabled' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:160'],
        ]);
        $starts = isset($data['starts_on']) ? CarbonImmutable::parse($data['starts_on']) : null;
        if ($starts !== null && $starts->year !== $year) {
            throw ValidationException::withMessages(['starts_on' => __('La fecha debe ser del año :year.', ['year' => $year])]);
        }
        $exists = DB::table('commercial_calendar_overrides')->where(['event_key' => $key, 'country' => $country, 'year' => $year])->exists();
        DB::table('commercial_calendar_overrides')->updateOrInsert(['event_key' => $key, 'country' => $country, 'year' => $year], [
            'starts_on' => $starts?->toDateString(),
            'ends_on' => $data['ends_on'] ?? null,
            'disabled' => (bool) ($data['disabled'] ?? false),
            'note' => isset($data['note']) && trim((string) $data['note']) !== '' ? trim((string) $data['note']) : null,
            'created_by' => $request->user()?->getAuthIdentifier(),
            'updated_at' => now(),
        ] + ($exists ? [] : ['created_at' => now()]));
        $this->audit->handle('platform.calendar_override_set', null, ['event' => $key, 'country' => $country, 'year' => $year, 'starts_on' => $starts?->toDateString(), 'disabled' => (bool) ($data['disabled'] ?? false)]);

        return response()->json($this->payload($country, $year));
    }

    /** DELETE /api/platform/calendar/{country}/{key}/{year}: vuelve a la regla. */
    public function destroy(string $country, string $key, int $year): JsonResponse
    {
        $country = $this->target($country, $key, $year);
        if (DB::table('commercial_calendar_overrides')->where(['event_key' => $key, 'country' => $country, 'year' => $year])->delete() > 0) {
            $this->audit->handle('platform.calendar_override_removed', null, ['event' => $key, 'country' => $country, 'year' => $year]);
        }

        return response()->json($this->payload($country, $year));
    }

    private function target(string $country, string $key, int $year): string
    {
        $country = strtoupper($country);
        if (! in_array($key, CommercialCalendar::keys(), true) || ! in_array($country, CommercialCalendar::countriesOf($key), true)) {
            abort(404);
        }
        if ($year < 2020 || $year > 2100) {
            abort(404);
        }

        return $country;
    }

    /** @return array<string, mixed> */
    private function payload(string $country, int $year): array
    {
        $overrides = CommercialCalendar::overrides($country, $year);
        $active = collect(CommercialCalendar::forYear($year, $country))->keyBy('key');
        $events = [];
        foreach (CommercialCalendar::keys() as $key) {
            if (! in_array($country, CommercialCalendar::countriesOf($key), true)) {
                continue;
            }
            $event = $active->get($key);
            $override = $overrides[$key] ?? null;
            $events[] = [
                'key' => $key,
                'name' => CommercialCalendar::name($key),
                'rule_date' => CommercialCalendar::ruleDate($key, $year)?->toDateString(),
                'date' => $event['date'] ?? null,
                'ends' => $event['ends'] ?? null,
                'audiences' => $event['audiences'] ?? [],
                'override' => $override === null ? null : [
                    'starts_on' => $override['starts_on']?->toDateString(),
                    'ends_on' => $override['ends_on']?->toDateString(),
                    'disabled' => $override['disabled'],
                    'note' => $override['note'],
                ],
            ];
        }
        usort($events, fn (array $a, array $b) => strcmp((string) ($a['date'] ?? $a['rule_date']), (string) ($b['date'] ?? $b['rule_date'])));

        return ['country' => $country, 'year' => $year, 'countries' => CommercialCalendar::COUNTRIES, 'events' => $events];
    }
}
