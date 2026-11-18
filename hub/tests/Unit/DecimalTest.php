<?php

use App\Support\Money\Decimal;

it('suma y compara montos sin errores de float', function () {
    expect(Decimal::add('0.10', '0.20'))->toBe('0.30')
        ->and(Decimal::of('159900'))->toBe('159900.00')
        ->and(Decimal::mul('150000.00', '2'))->toBe('300000.00')
        ->and(Decimal::div('100.00', 3))->toBe('33.33')
        ->and(Decimal::equals('1532000000.00', '1532000000'))->toBeTrue();
});
