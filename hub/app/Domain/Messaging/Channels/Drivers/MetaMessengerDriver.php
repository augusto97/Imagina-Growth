<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Channels\Drivers;

use App\Domain\Messaging\Models\ChannelAccount;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;

/**
 * Facebook Messenger de una página (M11, Fase 5). `external_id` = ID de la
 * página; el remitente llega con su PSID. Fuera de la ventana de 24 h solo una
 * persona del equipo puede responder con `HUMAN_AGENT`, hasta 7 días después del
 * último mensaje del cliente **[verificar]** con la política vigente de Meta.
 */
final class MetaMessengerDriver extends MetaSocialDriver
{
    public function provider(): string
    {
        return 'meta_messenger';
    }

    public function webhookObject(): string
    {
        return 'page';
    }

    protected function identityType(): string
    {
        return 'messenger_psid';
    }

    protected function maxTextLength(): int
    {
        return 2000;
    }

    public function supportsHumanAgentTag(): bool
    {
        return true;
    }

    public function profile(ChannelAccount $account, string $senderId): ?array
    {
        $token = $account->credential('page_access_token');
        if ($token === null) {
            return null;
        }
        try {
            $response = $this->graphGet($token, $senderId, ['fields' => 'first_name,last_name']);
        } catch (ConnectionException) {
            return null;
        }
        if (! $response->successful()) {
            return null; // sin el permiso de perfil la bandeja muestra «Cliente de Messenger»
        }
        $str = fn (string $k) => is_string($response->json($k)) && $response->json($k) !== '' ? (string) $response->json($k) : null;

        return ['first_name' => $str('first_name'), 'last_name' => $str('last_name')];
    }

    public function verify(array $config): array
    {
        $response = $this->graphGet((string) ($config['page_access_token'] ?? ''), (string) ($config['page_id'] ?? ''), ['fields' => 'id,name']);
        if (! $response->successful() || ! is_scalar($response->json('id'))) {
            throw new RuntimeException($this->errorMessage($response));
        }
        if ((string) $response->json('id') !== (string) ($config['page_id'] ?? '')) {
            throw new RuntimeException(__('El token no pertenece a esa página.'));
        }

        return ['name' => is_string($response->json('name')) ? (string) $response->json('name') : 'Messenger', 'external_id' => (string) $response->json('id')];
    }
}
