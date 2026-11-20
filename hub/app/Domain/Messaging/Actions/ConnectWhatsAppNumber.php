<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Actions;

use App\Domain\Messaging\Channels\Drivers\MetaCloudDriver;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Alta de un número de WhatsApp oficial (M07): lee el número, el nombre verificado, la calidad y el límite con la
 * Cloud API, evita el mismo número en dos espacios de trabajo, lo deja como cuenta por defecto si no hay otra y
 * sincroniza las plantillas. La usan la conexión manual (token pegado) y Embedded Signup (token en la conexión).
 */
final class ConnectWhatsAppNumber
{
    public function __construct(private readonly MetaCloudDriver $meta, private readonly CurrentWorkspace $current, private readonly RecordAuditLog $audit) {}

    /**
     * @param  array<string, mixed>  $config  waba_id, phone_number_id, connected_via y, a mano, access_token
     *
     * @throws ValidationException
     */
    public function handle(array $config, ?string $name, ?string $connectionId = null, string $status = 'active', ?string $lastError = null): ChannelAccount
    {
        $phoneId = (string) $config['phone_number_id'];
        $existing = ChannelAccount::withoutWorkspaceScope()->where('provider', 'meta_cloud')->where('external_id', $phoneId)->where('status', '!=', 'disconnected')->first();
        $reconnect = $existing !== null && $connectionId !== null && $existing->workspace_id === $this->current->id();
        if ($existing !== null && ! $reconnect) {
            throw ValidationException::withMessages(['phone_number_id' => __('Ese número ya está conectado en Imagina Growth.')]);
        }
        $account = $reconnect ? $existing : new ChannelAccount(['workspace_id' => $this->current->id(), 'channel' => Channel::WhatsApp, 'provider' => 'meta_cloud', 'is_default' => false]);
        $account->forceFill(['config' => $config, 'oauth_connection_id' => $connectionId, 'external_id' => $phoneId]);
        $account->forgetLinkedConnection();
        try {
            $info = $this->meta->phoneInfo($account);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['access_token' => __('Meta no reconoció el número con ese token: :msg', ['msg' => $e->getMessage()])]);
        }
        $account->forceFill([
            'display_name' => mb_substr($name ?? ($account->exists ? $account->display_name : ($info['verified_name'] ?? 'WhatsApp')), 0, 120),
            'identity' => $info['display_phone_number'],
            'quality_rating' => $info['quality_rating'],
            'messaging_limit_tier' => $info['messaging_limit_tier'],
            'status' => $status,
            'last_error' => $lastError !== null ? mb_substr($lastError, 0, 500) : null,
        ])->save();
        if ($status === 'active' && ! ChannelAccount::query()->where('channel', Channel::WhatsApp->value)->where('is_default', true)->where('status', 'active')->whereKeyNot($account->id)->exists()) {
            $account->forceFill(['is_default' => true])->save();
        }
        $this->audit->handle('channel.created', $account, ['provider' => 'meta_cloud', 'via' => (string) ($config['connected_via'] ?? 'manual')], $account->workspace_id);
        try {
            $this->meta->syncTemplates($account);
        } catch (RuntimeException) {
            // Las plantillas se pueden sincronizar después desde el panel.
        }

        return $account;
    }
}
