<?php

declare(strict_types=1);

namespace App\Domain\Agency\Support;

use App\Domain\Agency\Models\SupportSession;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Enums\AccountType;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\RedisKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Sesiones de soporte (M22, hub/CLAUDE.md §4): cuando alguien de la agencia
 * entra a un cliente sin ser miembro directo, queda una sesión con motivo, hora
 * de inicio, última actividad (`ended_at`) y huella de la IP (HMAC, nunca la IP),
 * más una entrada en la auditoría del cliente. Una sesión sigue abierta mientras
 * haya actividad en los últimos 30 minutos.
 */
final class SupportSessions
{
    private const WINDOW_MINUTES = 30;

    public function __construct(private readonly RecordAuditLog $audit) {}

    /** Desde ResolveWorkspace: solo cuentas de agencia; una sesión por ventana de actividad. */
    public function onAgencyAccess(User $user, Workspace $workspace, ?string $ip = null): ?SupportSession
    {
        $key = RedisKey::for($workspace->id, 'support:'.$user->id);
        $open = Cache::get($key);
        if (is_string($open)) {
            $this->keepAlive($key, $open);

            return null;
        }
        $type = DB::table('accounts')->where('id', $workspace->account_id)->value('type');
        if ($type !== AccountType::Agency->value) {
            return null;
        }

        return $this->start($user, $workspace, '', $ip);
    }

    public function start(User $user, Workspace $workspace, string $reason, ?string $ip = null): SupportSession
    {
        $now = now();
        $session = SupportSession::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'reason' => mb_substr(trim($reason) !== '' ? trim($reason) : __('Acceso de la agencia'), 0, 255),
            'started_at' => $now,
            'ended_at' => $now,
            'ip_hash' => $ip !== null && $ip !== '' ? hash_hmac('sha256', $ip, (string) config('app.key')) : null,
        ]);
        Cache::put(RedisKey::for($workspace->id, 'support:'.$user->id), $session->id, now()->addMinutes(self::WINDOW_MINUTES));
        $this->audit->handle('support.entered', $session, ['reason' => $session->reason], $workspace->id, (string) $workspace->account_id, $user->id);

        return $session;
    }

    /** Extiende la ventana y anota la última actividad (como mucho una escritura por minuto). */
    private function keepAlive(string $key, string $sessionId): void
    {
        Cache::put($key, $sessionId, now()->addMinutes(self::WINDOW_MINUTES));
        if (Cache::add($key.':seen', 1, 60)) {
            SupportSession::withoutWorkspaceScope()->whereKey($sessionId)->update(['ended_at' => now()]);
        }
    }
}
