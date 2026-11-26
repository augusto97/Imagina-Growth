import { useTranslation } from 'react-i18next';
import { itemVisible, navContext } from '@/components/layout/nav';
import { useWorkspace } from '@/hooks/useWorkspace';
import { useVerticals } from '@/features/verticals/api';

/*
 * Tienda, servicios o ambos (rediseño v2 §11): la misma regla del menú (`itemVisible` con `audience`), así la sección
 * Clientes muestra pedidos solo donde hay tienda y leads, citas y cotizaciones solo donde hay servicios. Sin ningún dato
 * (null) se muestra todo.
 */

export interface Business {
    /** Tiene tienda: pedidos, carritos, RFM. */
    store: boolean;
    /** Vende servicios: leads, citas, cotizaciones. */
    services: boolean;
    /** Solo servicios: la lista y el perfil hablan de leads y citas, nunca de pedidos. */
    servicesOnly: boolean;
    vertical: string | null;
}

export function businessOf(workspace: Parameters<typeof navContext>[0]): Business {
    const ctx = navContext(workspace);
    const store = itemVisible({ audience: 'store' }, ctx);
    const services = itemVisible({ audience: 'services' }, ctx);
    return { store, services, servicesOnly: services && !store, vertical: workspace.vertical ?? null };
}

export function useBusiness(): Business {
    return businessOf(useWorkspace().workspace);
}

/**
 * Cómo se llama a la persona en la vertical (cliente, paciente, estudiante, prospecto), de `GET verticals`
 * (VerticalCatalog → terms, en caché 5 min). Mientras llega, o sin vertical, «cliente».
 */
export function usePersonTerms(): { one: string; many: string } {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const verticals = useVerticals(workspace.id, (workspace.vertical ?? null) !== null);
    const profile = verticals.data?.data.find((v) => v.key === workspace.vertical);
    return {
        one: profile?.terms.client ?? t('contacts.terms.client'),
        many: profile?.terms.clients ?? t('contacts.terms.clients'),
    };
}
