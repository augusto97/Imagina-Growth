<?php

use App\Analytics\Ch;

it('informa la salud de MariaDB, ClickHouse, Redis y el stream de ingesta', function () {
    $this->useIngestInfrastructure();
    $this->getJson('/health')->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('checks.mariadb', true)
        ->assertJsonPath('checks.clickhouse', true)
        ->assertJsonPath('checks.redis', true)
        ->assertJsonStructure(['ingest' => [0 => ['length', 'pending', 'lag_seconds']]]);
});

it('responde 503 si ClickHouse no contesta', function () {
    config(['igr.clickhouse.url' => 'http://127.0.0.1:1']);
    app()->forgetInstance(Ch::class);
    $this->getJson('/health')->assertStatus(503)->assertJsonPath('checks.clickhouse', false);
});

it('en público (por el proxy) solo dice ok o degraded, sin el detalle de dependencias (S16)', function () {
    $this->useIngestInfrastructure();
    $public = ['X-Forwarded-For' => '190.85.12.34'];
    $res = $this->getJson('/health', $public)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    expect($res->json())->toBe(['status' => 'ok']);
    $this->getJson('/health/ready', $public)->assertOk()->assertExactJson(['status' => 'ok']);

    // Desde otra IP sin proxy, tampoco.
    $res = $this->call('GET', '/health', [], [], [], ['REMOTE_ADDR' => '203.0.114.9', 'HTTP_ACCEPT' => 'application/json']);
    expect($res->json())->toBe(['status' => 'ok']);

    // Caído, el público ve 503 sin saber qué falla.
    config(['igr.clickhouse.url' => 'http://127.0.0.1:1']);
    app()->forgetInstance(Ch::class);
    $this->getJson('/health', $public)->assertStatus(503)->assertExactJson(['status' => 'degraded']);
    $this->getJson('/health/ready', $public)->assertStatus(503)->assertExactJson(['status' => 'degraded']);
});

it('el detalle sale desde la máquina (127.0.0.1 sin proxy) o con el token, y no con un token falso (S16)', function () {
    $this->useIngestInfrastructure();
    // growth-update llama a http://127.0.0.1:<puerto>/health/ready y lee la versión.
    $this->getJson('/health/ready')->assertOk()->assertJsonStructure(['status', 'version', 'checks']);
    $this->call('GET', '/health', [], [], [], ['REMOTE_ADDR' => '::1', 'HTTP_ACCEPT' => 'application/json'])->assertJsonStructure(['checks', 'ingest']);
    // Un X-Forwarded-For «127.0.0.1» que llega por el proxy no vuelve local la petición.
    expect($this->getJson('/health', ['X-Forwarded-For' => '127.0.0.1'])->json())->toBe(['status' => 'ok']);

    config(['igr.health.token' => 'token-de-monitoreo-123']);
    $public = ['X-Forwarded-For' => '190.85.12.34'];
    $this->getJson('/health', $public + ['X-IGR-Health-Token' => 'token-de-monitoreo-123'])->assertOk()->assertJsonStructure(['checks' => ['mariadb', 'clickhouse', 'redis'], 'ingest']);
    $this->getJson('/health/ready', $public + ['Authorization' => 'Bearer token-de-monitoreo-123'])->assertOk()->assertJsonStructure(['version', 'checks']);
    expect($this->getJson('/health', $public + ['X-IGR-Health-Token' => 'otro'])->json())->toBe(['status' => 'ok'])
        ->and($this->getJson('/health/ready', $public + ['Authorization' => 'Bearer '])->json())->toBe(['status' => 'ok']);

    // Sin token configurado, cualquier token se ignora.
    config(['igr.health.token' => null]);
    expect($this->getJson('/health', $public + ['X-IGR-Health-Token' => ''])->json())->toBe(['status' => 'ok']);
});
