<?php

declare(strict_types=1);

namespace App\Domain\Connections\Actions;

use App\Domain\Bookings\Models\BookingStaff;
use App\Domain\Connections\Models\OAuthConnection;
use App\Domain\Connections\Providers\ProviderRegistry;
use App\Domain\Connections\Support\ConnectionError;
use App\Domain\Measurement\Enums\Platform;
use App\Domain\Measurement\Models\AdDestination;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Desconecta: revoca el acceso en el proveedor (lo mejor posible), borra los tokens y suelta lo que dependía de la
 * conexión. Los destinos de anuncios vuelven a sus tokens pegados a mano si los tienen; si no, quedan en pausa con
 * el aviso. Las cuentas de WhatsApp, Messenger e Instagram conectadas así quedan desconectadas, y las personas de
 * la agenda sin calendario. Corre con el espacio de trabajo activo.
 */
final class DisconnectConnection
{
    public function __construct(private readonly ProviderRegistry $providers, private readonly RecordAuditLog $audit) {}

    public function handle(OAuthConnection $connection, bool $revokeRemote = true): void
    {
        if ($revokeRemote && $connection->secret('access_token') !== null && ! $this->sharedGrant($connection)) {
            try {
                $this->providers->for($connection->provider)->revoke($connection);
            } catch (ConnectionError $e) {
                // Sin red o con el token ya revocado: se borra igual en el hub.
                Log::notice('igr.connections.revoke_failed', ['connection' => $connection->publicId(), 'reason' => $e->reason]);
            }
        }

        DB::transaction(function () use ($connection): void {
            $connection->forceFill(['status' => OAuthConnection::REVOKED, 'credentials' => null, 'revoked_at' => now(), 'expires_at' => null])->save();
            $notice = __('Se desconectó la cuenta de :provider. Vuelve a conectarla en Conexiones o pega los datos a mano.', ['provider' => $connection->provider->label()]);

            foreach (AdDestination::query()->where('oauth_connection_id', $connection->id)->get() as $destination) {
                $destination->oauth_connection_id = null;
                $destination->forgetLinkedConnection();
                $manual = match ($destination->platform) {
                    Platform::Google => $destination->hasGoogleAuth() || $destination->credential('api_secret') !== null,
                    default => $destination->credential('access_token') !== null,
                };
                if (! $manual) {
                    $destination->status = 'paused';
                    $destination->last_error = $notice;
                }
                $destination->save();
            }

            foreach (ChannelAccount::query()->where('oauth_connection_id', $connection->id)->get() as $account) {
                $keep = array_intersect_key($account->config ?? [], array_flip(['waba_id', 'phone_number_id', 'page_id', 'ig_user_id']));
                $account->forceFill(['oauth_connection_id' => null, 'status' => 'disconnected', 'is_default' => false, 'config' => $keep, 'last_error' => mb_substr($notice, 0, 500)])->save();
            }

            foreach (BookingStaff::query()->whereNotNull('calendar')->get() as $staff) {
                if (($staff->calendar['connection_id'] ?? null) === $connection->id) {
                    $staff->forceFill(['calendar' => null])->save();
                }
            }
        });

        $this->audit->handle('connection.disconnected', $connection, ['provider' => $connection->provider->value], $connection->workspace_id);
    }

    /**
     * ¿Otra conexión activa usa el mismo permiso en el proveedor? Revocar en Google quita el acceso de esa cuenta a todo
     * el proyecto (https://developers.google.com/identity/protocols/oauth2/web-server#tokenrevoke), y en Meta el permiso es
     * del mismo usuario del sistema o de la misma WABA: si una agencia conectó la misma cuenta en dos espacios de
     * trabajo (o la misma persona conectó su calendario), revocar aquí rompería la otra. Solo se borra en el hub.
     */
    private function sharedGrant(OAuthConnection $connection): bool
    {
        $others = OAuthConnection::withoutWorkspaceScope()->where('provider', $connection->provider->value)
            ->where('status', OAuthConnection::ACTIVE)->whereKeyNot($connection->id);

        return match (true) {
            str_starts_with($connection->subject, 'waba:') => $others->where('subject', $connection->subject)->exists(),
            $connection->external_id !== null && $connection->external_id !== '' => $others->where('external_id', $connection->external_id)->exists(),
            default => false,
        };
    }
}
