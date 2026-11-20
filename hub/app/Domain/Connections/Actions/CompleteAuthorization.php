<?php

declare(strict_types=1);

namespace App\Domain\Connections\Actions;

use App\Domain\Bookings\Models\BookingStaff;
use App\Domain\Connections\Data\TokenSet;
use App\Domain\Connections\Enums\ConnectionProvider;
use App\Domain\Connections\Models\OAuthConnection;
use App\Domain\Connections\Providers\GoogleProvider;
use App\Domain\Connections\Providers\ProviderRegistry;
use App\Domain\Connections\Support\ConnectionError;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Support\Ids\PrefixedId;

/**
 * Canjea el código de la vuelta y guarda la conexión (una por espacio de trabajo, proveedor y sujeto: volver a
 * conectar reemplaza los tokens). Los tokens quedan cifrados; a la auditoría solo van el proveedor y los alcances.
 * Debe correr con el espacio de trabajo activo (CurrentWorkspace::run).
 */
final class CompleteAuthorization
{
    /** Alcances sin los que la conexión no sirve para nada, por uso. */
    private const REQUIRED = [
        'google:measurement' => ['https://www.googleapis.com/auth/datamanager', 'https://www.googleapis.com/auth/adwords', 'https://www.googleapis.com/auth/analytics.edit'],
        'google:calendar' => ['https://www.googleapis.com/auth/calendar.events'],
    ];

    public function __construct(private readonly ProviderRegistry $providers, private readonly RecordAuditLog $audit) {}

    /**
     * @param  array<string, mixed>  $state  lo que guardó StartAuthorization (`purpose`, `subject`, `code_verifier`)
     *
     * @throws ConnectionError
     */
    public function handle(string $workspaceId, string $userId, ConnectionProvider $provider, string $code, array $state): OAuthConnection
    {
        $purpose = (string) ($state['purpose'] ?? '');
        $subject = (string) ($state['subject'] ?? '');
        $tokens = $this->providers->for($provider)->exchange($code, is_string($state['code_verifier'] ?? null) ? $state['code_verifier'] : null);

        $required = self::REQUIRED[$provider->value.':'.$purpose] ?? [];
        if ($required !== [] && array_intersect($required, $tokens->scopes) === []) {
            throw new ConnectionError(__('No aceptaste los permisos que hacen falta. Vuelve a conectar y deja marcadas las casillas.'), 'scopes');
        }

        $connection = $this->save($workspaceId, $userId, $provider, $subject, $tokens);
        if ($provider === ConnectionProvider::Google && $purpose === 'calendar') {
            $this->linkCalendar($connection, $subject);
        }
        $this->audit->handle('connection.connected', $connection, ['provider' => $provider->value, 'purpose' => $purpose, 'scopes' => $tokens->scopes], $workspaceId, null, $userId);

        return $connection;
    }

    public function save(string $workspaceId, string $userId, ConnectionProvider $provider, string $subject, TokenSet $tokens): OAuthConnection
    {
        $connection = OAuthConnection::query()->firstOrNew(['workspace_id' => $workspaceId, 'provider' => $provider->value, 'subject' => $subject]);
        // Con `include_granted_scopes` Google devuelve también lo concedido antes; Meta y TikTok, solo lo de ahora.
        $connection->fill([
            'status' => OAuthConnection::ACTIVE,
            'external_id' => $tokens->externalId,
            'account_name' => $tokens->accountName !== null ? mb_substr($tokens->accountName, 0, 190) : $connection->account_name,
            'scopes' => $tokens->scopes,
            'credentials' => array_filter(['access_token' => $tokens->accessToken, 'refresh_token' => $tokens->refreshToken ?? $connection->secret('refresh_token')]),
            'assets' => array_replace($connection->assets ?? [], $tokens->assets),
            'expires_at' => $tokens->expiresAt,
            'refreshed_at' => now(),
            'checked_at' => now(),
            'last_error' => null,
            'connected_by' => $userId,
            'revoked_at' => null,
        ])->save();

        return $connection;
    }

    /** Agenda (M18): la persona `staff:{id}` usa esta conexión para su Google Calendar. */
    private function linkCalendar(OAuthConnection $connection, string $subject): void
    {
        $id = str_starts_with($subject, 'staff:') ? substr($subject, 6) : '';
        $staff = PrefixedId::isUlid($id) ? BookingStaff::query()->find($id) : null;
        if ($staff === null) {
            throw new ConnectionError(__('No encontramos a esa persona de la agenda.'), 'asset');
        }
        $staff->forceFill(['calendar' => [
            'connection_id' => $connection->id,
            'calendar_id' => 'primary',
            'email' => $connection->account_name,
            'connected_at' => now()->toIso8601String(),
            'scopes' => array_values(array_intersect($connection->scopes ?? [], GoogleProvider::scopesFor('calendar'))),
        ]])->save();
    }
}
