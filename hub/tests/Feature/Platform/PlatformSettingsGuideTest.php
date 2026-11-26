<?php

use App\Domain\Connections\Enums\ConnectionProvider;
use App\Domain\Messaging\Support\SesEventSetup;
use App\Domain\Platform\Settings\PlatformSettingsRegistry;
use App\Domain\Platform\Settings\SettingDefinition;
use App\Domain\Platform\Settings\SetupGuideValues;

/*
 * La guía paso a paso de Plataforma → Ajustes (docs/guias-conexiones.md): lo que se copia en la consola de Meta, Google,
 * TikTok y AWS sale calculado del hub con lo mismo que usan las conexiones, y nunca trae un secreto.
 */

beforeEach(function () {
    $this->admin = $this->userWith2fa(['is_platform_admin' => true]);
    config(['app.url' => 'https://growth.imagina.cloud']);
});

function guideOf(array $json, string $section): ?array
{
    return collect($json['sections'])->firstWhere('key', $section)['info']['guide'] ?? null;
}

it('da los valores de Meta, Google y TikTok que usan las conexiones', function () {
    $json = $this->actingAs($this->admin)->getJson('/api/platform/settings')->assertOk()->json();

    $meta = guideOf($json, 'meta');
    expect($meta)->toMatchArray([
        'site_url' => 'https://growth.imagina.cloud',
        'domain' => 'growth.imagina.cloud',
        'callback_url' => ConnectionProvider::Meta->callbackUrl(),
        'webhook_url' => 'https://growth.imagina.cloud/webhooks/meta',
    ])
        ->and($meta['callback_url'])->toBe('https://growth.imagina.cloud/oauth/meta/callback')
        ->and($meta['whatsapp_fields'])->toContain('smb_message_echoes', 'account_update', 'history')
        ->and($meta['page_fields'])->toContain('leadgen')
        ->and($meta['login_permissions'])->toContain('leads_retrieval', 'pages_messaging', 'ads_management');

    $google = guideOf($json, 'google');
    expect($google['callback_url'])->toBe(ConnectionProvider::Google->callbackUrl())
        ->and($google['authorized_domain'])->toBe('imagina.cloud')
        ->and($google['scopes'])->toBe(['openid', 'email', 'https://www.googleapis.com/auth/adwords', 'https://www.googleapis.com/auth/datamanager',
            'https://www.googleapis.com/auth/analytics.edit', 'https://www.googleapis.com/auth/calendar.events', 'https://www.googleapis.com/auth/calendar.freebusy'])
        ->and($google)->not->toHaveKey('legacy_callback_url');

    expect(guideOf($json, 'tiktok'))->toBe(['callback_url' => 'https://growth.imagina.cloud/oauth/tiktok/callback'])
        ->and(guideOf($json, 'security'))->toBeNull();
});

it('los campos de Messenger e Instagram de la guía son los mismos que muestra el canal', function () {
    expect(SetupGuideValues::META_INSTAGRAM_FIELDS)->toBe(['messages', 'messaging_postbacks', 'messaging_seen', 'messaging_referral'])
        ->and(array_values(array_diff(SetupGuideValues::META_PAGE_FIELDS, ['leadgen'])))->toBe(['messages', 'messaging_postbacks', 'message_deliveries', 'message_reads', 'messaging_referrals']);
});

it('con el cliente viejo de la agenda también da su dirección de retorno', function () {
    config(['igr-bookings.google.client_id' => 'viejo.apps.googleusercontent.com', 'igr-bookings.google.client_secret' => 'secreto-viejo']);
    $json = $this->actingAs($this->admin)->getJson('/api/platform/settings')->assertOk()->json();

    expect(guideOf($json, 'google')['legacy_callback_url'])->toBe('https://growth.imagina.cloud/p/_google/calendar/callback')
        ->and(json_encode($json))->not->toContain('secreto-viejo');
});

it('arma las políticas de AWS con la región y la cuenta del tema de SNS', function () {
    config(['igr-messaging.ses.region' => 'us-east-1', 'igr-messaging.ses.sns_topic_arn' => 'arn:aws:sns:us-east-1:123456789012:igr-ses,arn:aws:sns:us-east-1:123456789012:otro',
        'igr-messaging.ses.key' => 'AKIAEJEMPLOEJEMPLO12', 'igr-messaging.ses.secret' => 'secreto-de-aws-muy-largo']);
    $json = $this->actingAs($this->admin)->getJson('/api/platform/settings')->assertOk()->json();
    $ses = guideOf($json, 'ses');
    $set = SesEventSetup::configurationSet();

    expect($ses['webhook_url'])->toBe('https://growth.imagina.cloud/webhooks/ses')
        ->and($ses['configuration_set'])->toBe($set)
        ->and($ses['policies_complete'])->toBeTrue();
    $iam = json_decode($ses['iam_policy'], true);
    expect($iam['Statement'][1]['Resource'])->toBe("arn:aws:ses:us-east-1:123456789012:configuration-set/{$set}")
        ->and($iam['Statement'][0]['Action'])->toContain('ses:SendEmail', 'ses:SendRawEmail', 'ses:CreateEmailIdentity');
    $sns = json_decode($ses['sns_policy_statement'], true);
    expect($sns['Resource'])->toBe('arn:aws:sns:us-east-1:123456789012:igr-ses')
        ->and($sns['Condition']['StringEquals'])->toBe(['AWS:SourceAccount' => '123456789012', 'AWS:SourceArn' => "arn:aws:ses:us-east-1:123456789012:configuration-set/{$set}"])
        ->and(json_encode($json))->not->toContain('secreto-de-aws-muy-largo')->not->toContain('AKIAEJEMPLOEJEMPLO12');
});

it('sin región ni tema deja marcado lo que falta', function () {
    config(['igr-messaging.ses.region' => '', 'igr-messaging.ses.sns_topic_arn' => '']);
    $ses = guideOf($this->actingAs($this->admin)->getJson('/api/platform/settings')->assertOk()->json(), 'ses');

    expect($ses['policies_complete'])->toBeFalse()
        ->and($ses['iam_policy'])->toContain('<región>')->toContain('<cuenta>')
        ->and($ses['sns_policy_statement'])->toContain('<tema>');
});

it('cada sección con credenciales de un tercero tiene su guía paso a paso en el panel', function () {
    // Las que piden una clave, un secreto o una dirección de otro servicio (no las que la plataforma genera sola).
    $sections = collect(PlatformSettingsRegistry::all())
        ->filter(fn (SettingDefinition $d) => $d->secret() && $d->generate === null)
        ->map(fn (SettingDefinition $d) => $d->section())->unique()->sort()->values()->all();
    $guides = json_decode((string) file_get_contents(resource_path('js/locales/es-CO/guides.json')), true)['guides']['platform'];

    expect($sections)->toBe(['ai', 'google', 'mail', 'meta', 'monitor', 'ses', 'tiktok'])
        ->and(array_keys($guides))->toEqualCanonicalizing($sections);
});

it('saca el dominio principal que acepta Google', function (string $host, string $top) {
    expect(SetupGuideValues::topDomain($host))->toBe($top);
})->with([
    ['growth.imagina.cloud', 'imagina.cloud'],
    ['imagina.cloud', 'imagina.cloud'],
    ['app.tienda.com.co', 'tienda.com.co'],
    ['panel.agencia.com.mx', 'agencia.com.mx'],
    ['crm.empresa.co', 'empresa.co'],
    ['a.b.example.com', 'example.com'],
    ['127.0.0.1', '127.0.0.1'],
]);
