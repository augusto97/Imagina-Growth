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
