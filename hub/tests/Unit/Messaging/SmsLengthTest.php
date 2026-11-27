<?php

use App\Domain\Messaging\Support\SmsText;
use App\Domain\Templates\Support\SmsEstimate;

/*
 * El largo de un SMS con los casos compartidos con la SPA (tests/fixtures/sms/cases.json; la misma tabla la lee
 * resources/js/features/templates/sms.test.ts): si una de las dos cuentas cambia, falla aquí o allá.
 */

/** @return array<string, mixed> */
function smsCases(): array
{
    return json_decode((string) file_get_contents(base_path('tests/fixtures/sms/cases.json')), true, flags: JSON_THROW_ON_ERROR);
}

/** @param list<array{0: string, 1: int}> $chunks */
function smsText(array $chunks): string
{
    return implode('', array_map(fn (array $c) => str_repeat($c[0], $c[1]), $chunks));
}

it('mide cada caso compartido como la SPA (GSM-7 con extensión, UCS-2 con pares)', function () {
    foreach (smsCases()['measure'] as $case) {
        $m = SmsText::measure(smsText($case['text']));
        expect(['encoding' => $m['encoding'], 'length' => $m['length'], 'segments' => $m['segments'], 'offenders' => $m['offenders']])
            ->toBe(['encoding' => $case['encoding'], 'length' => $case['length'], 'segments' => $case['segments'], 'offenders' => $case['offenders']], $case['name']);
    }
});

it('cambia por letras sin tilde solo lo que tiene equivalente y deja la ñ, la é y la ü', function () {
    foreach (smsCases()['transliterate'] as $case) {
        expect(SmsText::transliterate($case['in']))->toBe($case['out']);
    }
    expect(SmsText::measure(SmsText::transliterate('Canción rápida, “ya” — sí'))['encoding'])->toBe('GSM-7');
});

it('cuenta el peor caso de una plantilla con sus variables igual que la SPA', function () {
    $estimate = smsCases()['estimate'];
    foreach ($estimate['cases'] as $case) {
        $info = SmsEstimate::worstCase($case['body'], $estimate['limits']);
        expect([$info['encoding'], $info['length'], $info['segments']])->toBe([$case['encoding'], $case['length'], $case['segments']], $case['name']);
    }
});

it('al enviar: tal cual si cabe, sin tildes si así cabe, y nada si tampoco (ni cortado ni partido)', function () {
    $fits = str_repeat('a', 160);
    expect(SmsText::forSending($fits, false))->toBe(['text' => $fits, 'segments' => 1, 'transliterated' => false]);

    // 100 caracteres con «á»: en UCS-2 serían 2 partes; sin tildes caben en uno.
    $accented = str_repeat('á', 100);
    expect(SmsText::forSending($accented, false))->toBe(['text' => str_repeat('a', 100), 'segments' => 1, 'transliterated' => true]);

    expect(SmsText::forSending(str_repeat('a', 161), false))->toBeNull()
        ->and(SmsText::forSending(str_repeat('a', 161), true))->toBe(['text' => str_repeat('a', 161), 'segments' => 2, 'transliterated' => false])
        ->and(SmsText::forSending(str_repeat('a', 307), true))->toBeNull()
        // Un emoji no tiene equivalente: con más de 70 no cabe.
        ->and(SmsText::forSending(str_repeat('a', 70).'😀', false))->toBeNull()
        ->and(SmsText::maxParts(false))->toBe(1)->and(SmsText::maxParts(true))->toBe(2);
});
