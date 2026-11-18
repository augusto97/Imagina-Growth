<?php

use App\Domain\Measurement\Enums\Platform;
use App\Domain\Measurement\Support\UserDataHasher as H;

it('normaliza según eventos.md §7 y lo que pide cada plataforma', function () {
    expect(H::email('  Ana.Ruiz@Ejemplo.COM '))->toBe('ana.ruiz@ejemplo.com')
        ->and(H::email('no-es-correo'))->toBeNull()
        ->and(H::phone('300 123 4567', 'CO', Platform::Meta))->toBe('573001234567')
        ->and(H::phone('300 123 4567', 'CO', Platform::Google))->toBe('+573001234567')
        ->and(H::phone('+52 55 1234 5678', 'CO', Platform::TikTok))->toBe('+525512345678')
        ->and(H::name(' María José ', Platform::Meta))->toBe('maríajosé')
        ->and(H::name(' María José ', Platform::Google))->toBe('mariajose')
        ->and(H::city('Santa Marta'))->toBe('santamarta')
        ->and(H::city('Bogotá D.C.'))->toBe('bogotadc')
        ->and(H::state('CO-CAL'))->toBe('cal')
        ->and(H::zip(' 170 001 '))->toBe('170001')
        ->and(H::country('CO'))->toBe('co')
        ->and(H::country('Colombia'))->toBeNull();
});

it('aplica SHA-256 en hexadecimal sobre el valor normalizado (vectores fijos)', function () {
    expect(H::hash(H::email(' Ana@Ejemplo.com ')))->toBe('52f5655387a4bdcb850a93ce7223979b4a360fa146c89c47d5d897d10a13f76e')
        ->and(H::hash(H::phone('300 123 4567', 'CO', Platform::Meta)))->toBe('173afe355201e7ed8e349c576f30ebd4be4314b65e1d87196ae5f75b21518172')
        ->and(H::hash(null))->toBeNull()
        ->and(H::hash(''))->toBeNull();
});
