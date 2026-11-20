<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Actions;

use App\Domain\Connections\Support\ConnectionAlerts;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;

/**
 * Meta avisa (`account_update`) que la cuenta de WhatsApp ya no está con la plataforma: se dejó de compartir
 * (`PARTNER_REMOVED`, p. ej. el dueño desconectó la coexistencia desde la app o el celular estuvo inactivo), se
 * quitó el permiso de la app, se borró la cuenta o el número salió de la coexistencia al cambiar de celular.
 * https://developers.facebook.com/documentation/business-messaging/whatsapp/webhooks/reference/account_update
 *
 * Cada número de esa WABA queda `disconnected` con el motivo en palabras (no salen mensajes ni flujos por él) y
 * los dueños y administradores reciben un solo aviso (ConnectionAlerts). Se vuelve a conectar con Embedded Signup.
 */
final class DisconnectWhatsAppFromMeta
{
    public function __construct(private readonly CurrentWorkspace $current, private readonly ConnectionAlerts $alerts, private readonly RecordAuditLog $audit) {}

    /** @return int números desconectados */
    public function handle(string $waba, string $event, ?string $reason): int
    {
        $count = 0;
        $accounts = ChannelAccount::withoutWorkspaceScope()->where('provider', 'meta_cloud')->where('status', '!=', 'disconnected')->orderBy('id')->get()
            ->filter(fn (ChannelAccount $a): bool => $a->credential('waba_id') === $waba);
        foreach ($accounts as $account) {
            $workspace = Workspace::query()->find($account->workspace_id);
            if ($workspace === null) {
                continue;
            }
            $this->current->run($workspace, function () use ($account, $event, $reason, $workspace): void {
                $account->forceFill(['status' => 'disconnected', 'is_default' => false, 'last_error' => mb_substr(self::message($event, $reason), 0, 500)])->save();
                $this->audit->handle('channel.disconnected_by_provider', $account, ['event' => $event, 'reason' => $reason], $workspace->id);
                $this->alerts->channelDisconnected($account);
            });
            $count++;
        }

        return $count;
    }

    public static function message(string $event, ?string $reason): string
    {
        $why = match (true) {
            $event === 'ACCOUNT_OFFBOARDED' => __('el número salió de la app de WhatsApp Business al cambiarlo de celular o registrarlo de nuevo'),
            $event === 'ACCOUNT_DELETED' => __('se borró la cuenta de WhatsApp Business'),
            $event === 'PARTNER_APP_UNINSTALLED' => __('se quitó en Meta el permiso de la app'),
            $reason === 'PRIMARY_INACTIVITY', $reason === 'COMPANION_INACTIVITY' => __('la app de WhatsApp Business del celular estuvo inactiva demasiado tiempo'),
            $reason === 'CHANGE_NUMBER', $reason === 'USER_RE_REGISTERED' => __('el número cambió o se volvió a registrar en otro celular'),
            $reason === 'ACCOUNT_DISCONNECTED' => __('se desconectó desde la app de WhatsApp Business'),
            default => __('la cuenta se dejó de compartir con la plataforma'),
        };

        return __('Meta desconectó este número: :why. Vuelve a conectarlo en Conexiones.', ['why' => $why]);
    }
}
