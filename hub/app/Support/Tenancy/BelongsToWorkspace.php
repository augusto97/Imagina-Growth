<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Todos los modelos de negocio lo usan (hub/CLAUDE.md §4): agrega el scope
 * global por workspace_id y lo llena al crear. Sin espacio de trabajo activo,
 * cualquier consulta falla (MissingWorkspaceContext) en lugar de devolver
 * datos de todos.
 *
 * Para procesos de plataforma (ingesta, jobs nocturnos) que recorren varios
 * espacios de trabajo, usar CurrentWorkspace::run() o, de forma explícita y
 * revisable, withoutWorkspaceScope().
 */
trait BelongsToWorkspace
{
    public static function bootBelongsToWorkspace(): void
    {
        static::addGlobalScope(new WorkspaceScope);

        static::creating(function ($model): void {
            if (empty($model->workspace_id)) {
                $model->workspace_id = app(CurrentWorkspace::class)->id();
            }
        });
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Consulta sin el scope de tenancy. Solo para procesos de plataforma.
     *
     * @return Builder<static>
     */
    public static function withoutWorkspaceScope(): Builder
    {
        return static::query()->withoutGlobalScope(WorkspaceScope::class);
    }
}
