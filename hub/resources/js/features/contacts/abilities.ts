import type { Ability } from '@/api/types';
import { useWorkspace } from '@/hooks/useWorkspace';

/* Permisos sobre contactos (WorkspaceRole::ABILITIES). */
export function useContactAbilities() {
    const { workspace } = useWorkspace();
    const has = (ability: Ability) => workspace.abilities.includes(ability);
    return {
        edit: has('editContacts'),
        merge: has('mergeContacts'),
        export: has('exportContacts'),
        remove: has('deleteContacts'),
        manage: has('manageWorkspace'),
        viewDashboards: has('viewDashboards'),
    };
}
