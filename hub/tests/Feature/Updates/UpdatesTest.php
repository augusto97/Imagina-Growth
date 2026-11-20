<?php

use App\Domain\Updates\Support\UpdateState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

/*
 * Actualización desde el panel (docs/despliegue.md §5): detectar la versión nueva, pedirla y mostrar el
 * estado que escribe growth-update. El panel nunca instala: solo deja el pedido.
 */

beforeEach(function () {
    $this->stateDir = sys_get_temp_dir().'/growth-state-'.bin2hex(random_bytes(6));
    mkdir($this->stateDir);
    config(['growth.state_path' => $this->stateDir, 'growth.update.repo' => 'acme/growth', 'growth.update.api' => 'https://api.github.test', 'app.version' => '0.1.0']);
    $this->admin = $this->userWith2fa(['is_platform_admin' => true, 'password' => Hash::make('clave-admin-2026')]);
    $this->state = app(UpdateState::class);
    // GitHub falso: cada llamada responde con lo que diga $this->github en ese momento.
    $this->github = ['tag' => 'v0.2.0', 'status' => 200];
    Http::fake(['api.github.test/*' => fn () => Http::response(
        ['tag_name' => $this->github['tag'], 'name' => "Imagina Growth {$this->github['tag']}", 'body' => "- Arreglos\n- Mejoras",
            'published_at' => '2026-11-20T15:00:00Z', 'html_url' => "https://github.com/acme/growth/releases/tag/{$this->github['tag']}"],
        $this->github['status'],
    )]);
    $this->fakeGithub = function (string $tag = 'v0.2.0', int $status = 200): void {
        $this->github = ['tag' => $tag, 'status' => $status];
    };
});

afterEach(fn () => exec('rm -rf '.escapeshellarg($this->stateDir)));

it('el comando consulta la última versión publicada y la guarda para el panel', function () {
    ($this->fakeGithub)();
    $this->artisan('growth:updates:check')->expectsOutputToContain('Hay una versión nueva: 0.2.0')->assertSuccessful();
    Http::assertSent(fn ($r) => $r->url() === 'https://api.github.test/repos/acme/growth/releases/latest');
    expect($this->state->latest())->toMatchArray(['version' => '0.2.0', 'tag' => 'v0.2.0', 'error' => null])
        ->and($this->state->latest()['notes'])->toContain('Arreglos');

    // Si GitHub falla, se conserva la última versión vista y se anota el error.
    ($this->fakeGithub)('v0.3.0', 500);
    $this->artisan('growth:updates:check')->assertFailed();
    expect($this->state->latest())->toMatchArray(['version' => '0.2.0'])->and($this->state->latest()['error'])->toContain('500');

    // Un tag sin el formato vX.Y.Z no se ofrece.
    ($this->fakeGithub)('nightly');
    $this->artisan('growth:updates:check')->assertFailed();
    expect($this->state->latest()['version'])->toBe('0.2.0');
});

it('«Buscar ahora» no depende de los comandos de consola (en la web no están registrados)', function () {
    ($this->fakeGithub)();
    Artisan::shouldReceive('call')->never();
    $this->actingAs($this->admin)->postJson('/api/platform/updates/check')->assertOk()
        ->assertJsonPath('latest.version', '0.2.0')->assertJsonPath('update_available', true);
});

it('un pedido en proceso que la app no puede leer no tumba el panel', function () {
    // growth-update-dispatch (root) lo reescribe; si no se puede leer o está corrupto, el panel sigue y lo da por ocupado.
    file_put_contents($this->stateDir.'/update-request.processing.json', '{corrupto');
    $this->actingAs($this->admin)->getJson('/api/platform/updates')->assertOk()->assertJsonPath('busy', true);
});

it('solo un superadmin con 2FA ve y pide actualizaciones', function () {
    $owner = $this->userWith2fa();
    $this->actingAs($owner)->getJson('/api/platform/updates')->assertForbidden();
    $this->actingAs($owner)->postJson('/api/platform/updates/install', ['version' => '0.2.0', 'password' => 'x'])->assertForbidden();
    $this->actingAs($this->admin)->getJson('/api/platform/updates')->assertOk()
        ->assertJsonPath('current', '0.1.0')->assertJsonPath('update_available', false)->assertJsonPath('latest', null);
});

it('muestra la versión nueva, deja el pedido y responde 409 si ya hay uno', function () {
    ($this->fakeGithub)();
    $this->actingAs($this->admin)->postJson('/api/platform/updates/check')->assertOk()
        ->assertJsonPath('latest.version', '0.2.0')->assertJsonPath('update_available', true)->assertJsonPath('can_install', true);

    $this->actingAs($this->admin)->postJson('/api/platform/updates/install', ['version' => '0.2.0', 'password' => 'otra'])
        ->assertStatus(422)->assertJsonPath('error', 'invalid_password');
    expect(is_file($this->stateDir.'/update-request.json'))->toBeFalse();

    $this->actingAs($this->admin)->postJson('/api/platform/updates/install', ['version' => '0.2.0', 'password' => 'clave-admin-2026'])
        ->assertStatus(202)->assertJsonPath('busy', true)->assertJsonPath('can_install', false)->assertJsonPath('pending.version', '0.2.0');
    $request = json_decode((string) file_get_contents($this->stateDir.'/update-request.json'), true);
    expect($request['version'])->toBe('0.2.0')
        ->and($request['id'])->toMatch('/^upd_[A-Za-z0-9]{6,40}$/')
        ->and($request['requested_by'])->toBe($this->admin->publicId())
        ->and(array_keys($request))->toBe(['version', 'id', 'requested_by', 'requested_at']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'platform.update_requested', 'user_id' => $this->admin->id]);

    // Pedido en espera → 409; en proceso → 409; corriendo → 409.
    $this->actingAs($this->admin)->postJson('/api/platform/updates/install', ['version' => '0.2.0', 'password' => 'clave-admin-2026'])
        ->assertStatus(409)->assertJsonPath('error', 'update_in_progress');
    rename($this->stateDir.'/update-request.json', $this->stateDir.'/update-request.processing.json');
    $this->actingAs($this->admin)->postJson('/api/platform/updates/install', ['version' => '0.2.0', 'password' => 'clave-admin-2026'])->assertStatus(409);
    unlink($this->stateDir.'/update-request.processing.json');
    $this->state->write(UpdateState::STATUS, ['id' => 'upd_x', 'state' => 'running', 'step' => 'migrate', 'steps' => []]);
    $this->actingAs($this->admin)->postJson('/api/platform/updates/install', ['version' => '0.2.0', 'password' => 'clave-admin-2026'])->assertStatus(409);

    // Una «en curso» abandonada hace horas ya no bloquea.
    touch($this->stateDir.'/update-status.json', time() - 3 * 3600);
    $this->actingAs($this->admin)->postJson('/api/platform/updates/install', ['version' => '0.2.0', 'password' => 'clave-admin-2026'])->assertStatus(202);
});

it('responde 422 si la versión no es la última publicada o no es más nueva', function () {
    ($this->fakeGithub)();
    $this->artisan('growth:updates:check');
    foreach (['0.3.0', '0.1.0', '0.0.9', 'v0.2.0', '0.2.0; rm -rf /'] as $version) {
        $this->actingAs($this->admin)->postJson('/api/platform/updates/install', ['version' => $version, 'password' => 'clave-admin-2026'])
            ->assertStatus(422);
    }
    config(['app.version' => '0.2.0']);
    $this->actingAs($this->admin)->postJson('/api/platform/updates/install', ['version' => '0.2.0', 'password' => 'clave-admin-2026'])
        ->assertStatus(422)->assertJsonPath('error', 'version_not_available');
    config(['app.version' => 'dev']);
    $this->actingAs($this->admin)->getJson('/api/platform/updates')->assertJsonPath('update_available', false);
    expect(is_file($this->stateDir.'/update-request.json'))->toBeFalse();
});

it('muestra el estado y el historial que escribe growth-update', function () {
    $this->state->write(UpdateState::STATUS, [
        'id' => 'upd_abc123', 'from' => '0.1.0', 'to' => '0.2.0', 'state' => 'rolled_back', 'step' => 'health',
        'steps' => [['key' => 'health', 'status' => 'failed', 'message' => 'El health-check falló']], 'error' => 'El health-check falló',
    ]);
    file_put_contents($this->stateDir.'/update-history.jsonl', implode("\n", [
        json_encode(['id' => 'upd_1', 'to' => '0.1.0', 'state' => 'succeeded']),
        'línea dañada',
        json_encode(['id' => 'upd_2', 'to' => '0.2.0', 'state' => 'rolled_back']),
    ])."\n");
    $this->actingAs($this->admin)->getJson('/api/platform/updates')->assertOk()
        ->assertJsonPath('status.state', 'rolled_back')->assertJsonPath('status.steps.0.status', 'failed')
        ->assertJsonPath('busy', false)
        ->assertJsonPath('history.0.id', 'upd_2')->assertJsonPath('history.1.id', 'upd_1')->assertJsonCount(2, 'history');
});

it('no sigue un symlink en el estado ni en el historial', function () {
    $secret = $this->stateDir.'/../secreto-'.bin2hex(random_bytes(4)).'.json';
    file_put_contents($secret, json_encode(['state' => 'running', 'secreto' => 'no']));
    symlink($secret, $this->stateDir.'/update-status.json');
    $this->actingAs($this->admin)->getJson('/api/platform/updates')->assertOk()->assertJsonPath('status', null);
    unlink($secret);
});

it('un pedido que ya terminó o que el servidor nunca tomó no queda «en curso» ni bloquea la siguiente actualización', function () {
    ($this->fakeGithub)();
    $this->actingAs($this->admin)->postJson('/api/platform/updates/check')->assertOk();

    // Quedó el pedido en proceso de una actualización que ya terminó bien (el caso del panel en producción).
    $this->state->write(UpdateState::PROCESSING, ['version' => '0.1.9', 'id' => 'upd_viejo123456', 'requested_by' => 'usr_x', 'accepted_at' => '2026-11-20T13:32:00Z']);
    $this->state->write(UpdateState::STATUS, ['id' => 'upd_viejo123456', 'from' => '0.1.4', 'to' => '0.1.9', 'state' => 'succeeded', 'finished_at' => '2026-11-20T13:40:00Z', 'steps' => []]);
    $this->actingAs($this->admin)->getJson('/api/platform/updates')->assertOk()
        ->assertJsonPath('pending', null)->assertJsonPath('busy', false)->assertJsonPath('can_install', true);

    // Otro pedido de antes, con otro id, aceptado antes de que terminara la última: tampoco cuenta.
    $this->state->write(UpdateState::PROCESSING, ['version' => '0.1.9', 'id' => 'upd_otro1234567', 'requested_by' => 'usr_x', 'accepted_at' => '2026-11-20T13:30:00Z']);
    $this->actingAs($this->admin)->getJson('/api/platform/updates')->assertOk()->assertJsonPath('pending', null);

    // Un pedido aceptado después de la última actualización sí está en curso.
    $this->state->write(UpdateState::PROCESSING, ['version' => '0.2.0', 'id' => 'upd_nuevo123456', 'requested_by' => 'usr_x', 'accepted_at' => '2026-11-20T14:00:00Z']);
    $this->actingAs($this->admin)->getJson('/api/platform/updates')->assertOk()->assertJsonPath('pending.id', 'upd_nuevo123456')->assertJsonPath('busy', true);
    unlink($this->stateDir.'/update-request.processing.json');

    // Un pedido en espera que el servidor no tomó en 15 minutos deja volver a pedir (y lo reemplaza).
    $this->state->write(UpdateState::REQUEST, ['version' => '0.2.0', 'id' => 'upd_espera12345', 'requested_by' => 'usr_x', 'requested_at' => '2026-11-20T14:00:00Z']);
    $this->actingAs($this->admin)->getJson('/api/platform/updates')->assertOk()->assertJsonPath('busy', true)->assertJsonPath('stale_request', false);
    touch($this->stateDir.'/update-request.json', time() - 20 * 60);
    $this->actingAs($this->admin)->getJson('/api/platform/updates')->assertOk()
        ->assertJsonPath('pending', null)->assertJsonPath('busy', false)->assertJsonPath('stale_request', true);
    $this->actingAs($this->admin)->postJson('/api/platform/updates/install', ['version' => '0.2.0', 'password' => 'clave-admin-2026'])->assertStatus(202);
    expect(json_decode((string) file_get_contents($this->stateDir.'/update-request.json'), true)['id'])->not->toBe('upd_espera12345');
});
