<?php

declare(strict_types=1);

namespace App\Http\Controllers\Spa;

use App\Domain\Workspaces\Models\AuditLog;
use App\Domain\Workspaces\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class AuditLogController
{
    /** GET /api/w/{workspace}/audit-logs */
    public function __invoke(): JsonResponse
    {
        Gate::authorize('ws.viewAuditLog');
        $logs = AuditLog::query()->orderByDesc('id')->limit(200)->get();
        $users = User::query()->whereIn('id', $logs->pluck('user_id')->filter()->unique())->pluck('name', 'id');

        return response()->json(['data' => $logs->map(fn (AuditLog $l) => [
            'id' => $l->id,
            'action' => $l->action,
            'user' => $l->getAttribute('user_id') ? ($users[$l->getAttribute('user_id')] ?? null) : null,
            'subject_type' => $l->getAttribute('subject_type'),
            'subject_id' => $l->getAttribute('subject_id'),
            'changes' => $l->changes,
            'created_at' => $l->getAttribute('created_at')?->toIso8601String(),
        ])]);
    }
}
