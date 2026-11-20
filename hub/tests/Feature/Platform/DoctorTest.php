<?php

use App\Domain\Billing\Platform\InstallationDoctor;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Sites\Support\PluginRelease;
use App\Domain\Workspaces\Models\User;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/*
 * F3 y F15: igr:doctor y la pestaña «Instalación» dicen qué falta configurar y cómo; igr:admin:create crea o
 * promueve al superadmin sin tocar la base a mano; las tarifas de WhatsApp se siembran si faltan.
 */

beforeEach(function () {
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
});

function doctorJson(): array
{
    Artisan::call('igr:doctor', ['--json' => true]);

    return json_decode(trim(Artisan::output()), true);
}

function doctorCheck(array $report, string $key): ?array
{
    return collect($report['checks'])->firstWhere('key', $key);
}

it('avisa lo que falta, con el cómo, y sale con error si algo impide funcionar', function () {
    config([
        'mail.default' => 'log',
        'igr-messaging.meta.app_secret' => null,
        'igr-ai.enabled' => true,
        'igr-ai.api_key' => null,
        'broadcasting.default' => 'log',
    ]);
    // Con WhatsApp conectado, sin el secreto de Meta los webhooks responden 403: es un error.
    app(CurrentWorkspace::class)->run($this->ws, fn () => ChannelAccount::query()->create([
        'workspace_id' => $this->ws->id, 'channel' => 'whatsapp', 'provider' => 'meta_cloud', 'display_name' => 'WhatsApp',
        'status' => 'active', 'external_id' => '1111', 'config' => ['access_token' => 'EAAB'],
    ]));
    [$site] = $this->connectedSite($this->ws, 'tienda.co');
    $site->forceFill(['plugin_version' => '0.0.9'])->save();

    $this->artisan('igr:doctor')->assertFailed();
    $report = doctorJson();
    expect($report['status'])->toBe('error')
        ->and(doctorCheck($report, 'whatsapp.app_secret'))->toMatchArray(['status' => 'error'])
        ->and(doctorCheck($report, 'whatsapp.app_secret')['detail'])->toContain('IGR_META_APP_SECRET')->toContain('403')
        ->and(doctorCheck($report, 'email.mailer')['status'])->toBe('warning')
        ->and(doctorCheck($report, 'email.mailer')['detail'])->toContain('MAIL_MAILER')
        ->and(doctorCheck($report, 'ai.key')['status'])->toBe('warning')
        ->and(doctorCheck($report, 'ai.key')['detail'])->toContain('IGR_ANTHROPIC_API_KEY')
        ->and(doctorCheck($report, 'integrations.reverb')['status'])->toBe('info')
        // La migración sembró las tarifas de WhatsApp.
        ->and(doctorCheck($report, 'whatsapp.rates')['status'])->not->toBe('ok')
        ->and(DB::table('wa_rates')->count())->toBeGreaterThan(0)
        // Plugin más viejo que el mínimo: error, con el sitio en la lista.
        ->and(doctorCheck($report, 'data.plugin')['status'])->toBe('error')
        ->and(doctorCheck($report, 'data.plugin')['items'])->toBe(['tienda.co (0.0.9)'])
        // Sin superadmin.
        ->and(doctorCheck($report, 'data.admins')['status'])->toBe('error')
        ->and(doctorCheck($report, 'services.scheduler')['status'])->toBe('error');
});

it('con todo configurado dice que está bien, y nunca muestra los secretos', function () {
    config([
        'mail.default' => 'smtp', 'mail.from.address' => 'hola@growth.test',
        'igr-messaging.meta.app_secret' => 'meta-secreto-123', 'igr-messaging.meta.webhook_verify_token' => 'verifica-456',
        'igr-ai.enabled' => true, 'igr-ai.api_key' => 'sk-ant-secreta-789',
        'igr.health.token' => 'token-salud-000',
    ]);
    $this->userWith2fa(['is_platform_admin' => true]);
    Cache::put(InstallationDoctor::SCHEDULER_HEARTBEAT, now()->getTimestamp(), 3600);
    [$site] = $this->connectedSite($this->ws, 'tienda.co');
    $site->forceFill(['plugin_version' => PluginRelease::bundledVersion()])->save();

    $report = doctorJson();
    $json = json_encode($report);
    foreach (['whatsapp.app_secret', 'email.mailer', 'ai.key', 'services.scheduler', 'data.admins', 'data.plugin', 'app.health_token'] as $key) {
        expect(doctorCheck($report, $key)['status'])->toBe('ok', $key);
    }
    expect($json)->not->toContain('meta-secreto-123')->not->toContain('sk-ant-secreta-789')->not->toContain('verifica-456')->not->toContain('token-salud-000');
});

it('la pestaña Instalación es solo del superadmin con verificación en dos pasos', function () {
    $this->actingAs($this->owner)->getJson('/api/platform/doctor')->assertForbidden();
    $this->actingAs(User::factory()->create(['is_platform_admin' => true]))->getJson('/api/platform/doctor')
        ->assertForbidden()->assertJsonPath('error', 'two_factor_required');
    $admin = $this->userWith2fa(['is_platform_admin' => true]);
    $res = $this->actingAs($admin)->getJson('/api/platform/doctor')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    expect(collect($res->json('groups'))->pluck('key')->all())->toBe(['installation', 'services', 'email', 'whatsapp', 'integrations', 'data'])
        ->and($res->json('groups.0.name'))->toBe('Instalación')
        ->and($res->json('status'))->toBeIn(['ok', 'warning', 'error']);
});

it('lee la versión del plugin del zip que descargan los clientes o del código del connector', function () {
    expect(PluginRelease::parse(" * Plugin Name: Imagina Growth Connector\n * Version:           0.3.1\n"))->toBe('0.3.1')
        ->and(PluginRelease::parse('sin cabecera'))->toBeNull()
        ->and(PluginRelease::older('0.1.2', '0.1.10'))->toBeTrue()
        ->and(PluginRelease::older('0.2.0', '0.1.10'))->toBeFalse()
        ->and(PluginRelease::older(null, '0.1.0'))->toBeTrue();
    $zip = sys_get_temp_dir().'/igr-plugin-'.bin2hex(random_bytes(4)).'.zip';
    $archive = new ZipArchive;
    $archive->open($zip, ZipArchive::CREATE);
    $archive->addFromString('imagina-growth-connector/imagina-growth-connector.php', "<?php\n/**\n * Version: 9.8.7\n */");
    $archive->close();
    expect(PluginRelease::bundledVersion($zip))->toBe('9.8.7')
        ->and(PluginRelease::bundledVersion('/no/existe.zip'))->toBe(PluginRelease::parse((string) file_get_contents(base_path('../connector/imagina-growth-connector.php'))));
    unlink($zip);
});

it('igr:admin:create crea un superadmin con contraseña generada y le da el plan de la plataforma a sus cuentas', function () {
    $this->artisan('igr:admin:create', ['email' => 'Admin@Imagina.test', '--name' => 'Admin', '--generate' => true])
        ->expectsOutputToContain('Contraseña (se muestra una sola vez)')->assertSuccessful();
    $user = User::query()->where('email', 'admin@imagina.test')->firstOrFail();
    expect($user->is_platform_admin)->toBeTrue()->and($user->email_verified_at)->not->toBeNull();
    // La contraseña generada se muestra una sola vez en la consola y es la que quedó guardada (con hash).
    Artisan::call('igr:admin:create', ['email' => 'otra@imagina.test', '--generate' => true, '--no-interaction' => true]);
    preg_match('/: ([A-Za-z0-9]{20})\s*$/m', Artisan::output(), $m);
    $other = User::query()->where('email', 'otra@imagina.test')->firstOrFail();
    expect($m)->toHaveKey(1)->and(Hash::check($m[1], $other->password))->toBeTrue()
        ->and(DB::table('users')->where('email', 'otra@imagina.test')->value('password'))->not->toBe($m[1]);

    // Promover a alguien que ya existe no cambia su contraseña y su cuenta sin plan pasa al plan de la plataforma.
    $hash = DB::table('users')->where('id', $this->owner->id)->value('password');
    $this->artisan('igr:admin:create', ['email' => $this->owner->email])->assertSuccessful();
    expect($this->owner->refresh()->is_platform_admin)->toBeTrue()
        ->and(DB::table('users')->where('id', $this->owner->id)->value('password'))->toBe($hash)
        ->and(DB::table('accounts')->where('id', $this->ws->account_id)->value('plan_id'))->toBe('plataforma')
        ->and(DB::table('audit_logs')->where('action', 'platform.admin_promoted')->exists())->toBeTrue();
});

it('igr:admin:create pide la contraseña dos veces sin mostrarla y rechaza lo inválido', function () {
    $this->artisan('igr:admin:create', ['email' => 'no-es-correo'])->assertFailed();
    $this->artisan('igr:admin:create', ['email' => 'nueva@imagina.test', '--no-interaction' => true])->assertFailed();
    expect(User::query()->where('email', 'nueva@imagina.test')->exists())->toBeFalse();

    $this->artisan('igr:admin:create', ['email' => 'nueva@imagina.test'])
        ->expectsQuestion('Contraseña', 'Clave-Muy-Segura-2026')
        ->expectsQuestion('Repite la contraseña', 'Clave-Muy-Segura-2026')
        ->expectsQuestion('Nombre', 'Nueva Admin')
        ->assertSuccessful();
    $user = User::query()->where('email', 'nueva@imagina.test')->firstOrFail();
    expect($user->name)->toBe('Nueva Admin')->and(Hash::check('Clave-Muy-Segura-2026', $user->password))->toBeTrue()->and($user->is_platform_admin)->toBeTrue();
});
