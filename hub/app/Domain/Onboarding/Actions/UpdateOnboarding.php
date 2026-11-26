<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Actions;

use App\Domain\Onboarding\Models\WorkspaceOnboarding;
use App\Domain\Onboarding\Support\Verticals;
use App\Domain\Strategies\Support\StrategyCache;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Actions\UpdateWorkspaceSettings;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * Guarda lo que decide la persona en el asistente (docs/diseno/rediseno-v2.md §4 y §11.1): el tipo de negocio, a qué
 * se dedica (la vertical, en el espacio de trabajo), el paso donde va y si lo salta, lo retoma o lo termina; y, por
 * persona, que ya vio la bienvenida. El tipo de negocio y terminar o
 * saltar quedan en la auditoría.
 */
final class UpdateOnboarding
{
    public const ACTIONS = ['skip', 'resume', 'complete'];

    public function __construct(private readonly RecordAuditLog $audit, private readonly UpdateWorkspaceSettings $settings) {}

    /**
     * La vertical se guarda en el espacio de trabajo (se cambia después en Configuración); si todavía no hay tipo de
     * negocio, toma el que implica la vertical.
     *
     * @param  array{business_type?: string|null, vertical?: string|null, current_step?: string|null, action?: string|null}  $changes  ya validados
     */
    public function handle(Workspace $workspace, array $changes, ?string $userId): WorkspaceOnboarding
    {
        $row = WorkspaceOnboarding::query()->firstOrNew(['workspace_id' => $workspace->id]);
        $before = $row->exists ? $row->only(['business_type', 'skipped_at', 'completed_at']) : [];
        if (($changes['vertical'] ?? null) !== null) {
            $this->settings->handle($workspace, ['vertical' => $changes['vertical']]);
            if ($row->business_type === null && ($changes['business_type'] ?? null) === null) {
                $row->business_type = Verticals::businessType((string) $changes['vertical']);
            }
        }
        if (array_key_exists('business_type', $changes) && $changes['business_type'] !== null) {
            $row->business_type = $changes['business_type'];
        }
        if (isset($changes['current_step'])) {
            $row->current_step = $changes['current_step'];
        }
        match ($changes['action'] ?? null) {
            'skip' => $row->forceFill(['skipped_at' => now()]),
            'resume' => $row->forceFill(['skipped_at' => null, 'completed_at' => null]),
            'complete' => $row->forceFill(['completed_at' => now(), 'skipped_at' => null, 'current_step' => 'done']),
            default => null,
        };
        $row->updated_by = $userId;
        $dirty = array_keys($row->getDirty());
        $row->save();
        $audited = array_values(array_intersect($dirty, ['business_type', 'skipped_at', 'completed_at']));
        if ($audited !== []) {
            $this->audit->handle('onboarding.updated', $workspace, [
                'before' => $before, 'business_type' => $row->business_type, 'action' => $changes['action'] ?? null,
            ], $workspace->id, null, $userId);
        }
        StrategyCache::bump($workspace->id);

        return $row;
    }

    /** La persona ya vio la bienvenida en este espacio de trabajo (o la quiere ver otra vez, con `false`). */
    public function welcome(Workspace $workspace, string $userId, bool $seen): void
    {
        DB::table('onboarding_user_states')->upsert(
            [['workspace_id' => $workspace->id, 'user_id' => $userId, 'welcome_seen_at' => $seen ? now() : null, 'created_at' => now(), 'updated_at' => now()]],
            ['workspace_id', 'user_id'],
            ['welcome_seen_at', 'updated_at'],
        );
    }
}
