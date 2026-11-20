<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Support;

use App\Domain\Measurement\Models\AdDestination;

/**
 * Encabezados de la Google Ads API. Desde el 9 de septiembre de 2026 el developer token ya no hace falta (el
 * nivel de acceso va en el proyecto de Google Cloud y un token enviado se ignora):
 * https://developers.google.com/google-ads/api/docs/api-policy/developer-token
 * Se manda solo si el destino lo tiene pegado o la plataforma lo configuró (IGR_GOOGLE_ADS_DEVELOPER_TOKEN).
 * `login-customer-id` va cuando se entra a la cuenta por una cuenta de administrador (MCC).
 */
final class GoogleAdsHeaders
{
    /** @return array<string, string> */
    public static function for(AdDestination $destination): array
    {
        return self::build($destination->credential('ads_developer_token'), $destination->credential('ads_login_customer_id'));
    }

    /** @return array<string, string> */
    public static function build(?string $developerToken, ?string $loginCustomerId): array
    {
        $headers = [];
        $token = $developerToken ?? (string) config('igr-measurement.google.ads_developer_token');
        if ($token !== '') {
            $headers['developer-token'] = $token;
        }
        $login = (string) preg_replace('/\D/', '', (string) $loginCustomerId);
        if ($login !== '') {
            $headers['login-customer-id'] = $login;
        }

        return $headers;
    }

    public static function url(string $path): string
    {
        return rtrim((string) config('igr-measurement.google.ads_url'), '/').'/'.config('igr-measurement.google.ads_version').'/'.ltrim($path, '/');
    }
}
