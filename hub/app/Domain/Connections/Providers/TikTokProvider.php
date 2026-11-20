<?php

declare(strict_types=1);

namespace App\Domain\Connections\Providers;

use App\Domain\Connections\Data\TokenSet;
use App\Domain\Connections\Enums\ConnectionProvider;
use App\Domain\Connections\Models\OAuthConnection;
use App\Domain\Connections\Support\ConnectionError;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * TikTok API for Business (v1.3): el anunciante autoriza en `/portal/auth`, la vuelta trae `auth_code` y se canjea
 * en `POST /oauth2/access_token/` (JSON con app_id, secret y auth_code) por un token de largo plazo que no vence
 * hasta que se revoca (`POST /oauth2/revoke_token/`).
 * https://business-api.tiktok.com/portal/docs/obtain-a-long-term-access-token/v1.3
 * https://business-api.tiktok.com/portal/docs/revoke-a-long-term-access-token/v1.3
 * Las respuestas vienen como `{code, message, data}` con `code = 0` si todo salió bien.
 */
final class TikTokProvider implements OAuthProvider
{
    public function provider(): ConnectionProvider
    {
        return ConnectionProvider::TikTok;
    }

    public function missing(): array
    {
        $missing = [];
        foreach (['IGR_TIKTOK_APP_ID' => 'app_id', 'IGR_TIKTOK_APP_SECRET' => 'app_secret'] as $env => $key) {
            if ((string) config('igr-connections.tiktok.'.$key) === '') {
                $missing[] = $env;
            }
        }

        return $missing;
    }

    /** @return list<string> */
    public function purposes(): array
    {
        return ['business'];
    }

    public function supportsPkce(): bool
    {
        return false;
    }

    public function authorizationUrl(string $state, string $purpose, ?string $codeChallenge): string
    {
        return (string) config('igr-connections.tiktok.auth_url').'?'.http_build_query([
            'app_id' => (string) config('igr-connections.tiktok.app_id'),
            'state' => $state,
            'redirect_uri' => $this->provider()->callbackUrl(),
        ]);
    }

    public function exchange(string $code, ?string $codeVerifier): TokenSet
    {
        $response = $this->call('post', 'oauth2/access_token/', [
            'app_id' => (string) config('igr-connections.tiktok.app_id'),
            'secret' => (string) config('igr-connections.tiktok.app_secret'),
            'auth_code' => $code,
        ]);
        $token = $response->json('data.access_token');
        if (! self::ok($response) || ! is_string($token) || $token === '') {
            throw new ConnectionError(__('TikTok no aceptó la autorización: :msg', ['msg' => self::errorMessage($response)]), 'exchange');
        }

        return new TokenSet(
            accessToken: $token,
            scopes: array_values(array_map('strval', (array) $response->json('data.scope', []))),
            assets: ['advertiser_ids' => array_values(array_map('strval', (array) $response->json('data.advertiser_ids', [])))],
        );
    }

    public function refresh(OAuthConnection $connection): TokenSet
    {
        throw ConnectionError::reconnect('TikTok');
    }

    public function revoke(OAuthConnection $connection): void
    {
        $token = $connection->secret('access_token');
        if ($token !== null) {
            $this->call('post', 'oauth2/revoke_token/', [
                'app_id' => (string) config('igr-connections.tiktok.app_id'),
                'secret' => (string) config('igr-connections.tiktok.app_secret'),
                'access_token' => $token,
            ], $token);
        }
    }

    public function check(OAuthConnection $connection): void
    {
        $token = $connection->secret('access_token') ?? throw ConnectionError::reconnect('TikTok');
        $response = $this->advertisers($token);
        if (! self::ok($response)) {
            // 40001/40100–40105: token inválido o revocado [verificar la lista de códigos con la app real].
            $code = (int) $response->json('code', 0);
            if ($response->status() === 401 || ($code >= 40001 && $code <= 40199)) {
                throw ConnectionError::reconnect('TikTok');
            }
            throw new ConnectionError(self::errorMessage($response), 'provider');
        }
    }

    /** Anunciantes que autorizaron la app: GET /oauth2/advertiser/get/. */
    public function advertisers(string $token): Response
    {
        return $this->call('get', 'oauth2/advertiser/get/', [
            'app_id' => (string) config('igr-connections.tiktok.app_id'),
            'secret' => (string) config('igr-connections.tiktok.app_secret'),
        ], $token);
    }

    /** @param array<string, mixed> $data */
    public function call(string $method, string $path, array $data, ?string $token = null): Response
    {
        $url = rtrim((string) config('igr-connections.tiktok.api_url'), '/').'/'.ltrim($path, '/');
        try {
            $request = Http::acceptJson()->timeout(15);
            if ($token !== null) {
                $request = $request->withHeaders(['Access-Token' => $token]);
            }

            return $method === 'get' ? $request->get($url, $data) : $request->asJson()->post($url, $data);
        } catch (ConnectionException) {
            throw new ConnectionError(__('No pudimos comunicarnos con TikTok. Vuelve a intentarlo en unos minutos.'), 'provider');
        }
    }

    public static function ok(Response $response): bool
    {
        return $response->successful() && (int) $response->json('code', -1) === 0;
    }

    public static function errorMessage(Response $response): string
    {
        $message = $response->json('message');

        return is_string($message) && $message !== '' ? $message : __('TikTok respondió :status', ['status' => $response->status()]);
    }
}
