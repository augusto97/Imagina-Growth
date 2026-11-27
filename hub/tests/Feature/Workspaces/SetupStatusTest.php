<?php

use App\Domain\Billing\Platform\InstallationDoctor;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Queries\SetupStatus;
use App\Support\Queue\QueueActivity;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * SetupStatus: cómo está configurado un espacio de trabajo, sin IA, solo hechos y claves (la usan «Pregúntale a la
 * IA» y el servidor MCP). Sin secretos ni datos de contactos, aislado por espacio de trabajo.
 */

/** Una cuenta de canal mínima. */
function setupChannel(Workspace $ws, string $channel, string $provider, string $status = 'active'): void
{
    DB::table('channel_accounts')->insert(['id' => (string) Str::ulid(), 'workspace_id' => $ws->id, 'channel' => $channel, 'provider' => $provider,
        'display_name' => 'Cuenta', 'config' => encrypt(['access_token' => 'tok-secreto-123']), 'identity' => '+573001234567', 'status' => $status,
        'created_at' => now(), 'updated_at' => now()]);
}

function setupSite(Workspace $ws, string $status = 'connected', string $platform = 'woocommerce'): void
{
    DB::table('sites')->insert(['id' => (string) Str::ulid(), 'workspace_id' => $ws->id, 'platform' => $platform, 'url' => 'https://tienda.test', 'host' => 'tienda.test',
        'public_key' => 'pk_'.Str::random(20), 'secret' => encrypt('secreto-del-sitio'), 'status' => $status, 'connected_at' => $status === 'pending' ? null : now(),
        'created_at' => now(), 'updated_at' => now()]);
}

beforeEach(function () {
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
});

it('sin nada conectado dice qué falta, en orden, y trae las señales de operación', function () {
    config(['mail.default' => 'log']);
    $status = SetupStatus::for($this->ws);

    expect($status['workspace']['id'])->toBe($this->ws->publicId())
        ->and($status['site'])->toBe(['state' => 'none', 'store' => false, 'history_import' => 'not_applicable'])
        ->and($status['channels']['whatsapp'])->toBe(['state' => 'none', 'kind' => null, 'providers' => []])
        ->and($status['channels']['email'])->toBe(['state' => 'none'])
        ->and($status['ads'])->toBe(['meta' => false, 'google' => false, 'tiktok' => false])
        ->and($status['agenda'])->toBe(['services' => 0, 'staff' => 0, 'calendars' => 0])
        ->and($status['strategies']['active'])->toBe([])
        ->and($status['strategies']['recommended'])->not->toBeEmpty()
        ->and($status['missing'])->toBe(['site', 'channel', 'setup', 'strategy', 'ads'])
        ->and($status['operations']['platform_mail_configured'])->toBeFalse()
        ->and($status['operations']['scheduler_heartbeat_age_seconds'])->toBeNull();
    // Cada recomendada dice qué le falta con claves, no con textos.
    foreach ($status['strategies']['recommended'] as $s) {
        expect($s)->toHaveKeys(['key', 'status', 'missing'])->and($s['missing'])->each->toBeString();
    }
});

it('con la tienda, WhatsApp oficial, correo y anuncios conectados ya no los pide; distingue el WhatsApp no oficial', function () {
    setupSite($this->ws);
    setupChannel($this->ws, 'whatsapp', 'meta_cloud');
    setupChannel($this->ws, 'email', 'ses');
    DB::table('ad_destinations')->insert(['id' => (string) Str::ulid(), 'workspace_id' => $this->ws->id, 'platform' => 'meta', 'config' => encrypt(['access_token' => 'x']),
        'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

    $status = SetupStatus::for($this->ws);
    expect($status['site']['state'])->toBe('connected')->and($status['site']['store'])->toBeTrue()
        ->and($status['channels']['whatsapp'])->toBe(['state' => 'active', 'kind' => 'official', 'providers' => ['meta_cloud']])
        ->and($status['channels']['email']['state'])->toBe('active')
        ->and($status['ads']['meta'])->toBeTrue()
        ->and($status['missing'])->not->toContain('site')->not->toContain('channel')->not->toContain('ads');

    $other = $this->workspaceFor($this->userWith2fa(), 'Clínica');
    setupChannel($other, 'whatsapp', 'imagina_whats');
    expect(SetupStatus::for($other)['channels']['whatsapp'])->toBe(['state' => 'active', 'kind' => 'unofficial', 'providers' => ['imagina_whats']]);
});

it('un WhatsApp caído pide reconectar y un sitio pendiente sigue faltando', function () {
    setupSite($this->ws, 'pending');
    setupChannel($this->ws, 'whatsapp', 'meta_cloud', 'disconnected');

    $status = SetupStatus::for($this->ws);
    expect($status['site']['state'])->toBe('pending')
        ->and($status['channels']['whatsapp']['state'])->toBe('down')
        ->and($status['missing'])->toContain('site')->toContain('whatsapp_reconnect')->not->toContain('channel');
});

it('no mezcla espacios de trabajo ni trae secretos o datos de contacto', function () {
    $other = $this->workspaceFor($this->userWith2fa(), 'Otra tienda');
    setupSite($other);
    setupChannel($other, 'whatsapp', 'meta_cloud');

    $status = SetupStatus::for($this->ws);
    expect($status['site']['state'])->toBe('none')->and($status['channels']['whatsapp']['state'])->toBe('none');

    $json = json_encode(SetupStatus::for($other));
    expect($json)->not->toContain('tok-secreto-123')->not->toContain('secreto-del-sitio')->not->toContain('+573001234567')->not->toContain('pk_');
});

it('las señales de operación: último trabajo de cola, latido del programador y correo de la plataforma', function () {
    config(['mail.default' => 'smtp']);
    QueueActivity::reset();
    QueueActivity::record();
    Cache::put(InstallationDoctor::SCHEDULER_HEARTBEAT, now()->subSeconds(90)->getTimestamp());

    $ops = SetupStatus::operations();
    expect($ops['last_job_at'])->toBeString()
        ->and($ops['scheduler_heartbeat_age_seconds'])->toBeGreaterThanOrEqual(90)->toBeLessThan(120)
        ->and($ops['platform_mail_configured'])->toBeTrue();

    // Anotar no escribe con cada trabajo: como mucho una vez cada 30 s por proceso.
    Cache::forget(QueueActivity::KEY);
    QueueActivity::record();
    expect(QueueActivity::lastProcessedAt())->toBeNull();
});
