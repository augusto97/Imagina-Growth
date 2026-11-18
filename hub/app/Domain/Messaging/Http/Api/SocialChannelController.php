<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Http\Api;

use App\Domain\Messaging\Channels\Drivers\MetaInstagramDriver;
use App\Domain\Messaging\Channels\Drivers\MetaMessengerDriver;
use App\Domain\Messaging\Channels\Drivers\MetaSocialDriver;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Configuración → Canales: Messenger e Instagram Direct (M11, Fase 5). Conexión
 * manual con la misma app de Meta de WhatsApp: el ID de la página, su token de
 * acceso (permanente, de un usuario del sistema) y, para Instagram, el ID de la
 * cuenta profesional vinculada. Se comprueba con la Graph API antes de guardar y
 * se suscribe la app a los mensajes de la página; si la suscripción falla, la
 * cuenta queda activa con el aviso para hacerlo a mano.
 */
final class SocialChannelController
{
    public function __construct(private readonly CurrentWorkspace $current, private readonly RecordAuditLog $audit) {}

    /** POST /api/w/{ws}/channels/messenger */
    public function messenger(Request $request, MetaMessengerDriver $driver): JsonResponse
    {
        Gate::authorize('ws.manageWorkspace');
        $data = $request->validate([
            'page_id' => ['required', 'string', 'regex:/^\d{5,30}$/'],
            'page_access_token' => ['required', 'string', 'min:20', 'max:1000'],
            'display_name' => ['nullable', 'string', 'max:120'],
        ]);

        return $this->connect($driver, Channel::Messenger, ['page_id' => $data['page_id'], 'page_access_token' => $data['page_access_token']], $data['display_name'] ?? null);
    }

    /** POST /api/w/{ws}/channels/instagram */
    public function instagram(Request $request, MetaInstagramDriver $driver): JsonResponse
    {
        Gate::authorize('ws.manageWorkspace');
        $data = $request->validate([
            'page_id' => ['required', 'string', 'regex:/^\d{5,30}$/'],
            'ig_user_id' => ['required', 'string', 'regex:/^\d{5,30}$/'],
            'page_access_token' => ['required', 'string', 'min:20', 'max:1000'],
            'display_name' => ['nullable', 'string', 'max:120'],
        ]);

        return $this->connect($driver, Channel::Instagram, [
            'page_id' => $data['page_id'], 'ig_user_id' => $data['ig_user_id'], 'page_access_token' => $data['page_access_token'],
        ], $data['display_name'] ?? null);
    }

    /**
     * Vuelve a comprobar el token y la página (estado de la conexión). Lo llama
     * ChannelController::sync para las cuentas de Messenger e Instagram.
     */
    public static function check(ChannelAccount $account, MetaSocialDriver $driver): ChannelAccount
    {
        $config = array_map(fn ($v) => (string) $v, array_filter((array) $account->config, 'is_scalar'));
        try {
            $info = $driver->verify($config);
        } catch (RuntimeException $e) {
            $account->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 500)])->save();
            throw ValidationException::withMessages(['account' => $e->getMessage()]);
        }
        $account->forceFill(['identity' => $info['name'], 'last_error' => null])->save();

        return $account;
    }

    /** @param array<string, string> $config */
    private function connect(MetaSocialDriver $driver, Channel $channel, array $config, ?string $name): JsonResponse
    {
        try {
            $info = $driver->verify($config);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['page_access_token' => __('Meta no reconoció la cuenta con ese token: :msg', ['msg' => $e->getMessage()])]);
        }
        if (ChannelAccount::withoutWorkspaceScope()->where('provider', $driver->provider())->where('external_id', $info['external_id'])->where('status', '!=', 'disconnected')->exists()) {
            throw ValidationException::withMessages(['page_id' => __('Esa cuenta ya está conectada en Imagina Growth.')]);
        }
        $subscribeError = $driver->subscribePage($config['page_id'], $config['page_access_token']);
        $account = ChannelAccount::query()->create([
            'workspace_id' => $this->current->id(),
            'channel' => $channel,
            'provider' => $driver->provider(),
            'display_name' => mb_substr($name ?? $info['name'], 0, 120),
            'config' => $config + ['connected_via' => 'manual'],
            'external_id' => $info['external_id'],
            'identity' => $info['name'],
            'status' => 'active',
            'is_default' => ! ChannelAccount::query()->where('channel', $channel->value)->where('is_default', true)->where('status', 'active')->exists(),
            'last_error' => $subscribeError !== null
                ? mb_substr(__('No se pudo suscribir la app a los mensajes de la página (:msg). Hazlo en la app de Meta: Webhooks → :object.', ['msg' => $subscribeError, 'object' => $driver->webhookObject()]), 0, 500)
                : null,
        ]);
        $this->audit->handle('channel.created', $account, ['provider' => $driver->provider(), 'via' => 'manual'], $account->workspace_id);

        return response()->json(['data' => ChannelAccountPresenter::present($account)], 201);
    }
}
