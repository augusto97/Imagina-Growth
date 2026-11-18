import { createContext, useContext } from 'react';
import type { Ability, Me, Workspace } from '@/api/types';

export interface WorkspaceContextValue {
    workspace: Workspace;
    me: Me;
}

export const WorkspaceContext = createContext<WorkspaceContextValue | null>(null);

/** Espacio de trabajo activo (dentro de /w/:workspaceId). */
export function useWorkspace(): WorkspaceContextValue {
    const ctx = useContext(WorkspaceContext);
    if (ctx === null) {
        throw new Error('useWorkspace() fuera de WorkspaceLayout');
    }
    return ctx;
}

/** Permisos del rol efectivo, tal como los devuelve la API (WorkspaceRole::grantedAbilities). */
export function useCan(ability: Ability): boolean {
    return useWorkspace().workspace.abilities.includes(ability);
}
