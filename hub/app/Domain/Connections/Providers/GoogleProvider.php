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
 * Google con un solo OAuth de la plataforma (OAuth 2.0 para apps web de servidor, con `access_type=offline`,
 * `state` y PKCE S256): https://developers.google.com/identity/protocols/oauth2/web-server
 *
 * - `measurement`: Google Ads API (`adwords`, para listar cuentas y acciones de conversión), Data Manager API
 *   (`datamanager`, conversiones y Customer Match: https://developers.google.com/data-manager/api/get-started/set-up-access)
 *   y Analytics (`analytics.edit`, para listar propiedades y crear el secreto del Measurement Protocol).
 * - `calendar`: Google Calendar de una persona de la agenda (`calendar.events` y `calendar.freebusy`).
 *
 * La persona puede conceder solo algunos alcances (consentimiento granular): se guardan los que devolvió
 * Google y cada función revisa el suyo. Con la app en estado «Prueba», Google entrega refresh tokens que
 * vencen a los 7 días (https://developers.google.com/identity/protocols/oauth2): la app debe publicarse.
 */
final class GoogleProvider implements OAuthProvider
{
    public function provider(): ConnectionProvider
    {
        return ConnectionProvider::Google;
    }

    public function missing(): array
    {
        $missing = [];
        foreach (['IGR_GOOGLE_CLIENT_ID' => 'client_id', 'IGR_GOOGLE_CLIENT_SECRET' => 'client_secret'] as $env => $key) {
            if ((string) config('igr-connections.google.'.$key) === '') {
                $missing[] = $env;
            }
        }

        return $missing;
    }

    /** @return list<string> */
    public function purposes(): array
    {
        return ['measurement', 'calendar'];
    }

    public function supportsPkce(): bool
    {
        return true;
    }

    /** @return list<string> */
    public static function scopesFor(string $purpose): array
    {
        $scopes = (array) config('igr-connections.google.scopes');
        $wanted = $purpose === 'calendar' ? ['identity', 'calendar'] : ['identity', 'ads', 'analytics'];
        $out = [];
        foreach ($wanted as $group) {
            foreach ((array) ($scopes[$group] ?? []) as $scope) {
                $out[] = (string) $scope;
            }
        }

        return $out;
    }

    public function authorizationUrl(string $state, string $purpose, ?string $codeChallenge): string
    {
        return (string) config('igr-connections.google.auth_url').'?'.http_build_query(array_filter([
            'client_id' => (string) config('igr-connections.google.client_id'),
            'redirect_uri' => $this->provider()->callbackUrl(),
            'response_type' => 'code',
            'scope' => implode(' ', self::scopesFor($purpose)),
            'access_type' => 'offline',
            // Siempre la pantalla de consentimiento: así Google entrega un refresh token aunque ya se hubiera autorizado.
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => $codeChallenge !== null ? 'S256' : null,
        ], fn ($v) => $v !== null));
    }

    public function exchange(string $code, ?string $codeVerifier): TokenSet
    {
        $response = $this->post((string) config('igr-connections.google.token_url'), array_filter([
            'code' => $code,
            'client_id' => (string) config('igr-connections.google.client_id'),
            'client_secret' => (string) config('igr-connections.google.client_secret'),
            'redirect_uri' => $this->provider()->callbackUrl(),
            'grant_type' => 'authorization_code',
            'code_verifier' => $codeVerifier,
        ], fn ($v) => $v !== null));
        $access = $response->json('access_token');
        if (! $response->successful() || ! is_string($access) || $access === '') {
            throw new ConnectionError(__('Google no aceptó la autorización (:error). Vuelve a intentarlo.', ['error' => self::errorCode($response)]), 'exchange');
        }
        $refresh = $response->json('refresh_token');
        if (! is_string($refresh) || $refresh === '') {
            throw new ConnectionError(__('Google no entregó el acceso permanente. Vuelve a conectar y acepta todos los permisos.'), 'exchange');
        }
        $claims = self::idTokenClaims($response->json('id_token'));

        return new TokenSet(
            accessToken: $access,
            refreshToken: $refresh,
            expiresAt: CarbonImmutable::now()->addSeconds(max(60, (int) ($response->json('expires_in') ?? 3600))),
            scopes: self::scopes($response->json('scope')),
            externalId: isset($claims['sub']) && is_string($claims['sub']) ? $claims['sub'] : null,
            accountName: isset($claims['email']) && is_string($claims['email']) ? $claims['email'] : null,
        );
    }

    public function refresh(OAuthConnection $connection): TokenSet
    {
        $refresh = $connection->secret('refresh_token');
        if ($refresh === null) {
            throw ConnectionError::reconnect('Google');
        }
        $response = $this->post((string) config('igr-connections.google.token_url'), [
            'client_id' => (string) config('igr-connections.google.client_id'),
            'client_secret' => (string) config('igr-connections.google.client_secret'),
            'refresh_token' => $refresh,
            'grant_type' => 'refresh_token',
        ]);
        $access = $response->json('access_token');
        if (! $response->successful() || ! is_string($access) || $access === '') {
            // invalid_grant: la persona revocó el acceso, cambió la contraseña o el token venció (app en «Prueba»).
            if (in_array(self::errorCode($response), ['invalid_grant', 'unauthorized_client', 'invalid_client'], true)) {
                throw ConnectionError::reconnect('Google');
            }
            throw new ConnectionError(__('Google no renovó el acceso (:status). Se reintenta solo.', ['status' => $response->status()]), 'provider');
        }
        $scopes = self::scopes($response->json('scope'));

        return new TokenSet(
            accessToken: $access,
            refreshToken: is_string($response->json('refresh_token')) ? (string) $response->json('refresh_token') : $refresh,
            expiresAt: CarbonImmutable::now()->addSeconds(max(60, (int) ($response->json('expires_in') ?? 3600))),
            scopes: $scopes !== [] ? $scopes : array_values($connection->scopes ?? []),
        );
    }

    public function revoke(OAuthConnection $connection): void
    {
        $token = $connection->secret('refresh_token') ?? $connection->secret('access_token');
        if ($token !== null) {
            // https://developers.google.com/identity/protocols/oauth2/web-server#tokenrevoke
            $this->post((string) config('igr-connections.google.revoke_url'), ['token' => $token]);
        }
    }

    public function check(OAuthConnection $connection): void
    {
        $this->refresh($connection);
    }

    /** @param array<string, string> $form */
    private function post(string $url, array $form): Response
    {
        try {
            return Http::asForm()->acceptJson()->timeout(10)->post($url, $form);
        } catch (ConnectionException) {
            throw new ConnectionError(__('No pudimos comunicarnos con Google. Vuelve a intentarlo en unos minutos.'), 'provider');
        }
    }

    private static function errorCode(Response $response): string
    {
        $error = $response->json('error');

        return is_string($error) ? $error : (string) $response->status();
    }

    /** @return list<string> */
    private static function scopes(mixed $scope): array
    {
        return is_string($scope) ? array_values(array_filter(explode(' ', $scope), fn (string $s) => $s !== '')) : [];
    }

    /**
     * Datos del id_token (correo y `sub`). Llega directo del endpoint de tokens de Google por TLS, así que no
     * hace falta validar la firma para mostrar la cuenta (OpenID Connect Core §3.1.3.7).
     *
     * @return array<string, mixed>
     */
    private static function idTokenClaims(mixed $idToken): array
    {
        if (! is_string($idToken) || substr_count($idToken, '.') !== 2) {
            return [];
        }
        $payload = json_decode((string) base64_decode(strtr(explode('.', $idToken)[1], '-_', '+/'), true), true);

        return is_array($payload) ? $payload : [];
    }
}
