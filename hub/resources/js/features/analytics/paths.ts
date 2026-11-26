import { isDashboard, type DashboardSlug } from './api';

/** Ruta de cada tablero dentro de /w/:ws/analitica (en español, como el resto de la SPA). */
export const DASHBOARD_PATHS: Record<DashboardSlug, string> = {
    overview: '',
    funnel: 'embudo',
    channels: 'canales',
    customers: 'clientes',
    products: 'productos',
    searches: 'busquedas',
    geography: 'geografia',
    coupons: 'cupones',
    messaging: 'mensajeria',
    profitability: 'rentabilidad',
    services: 'servicios',
    hdyhau: 'como-nos-conociste',
};

export function slugFromPath(path: string | undefined): DashboardSlug | null {
    if (path === undefined || path === '') {
        return 'overview';
    }
    const found = (Object.entries(DASHBOARD_PATHS) as [DashboardSlug, string][]).find(([, p]) => p === path);
    if (found) {
        return found[0];
    }
    return isDashboard(path) ? path : null;
}

/** El enlace a un tablero conservando el periodo y los filtros (`search` sin «?»). */
export function dashboardHref(base: string, slug: DashboardSlug, search = ''): string {
    const path = DASHBOARD_PATHS[slug];
    return `${base}/analitica${path ? `/${path}` : ''}${search ? `?${search}` : ''}`;
}
