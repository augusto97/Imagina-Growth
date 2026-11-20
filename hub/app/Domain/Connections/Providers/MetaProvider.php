<?php

declare(strict_types=1);

namespace App\Domain\Connections\Providers;

use App\Domain\Connections\Data\TokenSet;
use App\Domain\Connections\Enums\ConnectionProvider;
use App\Domain\Connections\Models\OAuthConnection;
use App\Domain\Connections\Support\ConnectionError;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Meta con la app de la plataforma (tipo Business, proveedor de tecnología):
 *
 * - `meta`: Facebook Login for Business con una configuración (`config_id`) que pide anuncios, conjuntos de
 *   datos, páginas e Instagram y entrega un token de usuario del sistema del negocio del cliente (no vence).
 *   El diálogo se arma a mano (`/dialog/oauth` con `config_id`, `response_type=code` y
 *   `override_default_response_type=true`) y vuelve a {APP_URL}/oauth/meta/callback.
 *   https://developers.facebook.com/docs/facebook-login/facebook-login-for-business
 *   https://developers.facebook.com/docs/facebook-login/guides/advanced/manual-flow
 * - `meta_whatsapp`: el código de Embedded Signup (ventana del SDK de JavaScript, vive 5 minutos) se canjea
 *   sin `redirect_uri` por el token del negocio. https://developers.facebook.com/docs/whatsapp/embedded-signup/onboarding-customers-as-a-tech-provider
 *
 * Cada token se revisa con `debug_token` (que sea de esta app, válido, con qué permisos y sobre qué cuentas:
 * `granular_scopes[].target_ids`). https://developers.facebook.com/docs/graph-api/reference/debug_token
 * Meta no tiene refresh token: si el token se revoca o vence, hay que volver a conectar.
 */
final class MetaProvider implements OAuthProvider
{
    public function __construct(private readonly ConnectionProvider $provider = ConnectionProvider::Meta) {}

    public function provider(): ConnectionProvider
    {
        return $this->provider;
    }

    public function missing(): array
    {
        $required = ['IGR_META_APP_ID' => 'app_id', 'IGR_META_APP_SECRET' => 'app_secret'];
        $required += $this->provider === ConnectionProvider::MetaWhatsApp ? ['IGR_META_ES_CONFIG_ID' => 'whatsapp_config_id'] : ['IGR_META_LOGIN_CONFIG_ID' => 'login_config_id'];
        $missing = [];
        foreach ($required as $env => $key) {
            if ((string) config('igr-connections.meta.'.$key) === '') {
                $missing[] = $env;
            }
        }

        return $missing;
    }

    /** @return list<string> */
    public function purposes(): array
    {
        return $this->provider === ConnectionProvider::MetaWhatsApp ? ['whatsapp'] : ['business'];
    }

    public function supportsPkce(): bool
    {
        // La guía de Login for Business no documenta PKCE con config_id: la protección es el state y el secreto del servidor.
        return false;
    }

    public function authorizationUrl(string $state, string $purpose, ?string $codeChallenge): string
    {
        return rtrim((string) config('igr-connections.meta.dialog_url'), '/').'/'.self::version().'/dialog/oauth?'.http_build_query([
            'client_id' => (string) config('igr-connections.meta.app_id'),
            'redirect_uri' => $this->provider->callbackUrl(),
            'state' => $state,
            'config_id' => (string) config('igr-connections.meta.login_config_id'),
            'response_type' => 'code',
            'override_default_response_type' => 'true',
        ]);
    }

    public function exchange(string $code, ?string $codeVerifier): TokenSet
    {
        $query = [
            'client_id' => (string) config('igr-connections.meta.app_id'),
            'client_secret' => (string) config('igr-connections.meta.app_secret'),
            'code' => $code,
        ];
        if ($this->provider->usesRedirect()) {
            $query['redirect_uri'] = $this->provider->callbackUrl();
        }
        $response = $this->get('oauth/access_token', $query);
        $token = $response->json('access_token');
        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new ConnectionError(__('Meta no aceptó la autorización: :msg', ['msg' => self::errorMessage($response)]), 'exchange');
        }
        $debug = $this->debug($token);
        $me = $this->get('me', ['fields' => 'id,name'], $token);
        $expiresAt = (int) ($debug['expires_at'] ?? 0) > 0
            ? CarbonImmutable::createFromTimestamp((int) $debug['expires_at'])
            : ((int) ($response->json('expires_in') ?? 0) > 0 ? CarbonImmutable::now()->addSeconds((int) $response->json('expires_in')) : null);

        return new TokenSet(
            accessToken: $token,
            expiresAt: $expiresAt,
            scopes: array_values(array_map('strval', (array) ($debug['scopes'] ?? []))),
            externalId: is_scalar($debug['user_id'] ?? null) ? (string) $debug['user_id'] : null,
            accountName: is_string($me->json('name')) ? (string) $me->json('name') : null,
            assets: ['granted' => self::targets($debug)],
        );
    }

    public function refresh(OAuthConnection $connection): TokenSet
    {
        throw ConnectionError::reconnect('Meta');
    }

    public function revoke(OAuthConnection $connection): void
    {
        $token = $connection->secret('access_token');
        if ($token === null) {
            return;
        }
        if ($this->provider === ConnectionProvider::MetaWhatsApp) {
            // Deja de recibir los webhooks de esa cuenta de WhatsApp.
            $waba = $connection->asset('whatsapp')['waba_id'] ?? null;
            if (is_string($waba) && $waba !== '') {
                $this->send('delete', $waba.'/subscribed_apps', $token);
            }

            return;
        }
        $this->send('delete', 'me/permissions', $token);
    }

    public function check(OAuthConnection $connection): void
    {
        $token = $connection->secret('access_token');
        if ($token === null) {
            throw ConnectionError::reconnect('Meta');
        }
        $this->debug($token);
    }

    /**
     * `debug_token` con el token de la app. Falla si el token no es válido o es de otra app.
     *
     * @return array<string, mixed>
     */
    public function debug(string $token): array
    {
        $appId = (string) config('igr-connections.meta.app_id');
        $response = $this->get('debug_token', ['input_token' => $token], $appId.'|'.(string) config('igr-connections.meta.app_secret'));
        $data = (array) $response->json('data', []);
        if (! $response->successful()) {
            throw new ConnectionError(__('Meta no respondió al revisar el acceso (:status).', ['status' => $response->status()]), 'provider');
        }
        if (($data['is_valid'] ?? false) !== true || (string) ($data['app_id'] ?? '') !== $appId) {
            throw ConnectionError::reconnect('Meta');
        }

        return $data;
    }

    /**
     * Cuentas por permiso según `granular_scopes`: p. ej. `whatsapp_business_management` → IDs de WABA,
     * `ads_management` → cuentas publicitarias, `pages_messaging` → páginas. Sin `target_ids` = todas.
     *
     * @param  array<string, mixed>  $debug
     * @return array<string, list<string>|null>
     */
    public static function targets(array $debug): array
    {
        $out = [];
        foreach ((array) ($debug['granular_scopes'] ?? []) as $grant) {
            if (is_array($grant) && is_string($grant['scope'] ?? null)) {
                $out[$grant['scope']] = isset($grant['target_ids']) && is_array($grant['target_ids']) ? array_values(array_map('strval', $grant['target_ids'])) : null;
            }
        }

        return $out;
    }

    public static function version(): string
    {
        return (string) config('igr-connections.meta.graph_version', 'v27.0');
    }

    public static function url(string $path): string
    {
        return rtrim((string) config('igr-connections.meta.graph_url'), '/').'/'.self::version().'/'.ltrim($path, '/');
    }

    /** @param array<string, mixed> $query */
    public function get(string $path, array $query, ?string $token = null): Response
    {
        try {
            $request = Http::acceptJson()->timeout(15);

            return ($token !== null ? $request->withToken($token) : $request)->get(self::url($path), $query);
        } catch (ConnectionException) {
            throw new ConnectionError(__('No pudimos comunicarnos con Meta. Vuelve a intentarlo en unos minutos.'), 'provider');
        }
    }

    /** @param array<string, mixed> $body */
    public function send(string $method, string $path, string $token, array $body = []): Response
    {
        try {
            $request = Http::acceptJson()->timeout(20)->withToken($token);

            return $method === 'delete' ? $request->delete(self::url($path), $body) : $request->post(self::url($path), $body);
        } catch (ConnectionException) {
            throw new ConnectionError(__('No pudimos comunicarnos con Meta. Vuelve a intentarlo en unos minutos.'), 'provider');
        }
    }

    public static function errorMessage(Response $response): string
    {
        $message = $response->json('error.message');
        $details = $response->json('error.error_data.details');

        return trim((is_string($message) ? $message : __('Meta respondió :status', ['status' => $response->status()])).(is_string($details) ? ' — '.$details : ''));
    }
}
