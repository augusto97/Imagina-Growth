<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Domain\Workspaces\Models\Workspace;

/**
 * Espacio de trabajo activo en la petición o el job (hub/CLAUDE.md §4).
 * Se registra como singleton con alcance de petición (scoped).
 */
final class CurrentWorkspace
{
    private ?Workspace $workspace = null;

    private ?WorkspaceRole $role = null;

    public function set(Workspace $workspace, ?WorkspaceRole $role = null): void
    {
        $this->workspace = $workspace;
        $this->role = $role;
    }

    public function clear(): void
    {
        $this->workspace = null;
        $this->role = null;
    }

    public function has(): bool
    {
        return $this->workspace !== null;
    }

    public function get(): Workspace
    {
        return $this->workspace ?? throw new MissingWorkspaceContext;
    }

    public function id(): string
    {
        return $this->get()->getKey();
    }

    public function role(): ?WorkspaceRole
    {
        return $this->role;
    }

    /**
     * Ejecuta el callback con otro espacio de trabajo activo y restaura el anterior.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(Workspace $workspace, callable $callback): mixed
    {
        $previous = [$this->workspace, $this->role];
        $this->set($workspace);
        try {
            return $callback();
        } finally {
            [$this->workspace, $this->role] = $previous;
        }
    }
}
