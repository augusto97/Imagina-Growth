import type { Workspace } from '@/api/types';
import { itemVisible, navContext } from '@/components/layout/nav';
import { DASHBOARDS, type DashboardSlug } from './api';

/*
 * Qué tableros aplican a cada negocio y cómo se agrupan en la navegación de Resultados (rediseño v2 §11.2): un negocio
 * sin tienda no ve ventas, pedidos, carritos ni catálogo; una tienda no ve el de leads. Se decide igual que el menú
 * (`itemVisible` con `audience`): el tipo de negocio del asistente, la vertical o lo que dicen los sitios.
 */

export interface ResultsAudience {
    store: boolean;
    services: boolean;
}

export function resultsAudience(workspace: Pick<Workspace, 'abilities' | 'modules' | 'capabilities' | 'connections' | 'upgrade_plans' | 'business_type' | 'vertical'>): ResultsAudience {
    const ctx = { ...navContext(workspace), abilities: [] };
    return { store: itemVisible({ audience: 'store' }, ctx), services: itemVisible({ audience: 'services' }, ctx) };
}

/** Grupos de tableros en el orden de la navegación (el nombre en analytics.groups.<key>). */
export const DASHBOARD_GROUPS: { key: 'sales' | 'customers' | 'traffic' | 'leads' | 'messages'; dashboards: DashboardSlug[] }[] = [
    { key: 'sales', dashboards: ['overview', 'funnel', 'products', 'coupons', 'profitability'] },
    { key: 'customers', dashboards: ['customers', 'geography', 'hdyhau'] },
    { key: 'traffic', dashboards: ['channels', 'searches'] },
    { key: 'leads', dashboards: ['services'] },
    { key: 'messages', dashboards: ['messaging'] },
];

/** Tableros que hablan de pedidos, carritos o catálogo (o, como la encuesta, viven en la página de gracias de la tienda). */
export const STORE_DASHBOARDS: readonly DashboardSlug[] = ['overview', 'funnel', 'channels', 'customers', 'products', 'searches', 'geography', 'coupons', 'profitability', 'hdyhau'];
/** Tableros de un negocio de servicios (leads). */
export const SERVICES_DASHBOARDS: readonly DashboardSlug[] = ['services'];

/** Los tableros que aplican, en el orden de los grupos. */
export function visibleDashboards(audience: ResultsAudience): DashboardSlug[] {
    const order = DASHBOARD_GROUPS.flatMap((g) => g.dashboards);
    return order.filter((d) => DASHBOARDS.includes(d)).filter((d) => (STORE_DASHBOARDS.includes(d) ? audience.store : SERVICES_DASHBOARDS.includes(d) ? audience.services : true));
}

/** Los grupos con sus tableros visibles (sin los grupos vacíos). */
export function visibleGroups(audience: ResultsAudience): { key: string; dashboards: DashboardSlug[] }[] {
    const visible = visibleDashboards(audience);
    return DASHBOARD_GROUPS.map((g) => ({ key: g.key, dashboards: g.dashboards.filter((d) => visible.includes(d)) })).filter((g) => g.dashboards.length > 0);
}

/** Tableros que dependen por completo de las visitas del navegador: sin el seguimiento de la web no tienen qué mostrar. */
export const TRAFFIC_ONLY: readonly DashboardSlug[] = ['funnel', 'searches'];
/** Tableros que usan las visitas en parte (las ventas sí salen): llevan el aviso de salud de la web. */
export const TRAFFIC_PARTIAL: readonly DashboardSlug[] = ['overview', 'channels', 'products', 'geography', 'services'];
