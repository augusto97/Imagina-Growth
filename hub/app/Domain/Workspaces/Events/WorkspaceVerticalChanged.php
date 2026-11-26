<?php

declare(strict_types=1);

namespace App\Domain\Workspaces\Events;

use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * El espacio de trabajo eligió (o cambió) a qué se dedica (`workspaces.vertical`): en `PATCH /api/w/{ws}` o en el
 * asistente (`PUT onboarding`). Lo escucha Servicios para dejar los valores por defecto de la vertical
 * (ApplyVerticalDefaults).
 */
final class WorkspaceVerticalChanged
{
    use Dispatchable;

    public function __construct(public readonly Workspace $workspace, public readonly ?string $vertical) {}
}
