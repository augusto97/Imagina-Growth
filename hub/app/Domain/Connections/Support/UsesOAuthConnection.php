<?php

declare(strict_types=1);

namespace App\Domain\Connections\Support;

use App\Domain\Connections\Models\OAuthConnection;

/**
 * Puente de credenciales (docs/auditoria-2026-11.md §6): un destino de anuncios o una cuenta de canal con
 * `oauth_connection_id` usa el token de esa conexión en lugar del que se pegó a mano. La conexión se busca
 * siempre dentro del espacio de trabajo del propio registro (nunca la de otro), también desde jobs.
 *
 * @property string|null $oauth_connection_id
 * @property string $workspace_id
 */
trait UsesOAuthConnection
{
    private ?OAuthConnection $linkedConnectionCache = null;

    public function linkedConnection(): ?OAuthConnection
    {
        $id = $this->getAttribute('oauth_connection_id');
        if (! is_string($id) || $id === '') {
            return null;
        }
        if ($this->linkedConnectionCache?->id !== $id) {
            $this->linkedConnectionCache = OAuthConnection::withoutWorkspaceScope()
                ->where('workspace_id', (string) $this->getAttribute('workspace_id'))->whereKey($id)->first();
        }

        return $this->linkedConnectionCache;
    }

    /** El token de la conexión si está activa (sin llamar al proveedor), o null para caer al que se pegó a mano. */
    protected function connectionToken(): ?string
    {
        return $this->linkedConnection()?->freshAccessToken();
    }

    public function forgetLinkedConnection(): void
    {
        $this->linkedConnectionCache = null;
    }
}
