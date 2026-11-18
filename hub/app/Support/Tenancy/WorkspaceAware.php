<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

/** Jobs que trabajan dentro de un espacio de trabajo. SetWorkspaceContext restaura el contexto. */
interface WorkspaceAware
{
    public function workspaceId(): string;
}
