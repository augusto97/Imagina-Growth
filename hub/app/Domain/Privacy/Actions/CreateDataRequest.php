<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Actions;

use App\Domain\Privacy\Models\DataRequest;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\Workspace;

final class CreateDataRequest
{
    /** Días hábiles de plazo por país (cumplimiento.md §9: 15 en Colombia para consultas) [validar por tipo y país]. */
    private const DUE_DAYS = ['CO' => 15, 'MX' => 20, 'VE' => 15];

    public function __construct(private readonly RecordAuditLog $audit) {}

    /** @param array<string, mixed> $evidence */
    public function handle(Workspace $workspace, ?string $contactId, string $kind, ?string $requester, string $origin, ?string $userId = null, array $evidence = []): DataRequest
    {
        $request = DataRequest::query()->create([
            'workspace_id' => $workspace->id,
            'contact_id' => $contactId,
            'kind' => $kind,
            'requester' => $requester,
            'status' => 'pending',
            'due_at' => BusinessDays::add(now(), self::DUE_DAYS[$workspace->country] ?? 15, $workspace->country),
            'evidence' => ['origin' => $origin] + $evidence,
            'created_by' => $userId,
        ]);
        $this->audit->handle('data_request.created', $request, ['kind' => $kind, 'origin' => $origin], $workspace->id, null, $userId);

        return $request;
    }
}
