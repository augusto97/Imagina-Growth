<?php

use App\Domain\Campaigns\Queries\CampaignReport;
use App\Domain\Campaigns\Support\CommercialCalendar;
use App\Domain\Campaigns\Support\WaRateCard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

it('el incremento usa una diferencia de proporciones con IC del 95%', function () {
    $r = CampaignReport::compare(1000, 60, 3_000_000, 1000, 40, 2_000_000);
    expect($r['lift'])->toBe(0.02)
        ->and($r['lift_relative'])->toBe(0.5)
        ->and($r['ci_low'])->toBeGreaterThan(0.0)->toBeLessThan(0.02)
        ->and($r['ci_high'])->toBeGreaterThan(0.02)->toBeLessThan(0.04)
        ->and($r['significant'])->toBeTrue()
        ->and($r['incremental_revenue'])->toBe(1_000_000.0);
    $flat = CampaignReport::compare(200, 10, 0, 200, 9, 0);
    expect($flat['significant'])->toBeFalse()->and($flat['ci_low'])->toBeLessThan(0);
});

it('tarifas de WhatsApp: por mercado de Meta, con «otros» de respaldo, y aviso cuando pasa un trimestre', function () {
    // Las migraciones las cargan (2026_11_26_200200: la tabla de Meta vigente desde el 1 oct 2026).
    expect(WaRateCard::rate('CO'))->toBe(0.0125)->and(WaRateCard::rate('MX'))->toBe(0.0397);
    DB::table('wa_rates')->delete();
    expect(WaRateCard::rate('CO'))->toBeNull();   // tabla vacía
    WaRateCard::seed();
    $oct = CarbonImmutable::parse('2026-11-20');
    expect(WaRateCard::rate('CO', 'marketing', $oct))->toBe(0.0125)
        ->and(WaRateCard::rate('mx', 'utility', $oct))->toBe(0.0085)
        // Venezuela, Ecuador y Panamá: «Resto de América Latina».
        ->and(WaRateCard::rate('VE', 'marketing', $oct))->toBe(0.074)
        ->and(WaRateCard::rate('EC', 'utility', $oct))->toBe(0.0113)
        ->and(WaRateCard::market('PA'))->toBe('LA')
        // Mercados propios y Norteamérica; lo demás, «otros».
        ->and(WaRateCard::rate('AR', 'marketing', $oct))->toBe(0.0618)
        ->and(WaRateCard::rate('US', 'marketing', $oct))->toBe(0.025)
        ->and(WaRateCard::rate('ES', 'marketing', $oct))->toBe(0.0604)
        // Desde el 1 oct 2026 el servicio se cobra (antes, gratis) y México subió el marketing.
        ->and(WaRateCard::rate('CO', 'service', $oct))->toBe(0.0008)
        ->and(WaRateCard::rate('CO', 'service', CarbonImmutable::parse('2026-09-30')))->toBe(0.0)
        ->and(WaRateCard::rate('MX', 'marketing', CarbonImmutable::parse('2026-09-30')))->toBe(0.0305)
        ->and(WaRateCard::rate('VE', 'marketing', CarbonImmutable::parse('2026-09-30')))->toBe(0.074)
        ->and(WaRateCard::rate('CO', 'marketing', CarbonImmutable::parse('2025-01-01')))->toBeNull();   // antes de la vigencia
    expect(WaRateCard::isStale(CarbonImmutable::parse('2026-11-20')))->toBeFalse()
        ->and(WaRateCard::isStale(CarbonImmutable::parse('2027-02-01')))->toBeTrue();
    $this->artisan('igr:wa-rates', ['--check' => true])->assertSuccessful();
    DB::table('wa_rates')->where('effective_from', WaRateCard::EFFECTIVE_FROM)->delete();
    $this->artisan('igr:wa-rates', ['--check' => true])->assertFailed();   // solo la de 2025: desactualizada
    WaRateCard::seed(now()->toDateString());
    $this->artisan('igr:wa-rates', ['--check' => true])->assertSuccessful();
});

it('el calendario resuelve las fechas móviles de cada año', function () {
    $co = collect(CommercialCalendar::forYear(2027, 'CO'))->keyBy('key');
    expect($co['dia_madre']['date'])->toBe('2027-05-09')
        ->and($co['dia_padre']['date'])->toBe('2027-06-20')
        ->and($co['amor_amistad']['date'])->toBe('2027-09-18')
        ->and($co['black_friday']['date'])->toBe('2027-11-26')
        ->and($co['black_friday']['starts_communicating'])->toBe('2027-11-16');
    $mx = collect(CommercialCalendar::forYear(2027, 'MX'))->keyBy('key');
    expect($mx['buen_fin']['date'])->toBe('2027-11-12')
        ->and($mx['regreso_clases_mx']['date'])->toBe('2027-08-30');
    expect(collect(CommercialCalendar::forYear(2027, 'VE'))->pluck('key')->all())->toContain('regreso_clases_ve', 'navidad');
});
