<?php

use App\Domain\Backups\Support\BackupState;
use App\Domain\Workspaces\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/*
 * Plataforma → Copias de seguridad (docs/despliegue.md §6): el panel valida y deja el pedido en shared/state; lo
 * atiende growth-backup-dispatch (root, con sus pruebas en infra/tests/backup/dispatch.sh). Aquí: quién puede, la
 * validación, el pedido que queda (0600, sin recortes en los secretos), que ningún secreto vuelva en las respuestas
 * ni en la auditoría, y el estado que publica root.
 */

beforeEach(function () {
    $this->stateDir = sys_get_temp_dir().'/growth-backups-'.bin2hex(random_bytes(6));
    mkdir($this->stateDir);
    config(['growth.state_path' => $this->stateDir]);
    $this->admin = $this->userWith2fa(['is_platform_admin' => true, 'password' => Hash::make('clave-admin-2026')]);
    $this->request = fn (): ?array => is_file($this->stateDir.'/backup-request.json')
        ? json_decode((string) file_get_contents($this->stateDir.'/backup-request.json'), true) : null;
    // Lo que publica root (growth-backup-dispatch) en backup-status.json.
    $this->publish = function (array $over = []): void {
        $status = array_replace_recursive([
            'version' => 1, 'updated_at' => '2026-11-20T10:00:00Z', 'configured' => true, 'managed' => true, 'manual_remote' => null,
            'destination' => ['host' => 'u123456.your-storagebox.de', 'port' => 23, 'user' => 'u123456', 'path' => 'imagina-growth', 'auth' => 'key', 'keep_days' => 30],
            'secrets' => ['password' => false, 'encryption' => true],
            'heartbeats' => ['mariadb' => true, 'clickhouse' => false, 'verify' => false],
            'timers_enabled' => true,
            'ssh_key' => ['public' => 'ssh-ed25519 AAAAC3Nza growth', 'fingerprint' => 'SHA256:abc', 'created_at' => '2026-11-20T10:00:00Z'],
            'host_key' => ['fingerprint' => 'SHA256:srv'],
            'last_request' => ['id' => 'bkp_x1y2z3', 'action' => 'configure', 'requested_by' => 'usr_1', 'state' => 'succeeded', 'code' => null, 'message' => 'Configuración guardada.', 'started_at' => '2026-11-20T10:00:00Z', 'finished_at' => '2026-11-20T10:00:05Z'],
            'last_test' => ['ok' => true, 'code' => null, 'message' => null, 'at' => '2026-11-20T10:00:05Z', 'free_bytes' => 1000],
        ], $over);
        file_put_contents($this->stateDir.'/backup-status.json', json_encode($status));
    };
    $this->settings = fn (array $over = []): array => array_replace([
        'host' => 'u123456.your-storagebox.de', 'port' => 23, 'user' => 'u123456', 'path' => 'imagina-growth', 'auth' => 'key',
        'keep_days' => 30, 'heartbeats' => ['mariadb' => 'https://uptime.betterstack.com/api/v1/heartbeat/HBmariadb12345'],
        'encryption_password' => '  frase de cifrado con espacios  ', 'current_password' => 'clave-admin-2026',
    ], $over);
});

afterEach(fn () => exec('rm -rf '.escapeshellarg($this->stateDir)));

it('solo un superadmin con 2FA ve y configura las copias', function () {
    $owner = $this->userWith2fa();
    $this->actingAs($owner)->getJson('/api/platform/backups')->assertForbidden();
    $this->actingAs($owner)->putJson('/api/platform/backups/settings', ($this->settings)())->assertForbidden();
    $this->actingAs($owner)->postJson('/api/platform/backups/run')->assertForbidden();
    $this->actingAs($owner)->postJson('/api/platform/backups/install-key', ['password' => 'x', 'current_password' => 'x'])->assertForbidden();
    $noTwoFactor = User::factory()->create(['is_platform_admin' => true]);
    config(['igr.require_two_factor' => true]);
    $this->actingAs($noTwoFactor)->getJson('/api/platform/backups')->assertForbidden();
    expect(($this->request)())->toBeNull();
});

it('sin respuesta del servidor todavía: sin configurar y con los próximos horarios', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 03:30:00', 'UTC')); // viernes
    $this->actingAs($this->admin)->getJson('/api/platform/backups')->assertOk()
        ->assertJsonPath('answered', false)
        ->assertJsonPath('configured', false)
        ->assertJsonPath('destination', null)
        ->assertJsonPath('pending', null)
        ->assertJsonPath('busy', false)
        ->assertJsonPath('jobs.mariadb', null)
        ->assertJsonPath('schedule.mariadb', '2026-11-21T03:15:00Z')
        ->assertJsonPath('schedule.clickhouse', '2026-11-20T03:45:00Z')
        ->assertJsonPath('schedule.verify', '2026-11-22T05:00:00Z');
});

it('guarda el destino: deja el pedido 0600 con los secretos tal cual y sin devolverlos', function () {
    $response = $this->actingAs($this->admin)->putJson('/api/platform/backups/settings', ($this->settings)([
        'heartbeats' => ['mariadb' => 'https://uptime.betterstack.com/api/v1/heartbeat/HBmariadb12345', 'clickhouse' => null],
        'clear_heartbeats' => ['verify'],
    ]))->assertStatus(202)->assertJsonPath('busy', true)->assertJsonPath('pending.action', 'configure');

    $path = $this->stateDir.'/backup-request.json';
    expect(fileperms($path) & 0777)->toBe(0600);
    $request = ($this->request)();
    expect($request['id'])->toMatch('/^bkp_[A-Za-z0-9]{6,40}$/')
        ->and($request['action'])->toBe('configure')
        ->and($request['requested_by'])->toBe($this->admin->publicId())
        ->and($request['config'])->toBe([
            'host' => 'u123456.your-storagebox.de', 'port' => 23, 'user' => 'u123456', 'path' => 'imagina-growth', 'auth' => 'key', 'keep_days' => 30,
            'heartbeats' => ['mariadb' => 'https://uptime.betterstack.com/api/v1/heartbeat/HBmariadb12345', 'clickhouse' => null, 'verify' => ''],
        ])
        // La frase llega sin recortar (sin la exacta, las copias no se pueden leer) y sin contraseña con llave SSH.
        ->and($request['secrets'])->toBe(['password' => null, 'encryption_password' => '  frase de cifrado con espacios  '])
        ->and(array_keys($request))->toBe(['id', 'action', 'requested_by', 'requested_at', 'config', 'secrets']);

    $body = (string) $response->getContent();
    expect($body)->not->toContain('frase de cifrado')->not->toContain('HBmariadb12345')->not->toContain('clave-admin');
    $audit = DB::table('audit_logs')->where('action', 'platform.backups_configured')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->user_id)->toBe($this->admin->id)
        ->and((string) $audit->changes)->not->toContain('frase de cifrado')->not->toContain('HBmariadb12345')
        ->and(json_decode((string) $audit->changes, true)['heartbeats'])->toBe(['mariadb' => 'set', 'clickhouse' => 'keep', 'verify' => 'removed']);
});

it('con contraseña: la pide si no hay una guardada y nunca la devuelve', function () {
    ($this->publish)(['secrets' => ['password' => false, 'encryption' => true]]);
    $this->actingAs($this->admin)->putJson('/api/platform/backups/settings', ($this->settings)(['auth' => 'password', 'encryption_password' => null]))
        ->assertStatus(422)->assertJsonValidationErrors('password');
    expect(($this->request)())->toBeNull();

    $this->actingAs($this->admin)->putJson('/api/platform/backups/settings', ($this->settings)(['auth' => 'password', 'password' => ' Clave#del$Box ', 'encryption_password' => null]))
        ->assertStatus(202);
    expect(($this->request)()['secrets'])->toBe(['password' => ' Clave#del$Box ', 'encryption_password' => null]);
    unlink($this->stateDir.'/backup-request.json');

    // Ya guardada del lado de root: no hace falta escribirla otra vez.
    ($this->publish)(['secrets' => ['password' => true, 'encryption' => true], 'last_request' => ['state' => 'succeeded']]);
    $this->actingAs($this->admin)->putJson('/api/platform/backups/settings', ($this->settings)(['auth' => 'password', 'encryption_password' => null]))
        ->assertStatus(202);
    expect(($this->request)()['secrets'])->toBe(['password' => null, 'encryption_password' => null]);
});

it('la frase de cifrado es obligatoria la primera vez', function () {
    $this->actingAs($this->admin)->putJson('/api/platform/backups/settings', ($this->settings)(['encryption_password' => null]))
        ->assertStatus(422)->assertJsonValidationErrors('encryption_password');
    $this->actingAs($this->admin)->putJson('/api/platform/backups/settings', ($this->settings)(['encryption_password' => 'corta']))
        ->assertStatus(422)->assertJsonValidationErrors('encryption_password');
    expect(($this->request)())->toBeNull();
});

it('rechaza la contraseña del panel equivocada sin dejar pedido', function () {
    $this->actingAs($this->admin)->putJson('/api/platform/backups/settings', ($this->settings)(['current_password' => 'otra']))
        ->assertStatus(422)->assertJsonPath('error', 'invalid_password');
    $this->actingAs($this->admin)->postJson('/api/platform/backups/install-key', ['password' => 'x', 'current_password' => 'otra'])
        ->assertStatus(422)->assertJsonPath('error', 'invalid_password');
    expect(($this->request)())->toBeNull();
});

it('valida cada campo con las mismas listas blancas que root', function (array $over, string $field) {
    $this->actingAs($this->admin)->putJson('/api/platform/backups/settings', ($this->settings)($over))
        ->assertStatus(422)->assertJsonValidationErrors($field);
    expect(($this->request)())->toBeNull();
})->with([
    'servidor con ;' => [['host' => 'u1.example.com; rm -rf /'], 'host'],
    'servidor como opción' => [['host' => '-oProxyCommand=id'], 'host'],
    'servidor con salto de línea' => [['host' => "u1.exa\nmple.com"], 'host'],
    'puerto 0' => [['port' => 0], 'port'],
    'puerto 70000' => [['port' => 70000], 'port'],
    'usuario con $()' => [['user' => 'u1$(id)'], 'user'],
    'carpeta ..' => [['path' => '../etc'], 'path'],
    'carpeta absoluta' => [['path' => '/etc/growth'], 'path'],
    'carpeta .ssh' => [['path' => '.ssh'], 'path'],
    'carpeta con salto de línea' => [['path' => "gro\nwth"], 'path'],
    'días 0' => [['keep_days' => 0], 'keep_days'],
    'días 400' => [['keep_days' => 400], 'keep_days'],
    'forma de entrar' => [['auth' => 'agent'], 'auth'],
    'heartbeat http' => [['heartbeats' => ['mariadb' => 'http://uptime.betterstack.com/api/v1/heartbeat/abcdefgh12']], 'heartbeats.mariadb'],
    'heartbeat de otro sitio' => [['heartbeats' => ['mariadb' => 'https://evil.example/api/v1/heartbeat/abcdefgh12']], 'heartbeats.mariadb'],
    'heartbeat de otra tarea' => [['heartbeats' => ['otra' => 'https://uptime.betterstack.com/api/v1/heartbeat/abcdefgh12']], 'heartbeats'],
    'quitar uno que no existe' => [['clear_heartbeats' => ['otra']], 'clear_heartbeats.0'],
    'contraseña con salto de línea' => [['auth' => 'password', 'password' => "clave\npass = x"], 'password'],
    'frase con tabulador' => [['encryption_password' => "frase larga\tcon tab"], 'encryption_password'],
    'contraseña larguísima' => [['auth' => 'password', 'password' => str_repeat('x', 300)], 'password'],
    'contraseña que no es texto' => [['auth' => 'password', 'password' => ['x']], 'password'],
]);

it('un pedido a la vez: 409 mientras root no lo toma; uno abandonado no bloquea', function () {
    $this->actingAs($this->admin)->putJson('/api/platform/backups/settings', ($this->settings)())->assertStatus(202);
    $first = ($this->request)()['id'];
    $this->actingAs($this->admin)->putJson('/api/platform/backups/settings', ($this->settings)())
        ->assertStatus(409)->assertJsonPath('error', 'backup_request_in_progress');

    // root lo tomó y lo está atendiendo.
    unlink($this->stateDir.'/backup-request.json');
    ($this->publish)(['last_request' => ['id' => $first, 'state' => 'running']]);
    $this->actingAs($this->admin)->postJson('/api/platform/backups/test')->assertStatus(409);

    // Nadie lo tomó en 10 minutos (el servidor no tiene el despachador): se avisa y se puede pedir otra vez.
    ($this->publish)(['last_request' => ['state' => 'succeeded']]);
    $this->actingAs($this->admin)->postJson('/api/platform/backups/test')->assertStatus(202);
    touch($this->stateDir.'/backup-request.json', time() - 11 * 60);
    $this->actingAs($this->admin)->getJson('/api/platform/backups')->assertJsonPath('pending.stale', true)->assertJsonPath('busy', false);
    $this->actingAs($this->admin)->postJson('/api/platform/backups/run')->assertStatus(202);
    expect(($this->request)()['action'])->toBe('backup_now');
});

it('probar la conexión, copia ahora y estado: pedidos sin secretos; sin destino responde 422', function () {
    $this->actingAs($this->admin)->postJson('/api/platform/backups/test')->assertStatus(422)->assertJsonPath('error', 'not_configured');
    $this->actingAs($this->admin)->postJson('/api/platform/backups/run')->assertStatus(422);
    expect(($this->request)())->toBeNull();

    ($this->publish)();
    foreach (['test' => 'test', 'run' => 'backup_now', 'refresh' => 'refresh'] as $route => $action) {
        $this->actingAs($this->admin)->postJson("/api/platform/backups/{$route}")->assertStatus(202)->assertJsonPath('pending.action', $action);
        $request = ($this->request)();
        expect($request['action'])->toBe($action)->and(array_keys($request))->toBe(['id', 'action', 'requested_by', 'requested_at']);
        unlink($this->stateDir.'/backup-request.json');
    }
    $this->assertDatabaseHas('audit_logs', ['action' => 'platform.backups_run_requested', 'user_id' => $this->admin->id]);
});

it('instalar la llave con la contraseña del Storage Box: solo con llave SSH y la contraseña no se audita', function () {
    $this->actingAs($this->admin)->postJson('/api/platform/backups/install-key', ['password' => 'Clave-Box', 'current_password' => 'clave-admin-2026'])
        ->assertStatus(422)->assertJsonPath('error', 'not_configured');
    ($this->publish)(['destination' => ['auth' => 'password']]);
    $this->actingAs($this->admin)->postJson('/api/platform/backups/install-key', ['password' => 'Clave-Box', 'current_password' => 'clave-admin-2026'])
        ->assertStatus(422)->assertJsonPath('error', 'auth_not_key');
    $this->actingAs($this->admin)->postJson('/api/platform/backups/install-key', ['current_password' => 'clave-admin-2026'])
        ->assertStatus(422)->assertJsonValidationErrors('password');

    ($this->publish)(['destination' => ['auth' => 'key']]);
    $this->actingAs($this->admin)->postJson('/api/platform/backups/install-key', ['password' => 'Clave-Box', 'current_password' => 'clave-admin-2026'])
        ->assertStatus(202)->assertJsonMissingPath('password');
    expect(($this->request)())->toMatchArray(['action' => 'install_key', 'secrets' => ['password' => 'Clave-Box']]);
    expect((string) DB::table('audit_logs')->where('action', 'platform.backups_key_install_requested')->value('changes'))->not->toContain('Clave-Box');
});

it('muestra el estado de root y de cada copia, solo con los campos conocidos', function () {
    ($this->publish)(['destination' => ['password' => 'no-debe-salir'], 'secreto' => 'tampoco']);
    file_put_contents($this->stateDir.'/backup-job-mariadb.json', json_encode([
        'job' => 'mariadb', 'state' => 'failed', 'started_at' => '2026-11-20T03:15:00Z', 'finished_at' => '2026-11-20T03:16:00Z',
        'name' => null, 'size_bytes' => null, 'code' => 'upload_failed', 'message' => 'No se pudo subir la copia al Storage Box.',
        'last_success' => ['finished_at' => '2026-11-19T03:20:00Z', 'name' => '20261119T031500Z.tar.zst', 'size_bytes' => 52428800],
    ]));
    file_put_contents($this->stateDir.'/backup-job-clickhouse.json', '{corrupto');

    $response = $this->actingAs($this->admin)->getJson('/api/platform/backups')->assertOk()
        ->assertJsonPath('answered', true)
        ->assertJsonPath('managed', true)
        ->assertJsonPath('destination.host', 'u123456.your-storagebox.de')
        ->assertJsonPath('destination.auth', 'key')
        ->assertJsonPath('secrets.encryption', true)
        ->assertJsonPath('heartbeats.mariadb', true)
        ->assertJsonPath('heartbeats.verify', false)
        ->assertJsonPath('ssh_key.public', 'ssh-ed25519 AAAAC3Nza growth')
        ->assertJsonPath('last_test.ok', true)
        ->assertJsonPath('last_request.message', 'Configuración guardada.')
        ->assertJsonPath('jobs.mariadb.state', 'failed')
        ->assertJsonPath('jobs.mariadb.code', 'upload_failed')
        ->assertJsonPath('jobs.mariadb.last_success.size_bytes', 52428800)
        ->assertJsonPath('jobs.clickhouse', null)
        ->assertJsonPath('jobs.verify', null);
    expect((string) $response->getContent())->not->toContain('no-debe-salir')->not->toContain('tampoco');

    // Un estado que es un symlink (no lo escribió root) no se lee.
    unlink($this->stateDir.'/backup-status.json');
    file_put_contents($this->stateDir.'/otro.json', json_encode(['configured' => true]));
    symlink($this->stateDir.'/otro.json', $this->stateDir.'/backup-status.json');
    $this->actingAs($this->admin)->getJson('/api/platform/backups')->assertJsonPath('answered', false)->assertJsonPath('configured', false);
});

it('el pedido se escribe con un temporal y un rename (sin temporales que queden a la vista)', function () {
    app(BackupState::class)->writeRequest(['id' => 'bkp_abcdef', 'action' => 'refresh']);
    expect(glob($this->stateDir.'/.tmp.*'))->toBe([])
        ->and(json_decode((string) file_get_contents($this->stateDir.'/backup-request.json'), true))->toBe(['id' => 'bkp_abcdef', 'action' => 'refresh']);
});
