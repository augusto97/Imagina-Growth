<?php

declare(strict_types=1);

namespace App\Domain\Platform\Settings;

use App\Domain\Billing\Models\Plan;
use App\Support\Net\SafeUrl;
use Closure;

/**
 * Registro de los ajustes de la plataforma que se editan en Plataforma → Ajustes (F17). Cada uno dice qué valores de
 * `config()` alimenta y de qué variable del .env sale cuando el panel no tiene valor (el respaldo).
 *
 * Quedan fuera, a propósito, los del arranque que escribe el instalador y nadie debe tocar: APP_KEY y la llave del
 * índice ciego, las bases de datos, Redis, ClickHouse, Reverb, la sesión, APP_URL, el perfil de procesos y el token del
 * servicio de predicciones. También los dominios de ingesta y de enlaces (`IGR_INGEST_URL`, `IGR_LINKS_URL`,
 * `IGR_CUSTOM_DOMAINS_CNAME`): cambiarlos sin su DNS y su bloque de Caddy deja a los sitios sin enviar datos y a los
 * enlaces sin abrir. Y las versiones de las API de Meta, Google y TikTok, que vienen fijadas en cada versión.
 */
final class PlatformSettingsRegistry
{
    /** Orden de las secciones en el panel. */
    public const SECTIONS = ['mail', 'ses', 'meta', 'google', 'tiktok', 'ai', 'security', 'push', 'billing', 'monitor', 'retention', 'fx'];

    private const HOST = '/^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9-]{0,62}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,62}[A-Za-z0-9])?)*$/';

    private const DIGITS = '/^\d{5,30}$/';

    /** @var array<string, SettingDefinition>|null */
    private static ?array $definitions = null;

    /** @return array<string, SettingDefinition> */
    public static function all(): array
    {
        if (self::$definitions !== null) {
            return self::$definitions;
        }
        $out = [];
        foreach (self::build() as $definition) {
            $out[$definition->key] = $definition;
        }

        return self::$definitions = $out;
    }

    public static function find(string $key): ?SettingDefinition
    {
        return self::all()[$key] ?? null;
    }

    /** @return list<SettingDefinition> */
    public static function section(string $section): array
    {
        return array_values(array_filter(self::all(), fn (SettingDefinition $d) => $d->section() === $section));
    }

    /**
     * Todas las claves de config() que el panel puede cambiar.
     *
     * @return list<string>
     */
    public static function targets(): array
    {
        $keys = [];
        foreach (self::all() as $definition) {
            foreach ($definition->targets as $target) {
                $keys[$target] = true;
            }
        }

        return array_keys($keys);
    }

    /** @return list<SettingDefinition> */
    private static function build(): array
    {
        return [
            // ── Correo de la plataforma (invitaciones, recuperación de contraseña, informes, avisos) ──
            new SettingDefinition('mail.driver', 'enum', ['mail.default'], 'MAIL_MAILER', options: ['smtp', 'ses', 'log']),
            new SettingDefinition('mail.smtp_host', 'text', ['mail.mailers.smtp.host'], 'MAIL_HOST', ['max:253', 'regex:'.self::HOST]),
            new SettingDefinition('mail.smtp_port', 'int', ['mail.mailers.smtp.port'], 'MAIL_PORT', min: 1, max: 65535),
            new SettingDefinition('mail.smtp_encryption', 'enum',
                ['mail.mailers.smtp.scheme', 'mail.mailers.smtp.require_tls', 'mail.mailers.smtp.auto_tls'], 'MAIL_SCHEME',
                options: ['starttls', 'ssl', 'none'],
                toConfig: fn (mixed $v): array => [
                    // smtps = SSL/TLS directo (465); smtp = STARTTLS (587), obligatorio salvo «sin cifrado».
                    'mail.mailers.smtp.scheme' => $v === 'ssl' ? 'smtps' : 'smtp',
                    'mail.mailers.smtp.require_tls' => $v === 'starttls',
                    'mail.mailers.smtp.auto_tls' => $v === 'none' ? false : null,
                ],
                fromConfig: fn (array $b): string => ($b['mail.mailers.smtp.scheme'] ?? null) === 'smtps'
                    || (in_array($b['mail.mailers.smtp.scheme'] ?? null, [null, ''], true) && (int) ($b['mail.mailers.smtp.port'] ?? 0) === 465)
                    ? 'ssl' : (($b['mail.mailers.smtp.auto_tls'] ?? null) === false ? 'none' : 'starttls'),
            ),
            new SettingDefinition('mail.smtp_username', 'text', ['mail.mailers.smtp.username'], 'MAIL_USERNAME', ['max:255']),
            new SettingDefinition('mail.smtp_password', 'secret', ['mail.mailers.smtp.password'], 'MAIL_PASSWORD'),
            new SettingDefinition('mail.smtp_ehlo', 'text', ['mail.mailers.smtp.local_domain'], 'MAIL_EHLO_DOMAIN', ['max:253', 'regex:'.self::HOST]),
            new SettingDefinition('mail.from_address', 'email', ['mail.from.address'], 'MAIL_FROM_ADDRESS'),
            new SettingDefinition('mail.from_name', 'text', ['mail.from.name'], 'MAIL_FROM_NAME', ['max:120']),

            // ── Amazon SES de la plataforma (correo de los clientes y, si se elige, el de la plataforma) ──
            new SettingDefinition('ses.key', 'secret', ['igr-messaging.ses.key', 'services.ses.key'], 'AWS_ACCESS_KEY_ID', ['max:128', 'regex:/^[A-Z0-9]{16,128}$/']),
            new SettingDefinition('ses.secret', 'secret', ['igr-messaging.ses.secret', 'services.ses.secret'], 'AWS_SECRET_ACCESS_KEY', ['max:128']),
            new SettingDefinition('ses.region', 'text', ['igr-messaging.ses.region', 'services.ses.region'], 'IGR_SES_REGION', ['regex:/^[a-z]{2}(-gov)?-[a-z]+-\d$/']),
            new SettingDefinition('ses.sns_topic_arn', 'text', ['igr-messaging.ses.sns_topic_arn'], 'IGR_SES_SNS_TOPIC_ARN', ['max:1000', self::topicArns()]),
            new SettingDefinition('ses.configuration_set', 'text', ['igr-messaging.ses.configuration_set'], 'IGR_SES_CONFIGURATION_SET', ['regex:/^[A-Za-z0-9_-]{1,64}$/']),
            new SettingDefinition('ses.rate', 'int', ['igr-messaging.ses.rate_per_second'], 'IGR_SES_RATE', min: 1, max: 1000),

            // ── Meta: una sola app para WhatsApp, anuncios, Messenger e Instagram (docs/guia-apps-oauth.md §1) ──
            new SettingDefinition('meta.app_id', 'text', ['igr-connections.meta.app_id', 'igr-messaging.meta.app_id'], 'IGR_META_APP_ID', ['regex:'.self::DIGITS]),
            new SettingDefinition('meta.app_secret', 'secret', ['igr-connections.meta.app_secret', 'igr-messaging.meta.app_secret'], 'IGR_META_APP_SECRET', ['max:255']),
            new SettingDefinition('meta.webhook_verify_token', 'secret', ['igr-messaging.meta.webhook_verify_token'], 'IGR_META_WEBHOOK_VERIFY_TOKEN', ['min:12', 'max:255', 'regex:/^\S+$/'], generate: 'token'),
            new SettingDefinition('meta.es_config_id', 'text', ['igr-connections.meta.whatsapp_config_id', 'igr-messaging.meta.embedded_signup_config_id'], 'IGR_META_ES_CONFIG_ID', ['regex:'.self::DIGITS]),
            new SettingDefinition('meta.login_config_id', 'text', ['igr-connections.meta.login_config_id'], 'IGR_META_LOGIN_CONFIG_ID', ['regex:'.self::DIGITS]),

            // ── Google: un solo cliente OAuth (Ads, Data Manager, Analytics y Calendar) ──
            new SettingDefinition('google.client_id', 'text', ['igr-connections.google.client_id'], 'IGR_GOOGLE_CLIENT_ID', ['max:255', 'regex:/^\S+$/']),
            new SettingDefinition('google.client_secret', 'secret', ['igr-connections.google.client_secret'], 'IGR_GOOGLE_CLIENT_SECRET', ['max:255']),
            new SettingDefinition('google.ads_developer_token', 'secret', ['igr-measurement.google.ads_developer_token'], 'IGR_GOOGLE_ADS_DEVELOPER_TOKEN', ['max:255']),

            // ── TikTok for Business ──
            new SettingDefinition('tiktok.app_id', 'text', ['igr-connections.tiktok.app_id'], 'IGR_TIKTOK_APP_ID', ['max:64', 'regex:/^\S+$/']),
            new SettingDefinition('tiktok.app_secret', 'secret', ['igr-connections.tiktok.app_secret'], 'IGR_TIKTOK_APP_SECRET', ['max:255']),

            // ── IA (Anthropic) ──
            new SettingDefinition('ai.enabled', 'bool', ['igr-ai.enabled'], 'IGR_AI_ENABLED'),
            new SettingDefinition('ai.api_key', 'secret', ['igr-ai.api_key'], 'IGR_ANTHROPIC_API_KEY', ['max:255']),
            new SettingDefinition('ai.monthly_budget_usd', 'number', ['igr-ai.monthly_budget_usd'], 'IGR_AI_MONTHLY_BUDGET_USD', min: 0, max: 100000),

            // ── Registro y seguridad ──
            new SettingDefinition('security.open_registration', 'bool', ['igr.registration_open'], 'IGR_OPEN_REGISTRATION'),
            new SettingDefinition('security.require_2fa', 'bool', ['igr.require_two_factor'], 'IGR_REQUIRE_2FA'),
            // Las opciones salen de la tabla de planes (PlatformSettingsPresenter); `none` (todo permitido) no se ofrece.
            new SettingDefinition('security.default_plan', 'enum', ['igr-plans.default_plan'], 'IGR_DEFAULT_PLAN', ['max:64', self::activePlan()]),
            new SettingDefinition('security.health_token', 'secret', ['igr.health.token'], 'IGR_HEALTH_TOKEN', ['min:16', 'max:255', 'regex:/^\S+$/'], generate: 'token'),
            new SettingDefinition('security.accept_signature_v1', 'bool', ['igr.signature.accept_v1'], 'IGR_SIGNATURE_ACCEPT_V1'),

            // ── Avisos push de la bandeja (PWA) ──
            new SettingDefinition('push.vapid_public_key', 'text', ['services.webpush.public_key'], 'IGR_PUSH_VAPID_PUBLIC_KEY', ['regex:/^[A-Za-z0-9_-]{80,100}$/']),
            new SettingDefinition('push.vapid_private_key', 'secret', ['services.webpush.private_key'], 'IGR_PUSH_VAPID_PRIVATE_KEY', ['regex:/^[A-Za-z0-9_-]{40,50}$/'], generate: 'vapid'),
            new SettingDefinition('push.vapid_subject', 'text', ['services.webpush.subject'], 'IGR_PUSH_VAPID_SUBJECT', ['max:255', 'regex:/^(mailto:[^\s@]+@[^\s@]+\.[^\s@]+|https:\/\/\S+)$/']),

            // ── Facturación ──
            new SettingDefinition('billing.platform_email', 'email', ['igr-plans.invoices.platform_email'], 'IGR_PLATFORM_BILLING_EMAIL'),
            new SettingDefinition('billing.invoice_prefix', 'text', ['igr-plans.invoices.number_prefix'], 'IGR_INVOICE_PREFIX', ['regex:/^[A-Z0-9]{1,10}$/']),
            new SettingDefinition('billing.payment_url', 'text', ['igr-plans.invoices.manual_payment_url'], 'IGR_PAYMENT_MANUAL_URL', ['max:500', self::paymentUrl()]),

            // ── Monitoreo (growth:monitor y los heartbeats de Better Stack) ──
            new SettingDefinition('monitor.heartbeat_health', 'secret', ['igr-monitor.heartbeats.health'], 'BETTERSTACK_HEARTBEAT_HEALTH', ['max:500', 'url:https', self::publicUrl()]),
            new SettingDefinition('monitor.heartbeat_streams', 'secret', ['igr-monitor.heartbeats.streams'], 'BETTERSTACK_HEARTBEAT_STREAMS', ['max:500', 'url:https', self::publicUrl()]),
            new SettingDefinition('monitor.heartbeat_queues', 'secret', ['igr-monitor.heartbeats.queues'], 'BETTERSTACK_HEARTBEAT_QUEUES', ['max:500', 'url:https', self::publicUrl()]),
            new SettingDefinition('monitor.max_stream_lag', 'int', ['igr-monitor.max_stream_lag'], 'GROWTH_MONITOR_MAX_STREAM_LAG', min: 100, max: 10_000_000),
            new SettingDefinition('monitor.max_stream_age', 'int', ['igr-monitor.max_stream_age_seconds'], 'GROWTH_MONITOR_MAX_STREAM_AGE', min: 10, max: 86_400),
            new SettingDefinition('monitor.max_queue_backlog', 'int', ['igr-monitor.max_queue_backlog'], 'GROWTH_MONITOR_MAX_QUEUE_BACKLOG', min: 100, max: 10_000_000),
            new SettingDefinition('monitor.max_redis_memory_pct', 'int', ['igr-monitor.max_redis_memory_pct'], 'GROWTH_MONITOR_MAX_REDIS_MEMORY_PCT', min: 50, max: 99),

            // ── Retención (igr:retention:purge cada noche) ──
            new SettingDefinition('retention.messages_days', 'int', ['igr.retention.messages'], 'IGR_RETENTION_MESSAGES_DAYS', min: 90, max: 3650),
            new SettingDefinition('retention.failed_jobs_days', 'int', ['igr.retention.failed_jobs'], 'IGR_RETENTION_FAILED_JOBS_DAYS', min: 1, max: 365),
            new SettingDefinition('retention.flow_steps_days', 'int', ['igr.retention.flow_run_steps'], 'IGR_RETENTION_FLOW_STEPS_DAYS', min: 30, max: 3650),
            new SettingDefinition('retention.agent_turns_days', 'int', ['igr.retention.ai_agent_turns'], 'IGR_RETENTION_AGENT_TURNS_DAYS', min: 30, max: 3650),

            // ── Tasas de cambio (igr:fx:update) ──
            new SettingDefinition('fx.url', 'url', ['igr.fx.url'], 'IGR_FX_URL', ['max:500', self::publicUrl()]),
        ];
    }

    /** Uno o varios ARN de temas de SNS separados por comas (SesDriver acepta cualquiera de ellos). */
    private static function topicArns(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            foreach (array_filter(array_map('trim', explode(',', (string) $value))) as $arn) {
                if (preg_match('/^arn:aws[a-z-]*:sns:[a-z0-9-]+:\d{12}:[A-Za-z0-9_-]{1,256}$/', $arn) !== 1) {
                    $fail(__('«:arn» no es el ARN de un tema de SNS (arn:aws:sns:región:cuenta:nombre).', ['arn' => mb_substr($arn, 0, 80)]));

                    return;
                }
            }
        };
    }

    /** Un plan que existe y no está archivado. */
    private static function activePlan(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! Plan::query()->whereKey((string) $value)->whereNull('archived_at')->exists()) {
                $fail(__('Ese plan no existe o está archivado.'));
            }
        };
    }

    /** Dirección https del pago, con {number}, {total} y {currency} si se quieren (ManualPaymentLinkProvider). */
    private static function paymentUrl(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $url = strtr((string) $value, ['{number}' => 'IGR-2026-0001', '{total}' => '100', '{currency}' => 'COP']);
            if (filter_var($url, FILTER_VALIDATE_URL) === false || ! str_starts_with($url, 'https://')) {
                $fail(__('Escribe una dirección completa que empiece por https://.'));
            }
        };
    }

    /** Una dirección que el hub va a llamar (heartbeats, tasas de cambio): pública, nunca un servidor local o interno. */
    private static function publicUrl(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $problem = SafeUrl::problem((string) $value);
            if ($problem !== null) {
                $fail($problem);
            }
        };
    }
}
