<?php

declare(strict_types=1);

namespace App\Domain\Connections\Support;

use App\Domain\Connections\Enums\ConnectionProvider;
use App\Domain\Connections\Mail\ConnectionAlertMail;
use App\Domain\Connections\Models\OAuthConnection;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Workspaces\Contracts\Branding;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Avisos de conexiones por vencer o caídas (lote 4, docs/auditoria-2026-11.md §6):
 *
 * - **Conexiones OAuth** que vencen de verdad (con `expires_at` y sin refresh token: los tokens de usuario de Meta;
 *   Google se renueva solo y el usuario del sistema de Meta y TikTok no vencen): aviso 7 días y 1 día antes.
 * - **Conexiones que quedaron `expired`** (Google `invalid_grant`, Meta `is_valid = false`, o con la fecha pasada).
 * - **Cuentas de canal que Meta desconectó** (`account_update`: se dejó de compartir la cuenta, se quitó la app o el
 *   número salió de la coexistencia; DisconnectWhatsAppFromMeta).
 *
 * El correo va a dueños y administradores del espacio de trabajo **una vez por cambio de estado**
 * (`alert_state`): `expires_7d` → `expires_1d` → `expired`, y se limpia cuando vuelve a estar bien. El Inicio y
 * Conexiones muestran `problems()` con el botón «Reconectar». Lo revisa `igr:connections:check` cada noche y, al
 * momento, `ConnectionTokens::markExpired`.
 */
class ConnectionAlerts
{
    public const EXPIRES_7D = 'expires_7d';

    public const EXPIRES_1D = 'expires_1d';

    public const EXPIRED = 'expired';

    public const DISCONNECTED = 'disconnected';

    /** Días que una cuenta de canal desconectada por Meta sigue en el aviso si nadie la reconecta. */
    public const CHANNEL_NOTICE_DAYS = 30;

    public function __construct(private readonly CurrentWorkspace $current, private readonly Branding $branding) {}

    /** Estado que merece aviso, o null si la conexión está bien (o ya se desconectó a propósito). */
    public static function stateOf(OAuthConnection $c, ?CarbonImmutable $now = null): ?string
    {
        if (in_array($c->status, [OAuthConnection::EXPIRED, OAuthConnection::ERROR], true)) {
            return self::EXPIRED;
        }
        if ($c->status !== OAuthConnection::ACTIVE || ! self::expires($c) || $c->expires_at === null) {
            return null;
        }
        $left = $c->expires_at->getTimestamp() - ($now ?? CarbonImmutable::now())->getTimestamp();

        return match (true) {
            $left <= 0 => self::EXPIRED,
            $left <= 86_400 => self::EXPIRES_1D,
            $left <= 7 * 86_400 => self::EXPIRES_7D,
            default => null,
        };
    }

    /** ¿El acceso vence de verdad? Google se renueva solo con el refresh token. */
    public static function expires(OAuthConnection $c): bool
    {
        return $c->expires_at !== null && $c->provider !== ConnectionProvider::Google && $c->secret('refresh_token') === null;
    }

    /** Revisa una conexión y avisa si cambió de estado. Devuelve el estado avisado ahora, o null. */
    public function check(OAuthConnection $connection): ?string
    {
        $state = self::stateOf($connection);
        if ($state === $connection->alert_state) {
            return null;
        }
        $connection->forceFill(['alert_state' => $state, 'alert_sent_at' => $state !== null ? now() : null])->save();
        if ($state === null) {
            return null;
        }
        $workspace = Workspace::query()->find($connection->workspace_id);
        if ($workspace !== null) {
            $this->send($workspace, $state, $connection->provider->label(), $connection->account_name, $connection->expires_at !== null ? CarbonImmutable::instance($connection->expires_at) : null,
                $state === self::EXPIRED ? $connection->last_error : null, str_starts_with($connection->subject, 'staff:') ? 'agenda' : 'conexiones', $connection->publicId());
        }

        return $state;
    }

    /** Todas las conexiones que pueden necesitar aviso, en su espacio de trabajo. @return int avisos enviados */
    public function sweep(?string $workspaceId = null): int
    {
        $sent = 0;
        $query = OAuthConnection::withoutWorkspaceScope()
            ->where(fn ($q) => $q->whereIn('status', [OAuthConnection::EXPIRED, OAuthConnection::ERROR])
                ->orWhere(fn ($w) => $w->where('status', OAuthConnection::ACTIVE)->whereNotNull('expires_at'))
                ->orWhereNotNull('alert_state'))
            ->when($workspaceId !== null, fn ($q) => $q->where('workspace_id', $workspaceId))
            ->orderBy('id');
        foreach ($query->lazyById(100) as $connection) {
            $workspace = Workspace::query()->find($connection->workspace_id);
            if ($workspace !== null && $this->current->run($workspace, fn (): ?string => $this->check($connection)) !== null) {
                $sent++;
            }
        }

        return $sent;
    }

    /** Meta desconectó la cuenta (número de WhatsApp): un aviso, aunque Meta repita el webhook. */
    public function channelDisconnected(ChannelAccount $account): void
    {
        if ($account->alert_state === self::DISCONNECTED) {
            return;
        }
        $account->forceFill(['alert_state' => self::DISCONNECTED, 'alert_sent_at' => now()])->save();
        $workspace = Workspace::query()->find($account->workspace_id);
        if ($workspace !== null) {
            $this->send($workspace, self::DISCONNECTED, $account->channel->label(), $account->identity ?? $account->display_name, null, $account->last_error, 'conexiones', $account->publicId());
        }
    }

    /**
     * Lo que muestra el aviso del Inicio y de Conexiones, lo más urgente primero. Corre con el espacio de trabajo activo.
     *
     * @return list<array<string, mixed>>
     */
    public function problems(): array
    {
        $order = [self::EXPIRED => 0, self::DISCONNECTED => 1, self::EXPIRES_1D => 2, self::EXPIRES_7D => 3];
        $out = [];
        foreach (OAuthConnection::query()->whereIn('status', [OAuthConnection::ACTIVE, OAuthConnection::EXPIRED, OAuthConnection::ERROR])->orderBy('created_at')->get() as $c) {
            $state = self::stateOf($c);
            if ($state === null) {
                continue;
            }
            $out[] = [
                'kind' => 'connection',
                'id' => $c->publicId(),
                'provider' => $c->provider->value,
                'subject' => match (true) {
                    str_starts_with($c->subject, 'staff:') => 'calendar',
                    $c->provider === ConnectionProvider::MetaWhatsApp => 'whatsapp',
                    default => 'workspace',
                },
                'state' => $state,
                'name' => $c->account_name,
                'expires_at' => $c->expires_at?->toIso8601String(),
                'message' => $state === self::EXPIRED ? $c->last_error : null,
            ];
        }
        $accounts = ChannelAccount::query()->where('status', 'disconnected')->where('alert_state', self::DISCONNECTED)
            ->where('alert_sent_at', '>=', now()->subDays(self::CHANNEL_NOTICE_DAYS))->orderBy('alert_sent_at')->get();
        foreach ($accounts as $a) {
            // Ya se volvió a conectar el mismo número o página: el aviso sobra.
            if ($a->external_id !== null && ChannelAccount::query()->where('provider', $a->provider)->where('external_id', $a->external_id)->where('status', '!=', 'disconnected')->exists()) {
                continue;
            }
            $out[] = [
                'kind' => 'channel',
                'id' => $a->publicId(),
                'provider' => in_array($a->provider, ['meta_cloud'], true) ? 'meta_whatsapp' : 'meta',
                'subject' => $a->channel->value,
                'state' => self::DISCONNECTED,
                'name' => $a->identity ?? $a->display_name,
                'expires_at' => null,
                'message' => $a->last_error,
            ];
        }
        usort($out, fn (array $x, array $y): int => $order[$x['state']] <=> $order[$y['state']]);

        return $out;
    }

    private function send(Workspace $workspace, string $state, string $provider, ?string $account, ?CarbonImmutable $expiresAt, ?string $detail, string $path, string $ref): void
    {
        $brand = $this->branding->for($workspace);
        $timezone = (string) ($workspace->timezone ?: 'America/Bogota');
        $date = $expiresAt?->setTimezone($timezone)->settings(['locale' => 'es'])->isoFormat('D [de] MMMM');
        $url = rtrim((string) config('app.url'), '/').'/w/'.$workspace->publicId().'/'.ltrim((string) (config('igr-connections.return_paths.'.$path) ?? $path), '/');
        $recipients = $workspace->users()->wherePivotIn('role', [WorkspaceRole::Owner->value, WorkspaceRole::Admin->value])->pluck('email')
            ->map(fn ($e) => (string) $e)->filter(fn (string $e) => filter_var($e, FILTER_VALIDATE_EMAIL) !== false)->unique()->take(10);
        foreach ($recipients as $to) {
            try {
                Mail::to($to)->send(new ConnectionAlertMail($workspace->name, $state, $provider, $account, $date, $detail !== null ? mb_substr($detail, 0, 300) : null, $brand, $url));
            } catch (Throwable $e) {
                Log::warning('igr.connections.alert_failed', ['workspace' => $workspace->id, 'ref' => $ref, 'state' => $state, 'error' => class_basename($e)]);
            }
        }
        Log::info('igr.connections.alert', ['workspace' => $workspace->id, 'ref' => $ref, 'state' => $state, 'recipients' => $recipients->count()]);
    }
}
