<?php

declare(strict_types=1);

namespace App\Domain\Platform\Settings;

use App\Domain\Bookings\Google\GoogleCalendar;
use App\Domain\Connections\Enums\ConnectionProvider;
use App\Domain\Messaging\Support\SesEventSetup;

/**
 * Lo que la guía paso a paso de cada sección de Plataforma → Ajustes muestra para copiar (docs/guias-conexiones.md): las
 * direcciones, dominios, alcances, campos del webhook y políticas que hay que registrar en la consola de cada proveedor,
 * calculados con lo mismo que usa el hub (las URL de retorno de `ConnectionProvider`, los alcances de
 * `igr-connections.google.scopes`, el configuration set de `SesEventSetup`…). Nunca un secreto.
 */
final class SetupGuideValues
{
    /** Campos del webhook de WhatsApp que lee `MetaCloudDriver` (los de coexistencia incluidos). Se suscriben en la app. */
    public const META_WHATSAPP_FIELDS = ['messages', 'message_template_status_update', 'phone_number_quality_update', 'account_update', 'history', 'smb_app_state_sync', 'smb_message_echoes'];

    /** Objeto `page`: Messenger (`MetaMessengerDriver`) y los clientes potenciales de Meta Lead Ads (`leadgen`). */
    public const META_PAGE_FIELDS = ['messages', 'messaging_postbacks', 'message_deliveries', 'message_reads', 'messaging_referrals', 'leadgen'];

    /** Objeto `instagram`: Instagram Direct (`MetaInstagramDriver`). */
    public const META_INSTAGRAM_FIELDS = ['messages', 'messaging_postbacks', 'messaging_seen', 'messaging_referral'];

    /** Permisos de la configuración «WhatsApp con un clic» (Embedded Signup). */
    public const META_WHATSAPP_PERMISSIONS = ['whatsapp_business_management', 'whatsapp_business_messaging'];

    /** Permisos de la configuración «Anuncios y mensajes» (los que revisan `ConnectionPresenter` y `MetaLeadAds`). */
    public const META_LOGIN_PERMISSIONS = [
        'ads_management', 'ads_read', 'business_management', 'pages_show_list', 'pages_manage_metadata', 'pages_messaging',
        'instagram_basic', 'instagram_manage_messages', 'leads_retrieval', 'pages_read_engagement', 'pages_manage_ads',
    ];

    /** Segundos dominios de país donde el dominio principal tiene tres partes (empresa.com.co). */
    private const SECOND_LEVEL = ['com', 'net', 'org', 'gov', 'edu', 'co', 'ac', 'gob', 'nom', 'mil', 'info', 'web'];

    /**
     * @return array<string, mixed> vacío si la sección no tiene guía con valores
     */
    public function for(string $section): array
    {
        $panel = rtrim((string) config('app.url'), '/');
        $host = (string) parse_url($panel, PHP_URL_HOST);

        return match ($section) {
            'meta' => [
                'site_url' => $panel,
                'domain' => $host,
                'callback_url' => ConnectionProvider::Meta->callbackUrl(),
                'webhook_url' => $panel.'/webhooks/meta',
                'whatsapp_fields' => self::META_WHATSAPP_FIELDS,
                'page_fields' => self::META_PAGE_FIELDS,
                'instagram_fields' => self::META_INSTAGRAM_FIELDS,
                'whatsapp_permissions' => self::META_WHATSAPP_PERMISSIONS,
                'login_permissions' => self::META_LOGIN_PERMISSIONS,
            ],
            'google' => array_filter([
                'site_url' => $panel,
                'callback_url' => ConnectionProvider::Google->callbackUrl(),
                'authorized_domain' => self::topDomain($host),
                'scopes' => self::googleScopes(),
                'legacy_callback_url' => GoogleCalendar::legacyConfigured() ? GoogleCalendar::redirect() : null,
            ], fn ($v) => $v !== null && $v !== ''),
            'tiktok' => ['callback_url' => ConnectionProvider::TikTok->callbackUrl()],
            'ses' => $this->ses($panel),
            default => [],
        };
    }

    /**
     * El dominio principal de un host («growth.imagina.cloud» → «imagina.cloud», «app.tienda.com.co» → «tienda.com.co»):
     * Google solo acepta ese en los dominios autorizados de la pantalla de consentimiento.
     */
    public static function topDomain(string $host): string
    {
        $host = mb_strtolower(trim($host, '.'));
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }
        $labels = explode('.', $host);
        $count = count($labels);
        if ($count <= 2) {
            return $host;
        }
        $take = mb_strlen($labels[$count - 1]) === 2 && in_array($labels[$count - 2], self::SECOND_LEVEL, true) ? 3 : 2;

        return implode('.', array_slice($labels, -$take));
    }

    /** @return list<string> los alcances que pide el hub, sin repetir y en el orden de la configuración */
    public static function googleScopes(): array
    {
        $out = [];
        foreach ((array) config('igr-connections.google.scopes', []) as $group) {
            foreach ((array) $group as $scope) {
                if (is_string($scope) && $scope !== '' && ! in_array($scope, $out, true)) {
                    $out[] = $scope;
                }
            }
        }

        return $out;
    }

    /**
     * La política de IAM del usuario de la clave y la del tema de SNS, con la región, la cuenta (del ARN del tema) y el
     * configuration set ya puestos; lo que todavía no se sabe queda como `<región>` o `<cuenta>`.
     *
     * @return array<string, mixed>
     */
    private function ses(string $panel): array
    {
        $set = SesEventSetup::configurationSet();
        $region = (string) config('igr-messaging.ses.region');
        $topic = trim(explode(',', (string) config('igr-messaging.ses.sns_topic_arn'))[0]);
        $account = preg_match('/^arn:aws[a-z-]*:sns:[a-z0-9-]+:(\d{12}):/', $topic, $m) === 1 ? $m[1] : '';
        $partition = preg_match('/^arn:(aws[a-z-]*):/', $topic, $p) === 1 ? $p[1] : 'aws';
        $regionText = $region !== '' ? $region : '<región>';
        $accountText = $account !== '' ? $account : '<cuenta>';
        $setArn = "arn:{$partition}:ses:{$regionText}:{$accountText}:configuration-set/{$set}";

        $iam = [
            'Version' => '2012-10-17',
            'Statement' => [
                [
                    'Sid' => 'IgrEnviarYVerificarDominios',
                    'Effect' => 'Allow',
                    'Action' => ['ses:SendEmail', 'ses:SendRawEmail', 'ses:CreateEmailIdentity', 'ses:GetEmailIdentity'],
                    'Resource' => '*',
                ],
                [
                    'Sid' => 'IgrEventosDeEntrega',
                    'Effect' => 'Allow',
                    'Action' => [
                        'ses:CreateConfigurationSet', 'ses:GetConfigurationSet', 'ses:GetConfigurationSetEventDestinations',
                        'ses:CreateConfigurationSetEventDestination', 'ses:UpdateConfigurationSetEventDestination',
                    ],
                    'Resource' => $setArn,
                ],
            ],
        ];
        $sns = [
            'Sid' => 'IgrPermitirQueSesPublique',
            'Effect' => 'Allow',
            'Principal' => ['Service' => 'ses.amazonaws.com'],
            'Action' => 'sns:Publish',
            'Resource' => $topic !== '' ? $topic : "arn:{$partition}:sns:{$regionText}:{$accountText}:<tema>",
            'Condition' => ['StringEquals' => ['AWS:SourceAccount' => $accountText, 'AWS:SourceArn' => $setArn]],
        ];
        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

        return [
            'webhook_url' => $panel.'/webhooks/ses',
            'configuration_set' => $set,
            'iam_policy' => (string) json_encode($iam, $flags),
            'sns_policy_statement' => (string) json_encode($sns, $flags),
            'policies_complete' => $region !== '' && $account !== '',
        ];
    }
}
