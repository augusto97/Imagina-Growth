<?php

use App\Analytics\Ch;
use Illuminate\Support\Sleep;

it('espera a ClickHouse antes de migrar y falla con un mensaje claro si no vuelve', function () {
    Sleep::fake(syncWithCarbon: true);
    config(['igr.clickhouse.url' => 'http://127.0.0.1:1']);
    app()->forgetInstance(Ch::class);

    $this->artisan('igr:clickhouse:migrate', ['--wait' => 4])
        ->expectsOutputToContain('Esperando a ClickHouse')
        ->expectsOutputToContain('ClickHouse no responde')
        ->assertFailed();
    Sleep::assertSlept(fn ($duration) => (int) $duration->totalSeconds === 2, 2);
});

it('con ClickHouse arriba migra sin esperar', function () {
    $this->useIngestInfrastructure();
    Sleep::fake();

    $this->artisan('igr:clickhouse:migrate')->expectsOutputToContain('ClickHouse ya está al día')->assertSuccessful();
    Sleep::assertNeverSlept();
});
