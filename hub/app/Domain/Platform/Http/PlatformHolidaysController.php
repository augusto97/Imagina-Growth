<?php

declare(strict_types=1);

namespace App\Domain\Platform\Http;

use App\Domain\Platform\Settings\PlatformSettings;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Support\Holidays\Holidays;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Plataforma → Ajustes → Festivos (F17; cumplimiento.md §4.2): el calendario de cada país con los festivos de la ley y
 * las excepciones que agrega el superadmin (un día cívico decretado, un festivo que se mueve o que ese año no aplica).
 * Lo mismo que `igr:holidays`, sin consola. SendPolicy no envía marketing en festivo; la agenda se cierra sola. El
 * cambio llega a todos los procesos sin reiniciar (PlatformSettings::touch).
 */
final class PlatformHolidaysController
{
    public function __construct(private readonly PlatformSettings $settings, private readonly RecordAuditLog $audit) {}

    /** GET /api/platform/holidays?country=CO&year=2026 */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'country' => ['nullable', Rule::in(Holidays::COUNTRIES)],
            'year' => ['nullable', 'integer', 'between:2020,2100'],
        ]);

        return response()->json($this->payload((string) ($data['country'] ?? 'CO'), (int) ($data['year'] ?? now()->year)));
    }

    /** PUT /api/platform/holidays/{country}/{date} {is_holiday, name}: agrega un festivo o quita uno de la ley. */
    public function update(Request $request, string $country, string $date): JsonResponse
    {
        [$country, $day] = $this->target($country, $date);
        $data = $request->validate([
            'is_holiday' => ['required', 'boolean'],
            'name' => ['nullable', 'string', 'max:120', Rule::requiredIf(fn () => $request->boolean('is_holiday') && ! isset(Holidays::statutory($country, $day->year)[$day->toDateString()]))],
        ]);
        $exists = DB::table('holiday_overrides')->where('country', $country)->where('date', $day->toDateString())->exists();
        DB::table('holiday_overrides')->updateOrInsert(['country' => $country, 'date' => $day->toDateString()], [
            'is_holiday' => (bool) $data['is_holiday'],
            'name' => isset($data['name']) && trim((string) $data['name']) !== '' ? trim((string) $data['name']) : null,
            'created_by' => $request->user()?->getAuthIdentifier(),
            'updated_at' => now(),
        ] + ($exists ? [] : ['created_at' => now()]));
        $this->changed('platform.holiday_set', $country, $day, ['is_holiday' => (bool) $data['is_holiday']]);

        return response()->json($this->payload($country, $day->year));
    }

    /** DELETE /api/platform/holidays/{country}/{date}: quita la excepción (vuelve a lo que dice la ley). */
    public function destroy(string $country, string $date): JsonResponse
    {
        [$country, $day] = $this->target($country, $date);
        if (DB::table('holiday_overrides')->where('country', $country)->where('date', $day->toDateString())->delete() > 0) {
            $this->changed('platform.holiday_removed', $country, $day);
        }

        return response()->json($this->payload($country, $day->year));
    }

    /** @param array<string, mixed> $extra */
    private function changed(string $action, string $country, CarbonImmutable $day, array $extra = []): void
    {
        Holidays::flush();
        $this->settings->touch();
        $this->audit->handle($action, null, ['country' => $country, 'date' => $day->toDateString()] + $extra);
    }

    /** @return array{0: string, 1: CarbonImmutable} */
    private function target(string $country, string $date): array
    {
        $country = strtoupper($country);
        validator(['country' => $country, 'date' => $date], [
            'country' => ['required', Rule::in(Holidays::COUNTRIES)],
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2020-01-01', 'before_or_equal:2100-12-31'],
        ])->validate();

        return [$country, CarbonImmutable::parse($date)->startOfDay()];
    }

    /** @return array<string, mixed> */
    private function payload(string $country, int $year): array
    {
        $statutory = Holidays::statutory($country, $year);
        $overrides = [];
        foreach (DB::table('holiday_overrides')->where('country', $country)->whereYear('date', $year)->orderBy('date')->get(['date', 'is_holiday', 'name']) as $row) {
            $overrides[substr((string) $row->date, 0, 10)] = ['is_holiday' => (bool) $row->is_holiday, 'name' => $row->name === null ? null : (string) $row->name];
        }
        $days = [];
        foreach (Holidays::forYear($country, $year) as $date => $name) {
            $days[$date] = [
                'date' => $date,
                'name' => $name,
                'origin' => isset($overrides[$date]) ? 'added' : (isset($statutory[$date]) ? 'law' : 'code'),
                'law_name' => $statutory[$date] ?? null,
            ];
        }
        foreach ($overrides as $date => $override) {
            if (! $override['is_holiday']) {
                $days[$date] = ['date' => $date, 'name' => $statutory[$date] ?? __('Festivo'), 'origin' => 'removed', 'law_name' => $statutory[$date] ?? null];
            }
        }
        ksort($days);

        return [
            'country' => $country,
            'year' => $year,
            'countries' => Holidays::COUNTRIES,
            'holidays' => array_values($days),
        ];
    }
}
