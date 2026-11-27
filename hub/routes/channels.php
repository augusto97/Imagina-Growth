<?php

use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use App\Http\Middleware\ResolveWorkspace;
use Illuminate\Support\Facades\Broadcast;

/*
 * Canales privados de Reverb (hub/CLAUDE.md §13). El nombre lleva el id público del
 * espacio de trabajo (ws_…). Los eventos solo traen IDs: la SPA vuelve a pedir los
 * datos por la API, que aplica los permisos de cada rol (un agente solo ve lo suyo).
 */
$allows = function (User $user, string $workspaceId, string $ability): bool {
    $workspace = Workspace::findByPublicId($workspaceId);
    if ($workspace === null) {
        return false;
    }
    $role = ResolveWorkspace::roleFor($user, $workspace);

    return $role !== null && $role->can($ability);
};

// Bandeja (M11): cambios en conversaciones.
Broadcast::channel('workspace.{workspaceId}.inbox', fn (User $user, string $workspaceId) => $allows($user, $workspaceId, 'useInbox'));

// Centro de avisos (lote 0.4.0): los avisos de una persona, solo para ella y mientras siga en el espacio de trabajo.
Broadcast::channel('workspace.{workspaceId}.notices.{userId}', fn (User $user, string $workspaceId, string $userId) => $user->publicId() === $userId && $allows($user, $workspaceId, 'viewWorkspace'));

// Tablero «En este momento» (M05): actividad en vivo.
Broadcast::channel('workspace.{workspaceId}.live', fn (User $user, string $workspaceId) => $allows($user, $workspaceId, 'viewDashboards'));
