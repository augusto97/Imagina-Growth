<?php

use App\Domain\Templates\Rendering\SampleData;
use App\Domain\Workspaces\Models\Workspace;

/*
 * Los datos de ejemplo de las plantillas (vista previa y selector de variables) siguen el país y la moneda
 * del espacio de trabajo: ciudad, dirección, medio de pago, WhatsApp, dominio y montos.
 */

function sampleWorkspace($test, string $country, string $currency, string $locale): Workspace
{
    $test->owner ??= $test->userWith2fa();
    $ws = $test->workspaceFor($test->owner, 'Tienda '.$country, $currency);
    $ws->forceFill(['country' => $country, 'locale' => $locale])->save();

    return $ws;
}

it('Colombia conserva sus ejemplos y un país sin ejemplos propios usa los de Colombia', function () {
    $co = SampleData::vars();
    expect($co['contacto']['ciudad'])->toBe('Manizales')
        ->and($co['pedido']['metodo_pago'])->toBe('PSE')
        ->and($co['carrito']['subtotal'])->toBe('320000')
        ->and($co['producto']['precio'])->toBe('129000')
        ->and($co['pedido']['url'])->toBe('https://tienda.co/mi-cuenta/pedidos/10620')
        ->and(SampleData::vars('', 'PE', 'PEN')['contacto'])->toMatchArray(['ciudad' => 'Manizales', 'pais' => 'CO'])
        ->and(SampleData::vars('https://mitienda.com/', 'CO')['carrito']['url_restaurar'])->toBe('https://mitienda.com/?igr_cart=ejemplo');
});

it('México y Venezuela tienen su ciudad, su medio de pago y montos en su moneda', function () {
    $mx = SampleData::forWorkspace(sampleWorkspace($this, 'MX', 'MXN', 'es-MX'));
    expect($mx['contacto'])->toMatchArray(['ciudad' => 'Puebla', 'pais' => 'MX'])
        ->and($mx['pedido']['metodo_pago'])->toBe('OXXO')
        ->and($mx['carrito']['subtotal'])->toBe('2020')
        ->and($mx['cita']['lugar'])->toContain('Puebla')
        ->and($mx['pedido']['url_pago'])->toStartWith('https://tienda.mx/');

    $ve = SampleData::forWorkspace(sampleWorkspace($this, 'VE', 'USD', 'es-VE'));
    expect($ve['contacto']['ciudad'])->toBe('Valencia')
        ->and($ve['pedido']['metodo_pago'])->toBe('Pago Móvil')
        ->and($ve['carrito']['subtotal'])->toBe('86')
        ->and($ve['aviso']['detalle'])->toContain('Valencia');
    // En bolívares no hay precios de ejemplo propios: van los del país (dólares).
    expect(SampleData::vars('', 'VE', 'VES')['carrito']['subtotal'])->toBe('86');
});

it('la vista previa y el selector de variables usan los ejemplos del espacio de trabajo', function () {
    $mx = sampleWorkspace($this, 'MX', 'MXN', 'es-MX');
    $url = "/api/w/{$mx->publicId()}/templates";

    $groups = collect($this->actingAs($this->owner)->getJson("{$url}/variables")->assertOk()->json('groups'))
        ->flatMap(fn (array $g) => $g['items'])->pluck('example', 'expr');
    expect($groups['{{ contacto.ciudad }}'])->toBe('Puebla')
        ->and($groups['{{ pedido.metodo_pago }}'])->toBe('OXXO')
        ->and($groups['{{ tienda.whatsapp_url }}'])->toBe('https://wa.me/525512345678')
        ->and($groups['{{ carrito.subtotal | moneda }}'])->toBe('$2,020.00')
        ->and($groups['{{ producto.url }}'])->toStartWith('https://tienda.mx/');

    $preview = $this->actingAs($this->owner)->postJson("{$url}/preview", [
        'channel' => 'sms', 'category' => 'transactional',
        'body_text' => 'Tu pedido de {{ contacto.ciudad }} por {{ pedido.total | moneda }} con {{ pedido.metodo_pago }}',
    ])->assertOk();
    expect($preview->json('text'))->toBe('Tu pedido de Puebla por $2,020.00 con OXXO');
});
