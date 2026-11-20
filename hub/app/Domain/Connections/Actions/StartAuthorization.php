<?php

declare(strict_types=1);

namespace App\Domain\Connections\Actions;

use App\Domain\Connections\Enums\ConnectionProvider;
use App\Domain\Connections\Providers\ProviderRegistry;
use App\Domain\Connections\Support\ConnectionError;
use App\Domain\Connections\Support\OAuthStates;

/**
 * Arma la URL de consentimiento del proveedor: `state` firmado y de un solo uso, ligado a la persona y al
 * espacio de trabajo, y PKCE S256 donde el proveedor lo acepta. La vuelta siempre es {APP_URL}/oauth/{p}/callback
 * y después la persona vuelve a una pantalla fija del panel (`return`), nunca a una URL que llegue de afuera.
 */
final class StartAuthorization
{
    public function __construct(private readonly ProviderRegistry $providers, private readonly OAuthStates $states) {}

    /** @throws ConnectionError */
    public function handle(string $workspaceId, string $userId, ConnectionProvider $provider, string $purpose, string $subject = '', string $return = 'conexiones'): string
    {
        $oauth = $this->providers->for($provider);
        if (! $provider->usesRedirect()) {
            throw new ConnectionError(__('Este proveedor se conecta desde su ventana, no con una redirección.'), 'provider');
        }
        if ($oauth->missing() !== []) {
            throw ConnectionError::notConfigured($provider->label());
        }
        if (! in_array($purpose, $oauth->purposes(), true)) {
            throw new ConnectionError(__('Ese uso no existe para :provider.', ['provider' => $provider->label()]), 'provider');
        }
        $verifier = $challenge = null;
        if ($oauth->supportsPkce()) {
            [$verifier, $challenge] = OAuthStates::pkce();
        }
        $state = $this->states->issue($workspaceId, $userId, $provider, [
            'purpose' => $purpose,
            'subject' => $subject,
            'return' => array_key_exists($return, (array) config('igr-connections.return_paths')) ? $return : 'conexiones',
            'code_verifier' => $verifier,
        ]);

        return $oauth->authorizationUrl($state, $purpose, $challenge);
    }
}
