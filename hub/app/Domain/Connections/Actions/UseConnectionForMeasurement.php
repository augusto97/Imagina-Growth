<?php

declare(strict_types=1);

namespace App\Domain\Connections\Actions;

use App\Domain\Connections\Assets\GoogleAssets;
use App\Domain\Connections\Assets\TikTokAssets;
use App\Domain\Connections\Enums\ConnectionProvider;
use App\Domain\Connections\Models\OAuthConnection;
use App\Domain\Connections\Providers\MetaProvider;
use App\Domain\Connections\Support\ConnectionError;
use App\Domain\Connections\Support\ConnectionTokens;
use App\Domain\Measurement\Enums\DestinationMode;
use App\Domain\Measurement\Enums\Platform;
use App\Domain\Measurement\Models\AdDestination;
use App\Domain\Measurement\Support\DestinationFields;
use App\Domain\Workspaces\Actions\RecordAuditLog;

/**
 * Después de autorizar: el destino de Medición de esa plataforma (el que ya existe o uno nuevo) queda con los IDs
 * que se eligieron y con `oauth_connection_id`, así la Conversions API, Data Manager, la Events API, las audiencias
 * y el gasto usan la conexión. Los tokens pegados a mano no se borran: son el respaldo.
 */
final class UseConnectionForMeasurement
{
    public function __construct(
        private readonly ConnectionTokens $tokens,
        private readonly GoogleAssets $google,
        private readonly TikTokAssets $tiktok,
        private readonly RecordAuditLog $audit,
        private readonly MetaProvider $meta = new MetaProvider,
    ) {}

    /**
     * @param  array<string, string|null>  $input  IDs elegidos (dataset_id, ad_account_id, ads_customer_id, …)
     *
     * @throws ConnectionError
     */
    public function handle(OAuthConnection $connection, array $input): AdDestination
    {
        $platform = match ($connection->provider) {
            ConnectionProvider::Meta => Platform::Meta,
            ConnectionProvider::Google => Platform::Google,
            ConnectionProvider::TikTok => Platform::TikTok,
            ConnectionProvider::MetaWhatsApp => throw new ConnectionError(__('Esta conexión es de WhatsApp.'), 'asset'),
        };
        if (! $connection->isActive()) {
            throw ConnectionError::reconnect($connection->provider->label());
        }
        $ids = match ($platform) {
            Platform::Meta => $this->meta($connection, $input),
            Platform::Google => $this->google($connection, $input),
            Platform::TikTok => $this->tiktok($connection, $input),
        };

        $destination = AdDestination::query()->where('platform', $platform->value)
            ->orderByRaw('oauth_connection_id = ? desc', [$connection->id])->orderBy('created_at')->first()
            ?? new AdDestination(['platform' => $platform, 'name' => $platform->label(), 'mode' => DestinationMode::ServerOnly, 'config' => []]);
        $config = array_replace($destination->config ?? [], array_filter($ids, fn ($v) => is_string($v) && $v !== ''));
        $destination->fill([
            'config' => $config,
            'oauth_connection_id' => $connection->id,
            'pixel_id' => DestinationFields::pixelId($platform, $config),
            'status' => 'active',
            'last_error' => null,
        ]);
        $destination->forgetLinkedConnection();
        $destination->save();
        $this->audit->handle('measurement.destination_connected', $destination, ['platform' => $platform->value, 'connection' => $connection->publicId(), 'fields' => array_keys(array_filter($ids))], $destination->workspace_id);

        return $destination;
    }

    /**
     * @param  array<string, string|null>  $input
     * @return array<string, string|null>
     */
    private function meta(OAuthConnection $connection, array $input): array
    {
        $dataset = self::digits($input['dataset_id'] ?? null);
        if ($dataset === null) {
            throw new ConnectionError(__('Elige el conjunto de datos (píxel) de Meta.'), 'asset');
        }
        // El conjunto de datos tiene que verse con este token (es del negocio que autorizó).
        $response = $this->meta->get($dataset, ['fields' => 'id,name'], $this->tokens->accessToken($connection));
        if (! $response->successful()) {
            throw new ConnectionError(__('Meta no deja usar ese conjunto de datos con esta conexión: :msg', ['msg' => MetaProvider::errorMessage($response)]), 'asset');
        }
        $account = self::digits($input['ad_account_id'] ?? null);
        $granted = $connection->asset('granted');
        $allowed = $granted['ads_management'] ?? $granted['ads_read'] ?? null;
        if ($account !== null && is_array($allowed) && ! in_array($account, $allowed, true) && ! in_array('act_'.$account, $allowed, true)) {
            throw new ConnectionError(__('Esa cuenta publicitaria no está entre las que autorizaste en Meta.'), 'asset');
        }

        return ['dataset_id' => $dataset, 'ad_account_id' => $account];
    }

    /**
     * @param  array<string, string|null>  $input
     * @return array<string, string|null>
     */
    private function google(OAuthConnection $connection, array $input): array
    {
        $out = [
            'ads_customer_id' => self::digits($input['ads_customer_id'] ?? null),
            'ads_login_customer_id' => self::digits($input['ads_login_customer_id'] ?? null),
            'conversion_action_purchase' => self::digits($input['conversion_action_purchase'] ?? null),
            'conversion_action_lead' => self::digits($input['conversion_action_lead'] ?? null),
            'conversion_action_lead_won' => self::digits($input['conversion_action_lead_won'] ?? null),
            // Clics que valen (M06): tocar WhatsApp, llamar o escribir un correo, si el destino los envía.
            'conversion_action_contact' => self::digits($input['conversion_action_contact'] ?? null),
        ];
        $stream = $input['ga4_stream'] ?? null;
        if (is_string($stream) && $stream !== '') {
            $out += $this->google->measurementProtocol($connection, $stream);
        }
        if ($out['ads_customer_id'] === null && ! isset($out['measurement_id'])) {
            throw new ConnectionError(__('Elige la cuenta de Google Ads o la propiedad de Analytics.'), 'asset');
        }

        return $out;
    }

    /**
     * @param  array<string, string|null>  $input
     * @return array<string, string|null>
     */
    private function tiktok(OAuthConnection $connection, array $input): array
    {
        $advertiser = self::digits($input['advertiser_id'] ?? null);
        $pixel = isset($input['pixel_code']) && preg_match('/^[A-Z0-9]{8,40}$/i', (string) $input['pixel_code']) ? (string) $input['pixel_code'] : null;
        if ($advertiser === null || $pixel === null) {
            throw new ConnectionError(__('Elige el anunciante y el píxel de TikTok.'), 'asset');
        }
        $this->tiktok->assertAdvertiser($connection, $advertiser);

        return ['advertiser_id' => $advertiser, 'pixel_code' => $pixel];
    }

    private static function digits(?string $value): ?string
    {
        $digits = (string) preg_replace('/\D/', '', (string) $value);

        return $digits !== '' ? $digits : null;
    }
}
