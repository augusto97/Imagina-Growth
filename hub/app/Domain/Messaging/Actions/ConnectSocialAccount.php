<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Actions;

use App\Domain\Messaging\Channels\Drivers\MetaSocialDriver;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Alta de una cuenta de Messenger o Instagram Direct (M11): comprueba la página (y la cuenta de Instagram
 * vinculada) con su token, evita que la misma página esté en dos espacios de trabajo y suscribe la app a sus
 * mensajes; si la suscripción falla, la cuenta queda activa con el aviso para hacerlo a mano. La usan la
 * conexión manual (SocialChannelController) y la de un clic (Conexiones → Meta).
 */
final class ConnectSocialAccount
{
    public function __construct(private readonly CurrentWorkspace $current, private readonly RecordAuditLog $audit) {}

    /**
     * @param  array<string, string>  $config  page_id, page_access_token y, para Instagram, ig_user_id
     *
     * @throws ValidationException
     */
    public function handle(MetaSocialDriver $driver, Channel $channel, array $config, ?string $name, string $via, ?string $connectionId = null): ChannelAccount
    {
        try {
            $info = $driver->verify($config);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['page_access_token' => __('Meta no reconoció la cuenta con ese token: :msg', ['msg' => $e->getMessage()])]);
        }
        $existing = ChannelAccount::withoutWorkspaceScope()->where('provider', $driver->provider())->where('external_id', $info['external_id'])->where('status', '!=', 'disconnected')->first();
        if ($existing !== null && ! ($connectionId !== null && $existing->workspace_id === $this->current->id())) {
            throw ValidationException::withMessages(['page_id' => __('Esa cuenta ya está conectada en Imagina Growth.')]);
        }
        $subscribeError = $driver->subscribePage($config['page_id'], $config['page_access_token']);
        $attributes = [
            'workspace_id' => $this->current->id(),
            'channel' => $channel,
            'provider' => $driver->provider(),
            'display_name' => mb_substr($name ?? $info['name'], 0, 120),
            'config' => $config + ['connected_via' => $via],
            'oauth_connection_id' => $connectionId,
            'external_id' => $info['external_id'],
            'identity' => $info['name'],
            'status' => 'active',
            'last_error' => $subscribeError !== null
                ? mb_substr(__('No se pudo suscribir la app a los mensajes de la página (:msg). Hazlo en la app de Meta: Webhooks → :object.', ['msg' => $subscribeError, 'object' => $driver->webhookObject()]), 0, 500)
                : null,
        ];
        if ($existing !== null) {
            // Volver a conectar con un clic la misma página del mismo espacio de trabajo: se actualiza la cuenta.
            $existing->forceFill($attributes)->save();
            $account = $existing;
        } else {
            $account = ChannelAccount::query()->create($attributes + [
                'is_default' => ! ChannelAccount::query()->where('channel', $channel->value)->where('is_default', true)->where('status', 'active')->exists(),
            ]);
        }
        $this->audit->handle('channel.created', $account, ['provider' => $driver->provider(), 'via' => $via], $account->workspace_id);

        return $account;
    }
}
