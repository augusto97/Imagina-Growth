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

it('precarga el subconjunto latino de cada fuente variable del build y nada sin build', function () {
    $manifest = (string) tempnam(sys_get_temp_dir(), 'igr-manifest');
    file_put_contents($manifest, (string) json_encode([
        'resources/js/main.tsx' => ['file' => 'assets/main-abc.js', 'isEntry' => true],
        'node_modules/@fontsource-variable/inter/files/inter-latin-wght-normal.woff2' => ['file' => 'assets/inter-latin-wght-normal-X1.woff2'],
        'node_modules/@fontsource-variable/inter/files/inter-latin-ext-wght-normal.woff2' => ['file' => 'assets/inter-latin-ext-wght-normal-X2.woff2'],
        'node_modules/@fontsource-variable/inter/files/inter-cyrillic-wght-normal.woff2' => ['file' => 'assets/inter-cyrillic-wght-normal-X3.woff2'],
    ]));

    expect(SpaRuntime::fontPreloads($manifest))->toBe([asset('build/assets/inter-latin-wght-normal-X1.woff2')])
        ->and(SpaRuntime::fontPreloads($manifest.'-no-existe'))->toBe([]);
    unlink($manifest);
});

it('con sesión el HTML pide /api/me en paralelo con el código; sin sesión, no', function () {
    $this->withoutVite();
    expect($this->get('/login')->assertOk()->getContent())->not->toContain('href="/api/me"');

    $html = $this->actingAs($this->userWith2fa())->get('/onboarding')->assertOk()->getContent();
    expect($html)->toContain('<link rel="preload" as="fetch" href="/api/me" crossorigin>');
});
