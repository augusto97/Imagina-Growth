<?php

declare(strict_types=1);

namespace App\Domain\Campaigns\Support;

use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Consent\Policy\LegalWindow;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;

/**
 * Hora de envío por contacto (M10, paso 4), siempre dentro de la ventana legal
 * del país del contacto (LegalWindow):
 *
 * - `now`: ya.
 * - `scheduled`: `send_at` para todos.
 * - `contact_timezone`: la misma hora de reloj de `send_at` (en la zona del
 *   espacio de trabajo) pero en la zona de cada contacto.
 * - `best_time`: el día de `send_at` a la hora preferida del contacto
 *   (`contacts.preferred_hour`); sin hora preferida, la de `send_at`.
 */
final class SendSchedule
{
    /** Margen para materializar antes de `send_at` en los modos por zona horaria (la diferencia máxima entre zonas es de 26 h). */
    public const TIMEZONE_LEAD_HOURS = 26;

    public static function materializeAt(Campaign $campaign): CarbonImmutable
    {
        $sendAt = $campaign->send_at !== null ? CarbonImmutable::instance($campaign->send_at) : CarbonImmutable::now();
        if ($campaign->send_mode === 'now') {
            return CarbonImmutable::now();
        }

        return in_array($campaign->send_mode, ['contact_timezone', 'best_time'], true) ? $sendAt->subHours(self::TIMEZONE_LEAD_HOURS) : $sendAt;
    }

    /** Hora planeada para el contacto, sin ventana legal (puede estar en el pasado). */
    public static function planned(Campaign $campaign, Workspace $workspace, string $country, ?string $timezone, ?int $preferredHour, CarbonImmutable $now): CarbonImmutable
    {
        if ($campaign->send_mode === 'now' || $campaign->send_at === null) {
            return $now;
        }
        $sendAt = CarbonImmutable::instance($campaign->send_at)->utc();
        if ($campaign->send_mode === 'scheduled') {
            return $sendAt;
        }
        $wall = $sendAt->setTimezone($workspace->timezone ?: 'America/Bogota');
        $tz = LegalWindow::timezoneFor($country, $timezone);
        $hour = $campaign->send_mode === 'best_time' && $preferredHour !== null && $preferredHour >= 0 && $preferredHour <= 23 ? $preferredHour : (int) $wall->format('G');
        $minute = $campaign->send_mode === 'best_time' && $preferredHour !== null ? 0 : (int) $wall->format('i');

        return CarbonImmutable::create((int) $wall->format('Y'), (int) $wall->format('n'), (int) $wall->format('j'), $hour, $minute, 0, $tz)->utc();
    }

    /** Hora de envío real: la planeada (o ya, si pasó) movida al siguiente momento permitido. */
    public static function forContact(Campaign $campaign, Workspace $workspace, ?string $country, ?string $timezone, ?int $preferredHour, ?CarbonImmutable $now = null): CarbonImmutable
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $country = strtoupper($country ?: ($workspace->country ?: 'CO'));
        $tz = $timezone !== null && $timezone !== '' ? $timezone : null;
        $planned = self::planned($campaign, $workspace, $country, $tz, $preferredHour, $now);

        return LegalWindow::nextOpen($country, $planned->lessThan($now) ? $now : $planned, $tz);
    }
}
