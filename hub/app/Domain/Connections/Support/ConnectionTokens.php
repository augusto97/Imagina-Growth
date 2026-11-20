<?php

declare(strict_types=1);

namespace App\Domain\Connections\Support;

use App\Domain\Connections\Data\TokenSet;
use App\Domain\Connections\Models\OAuthConnection;
use App\Domain\Connections\Providers\ProviderRegistry;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Token de acceso vigente de una conexión, con refresco automático: si vence en menos de un minuto, lo renueva
 * con el refresh token bajo un candado (dos jobs a la vez no gastan dos renovaciones) y lo guarda cifrado. Si el
 * proveedor dice que el acceso ya no sirve, la conexión queda `expired` con el aviso para volver a conectar.
 */
class ConnectionTokens
{
    public function __construct(private readonly ProviderRegistry $providers) {}

    /** @throws ConnectionError */
    public function accessToken(OAuthConnection $connection): string
    {
        if (! $connection->isActive()) {
            throw ConnectionError::reconnect($connection->provider->label());
        }
        $token = $connection->freshAccessToken();
        if ($token !== null) {
            return $token;
        }
        try {
            return Cache::lock('oac-refresh:'.$connection->id, 30)->block(15, function () use ($connection): string {
                $connection->refresh();  // otro proceso pudo renovarlo mientras se esperaba el candado

                return $connection->freshAccessToken() ?? $this->renew($connection)->accessToken;
            });
        } catch (LockTimeoutException) {
            throw new ConnectionError(__('La renovación del acceso tardó demasiado. Se reintenta solo.'), 'provider');
        }
    }

    /** Renueva ya (lo usa la revisión diaria). @throws ConnectionError */
    public function renew(OAuthConnection $connection): TokenSet
    {
        try {
            $set = $this->providers->for($connection->provider)->refresh($connection);
        } catch (ConnectionError $e) {
            if ($e->permanent) {
                $this->markExpired($connection, $e->getMessage());
            } else {
                $connection->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 500)])->save();
            }
            throw $e;
        }
        $connection->forceFill([
            'credentials' => array_replace($connection->credentials ?? [], array_filter(['access_token' => $set->accessToken, 'refresh_token' => $set->refreshToken])),
            'expires_at' => $set->expiresAt,
            'scopes' => $set->scopes !== [] ? $set->scopes : $connection->scopes,
            'refreshed_at' => now(),
            'last_error' => null,
        ])->save();

        return $set;
    }

    public function markExpired(OAuthConnection $connection, string $message): void
    {
        $connection->forceFill(['status' => OAuthConnection::EXPIRED, 'last_error' => mb_substr($message, 0, 500)])->save();
        Log::notice('igr.connections.expired', ['connection' => $connection->publicId(), 'provider' => $connection->provider->value]);
        try {
            // El aviso a dueños y administradores sale en el momento (una sola vez por cambio de estado).
            app(ConnectionAlerts::class)->check($connection);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
