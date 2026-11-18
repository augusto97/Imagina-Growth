import type { ConsentChannel, ContactFilters, ContactSort } from './api';

/* Filtros de la lista de contactos guardados en la URL (se pueden compartir y sobreviven al volver del perfil). */

export const DEFAULT_FILTERS: ContactFilters = { q: '', stage: '', city: '', rfm_segment: '', consent: '', buyers: '', tag: '', sort: 'last_seen', page: 1 };

const SORTS: ContactSort[] = ['last_seen', 'last_order', 'revenue', 'orders', 'created'];
const CHANNELS: ConsentChannel[] = ['email', 'whatsapp', 'sms'];

export function readFilters(params: URLSearchParams): ContactFilters {
    const text = (key: string, max: number) => (params.get(key) ?? '').slice(0, max);
    const sort = params.get('sort') as ContactSort | null;
    const consent = params.get('consent') as ConsentChannel | null;
    const buyers = params.get('buyers');
    const page = Number.parseInt(params.get('page') ?? '1', 10);
    return {
        q: text('q', 120),
        stage: text('stage', 16),
        city: text('city', 120),
        rfm_segment: text('rfm_segment', 32),
        consent: consent !== null && CHANNELS.includes(consent) ? consent : '',
        buyers: buyers === '1' || buyers === '0' ? buyers : '',
        tag: text('tag', 40),
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

/** ¿Hay algún filtro activo (aparte del orden y la página)? */
export function hasActiveFilters(filters: ContactFilters): boolean {
    return (['q', 'stage', 'city', 'rfm_segment', 'consent', 'buyers', 'tag'] as const).some((k) => filters[k] !== '');
}
