<?php

use App\Domain\Consent\Policy\LegalWindow;
use App\Domain\Consent\Policy\PolicyDecision;
use Carbon\CarbonImmutable;

/*
 * Ventana legal por país del contacto (cumplimiento.md §6 y §7): México de lunes
 * a sábado de 9:00 a 20:00 sin festivos oficiales [validar]; Venezuela y los
 * demás países con el estándar de Colombia, en su propia hora local.
 */

function local(string $at, string $tz): CarbonImmutable
{
    return CarbonImmutable::parse($at, $tz);
}

function nextLocal(string $country, string $at, string $tz, ?string $contactTz = null): string
{
    return LegalWindow::nextOpen($country, local($at, $tz), $contactTz)->setTimezone($contactTz ?? $tz)->format('Y-m-d H:i');
}

it('México: lunes a sábado de 9:00 a 20:00 en la Ciudad de México, sin domingos ni festivos oficiales', function () {
    $tz = 'America/Mexico_City';
    expect(LegalWindow::isOpen('MX', local('2026-11-17 09:00:00', $tz)))->toBeTrue()
        ->and(LegalWindow::isOpen('MX', local('2026-11-21 19:59:00', $tz)))->toBeTrue()   // el sábado también
        ->and(nextLocal('MX', '2026-11-17 20:00:00', $tz))->toBe('2026-11-18 09:00')
        ->and(LegalWindow::closedReason('MX', local('2026-11-22 12:00:00', $tz)))->toBe(PolicyDecision::SUNDAY)
        ->and(nextLocal('MX', '2026-11-22 12:00:00', $tz))->toBe('2026-11-23 09:00');
    // 16 de septiembre de 2026 (miércoles): Independencia.
    expect(LegalWindow::closedReason('MX', local('2026-09-16 12:00:00', $tz)))->toBe(PolicyDecision::HOLIDAY)
        ->and(nextLocal('MX', '2026-09-16 12:00:00', $tz))->toBe('2026-09-17 09:00');
    // Tercer lunes de noviembre de 2026 (16): Revolución.
    expect(LegalWindow::closedReason('MX', local('2026-11-16 12:00:00', $tz)))->toBe(PolicyDecision::HOLIDAY);
});

it('Venezuela usa el estándar de Colombia en la hora de Caracas', function () {
    $tz = 'America/Caracas';
    expect(LegalWindow::isOpen('VE', local('2026-11-17 18:59:00', $tz)))->toBeTrue()
        ->and(nextLocal('VE', '2026-11-21 15:01:00', $tz))->toBe('2026-11-23 07:00')
        ->and(LegalWindow::closedReason('VE', local('2026-11-22 10:00:00', $tz)))->toBe(PolicyDecision::SUNDAY);
    // Las 18:30 de Caracas son las 17:30 de Bogota: abierto en ambos; las 19:30 de Caracas, no.
    expect(LegalWindow::isOpen('VE', local('2026-11-17 19:30:00', $tz)))->toBeFalse();
});

it('la zona horaria del contacto manda sobre la del país, y un país sin reglas usa las de Colombia', function () {
    // Contacto colombiano que vive en Madrid: el martes 17 a las 13:00 de Madrid está abierto; a las 20:00, no.
    expect(LegalWindow::isOpen('CO', local('2026-11-17 13:00:00', 'Europe/Madrid'), 'Europe/Madrid'))->toBeTrue()
        ->and(LegalWindow::isOpen('CO', local('2026-11-17 20:00:00', 'Europe/Madrid'), 'Europe/Madrid'))->toBeFalse()
        ->and(LegalWindow::timezoneFor('PE'))->toBe('America/Bogota')
        ->and(LegalWindow::windowsFor('PE'))->toBe(LegalWindow::WINDOWS['CO'])
        ->and(LegalWindow::timezoneFor('CO', 'No/Existe'))->toBe('America/Bogota');
});
