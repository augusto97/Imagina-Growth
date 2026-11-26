<?php

declare(strict_types=1);

namespace App\Domain\Platform\Settings;

use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Platform\InstallationDoctor;
use App\Domain\Bookings\Google\GoogleCalendar;
use App\Domain\Connections\Enums\ConnectionProvider;
use App\Domain\Connections\Providers\ProviderRegistry;
use App\Domain\Inbox\Push\WebPushNotifier;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Support\SesClientFactory;
use App\Domain\Messaging\Support\SesEventSetup;
use App\Domain\Sites\Support\SiteSignatures;
use App\Support\Hmac\RequestSignature;
use Throwable;

/**
 * Lo que ve la SPA de Plataforma → Ajustes (F17). De un secreto solo dice si está configurado, de dónde sale (el panel
 * o el servidor) y, si es largo, sus últimos 4 caracteres: nunca el valor.
 */
final class PlatformSettingsPresenter
{
    public function __construct(
        private readonly PlatformSettings $store,
        private readonly PlatformSettingsRuntime $runtime,
        private readonly SesClientFactory $ses,
        private readonly InstallationDoctor $doctor,
        private readonly ProviderRegistry $providers,
        private readonly SetupGuideValues $guide,
    ) {}

    /** @return array{sections: list<array<string, mixed>>} */
    public function present(): array
    {
        $values = $this->store->values();
        $sections = [];
        foreach (PlatformSettingsRegistry::SECTIONS as $section) {
            $fields = [];
            foreach (PlatformSettingsRegistry::section($section) as $definition) {
                $fields[] = $this->field($definition, $values[$definition->key] ?? null);
            }
            $info = $this->info($section);
            // Lo que la guía paso a paso muestra para copiar en la consola de cada proveedor.
            $guide = $this->guide->for($section);
            if ($guide !== []) {
                $info['guide'] = $guide;
            }
            $sections[] = ['key' => $section, 'fields' => $fields, 'info' => $info];
        }

        return ['sections' => $sections];
    }

    /** @return array<string, mixed> */
    private function field(SettingDefinition $definition, mixed $stored): array
    {
        $fallback = $this->runtime->fallback($definition);
        $effective = SettingDefinition::filled($stored) ? $stored : $fallback;
        $secret = $definition->secret();

        return [
            'key' => $definition->name(),
            'type' => $definition->type,
            'options' => $definition->key === 'security.default_plan' ? $this->planOptions($effective) : ($definition->options === [] ? null : $definition->options),
            'min' => $definition->min,
            'max' => $definition->max,
            'generate' => $definition->generate,
            'value' => $secret ? null : $stored,
            'fallback' => $secret ? null : $fallback,
            'source' => SettingDefinition::filled($stored) ? 'panel' : (SettingDefinition::filled($fallback) ? 'server' : null),
            'configured' => SettingDefinition::filled($effective),
            'hint' => $secret && is_string($effective) ? self::hint($effective) : null,
        ];
    }

    /** Los últimos 4 caracteres de un secreto largo (los cortos no se insinúan). */
    public static function hint(string $secret): ?string
    {
        return mb_strlen($secret) >= 16 ? '…'.mb_substr($secret, -4) : null;
    }

    /** @return list<string> */
    private function planOptions(mixed $current): array
    {
        try {
            $ids = Plan::query()->whereNull('archived_at')->orderBy('id')->pluck('id')->map(fn ($id) => (string) $id)->all();
        } catch (Throwable) {
            $ids = [];
        }
        if (is_string($current) && $current !== '' && ! in_array($current, $ids, true)) {
            $ids[] = $current;
        }

        return array_values($ids);
    }

    /** @return array<string, mixed> */
    private function info(string $section): array
    {
        $panel = rtrim((string) config('app.url'), '/');

        return match ($section) {
            'mail' => [
                'configured' => ! in_array((string) config('mail.default'), ['log', 'array'], true),
                'mailer' => (string) config('mail.default'),
            ],
            'ses' => [
                'configured' => $this->ses->configured(),
                'webhook_url' => $panel.'/webhooks/ses',
                'configuration_set' => SesEventSetup::configurationSet(),
                'last_setup' => SesEventSetup::lastResult(),
            ],
            'meta' => [
                'configured' => $this->providers->configured(ConnectionProvider::Meta) && $this->providers->configured(ConnectionProvider::MetaWhatsApp),
                'webhook_url' => $panel.'/webhooks/meta',
                'callback_url' => ConnectionProvider::Meta->callbackUrl(),
                'domain' => (string) parse_url($panel, PHP_URL_HOST),
                'connected_accounts' => $this->count(fn () => ChannelAccount::withoutWorkspaceScope()->whereIn('provider', ['meta_cloud', 'meta_messenger', 'meta_instagram'])->where('status', '!=', 'disconnected')->count()),
            ],
            'google' => [
                'configured' => $this->providers->configured(ConnectionProvider::Google),
                'callback_url' => ConnectionProvider::Google->callbackUrl(),
                'calendar_legacy' => GoogleCalendar::legacyConfigured(),
            ],
            'tiktok' => ['configured' => $this->providers->configured(ConnectionProvider::TikTok), 'callback_url' => ConnectionProvider::TikTok->callbackUrl()],
            'ai' => ['configured' => (bool) config('igr-ai.enabled') && (string) config('igr-ai.api_key') !== ''],
            'security' => [
                'plans' => $this->plans(),
                'signature_v1_accepted' => SiteSignatures::acceptsV1(),
                'signature_v1_sites' => $this->doctor->legacySignatureSites(),
                'signature_v2_min_plugin' => RequestSignature::V2_MIN_PLUGIN_VERSION,
                'health_url' => $panel.'/health',
            ],
            'push' => ['configured' => WebPushNotifier::configured()],
            default => [],
        };
    }

    /** @return list<array{id: string, name: string}> */
    private function plans(): array
    {
        try {
            $out = [];
            foreach (Plan::query()->orderBy('metric')->orderBy('price_usd')->get(['id', 'name']) as $plan) {
                $out[] = ['id' => (string) $plan->id, 'name' => (string) $plan->name];
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /** @param callable(): int $query */
    private function count(callable $query): int
    {
        try {
            return (int) $query();
        } catch (Throwable) {
            return 0;
        }
    }
}
