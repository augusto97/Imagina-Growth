<?php

declare(strict_types=1);

namespace Tests\Feature\Connections\Support;

use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

/**
 * Datos de las pruebas de Conexiones: un espacio de trabajo con su dueña (2FA) y las apps de Meta, Google y
 * TikTok «configuradas» con valores de prueba. No hay apps reales: todo va con Http::fake.
 *
 * @property User $owner
 * @property Workspace $ws
 * @property string $api
 */
trait ConnectionFixtures
{
    public function setUpConnections(): void
    {
        config([
            'app.url' => 'https://growth.test',
            'igr-connections.google.client_id' => 'google-client',
            'igr-connections.google.client_secret' => 'google-secret',
            'igr-connections.meta.app_id' => '1234',
            'igr-connections.meta.app_secret' => 'meta-secret',
            'igr-connections.meta.login_config_id' => 'cfg-login',
            'igr-connections.meta.whatsapp_config_id' => 'cfg-wa',
            'igr-connections.meta.graph_url' => 'https://graph.test',
            'igr-connections.meta.dialog_url' => 'https://www.facebook.test',
            'igr-connections.tiktok.app_id' => 'tt-app',
            'igr-connections.tiktok.app_secret' => 'tt-secret',
            'igr-messaging.meta.graph_url' => 'https://graph.test',
            'igr-measurement.meta.graph_url' => 'https://graph.test',
        ]);
        $this->owner = $this->userWith2fa(['email' => 'duena@pinturas.co']);
        $this->ws = $this->workspaceFor($this->owner);
        $this->api = "/api/w/{$this->ws->publicId()}";
    }

    /**
     * Http::fake suma los stubs y gana el primero: para cambiar la respuesta de una URL ya simulada hay que
     * empezar con una fábrica nueva.
     *
     * @param  array<string, mixed>  $stubs
     */
    public function refake(array $stubs): void
    {
        Http::swap(new Factory(app('events')));
        Http::fake($stubs);
    }

    /** @return array<string, string> la query de una URL */
    public function urlQuery(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        return array_map('strval', $q);
    }

    /** id_token de prueba (solo el payload importa: llega directo del endpoint de tokens). */
    public function idToken(string $email): string
    {
        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');

        return $b64('{"alg":"none"}').'.'.$b64((string) json_encode(['sub' => '1098', 'email' => $email])).'.firma';
    }

    /** Empieza el OAuth de Google y vuelve con el código; devuelve la URL a la que redirigió el panel. */
    public function connectGoogle(?User $as = null, string $scopes = GoogleScopes::ALL): string
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.primero', 'refresh_token' => 'rt-google', 'expires_in' => 3599, 'scope' => $scopes, 'id_token' => $this->idToken('duena@pinturas.co'), 'token_type' => 'Bearer']),
            'oauth2.googleapis.com/revoke' => Http::response([]),
        ]);
        $url = $this->actingAs($as ?? $this->owner)->postJson("{$this->api}/connections/google/start")->assertOk()->json('url');

        return (string) $this->actingAs($as ?? $this->owner)->get('/oauth/google/callback?'.http_build_query(['state' => $this->urlQuery((string) $url)['state'], 'code' => '4/c0de']))->headers->get('Location');
    }

    /** @return array<string, mixed> respuesta de debug_token de Meta */
    public function debugToken(array $scopes, array $granular = [], string $appId = '1234'): array
    {
        return ['data' => [
            'app_id' => $appId, 'type' => 'SYSTEM_USER', 'application' => 'Imagina Growth', 'is_valid' => true, 'expires_at' => 0, 'user_id' => '777',
            'scopes' => $scopes,
            'granular_scopes' => array_map(fn (string $scope, ?array $ids) => array_filter(['scope' => $scope, 'target_ids' => $ids], fn ($v) => $v !== null), array_keys($granular), array_values($granular)),
        ]];
    }
}
