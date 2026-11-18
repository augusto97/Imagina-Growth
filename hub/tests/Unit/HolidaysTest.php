<?php

use App\Support\Holidays\Holidays;
use Carbon\CarbonImmutable;

/*
 * Calendarios oficiales de festivos de Colombia 2026–2030 (cumplimiento.md
 * §4.2: fijos, Ley Emiliani y los ligados a la Pascua).
 */
dataset('festivos_colombia', [
    2026 => [2026, ['01-01', '01-12', '03-23', '04-02', '04-03', '05-01', '05-18', '06-08', '06-15', '06-29', '07-20', '08-07', '08-17', '10-12', '11-02', '11-16', '12-08', '12-25']],
    2027 => [2027, ['01-01', '01-11', '03-22', '03-25', '03-26', '05-01', '05-10', '05-31', '06-07', '07-05', '07-20', '08-07', '08-16', '10-18', '11-01', '11-15', '12-08', '12-25']],
    2028 => [2028, ['01-01', '01-10', '03-20', '04-13', '04-14', '05-01', '05-29', '06-19', '06-26', '07-03', '07-20', '08-07', '08-21', '10-16', '11-06', '11-13', '12-08', '12-25']],
    2029 => [2029, ['01-01', '01-08', '03-19', '03-29', '03-30', '05-01', '05-14', '06-04', '06-11', '07-02', '07-20', '08-07', '08-20', '10-15', '11-05', '11-12', '12-08', '12-25']],
    2030 => [2030, ['01-01', '01-07', '03-25', '04-18', '04-19', '05-01', '06-03', '06-24', '07-01', '07-01', '07-20', '08-07', '08-19', '10-14', '11-04', '11-11', '12-08', '12-25']],
]);

it('calcula los festivos de Colombia', function (int $year, array $expected) {
    $expected = array_values(array_unique(array_map(fn ($d) => "{$year}-{$d}", $expected)));
    sort($expected);
    expect(array_keys(Holidays::forYear('CO', $year)))->toBe($expected);
})->with('festivos_colombia');

it('calcula la Pascua', function () {
    expect(Holidays::easter(2026)->format('Y-m-d'))->toBe('2026-04-05')
        ->and(Holidays::easter(2027)->format('Y-m-d'))->toBe('2027-03-28')
        ->and(Holidays::easter(2030)->format('Y-m-d'))->toBe('2030-04-21');
});

it('reconoce festivos y días hábiles', function () {
    expect(Holidays::isHoliday('CO', CarbonImmutable::parse('2026-12-08')))->toBeTrue()
        ->and(Holidays::isHoliday('CO', CarbonImmutable::parse('2026-12-09')))->toBeFalse()
        ->and(Holidays::isHoliday('XX', CarbonImmutable::parse('2026-12-25')))->toBeFalse();
});

it('calcula los días de descanso obligatorio de México', function () {
    expect(array_keys(Holidays::forYear('MX', 2026)))->toBe(['2026-01-01', '2026-02-02', '2026-03-16', '2026-05-01', '2026-09-16', '2026-11-16', '2026-12-25'])
        ->and(Holidays::forYear('MX', 2030))->toHaveKey('2030-10-01');
});

it('aplica excepciones de configuración', function () {
    config(['holidays.overrides' => ['CO' => ['2026' => ['2026-12-31' => 'Decretado', '2026-01-12' => null]]]]);
    Holidays::flush();
    $list = Holidays::forYear('CO', 2026);
    expect($list)->toHaveKey('2026-12-31')->not->toHaveKey('2026-01-12');
    Holidays::flush();
});
