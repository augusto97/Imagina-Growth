import type { ConsentChannel, ContactFilters, ContactSort } from './api';

/* Filtros de la lista de contactos guardados en la URL (se pueden compartir y sobreviven al volver del perfil). */

export const DEFAULT_FILTERS: ContactFilters = {
    q: '',
    stage: '',
    city: '',
    rfm_segment: '',
    consent: '',
    buyers: '',
    tag: '',
    segment: '',
    temperature: '',
    sort: 'last_seen',
    page: 1,
};

const SORTS: ContactSort[] = ['last_seen', 'last_order', 'revenue', 'orders', 'created', 'lead_score'];
const TEMPERATURES = ['hot', 'warm', 'cold'] as const;
const CHANNELS: ConsentChannel[] = ['email', 'whatsapp', 'sms'];

export function readFilters(params: URLSearchParams): ContactFilters {
    const text = (key: string, max: number) => (params.get(key) ?? '').slice(0, max);
    const sort = params.get('sort') as ContactSort | null;
    const consent = params.get('consent') as ConsentChannel | null;
    const buyers = params.get('buyers');
    const temperature = params.get('temperature');
    const page = Number.parseInt(params.get('page') ?? '1', 10);
    return {
        q: text('q', 120),
        stage: text('stage', 16),
        city: text('city', 120),
        rfm_segment: text('rfm_segment', 32),
        consent: consent !== null && CHANNELS.includes(consent) ? consent : '',
        buyers: buyers === '1' || buyers === '0' ? buyers : '',
        tag: text('tag', 40),
        segment: text('segment', 40),
        temperature: TEMPERATURES.find((x) => x === temperature) ?? '',
        sort: sort !== null && SORTS.includes(sort) ? sort : 'last_seen',
        page: Number.isFinite(page) && page > 0 ? page : 1,
    };
}

/** Solo escribe lo que difiere del valor por defecto, para URLs cortas. */
export function writeFilters(filters: ContactFilters): URLSearchParams {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(filters) as [keyof ContactFilters, string | number][]) {
        if (value !== DEFAULT_FILTERS[key] && value !== '') {
            params.set(key, String(value));
        }
    }
    return params;
}

/** Filtros que se eligen en «Filtros» (no la búsqueda). */
export const PANEL_FILTERS = ['segment', 'city', 'consent', 'stage', 'rfm_segment', 'buyers', 'temperature', 'tag'] as const;
export type PanelFilter = (typeof PANEL_FILTERS)[number];

/** ¿Hay algún filtro activo (aparte del orden y la página)? */
export function hasActiveFilters(filters: ContactFilters): boolean {
    return (['q', ...PANEL_FILTERS] as const).some((k) => filters[k] !== '');
}

/** Cuántos filtros del panel están activos (el número del botón «Filtros»). */
export function activeFilterCount(filters: ContactFilters): number {
    return PANEL_FILTERS.filter((k) => filters[k] !== '').length;
}

/** Los filtros rápidos de la lista, un chip cada uno, según el tipo de negocio. */
export type QuickFilter = 'all' | 'buyers' | 'nonBuyers' | 'whatsapp' | 'atRisk' | 'hot' | 'warm' | 'email';

const QUICK: Record<Exclude<QuickFilter, 'all'>, Partial<ContactFilters>> = {
    buyers: { buyers: '1' },
    nonBuyers: { buyers: '0' },
    whatsapp: { consent: 'whatsapp' },
    email: { consent: 'email' },
    atRisk: { rfm_segment: 'at_risk' },
    hot: { temperature: 'hot' },
    warm: { temperature: 'warm' },
};

export function quickFilters(store: boolean, services: boolean): QuickFilter[] {
    if (services && !store) {
        return ['all', 'hot', 'warm', 'whatsapp', 'email'];
    }
    return ['all', 'buyers', 'nonBuyers', 'whatsapp', 'atRisk', ...(services ? (['hot'] as const) : [])];
}

/** ¿Está prendido el filtro rápido? «Todos» cuando no hay ningún filtro del panel. */
export function quickActive(filters: ContactFilters, quick: QuickFilter): boolean {
    if (quick === 'all') {
        return activeFilterCount(filters) === 0;
    }
    return Object.entries(QUICK[quick]).every(([k, v]) => filters[k as keyof ContactFilters] === v);
}

/** Aplica (o quita, si ya estaba) un filtro rápido; «Todos» limpia los filtros del panel y deja la búsqueda. */
export function toggleQuick(filters: ContactFilters, quick: QuickFilter): ContactFilters {
    if (quick === 'all') {
        return { ...DEFAULT_FILTERS, q: filters.q, sort: filters.sort };
    }
    const patch = QUICK[quick];
    if (quickActive(filters, quick)) {
        const cleared = Object.fromEntries(Object.keys(patch).map((k) => [k, ''])) as Partial<ContactFilters>;
        return { ...filters, ...cleared, page: 1 };
    }
    return { ...filters, ...patch, page: 1 };
}
