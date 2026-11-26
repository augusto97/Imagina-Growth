<?php

use App\Domain\Billing\Contracts\Entitlements;
use App\Domain\Intelligence\Ai\AnthropicAiGateway;
use App\Domain\Intelligence\Ai\Contracts\AiGateway;
use App\Domain\Platform\Settings\PlatformSettings;
use App\Domain\Platform\Settings\PlatformSettingsRegistry;
use App\Domain\Platform\Settings\PlatformSettingsRuntime;
use App\Domain\Workspaces\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/*
 * F17: lo que antes se configuraba en el .env del servidor se guarda en Plataforma → Ajustes. El valor del panel manda,
 * vacío vuelve al .env; los secretos van cifrados y nunca vuelven a la SPA; cada cambio queda en la auditoría sin
 * valores y llega en caliente a las peticiones y los trabajos siguientes, también de otros procesos.
 */

beforeEach(function () {
    $this->admin = $this->userWith2fa(['is_platform_admin' => true]);
    $this->runtime = app(PlatformSettingsRuntime::class);
});

/** Lo que haría otro proceso (el panel en otra petición): guarda en la base sin tocar la configuración de este. */
function saveFromAnotherProcess(array $values): void
{
    foreach ($values as $key => $value) {
        $secret = PlatformSettingsRegistry::find($key)?->secret() ?? false;
        $json = json_encode($value);
        DB::table('platform_settings')->updateOrInsert(['key' => $key], [
            'value' => $secret ? Crypt::encryptString($json) : $json, 'encrypted' => $secret, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    Cache::forget(PlatformSettings::VERSION_KEY);
    Cache::forget(PlatformSettings::ROWS_KEY);
}

function settingsField(array $json, string $section, string $name): array
{
    $fields = collect($json['sections'])->firstWhere('key', $section)['fields'];

    return collect($fields)->firstWhere('key', $name);
}

it('guarda los secretos cifrados con la llave de la app y los aplica en el momento', function () {
    $res = $this->actingAs($this->admin)->putJson('/api/platform/settings/mail', ['values' => [
        'driver' => 'smtp', 'smtp_host' => 'smtp.proveedor.test', 'smtp_port' => 587, 'smtp_encryption' => 'starttls',
        'smtp_username' => 'growth', 'smtp_password' => 'Clave-SMTP-Muy-Larga-2026', 'from_address' => 'Hola@Growth.Test', 'from_name' => 'Imagina Growth',
    ]])->assertOk();

    $row = DB::table('platform_settings')->where('key', 'mail.smtp_password')->first();
    expect((bool) $row->encrypted)->toBeTrue()
        ->and($row->value)->not->toContain('Clave-SMTP')
        ->and(json_decode(Crypt::decryptString($row->value), true))->toBe('Clave-SMTP-Muy-Larga-2026')
        ->and(DB::table('platform_settings')->where('key', 'mail.smtp_host')->value('value'))->toBe('"smtp.proveedor.test"')
        // Aplicado en este proceso: el correo sale con lo del panel.
        ->and(config('mail.default'))->toBe('smtp')
        ->and(config('mail.mailers.smtp.host'))->toBe('smtp.proveedor.test')
        ->and(config('mail.mailers.smtp.password'))->toBe('Clave-SMTP-Muy-Larga-2026')
        ->and(config('mail.mailers.smtp.scheme'))->toBe('smtp')
        ->and(config('mail.mailers.smtp.require_tls'))->toBeTrue()
        ->and(config('mail.from.address'))->toBe('hola@growth.test')
        ->and(app(PlatformSettings::class)->values())->toMatchArray(['mail.smtp_password' => 'Clave-SMTP-Muy-Larga-2026', 'mail.smtp_port' => 587])
        ->and($res->json('changed.set'))->toContain('mail.smtp_password')
        ->and(settingsField($res->json(), 'mail', 'smtp_password'))->toMatchArray(['value' => null, 'configured' => true, 'source' => 'panel', 'hint' => '…2026']);

    // SSL directo: smtps, sin exigir STARTTLS.
    $this->actingAs($this->admin)->putJson('/api/platform/settings/mail', ['values' => ['smtp_encryption' => 'ssl', 'smtp_port' => 465]])->assertOk();
    expect(config('mail.mailers.smtp.scheme'))->toBe('smtps')->and(config('mail.mailers.smtp.require_tls'))->toBeFalse();

    // Cifrado que no va con el puerto: no se guarda y se dice cuál elegir (el SSL/TLS en el 2525 falla con «wrong version number»).
    $this->actingAs($this->admin)->putJson('/api/platform/settings/mail', ['values' => ['smtp_port' => 2525]])
        ->assertUnprocessable()->assertJsonPath('errors', fn (array $e) => str_contains($e['values.smtp_encryption'][0], 'STARTTLS'));
    $this->actingAs($this->admin)->putJson('/api/platform/settings/mail', ['values' => ['smtp_encryption' => 'starttls']])->assertUnprocessable();
    $this->actingAs($this->admin)->putJson('/api/platform/settings/mail', ['values' => ['smtp_port' => 2525, 'smtp_encryption' => 'starttls']])->assertOk();
    expect(config('mail.mailers.smtp.scheme'))->toBe('smtp')->and((int) config('mail.mailers.smtp.port'))->toBe(2525);
});

it('el valor del panel manda sobre el .env y vacío vuelve al .env (también con la configuración en caché)', function () {
    // Lo que trae el .env (o bootstrap/cache/config.php): el respaldo se toma de config(), nunca de env().
    config(['igr-ai.api_key' => 'sk-ant-del-env-0000000000', 'mail.default' => 'log', 'igr.registration_open' => false, 'igr-monitor.max_stream_lag' => 5000]);
    $this->runtime->rebase();
    $this->runtime->refresh();

    $this->actingAs($this->admin)->putJson('/api/platform/settings/ai', ['values' => ['api_key' => 'sk-ant-del-panel-1111111111']])->assertOk();
    $this->actingAs($this->admin)->putJson('/api/platform/settings/security', ['values' => ['open_registration' => true]])->assertOk();
    $this->actingAs($this->admin)->putJson('/api/platform/settings/monitor', ['values' => ['max_stream_lag' => 9000]])->assertOk();
    expect(config('igr-ai.api_key'))->toBe('sk-ant-del-panel-1111111111')
        ->and(config('igr.registration_open'))->toBeTrue()
        ->and(config('igr-monitor.max_stream_lag'))->toBe(9000);

    $res = $this->actingAs($this->admin)->getJson('/api/platform/settings')->assertOk();
    expect(settingsField($res->json(), 'ai', 'api_key'))->toMatchArray(['source' => 'panel', 'hint' => '…1111'])
        ->and(settingsField($res->json(), 'monitor', 'max_stream_lag'))->toMatchArray(['value' => 9000, 'fallback' => 5000, 'source' => 'panel'])
        ->and(settingsField($res->json(), 'mail', 'driver'))->toMatchArray(['value' => null, 'fallback' => 'log', 'source' => 'server']);

    // Vacío (o null): vuelve a lo del .env.
    $res = $this->actingAs($this->admin)->putJson('/api/platform/settings/ai', ['values' => ['api_key' => '']])->assertOk();
    $this->actingAs($this->admin)->putJson('/api/platform/settings/security', ['values' => ['open_registration' => null]])->assertOk();
    $this->actingAs($this->admin)->putJson('/api/platform/settings/monitor', ['values' => ['max_stream_lag' => null]])->assertOk();
    expect(config('igr-ai.api_key'))->toBe('sk-ant-del-env-0000000000')
        ->and(config('igr.registration_open'))->toBeFalse()
        ->and(config('igr-monitor.max_stream_lag'))->toBe(5000)
        ->and($res->json('changed.cleared'))->toBe(['ai.api_key'])
        ->and(settingsField($res->json(), 'ai', 'api_key'))->toMatchArray(['source' => 'server', 'configured' => true, 'hint' => '…0000'])
        ->and(DB::table('platform_settings')->whereIn('key', ['ai.api_key', 'security.open_registration', 'monitor.max_stream_lag'])->count())->toBe(0);
});

it('nunca devuelve los secretos: solo si están y, si son largos, sus últimos 4 caracteres', function () {
    $secrets = [
        'ses' => ['key' => 'AKIAEJEMPLO1234567890', 'secret' => 'aws-secreto-muy-largo-abcdefghij'],
        'meta' => ['app_secret' => 'meta-secreto-de-la-app-9876', 'webhook_verify_token' => 'verifica-meta-123456'],
        'google' => ['client_secret' => 'GOCSPX-secreto-google-xyz'],
        'tiktok' => ['app_secret' => 'corto-tt'],
        'monitor' => ['heartbeat_health' => 'https://uptime.betterstack.test/api/v1/heartbeat/tokenlatido1234'],
    ];
    foreach ($secrets as $section => $values) {
        $res = $this->actingAs($this->admin)->putJson("/api/platform/settings/{$section}", ['values' => $values])->assertOk();
        foreach ($values as $value) {
            expect($res->getContent())->not->toContain($value);
        }
    }
    $json = $this->actingAs($this->admin)->getJson('/api/platform/settings')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    foreach ($secrets as $values) {
        foreach ($values as $value) {
            expect($json->getContent())->not->toContain($value);
        }
    }
    expect(settingsField($json->json(), 'tiktok', 'app_secret'))->toMatchArray(['value' => null, 'fallback' => null, 'configured' => true, 'hint' => null])
        ->and(settingsField($json->json(), 'ses', 'secret'))->toMatchArray(['value' => null, 'hint' => '…ghij'])
        ->and(DB::table('platform_settings')->where('encrypted', true)->count())->toBe(7)
        // Ni la auditoría ni los registros llevan valores.
        ->and(DB::table('audit_logs')->where('action', 'platform.settings_updated')->pluck('changes')->implode(' '))
        ->not->toContain('aws-secreto')->not->toContain('meta-secreto')->not->toContain('corto-tt')->not->toContain('tokenlatido');
});

it('cada cambio queda en la auditoría con las claves, nunca con los valores', function () {
    $this->actingAs($this->admin)->putJson('/api/platform/settings/billing', ['values' => [
        'platform_email' => 'facturas@imagina.test', 'invoice_prefix' => 'IMG', 'payment_url' => 'https://pagos.imagina.test/?ref={number}&v={total}',
    ]])->assertOk();
    $this->actingAs($this->admin)->putJson('/api/platform/settings/billing', ['values' => ['invoice_prefix' => null]])->assertOk();
    // Guardar lo mismo no cambia nada ni deja auditoría.
    $this->actingAs($this->admin)->putJson('/api/platform/settings/billing', ['values' => ['platform_email' => 'facturas@imagina.test']])->assertOk()->assertJsonPath('changed.set', []);

    $logs = DB::table('audit_logs')->where('action', 'platform.settings_updated')->orderBy('id')->get();
    expect($logs)->toHaveCount(2)
        ->and(json_decode($logs[0]->changes, true))->toBe(['set' => ['billing.platform_email', 'billing.invoice_prefix', 'billing.payment_url'], 'cleared' => []])
        ->and(json_decode($logs[1]->changes, true))->toBe(['set' => [], 'cleared' => ['billing.invoice_prefix']])
        ->and($logs[0]->user_id)->toBe($this->admin->id)
        ->and($logs->pluck('changes')->implode(' '))->not->toContain('facturas@')->not->toContain('pagos.imagina');
    expect(config('igr-plans.invoices.platform_email'))->toBe('facturas@imagina.test')
        ->and(config('igr-plans.invoices.manual_payment_url'))->toBe('https://pagos.imagina.test/?ref={number}&v={total}');
});

it('valida cada campo y no acepta campos ni secciones que no existen', function () {
    $this->actingAs($this->admin)->putJson('/api/platform/settings/mail', ['values' => ['from_address' => 'no-es-correo', 'smtp_port' => 70000, 'driver' => 'sendmail']])
        ->assertUnprocessable()->assertJsonValidationErrors(['values.from_address', 'values.smtp_port', 'values.driver']);
    $this->actingAs($this->admin)->putJson('/api/platform/settings/ses', ['values' => ['sns_topic_arn' => 'arn:aws:sqs:us-east-1:123456789012:cola', 'region' => 'Virginia']])
        ->assertUnprocessable()->assertJsonValidationErrors(['values.sns_topic_arn', 'values.region']);
    $this->actingAs($this->admin)->putJson('/api/platform/settings/retention', ['values' => ['messages_days' => 30]])->assertUnprocessable();
    $this->actingAs($this->admin)->putJson('/api/platform/settings/security', ['values' => ['default_plan' => 'none']])->assertUnprocessable();
    $this->actingAs($this->admin)->putJson('/api/platform/settings/billing', ['values' => ['payment_url' => 'http://inseguro.test/{number}']])->assertUnprocessable();
    $this->actingAs($this->admin)->putJson('/api/platform/settings/mail', ['values' => ['app_key' => 'x']])->assertUnprocessable();
    $this->actingAs($this->admin)->putJson('/api/platform/settings/database', ['values' => ['host' => 'x']])->assertNotFound();
    // Lo que el hub va a llamar (heartbeats, tasas) nunca apunta a la red interna.
    config(['igr.webhooks.allow_private_urls' => false]);
    $this->actingAs($this->admin)->putJson('/api/platform/settings/monitor', ['values' => ['heartbeat_health' => 'https://10.0.0.5/latido']])
        ->assertUnprocessable()->assertJsonValidationErrors('values.heartbeat_health');
    $this->actingAs($this->admin)->putJson('/api/platform/settings/fx', ['values' => ['url' => 'https://localhost/tasas']])->assertUnprocessable();
    expect(DB::table('platform_settings')->count())->toBe(0);

    // Varios temas de SNS separados por comas.
    $this->actingAs($this->admin)->putJson('/api/platform/settings/ses', ['values' => ['sns_topic_arn' => 'arn:aws:sns:us-east-1:123456789012:igr-ses, arn:aws:sns:us-east-1:123456789012:viejo', 'region' => 'us-east-1']])->assertOk();
    expect(config('igr-messaging.ses.region'))->toBe('us-east-1')->and(config('services.ses.region'))->toBe('us-east-1');
});

it('solo el superadmin con verificación en dos pasos, aunque la instalación no la exija', function () {
    config(['igr.require_two_factor' => false]);
    $owner = $this->userWith2fa();
    $this->workspaceFor($owner);
    $urls = ['/api/platform/settings', '/api/platform/holidays', '/api/platform/wa-rates', '/api/platform/admins'];
    foreach ($urls as $url) {
        $this->getJson($url)->assertUnauthorized();
    }
    foreach ($urls as $url) {
        $this->actingAs($owner)->getJson($url)->assertForbidden();
    }
    $this->actingAs($owner)->putJson('/api/platform/settings/ai', ['values' => ['api_key' => 'sk-ant-intruso-000000000']])->assertForbidden();
    $noTwoFactor = User::factory()->create(['is_platform_admin' => true]);
    $this->actingAs($noTwoFactor)->getJson('/api/platform/settings')->assertForbidden()->assertJsonPath('error', 'two_factor_required');
    $this->actingAs($noTwoFactor)->putJson('/api/platform/settings/security', ['values' => ['require_2fa' => false]])->assertForbidden();
    expect(DB::table('platform_settings')->count())->toBe(0);
    $this->actingAs($this->admin)->getJson('/api/platform/settings')->assertOk()
        ->assertJsonPath('sections.0.key', 'mail');
});

it('una petición posterior ve el valor que guardó otro proceso (Meta y correo)', function () {
    config(['igr-messaging.meta.webhook_verify_token' => 'token-viejo-del-env', 'mail.default' => 'log']);
    $this->runtime->rebase();
    $this->runtime->refresh();
    $this->get('/webhooks/meta?hub_mode=subscribe&hub_verify_token=token-nuevo-del-panel&hub_challenge=42')->assertForbidden();

    saveFromAnotherProcess(['meta.webhook_verify_token' => 'token-nuevo-del-panel', 'mail.driver' => 'smtp', 'mail.from_address' => 'avisos@growth.test']);
    // Este proceso todavía no lo aplicó: lo hace la petición siguiente.
    expect(config('igr-messaging.meta.webhook_verify_token'))->toBe('token-viejo-del-env');
    $this->get('/webhooks/meta?hub_mode=subscribe&hub_verify_token=token-nuevo-del-panel&hub_challenge=42')->assertOk()->assertSee('42');
    $this->actingAs($this->admin)->getJson('/api/platform/mail')->assertOk()
        ->assertJsonPath('configured', true)->assertJsonPath('mailer', 'smtp')->assertJsonPath('from_address', 'avisos@growth.test');
});

it('un trabajo posterior ve el valor nuevo: olvida los mailers y el cliente de la IA ya creados', function () {
    $ws = $this->workspaceFor($this->userWith2fa());
    config(['mail.mailers.smtp.host' => 'smtp.viejo.test', 'mail.mailers.smtp.port' => 587, 'igr-ai.enabled' => true, 'igr-ai.api_key' => null]);
    $this->runtime->rebase();
    $this->runtime->refresh();
    $oldHost = app('mail.manager')->mailer('smtp')->getSymfonyTransport()->getStream()->getHost();
    $gateway = app(AiGateway::class);
    expect($oldHost)->toBe('smtp.viejo.test')->and($gateway)->toBeInstanceOf(AnthropicAiGateway::class)->and($gateway->available($ws->id))->toBeFalse();

    saveFromAnotherProcess(['mail.smtp_host' => 'smtp.nuevo.test', 'ai.api_key' => 'sk-ant-nueva-desde-el-panel-01', 'meta.app_secret' => 'meta-secreto-nuevo-del-panel']);
    // El trabajo corre en la cola (sync en las pruebas: el mismo proceso, con JobProcessing antes de cada uno).
    $wsId = $ws->id;
    dispatch(function () use ($wsId) {
        $GLOBALS['igr_settings_job'] = [
            'host' => app('mail.manager')->mailer('smtp')->getSymfonyTransport()->getStream()->getHost(),
            'key' => config('igr-ai.api_key'),
            'meta' => [config('igr-messaging.meta.app_secret'), config('igr-connections.meta.app_secret')],
            'gateway' => spl_object_id(app(AiGateway::class)),
            'available' => app(AiGateway::class)->available($wsId),
        ];
    });
    $seen = $GLOBALS['igr_settings_job'] ?? [];
    unset($GLOBALS['igr_settings_job']);
    expect($seen['host'] ?? null)->toBe('smtp.nuevo.test')
        ->and($seen['key'])->toBe('sk-ant-nueva-desde-el-panel-01')
        ->and($seen['meta'])->toBe(['meta-secreto-nuevo-del-panel', 'meta-secreto-nuevo-del-panel'])
        ->and($seen['gateway'])->not->toBe(spl_object_id($gateway))
        ->and($seen['available'])->toBeTrue();
});

it('funciona sin la tabla (instalación, migraciones) y config:cache no guarda los ajustes del panel', function () {
    $this->actingAs($this->admin)->putJson('/api/platform/settings/ai', ['values' => ['api_key' => 'sk-ant-del-panel-2222222222']])->assertOk();
    expect(config('igr-ai.api_key'))->toBe('sk-ant-del-panel-2222222222');

    // Una base sin la tabla: no hay ajustes, nada falla y rige lo de antes.
    config(['database.connections.igr_vacia' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    $default = DB::getDefaultConnection();
    DB::setDefaultConnection('igr_vacia');
    try {
        Cache::forget(PlatformSettings::VERSION_KEY);
        Cache::forget(PlatformSettings::ROWS_KEY);
        $store = new PlatformSettings;
        $fresh = new PlatformSettingsRuntime(app(), $store);
        expect($store->snapshot())->toBeNull()
            ->and($store->version())->toBeNull()
            ->and($store->values())->toBe([])
            ->and($fresh->refresh())->toBeFalse()
            ->and($fresh->refreshIfStale())->toBeFalse();
        $fresh->boot();
    } finally {
        DB::setDefaultConnection($default);
    }

    // `config:cache` arma la configuración en un proceso nuevo: ahí no se aplican los ajustes (quedarían en disco).
    $argv = $_SERVER['argv'];
    $_SERVER['argv'] = ['artisan', 'config:cache'];
    try {
        config(['igr-ai.api_key' => 'sk-ant-del-env-3333333333']);
        $cacheRuntime = new PlatformSettingsRuntime(app(), app(PlatformSettings::class));
        $cacheRuntime->boot();
        expect(config('igr-ai.api_key'))->toBe('sk-ant-del-env-3333333333')->and($cacheRuntime->refreshIfStale())->toBeFalse();
    } finally {
        $_SERVER['argv'] = $argv;
    }
});

it('genera las llaves de los avisos push sin mostrar la privada', function () {
    $res = $this->actingAs($this->admin)->postJson('/api/platform/settings/push/vapid')->assertOk();
    $public = settingsField($res->json(), 'push', 'vapid_public_key');
    $private = settingsField($res->json(), 'push', 'vapid_private_key');
    expect($public['value'])->toBeString()->toMatch('/^[A-Za-z0-9_-]{80,100}$/')
        ->and($private)->toMatchArray(['value' => null, 'configured' => true, 'source' => 'panel'])
        ->and(config('services.webpush.public_key'))->toBe($public['value'])
        ->and(config('services.webpush.private_key'))->toMatch('/^[A-Za-z0-9_-]{40,50}$/')
        ->and($res->getContent())->not->toContain((string) config('services.webpush.private_key'))
        ->and(res_json_section($res->json(), 'push')['info']['configured'])->toBeTrue()
        ->and(DB::table('audit_logs')->where('action', 'platform.settings_generated')->value('changes'))->toContain('push.vapid_private_key');
});

it('prepara el SES de la plataforma desde el panel y avisa si faltan credenciales', function () {
    config(['igr-messaging.ses.key' => null, 'igr-messaging.ses.secret' => null]);
    $res = $this->actingAs($this->admin)->postJson('/api/platform/settings/ses/setup')->assertOk();
    expect($res->json('result.status'))->toBe('error')->and($res->json('result.message'))->toContain('Plataforma → Ajustes → Amazon SES')
        ->and(DB::table('audit_logs')->where('action', 'platform.ses_setup')->exists())->toBeTrue();

    // El correo de la plataforma por SES sin credenciales: Instalación lo marca y lleva a la sección.
    $this->actingAs($this->admin)->putJson('/api/platform/settings/mail', ['values' => ['driver' => 'ses']])->assertOk();
    $checks = collect($this->actingAs($this->admin)->getJson('/api/platform/doctor')->assertOk()->json('groups'))->flatMap(fn ($g) => $g['checks']);
    expect($checks->firstWhere('key', 'email.mailer_ses'))->toMatchArray(['status' => 'error', 'link' => 'pestana=ajustes&seccion=ses']);
});

it('otro plan por defecto desde el panel vuelve a calcular los módulos de las cuentas sin plan', function () {
    $owner = $this->userWith2fa();
    $ws = $this->workspaceFor($owner);
    $this->actingAs($this->admin)->putJson('/api/platform/settings/security', ['values' => ['default_plan' => 'diagnostico']])->assertOk();
    expect(config('igr-plans.default_plan'))->toBe('diagnostico')
        ->and(collect(settingsField($this->actingAs($this->admin)->getJson('/api/platform/settings')->json(), 'security', 'default_plan')['options']))->toContain('diagnostico')->not->toContain('none')
        ->and(app(Entitlements::class)->allows($ws, 'loyalty'))->toBeFalse();
});

function res_json_section(array $json, string $section): array
{
    return collect($json['sections'])->firstWhere('key', $section);
}

it('cada ajuste del registro alimenta claves de config que existen y tiene su nombre y su ayuda en el panel', function () {
    $texts = json_decode((string) file_get_contents(resource_path('js/locales/es-CO/platform-settings.json')), true)['platformSettings'];
    $missing = [];
    foreach (PlatformSettingsRegistry::all() as $key => $definition) {
        expect(PlatformSettingsRegistry::SECTIONS)->toContain($definition->section());
        foreach ($definition->targets as $target) {
            // STARTTLS obligatorio y la negociación automática no vienen en config/mail.php: los pone el panel.
            if (! in_array($target, ['mail.mailers.smtp.require_tls', 'mail.mailers.smtp.auto_tls'], true) && ! config()->has($target)) {
                $missing[] = "config {$target} ({$key})";
            }
        }
        foreach (['label', 'help'] as $part) {
            if (! is_string(data_get($texts, "fields.{$definition->section()}.{$definition->name()}.{$part}"))) {
                $missing[] = "texto {$key}.{$part}";
            }
        }
        // El respaldo: la variable del .env que la configuración lee hoy.
        expect($definition->env)->toBeString()->not->toBe('');
    }
    foreach (PlatformSettingsRegistry::SECTIONS as $section) {
        expect(PlatformSettingsRegistry::section($section))->not->toBeEmpty()
            ->and(data_get($texts, "sections.{$section}.title"))->toBeString();
    }
    expect($missing)->toBe([]);
});
