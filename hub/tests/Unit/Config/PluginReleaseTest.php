<?php

use App\Domain\Sites\Models\Site;
use App\Domain\Sites\Support\PluginRelease;

/*
 * El hub empaqueta el zip del plugin de su mismo commit (infra/release/build.sh → public/downloads) y le dice a cada
 * sitio, en /v1/s/config, cuál es la última versión. Si alguien sube la versión del plugin y no la de config/igr.php
 * (o al revés), los sitios verían un aviso equivocado.
 */
it('la última versión del plugin que anuncia el hub es la de la cabecera del connector', function () {
    $file = dirname(__DIR__, 4).'/connector/imagina-growth-connector.php';
    if (! is_file($file)) {
        $this->markTestSkipped('Sin el connector en el repositorio');
    }
    $source = (string) file_get_contents($file);
    preg_match('/^ \* Version:\s+([0-9.]+)\s*$/m', $source, $header);
    preg_match("/define\\( 'IGR_VERSION', '([0-9.]+)' \\);/", $source, $const);
    $config = require dirname(__DIR__, 3).'/config/igr.php';

    expect($header[1] ?? null)->toBe($const[1] ?? null)
        ->and($config['plugin']['latest_version'])->toBe($header[1] ?? null)
        ->and(version_compare($config['min_plugin_version'], $config['plugin']['latest_version'], '<='))->toBeTrue();
});

it('marca al sitio desactualizado o con versión nueva disponible', function (?string $current, string $status) {
    config(['igr.min_plugin_version' => '0.1.2', 'igr.plugin.latest_version' => '0.1.5', 'igr.urls.app' => 'https://growth.imagina.cloud/']);
    $site = new Site(['plugin_version' => $current]);

    expect(PluginRelease::forSite($site))->toBe([
        'min_version' => '0.1.2',
        'latest_version' => '0.1.5',
        'download_url' => 'https://growth.imagina.cloud/downloads/imagina-growth-connector.zip',
        'status' => $status,
    ]);
})->with([
    ['0.1.1', 'outdated'],
    ['0.1.2', 'update_available'],
    ['0.1.5', 'current'],
    ['0.2.0', 'current'],
    [null, 'current'],
]);
