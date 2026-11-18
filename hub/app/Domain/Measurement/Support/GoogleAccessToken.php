<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Support;

use App\Domain\Measurement\Models\AdDestination;
use App\Support\Tenancy\RedisKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Token de acceso de OAuth de Google (Data Manager y Google Ads) a partir del
 * refresh token del destino. Se guarda en caché hasta poco antes de vencer.
 */
final class GoogleAccessToken
{
    public function for(AdDestination $destination): string
    {
        $key = RedisKey::for($destination->workspace_id, 'google_token:'.$destination->id);
        $cached = Cache::get($key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        $clientId = $destination->credential('oauth_client_id');
        $secret = $destination->credential('oauth_client_secret');
        $refresh = $destination->credential('oauth_refresh_token');
        if ($clientId === null || $secret === null || $refresh === null) {
            throw new RuntimeException(__('Falta autorizar la cuenta de Google en el destino.'));
        }
        $response = Http::asForm()->timeout(10)->post((string) config('igr-measurement.google.oauth_token_url'), [
            'client_id' => $clientId,
            'client_secret' => $secret,
            'refresh_token' => $refresh,
            'grant_type' => 'refresh_token',
        ]);
        $token = $response->json('access_token');
        if (! $response->successful() || ! is_string($token)) {
            throw new RuntimeException(__('Google no renovó el acceso (:status). Vuelve a autorizar la cuenta.', ['status' => $response->status()]));
        }
        Cache::put($key, $token, max(60, (int) $response->json('expires_in', 3600) - 120));

        return $token;
    }
}
