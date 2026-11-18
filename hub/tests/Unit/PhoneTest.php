<?php

use App\Support\Phone\Phone;

it('normaliza teléfonos de Colombia, México y Venezuela a E.164', function (string $raw, string $country, ?string $expected) {
    expect(Phone::toE164($raw, $country))->toBe($expected);
})->with([
    ['300 123 4567', 'CO', '+573001234567'],
    ['+57 300-123-4567', 'MX', '+573001234567'],
    ['55 1234 5678', 'MX', '+525512345678'],
    ['0412-1234567', 'VE', '+584121234567'],
    ['123', 'CO', null],
    ['', 'CO', null],
]);
