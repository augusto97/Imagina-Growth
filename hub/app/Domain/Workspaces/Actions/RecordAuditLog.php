<?php

declare(strict_types=1);

namespace App\Domain\Workspaces\Actions;

use App\Support\Pii\Pii;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Registra una acción en audit_logs. Los cambios se guardan enmascarados y la
 * IP solo como hash (CLAUDE.md §3.9).
 */
final class RecordAuditLog
{
    /**
     * @param  array<string, mixed>  $changes
     */
    public function handle(
        string $action,
        ?Model $subject = null,
        array $changes = [],
        ?string $workspaceId = null,
        ?string $accountId = null,
        ?string $userId = null,
    ): void {
        $request = app()->runningInConsole() ? null : request();
        $ip = $request?->ip();

        DB::table('audit_logs')->insert([
            'workspace_id' => $workspaceId,
            'account_id' => $accountId,
            'user_id' => $userId ?? auth()->id(),
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey() !== null ? (string) $subject->getKey() : null,
            'changes' => $changes === [] ? null : json_encode(Pii::mask($changes), JSON_UNESCAPED_UNICODE),
            'ip_hash' => $ip ? hash_hmac('sha256', $ip, (string) config('app.key')) : null,
            'created_at' => now(),
        ]);
    }
}
