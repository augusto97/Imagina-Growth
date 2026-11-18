import type { Ability } from '@/api/types';
import { useWorkspace } from '@/hooks/useWorkspace';

/*
 * Permisos sobre contactos (WorkspaceRole::ABILITIES). `mergeContacts` y
 * `editContacts` llegan en workspace.abilities pero aún no están en el tipo
 * compartido `Ability`, por eso se comparan como texto.
 */
export function useContactAbilities() {
    const { workspace } = useWorkspace();
    const has = (ability: Ability | 'mergeContacts' | 'editContacts') => (workspace.abilities as readonly string[]).includes(ability);
    return {
        edit: has('editContacts'),
        merge: has('mergeContacts'),
        export: has('exportContacts'),
        remove: has('deleteContacts'),
        manage: has('manageWorkspace'),
        viewDashboards: has('viewDashboards'),
    };
}
