<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Http\Api;

use App\Domain\Messaging\Actions\ConnectSocialAccount;
use App\Domain\Messaging\Channels\Drivers\MetaInstagramDriver;
use App\Domain\Messaging\Channels\Drivers\MetaMessengerDriver;
use App\Domain\Messaging\Channels\Drivers\MetaSocialDriver;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Models\ChannelAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Configuración → Canales: Messenger e Instagram Direct (M11, Fase 5). Conexión
 * manual con la misma app de Meta de WhatsApp: el ID de la página, su token de
 * acceso (permanente, de un usuario del sistema) y, para Instagram, el ID de la
 * cuenta profesional vinculada. La de un clic (Conexiones → Meta) usa la misma
 * acción, ConnectSocialAccount, que comprueba, suscribe y guarda.
 */
final class SocialChannelController
{
    public function __construct(private readonly ConnectSocialAccount $connect) {}

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
        return response()->json(['data' => ChannelAccountPresenter::present($this->connect->handle($driver, $channel, $config, $name, 'manual'))], 201);
    }
}
