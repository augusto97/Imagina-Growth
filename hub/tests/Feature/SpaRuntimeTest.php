<?php

use App\Support\SpaRuntime;

/*
 * El paquete de versión trae la SPA compilada sin saber el servidor (docs/despliegue.md §3):
 * la conexión pública a Reverb y la versión llegan en el HTML.
 */

it('inyecta en el HTML la conexión pública a Reverb y la versión, sin el secreto', function () {
    $this->withoutVite();
    config([
        'app.version' => '0.4.0',
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'clave-publica',
        'broadcasting.connections.reverb.secret' => 'secreto-que-no-sale',
        'broadcasting.connections.reverb.public' => ['host' => 'growth.ejemplo.com', 'port' => 443, 'scheme' => 'https'],
    ]);
    $html = $this->get('/login')->assertOk()->getContent();

    expect($html)->toContain('window.igrRuntime = ')
        ->and($html)->toContain('"key":"clave-publica"')
        ->and($html)->toContain('"host":"growth.ejemplo.com"')
        ->and($html)->toContain('"version":"0.4.0"')
        ->and($html)->not->toContain('secreto-que-no-sale');
});

it('sin Reverb el navegador no intenta conectarse', function () {
    config(['broadcasting.default' => 'log', 'broadcasting.connections.reverb.key' => 'k']);
    expect(SpaRuntime::config()['reverb'])->toBeNull();
    config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => '']);
    expect(SpaRuntime::config()['reverb'])->toBeNull();
});

it('el host público por defecto es el del panel', function () {
    $config = require base_path('config/broadcasting.php');
    expect($config['connections']['reverb']['public']['port'])->toBeInt()
        ->and(in_array($config['connections']['reverb']['public']['scheme'], ['http', 'https'], true))->toBeTrue();
    config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'k',
        'broadcasting.connections.reverb.public' => ['host' => 'panel.test', 'port' => 8443, 'scheme' => 'http']]);
    expect(SpaRuntime::config()['reverb'])->toBe(['key' => 'k', 'host' => 'panel.test', 'port' => 8443, 'scheme' => 'http']);
});
