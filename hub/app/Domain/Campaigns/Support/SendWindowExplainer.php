<?php

declare(strict_types=1);

namespace App\Domain\Campaigns\Support;

use App\Domain\Consent\Policy\LegalWindow;
use App\Domain\Consent\Policy\PolicyDecision;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Holidays\Holidays;
use Carbon\CarbonImmutable;

/**
 * La ventana legal explicada para el paso «Cuándo» del asistente de campañas (cumplimiento.md §4 a §7): a qué hora
 * saldría la campaña para un contacto del país del negocio y, si la ley la mueve, por qué («el domingo no se envía»,
 * «el lunes es festivo», «pasa de las 19:00»), con los días que se salta. Con «a la hora de cada contacto» o «mejor
 * hora» cada contacto tiene su hora: lo dice `per_contact` y la revisión da la franja real.
 */
final class SendWindowExplainer
{
    /**
     * @return array{country: string, timezone: string, requested_at: string, sends_at: string, moved: bool, reason: string|null,
     *     requested_window: array{0: string, 1: string}|null, skipped: list<array{date: string, reason: string, holiday: string|null}>,
     *     holiday: string|null, per_contact: bool, windows: array<int, array{0: string, 1: string}>}
     */
    public static function explain(Workspace $workspace, string $mode, ?CarbonImmutable $sendAt, ?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $country = strtoupper((string) ($workspace->country ?: 'CO'));
        $tz = LegalWindow::timezoneFor($country, $workspace->timezone);
        $requested = $mode === 'now' || $sendAt === null ? $now : $sendAt->utc();
        $past = $mode !== 'now' && $sendAt !== null && $requested->lessThan($now);
        $from = $past ? $now : $requested;
        $sends = LegalWindow::nextOpen($country, $from, $tz);
        $reason = $past ? 'past' : LegalWindow::closedReason($country, $from, $tz);
        $local = $from->setTimezone($tz);
        $windows = LegalWindow::windowsFor($country);

        // Los días completos que se salta entre el pedido y el envío (domingos y festivos), para explicarlos.
        $skipped = [];
        $day = $local->startOfDay()->addDay();
        $last = $sends->setTimezone($tz)->startOfDay();
        while ($day->lessThan($last) && count($skipped) < 10) {
            $holiday = Holidays::forYear($country, (int) $day->year)[$day->format('Y-m-d')] ?? null;
            $closed = ($windows[$day->dayOfWeekIso] ?? null) === null ? PolicyDecision::SUNDAY : PolicyDecision::OUTSIDE_HOURS;
            $skipped[] = ['date' => $day->format('Y-m-d'), 'reason' => $holiday !== null ? PolicyDecision::HOLIDAY : $closed, 'holiday' => $holiday];
            $day = $day->addDay();
        }

        return [
            'country' => $country,
            'timezone' => $tz,
            'requested_at' => $requested->toIso8601String(),
            'sends_at' => $sends->toIso8601String(),
            'moved' => ! $sends->equalTo($requested),
            'reason' => $sends->equalTo($from) && ! $past ? null : $reason,
            'requested_window' => $windows[$local->dayOfWeekIso] ?? null,
            'skipped' => $skipped,
            'holiday' => Holidays::forYear($country, (int) $local->year)[$local->format('Y-m-d')] ?? null,
            'per_contact' => in_array($mode, ['contact_timezone', 'best_time'], true),
            'windows' => $windows,
        ];
    }
}
