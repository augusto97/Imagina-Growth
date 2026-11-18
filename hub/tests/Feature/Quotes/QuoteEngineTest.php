<?php

use App\Domain\Quotes\Models\QuoteCalculator;
use App\Domain\Quotes\Support\QuoteEngine;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/QuoteFixtures.php';

/*
 * La cuenta del cotizador (M18): precio por unidad, opciones con precio y
 * multiplicador, casillas, paquetes, mínimo, rango ± % y redondeo.
 */

function quoteProbe(string $kind, array $definition): QuoteCalculator
{
    $c = new QuoteCalculator(['name' => 'Prueba', 'kind' => $kind]);
    $c->definition = QuoteEngine::normalize($definition, $kind);

    return $c;
}

it('suma, multiplica, aplica el rango y redondea', function () {
    $c = quoteProbe('area', quoteAreaDefinition());
    // (50.000 + 40 × 12.000 + 80.000) × 1,25 = 762.500 → ± 10 %, a miles.
    $r = QuoteEngine::estimate($c, ['area' => '40', 'tipo' => 'esmalte', 'extras' => ['techo']]);
    expect($r['mid'])->toBe('763000.00')->and($r['min'])->toBe('686000.00')->and($r['max'])->toBe('839000.00')
        ->and($r['answers'])->toBe(['area' => 40.0, 'tipo' => 'esmalte', 'extras' => ['techo']])
        ->and(collect($r['lines'])->pluck('label')->all())->toBe(['Área a pintar (40 m²)', 'Extras: Techo']);
});

it('nunca baja del mínimo', function () {
    $r = QuoteEngine::estimate(quoteProbe('area', quoteAreaDefinition()), ['area' => 5, 'tipo' => 'vinilo']);
    // 50.000 + 60.000 = 110.000 < 300.000
    expect($r['mid'])->toBe('300000.00')->and($r['min'])->toBe('270000.00')->and($r['max'])->toBe('330000.00');
});

it('cotiza por paquetes con adicionales', function () {
    $c = quoteProbe('package', ['range_pct' => 0, 'packages' => [
        ['key' => 'basico', 'label' => 'Básico', 'price' => 900000], ['key' => 'pro', 'label' => 'Pro', 'price' => 1500000],
    ], 'fields' => [['key' => 'urgente', 'label' => 'Urgente', 'type' => 'checkbox', 'options' => [['value' => 'si', 'label' => 'En 48 horas', 'price' => 0, 'multiplier' => 1.2]]]]]);
    expect(QuoteEngine::estimate($c, ['package' => 'pro', 'urgente' => ['si']])['mid'])->toBe('1800000.00')
        ->and(QuoteEngine::estimate($c, ['package' => 'basico'])['max'])->toBe('900000.00');
    expect(fn () => QuoteEngine::estimate($c, ['package' => 'oro']))->toThrow(ValidationException::class);
});

it('rechaza respuestas fuera de rango, opciones inventadas y faltantes', function (array $answers, string $field) {
    try {
        QuoteEngine::estimate(quoteProbe('area', quoteAreaDefinition()), $answers);
        $this->fail('pasó');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('answers.'.$field);
    }
})->with([
    'área muy pequeña' => [['area' => 2, 'tipo' => 'vinilo'], 'area'],
    'área de texto' => [['area' => 'mucho', 'tipo' => 'vinilo'], 'area'],
    'opción inventada' => [['area' => 20, 'tipo' => 'oro'], 'tipo'],
    'casilla inventada' => [['area' => 20, 'tipo' => 'vinilo', 'extras' => ['jacuzzi']], 'extras'],
    'falta el tipo' => [['area' => 20], 'tipo'],
]);

it('valida la definición del constructor', function () {
    expect(fn () => QuoteEngine::normalize(['fields' => []], 'service'))->toThrow(ValidationException::class)
        ->and(fn () => QuoteEngine::normalize(['fields' => [['label' => 'Tipo', 'type' => 'select', 'options' => []]]], 'service'))->toThrow(ValidationException::class)
        ->and(fn () => QuoteEngine::normalize(['packages' => []], 'package'))->toThrow(ValidationException::class)
        ->and(fn () => QuoteEngine::normalize(['fields' => [['label' => 'Tipo', 'type' => 'select', 'options' => [['label' => 'A', 'price' => 1]]]]], 'area'))->toThrow(ValidationException::class)
        ->and(fn () => QuoteEngine::normalize(quoteAreaDefinition(['range_pct' => 120]), 'area'))->toThrow(ValidationException::class);
    $def = QuoteEngine::normalize(['fields' => [['label' => 'Número de habitaciones', 'type' => 'number', 'unit_price' => 1000], ['key' => 'numero de habitaciones', 'label' => 'Otra', 'type' => 'number']]], 'service');
    expect(array_column($def['fields'], 'key'))->toBe(['campo_1', 'numero_de_habitaciones']);
});
