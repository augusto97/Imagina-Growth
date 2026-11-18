<?php

use App\Support\Pii\Pii;

it('enmascara correos, teléfonos y nombres', function () {
    expect(Pii::maskEmail('ana.ruiz@ejemplo.com'))->toBe('a*******@e******.com')
        ->and(Pii::maskPhone('+573001234567'))->toBe('+57*******567')
        ->and(Pii::maskString('Ana'))->toBe('A**');
});

it('enmascara claves personales en cualquier nivel de un arreglo', function () {
    $out = Pii::mask(['order' => ['billing' => ['email' => 'ana@ejemplo.com', 'city' => 'Manizales']], 'phone' => '3001234567']);
    expect($out['order']['billing']['email'])->not->toContain('ana@')
        ->and($out['order']['billing']['city'])->toBe('Manizales')
        ->and($out['phone'])->not->toBe('3001234567');
});

it('limpia correos y teléfonos de un texto libre sin borrar lo demás', function () {
    expect(Pii::scrub('Falló /order/billing con ana@ejemplo.com y 300 123 4567'))
        ->toBe('Falló /order/billing con a**@e******.com y 30*****567');
});
