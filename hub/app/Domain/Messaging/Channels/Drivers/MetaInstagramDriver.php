<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Channels\Drivers;

use App\Domain\Messaging\Models\ChannelAccount;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;

/**
 * Instagram Direct de una cuenta profesional vinculada a una página (M11, Fase 5),
 * por la API de Instagram con inicio de sesión de Facebook. `external_id` = ID de
 * la cuenta de Instagram; el remitente llega con su IGSID. Solo se responde dentro
 * de la ventana de 24 h: sin etiquetas (la bandeja muestra «ventana cerrada»)
 * **[verificar]** con la política vigente de Meta.
 */
final class MetaInstagramDriver extends MetaSocialDriver
{
    public function provider(): string
    {
        return 'meta_instagram';
    }

    public function webhookObject(): string
    {
        return 'instagram';
    }

    protected function identityType(): string
    {
        return 'instagram_igsid';
    }

    protected function maxTextLength(): int
    {
        return 1000;
    }

    public function supportsHumanAgentTag(): bool
    {
        return false;
    }

    public function profile(ChannelAccount $account, string $senderId): ?array
    {
        $token = $account->credential('page_access_token');
        if ($token === null) {
            return null;
        }
        try {
            $response = $this->graphGet($token, $senderId, ['fields' => 'name,username']);
        } catch (ConnectionException) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }
        $name = $response->json('name');
        $username = $response->json('username');
        $full = is_string($name) && trim($name) !== '' ? trim($name) : (is_string($username) && $username !== '' ? '@'.$username : null);
        if ($full === null) {
            return null;
        }
        $parts = explode(' ', $full, 2);

        return ['first_name' => $parts[0], 'last_name' => $parts[1] ?? null];
    }

    public function verify(array $config): array
    {
        $igId = (string) ($config['ig_user_id'] ?? '');
        $response = $this->graphGet((string) ($config['page_access_token'] ?? ''), (string) ($config['page_id'] ?? ''), ['fields' => 'id,name,instagram_business_account{id,username}']);
        if (! $response->successful() || ! is_scalar($response->json('id'))) {
            throw new RuntimeException($this->errorMessage($response));
        }
        $linked = $response->json('instagram_business_account.id');
        if (! is_scalar($linked) || (string) $linked !== $igId) {
            throw new RuntimeException(__('Esa cuenta de Instagram no está vinculada a la página.'));
        }
        $username = $response->json('instagram_business_account.username');

        return ['name' => is_string($username) && $username !== '' ? '@'.$username : 'Instagram', 'external_id' => $igId];
    }
}
