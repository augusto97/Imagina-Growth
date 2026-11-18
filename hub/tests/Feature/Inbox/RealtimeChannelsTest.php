<?php

use App\Domain\Inbox\Realtime\InboxUpdated;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use Illuminate\Support\Facades\Broadcast;

beforeEach(function () {
    // El driver de Reverb firma la autorización localmente (protocolo de Pusher), sin red.
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'igr-test-key',
        'broadcasting.connections.reverb.secret' => 'igr-test-secret',
        'broadcasting.connections.reverb.app_id' => 'igr-test',
    ]);
    // Los canales se registran en el driver que existía al arrancar (null en pruebas).
    Broadcast::forgetDrivers();
    require base_path('routes/channels.php');
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
});

function realtimeAuth($test, $user, string $channel)
{
    $request = $user === null ? $test : $test->actingAs($user);

    return $request->postJson('/api/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel]);
}

it('autoriza la bandeja solo a quien puede usarla en ese espacio de trabajo', function () {
    $inbox = 'private-workspace.'.$this->ws->publicId().'.inbox';
    realtimeAuth($this, null, $inbox)->assertUnauthorized(); // antes de iniciar sesión con nadie
    $agent = $this->addMember($this->ws, WorkspaceRole::Agent);
    $viewer = $this->addMember($this->ws, WorkspaceRole::Viewer);
    $other = $this->userWith2fa();
    $this->workspaceFor($other);

    expect(realtimeAuth($this, $this->owner, $inbox)->assertOk()->json('auth'))->toStartWith('igr-test-key:')
        ->and(realtimeAuth($this, $agent, $inbox)->status())->toBe(200);
    realtimeAuth($this, $viewer, $inbox)->assertForbidden();
    realtimeAuth($this, $other, $inbox)->assertForbidden();
    realtimeAuth($this, $this->owner, 'private-workspace.ws_01JD3QG7Y2V8K6N4M5P9R0S1T2.inbox')->assertForbidden();
});

it('el tablero en vivo es para quien ve los tableros, no para el agente', function () {
    $live = 'private-workspace.'.$this->ws->publicId().'.live';
    realtimeAuth($this, $this->addMember($this->ws, WorkspaceRole::Viewer), $live)->assertOk();
    realtimeAuth($this, $this->addMember($this->ws, WorkspaceRole::Agent), $live)->assertForbidden();
});

it('el evento de la bandeja va al canal con el id público y solo lleva IDs', function () {
    $e = new InboxUpdated($this->ws->id, 'cnv_01JD3QG7Y2V8K6N4M5P9R0S1T2', 'inbound', 'u1');
    expect($e->broadcastOn()[0]->name)->toBe('private-workspace.'.$this->ws->publicId().'.inbox')
        ->and($e->broadcastAs())->toBe('inbox.updated')
        ->and($e->broadcastWith())->toBe(['conversation' => 'cnv_01JD3QG7Y2V8K6N4M5P9R0S1T2', 'reason' => 'inbound', 'assignee' => 'u1']);
});
