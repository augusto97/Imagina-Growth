<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Support;

use App\Domain\Measurement\Enums\Platform;

/** Campos de credenciales por plataforma: cuáles son secretos (nunca vuelven a la SPA) y cuáles públicos. */
final class DestinationFields
{
    /** @var array<string, array<string, bool>> plataforma => campo => es secreto */
    private const FIELDS = [
        'meta' => [
            'dataset_id' => false, 'access_token' => true,
            'ad_account_id' => false, 'ads_access_token' => true,
        ],
        'google' => [
            'measurement_id' => false, 'api_secret' => true,
            'ads_customer_id' => false, 'ads_login_customer_id' => false,
            'conversion_action_purchase' => false, 'conversion_action_lead' => false, 'conversion_action_lead_won' => false, 'conversion_action_contact' => false,
            'oauth_client_id' => false, 'oauth_client_secret' => true, 'oauth_refresh_token' => true,
            'ads_developer_token' => true,
        ],
        'tiktok' => [
            'pixel_code' => false, 'access_token' => true,
            'advertiser_id' => false, 'ads_access_token' => true,
        ],
    ];

    /** @return array<string, bool> */
    public static function for(Platform $platform): array
    {
        return self::FIELDS[$platform->value];
    }

    /**
     * Credenciales para la SPA: los públicos tal cual, los secretos enmascarados.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, string|null>
     */
    public static function present(Platform $platform, array $config): array
    {
        $out = [];
        foreach (self::FIELDS[$platform->value] as $field => $secret) {
            $value = $config[$field] ?? null;
            if (! is_string($value) || $value === '') {
                $out[$field] = null;

                continue;
            }
            $out[$field] = $secret ? '••••'.mb_substr($value, -4) : $value;
        }

        return $out;
    }

    /**
     * ID público del píxel que va al sitio en el modo «completo».
     *
     * @param  array<string, mixed>  $config
     */
    public static function pixelId(Platform $platform, array $config): ?string
    {
        $key = match ($platform) {
            Platform::Meta => 'dataset_id',
            Platform::Google => 'measurement_id',
            Platform::TikTok => 'pixel_code',
        };
        $value = $config[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
