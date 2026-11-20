<?php

use App\Domain\Sites\Health\SiteHealth;
use App\Domain\Sites\Models\Site;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * U4 y F6 (docs/auditoria-2026-11.md): el semáforo de salud del sitio (al día, con atraso, sin datos) con sus causas,
 * en el panel y en `sites.status` (degraded) con `igr:sites:health`.
 */

function healthySite(array $overrides = []): Site
{
    $site = new Site;
    $site->forceFill(array_replace([
        'id' => '01JTEST0000000000000000000', 'status' => Site::STATUS_CONNECTED, 'plugin_version' => '0.1.2',
        'connected_at' => now()->subDays(3), 'last_seen_at' => now()->subMinutes(20), 'last_event_at' => now()->subMinutes(2),
        'health' => ['heartbeat_at' => now()->subMinutes(20)->toIso8601String(), 'outbox_pending' => 0, 'outbox_dead' => 0, 'oldest_pending_at' => null],
        'backfill_state' => ['status' => 'completed'],
    ], $overrides));

    return $site;
}

function signalsNow(array $overrides = []): array
{
    return array_replace(['clickhouse' => true, 'server_at' => now()->subMinutes(2)->toDateTimeString(), 'browser_at' => now()->subMinutes(5)->toDateTimeString(), 'rejections_24h' => 0], $overrides);
}

function causeKeys(array $result): array
{
    return array_column($result['causes'], 'key');
}

it('al día cuando llegan el servidor, el navegador y el heartbeat', function () {
    $r = SiteHealth::evaluate(healthySite(), signalsNow(), CarbonImmutable::now());
    expect($r['state'])->toBe(SiteHealth::OK)->and($r['causes'])->toBe([]);
});

it('sin visitas del navegador: con atraso y la causa que explica los KPI en cero', function () {
    $r = SiteHealth::evaluate(healthySite(), signalsNow(['browser_at' => null]), CarbonImmutable::now());
    expect($r['state'])->toBe(SiteHealth::LAGGING)->and(causeKeys($r))->toBe(['browser_missing']);

    $r = SiteHealth::evaluate(healthySite(), signalsNow(['browser_at' => now()->subHours(60)->toDateTimeString()]), CarbonImmutable::now());
    expect(causeKeys($r))->toBe(['browser_late'])->and($r['causes'][0]['params']['hours'])->toBe(60);
});

it('sin eventos del servidor: con atraso a las 3 horas y sin datos pasado un día', function () {
    $late = healthySite(['last_seen_at' => now()->subHours(5), 'last_event_at' => now()->subHours(5), 'health' => ['heartbeat_at' => now()->subHours(5)->toIso8601String()]]);
    $r = SiteHealth::evaluate($late, signalsNow(['server_at' => now()->subHours(5)->toDateTimeString()]), CarbonImmutable::now());
    expect($r['state'])->toBe(SiteHealth::LAGGING)->and(causeKeys($r))->toBe(['server_late'])->and($r['causes'][0]['params']['hours'])->toBe(5);

    $silent = healthySite(['last_seen_at' => now()->subHours(30), 'last_event_at' => now()->subHours(30), 'health' => ['heartbeat_at' => now()->subHours(30)->toIso8601String()]]);
    $r = SiteHealth::evaluate($silent, signalsNow(['server_at' => null]), CarbonImmutable::now());
    expect($r['state'])->toBe(SiteHealth::NO_DATA)->and(causeKeys($r))->toContain('server_silent');
});

it('WP-Cron atrasado según el heartbeat y la cola de envío', function () {
    $cron = healthySite(['health' => ['heartbeat_at' => now()->subHours(4)->toIso8601String()]]);
    expect(causeKeys(SiteHealth::evaluate($cron, signalsNow(), CarbonImmutable::now())))->toBe(['cron_late']);

    $outbox = healthySite(['health' => ['heartbeat_at' => now()->subMinutes(10)->toIso8601String(), 'outbox_pending' => 40, 'oldest_pending_at' => now()->subMinutes(55)->toIso8601String()]]);
    $r = SiteHealth::evaluate($outbox, signalsNow(), CarbonImmutable::now());
    expect(causeKeys($r))->toBe(['outbox_late'])->and($r['causes'][0]['params'])->toBe(['minutes' => 45, 'pending' => 40]);
});

it('plugin desactualizado, rechazos, eventos que el plugin no pudo enviar e importación detenida', function () {
    config(['igr.min_plugin_version' => '0.2.0']);
    $site = healthySite([
        'health' => ['heartbeat_at' => now()->subMinutes(5)->toIso8601String(), 'outbox_dead' => 3],
        'backfill_state' => ['status' => 'running', 'reported_at' => now()->subHour()->toIso8601String()],
    ]);
    $r = SiteHealth::evaluate($site, signalsNow(['rejections_24h' => 7]), CarbonImmutable::now());
    expect($r['state'])->toBe(SiteHealth::LAGGING)
        ->and(causeKeys($r))->toBe(['plugin_outdated', 'rejections', 'outbox_dead', 'backfill_stalled'])
        ->and($r['causes'][0]['params'])->toBe(['version' => '0.1.2', 'min' => '0.2.0']);

    config(['igr.min_plugin_version' => '0.1.0']);
    $failed = healthySite(['backfill_state' => ['status' => 'failed']]);
    $r = SiteHealth::evaluate($failed, signalsNow(), CarbonImmutable::now());
    expect(causeKeys($r))->toBe(['backfill_failed'])->and($r['causes'][0]['severity'])->toBe('error');
});

it('pendiente, desconectado o conectado sin ningún evento: sin datos', function () {
    expect(SiteHealth::evaluate(healthySite(['status' => Site::STATUS_PENDING]), [], CarbonImmutable::now())['state'])->toBe(SiteHealth::NO_DATA)
        ->and(causeKeys(SiteHealth::evaluate(healthySite(['status' => Site::STATUS_DISCONNECTED]), [], CarbonImmutable::now())))->toBe(['disconnected']);
    $never = healthySite(['last_event_at' => null, 'last_seen_at' => null, 'health' => [], 'connected_at' => now()->subHour()]);
    expect(causeKeys(SiteHealth::evaluate($never, signalsNow(['server_at' => null, 'browser_at' => null]), CarbonImmutable::now())))->toBe(['never_received']);
    // Recién conectado: todavía no es un problema.
    $fresh = healthySite(['last_event_at' => null, 'last_seen_at' => null, 'health' => [], 'connected_at' => now()->subMinutes(3)]);
    expect(SiteHealth::evaluate($fresh, [], CarbonImmutable::now())['state'])->toBe(SiteHealth::OK);
});

it('el panel muestra el semáforo con datos reales y el comando marca el sitio degradado y lo recupera', function () {
    $this->useIngestInfrastructure();
    $owner = $this->userWith2fa();
    $ws = $this->workspaceFor($owner);
    [$site, $secret] = $this->connectedSite($ws);
    $heartbeat = $this->envelope($site, 'site.heartbeat', ['plugin_version' => '0.1.2', 'outbox_pending' => 0, 'outbox_dead' => 0]);
    $this->postSigned($site, $secret, [$heartbeat])->assertStatus(202);
    $this->runWorker();

    $url = "/api/w/{$ws->publicId()}/sites";
    $status = $this->actingAs($owner)->getJson($url)->assertOk()->json('sites.0.health_status');
    // Llega el servidor pero ninguna visita del navegador: el tracker no carga.
    expect($status['state'])->toBe('lagging')->and(array_column($status['causes'], 'key'))->toBe(['browser_missing']);

    $this->artisan('igr:sites:health')->assertSuccessful();
    expect(Site::withoutWorkspaceScope()->find($site->id)->status)->toBe(Site::STATUS_DEGRADED);

    $view = $this->envelope($site, 'page.viewed', ['page_type' => 'home'], ['source' => ['type' => 'browser', 'sdk' => 'tracker/0.1.0'], 'identity' => ['anonymous_id' => 'c3b0f4a2']]);
    $this->call('POST', '/v1/b', [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_ORIGIN' => 'https://tienda.co'], json_encode(['k' => $site->public_key, 'events' => [$view]]))->assertStatus(202);
    $this->runWorker();
    cache()->flush();

    $detail = $this->actingAs($owner)->getJson("{$url}/{$site->publicId()}")->assertOk()->json('site.health_status');
    expect($detail['state'])->toBe('ok');
    $this->artisan('igr:sites:health')->assertSuccessful();
    expect(Site::withoutWorkspaceScope()->find($site->id)->status)->toBe(Site::STATUS_CONNECTED);
});

it('los rechazos y eventos de otro espacio de trabajo no cuentan', function () {
    $this->useIngestInfrastructure();
    $owner = $this->userWith2fa();
    $ws = $this->workspaceFor($owner);
    [$site, $secret] = $this->connectedSite($ws);
    $this->postSigned($site, $secret, [$this->envelope($site, 'site.heartbeat', ['plugin_version' => '0.1.2'])])->assertStatus(202);
    $this->runWorker();

    $other = $this->workspaceFor($this->userWith2fa(), 'Otra tienda');
    [$otherSite] = $this->connectedSite($other, 'otra.co');
    DB::table('ingest_rejections')->insert([
        'workspace_id' => $other->id, 'site_id' => $otherSite->id, 'event' => 'order.paid', 'reason' => 'schema', 'detail' => 'x', 'created_at' => now(),
    ]);
    // Mismo ch_id no es posible, pero un rechazo con el id del sitio en otro espacio tampoco se cuela.
    DB::table('ingest_rejections')->insert([
        'workspace_id' => $other->id, 'site_id' => $site->id, 'event' => 'order.paid', 'reason' => 'schema', 'detail' => 'x', 'created_at' => now(),
    ]);

    $status = app(CurrentWorkspace::class)->run($ws, fn () => app(SiteHealth::class)->forSite($ws, Site::query()->findOrFail($site->id)));
    expect(array_column($status['causes'], 'key'))->not->toContain('rejections');
});
