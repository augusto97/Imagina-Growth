import { queryOptions, useQuery } from '@tanstack/react-query';
import { wsPath } from '@/api/queries';
import { api } from '@/lib/api';

/*
 * Las cifras y lo primero que conviene hacer en la página de entrada de cada sección (rediseño v2 §3):
 * GET /api/w/{ws}/sections/{section} (App\Domain\Home\Queries\SectionSummary). Solo números y claves: los textos van en
 * `sectionEntry.<sección>` (locales/es-CO/section-entry.json), que cada frente ajusta para su sección.
 */

export type SummarySection = 'customers' | 'conversations' | 'messages' | 'onsite' | 'results' | 'settings';

export const SUMMARY_SECTIONS: readonly SummarySection[] = ['customers', 'conversations', 'messages', 'onsite', 'results', 'settings'];

export type FigureUnit = 'count' | 'money' | 'percent';

export interface SectionFigure {
    key: string;
    value: number;
    unit: FigureUnit;
    /** Adónde lleva la cifra (ruta relativa a /w/:ws), o null. */
    to: string | null;
}

export interface SectionNext {
    kind: string;
    to: string;
    /** Los números del texto («Atiende a los {{count}} contactos de hoy»). Un objeto vacío llega como []. */
    params: Record<string, number | string> | [];
}

export interface SectionSummary {
    section: SummarySection;
    business_type: 'store' | 'services' | 'both';
    currency: string;
    month: { from: string; to: string };
    figures: SectionFigure[];
    next: SectionNext | null;
}

export function isSummarySection(key: string): key is SummarySection {
    return (SUMMARY_SECTIONS as readonly string[]).includes(key);
}

export function sectionSummaryQuery(ws: string, section: SummarySection) {
    return queryOptions({
        queryKey: ['ws', ws, 'section-summary', section] as const,
        queryFn: ({ signal }) => api.get<SectionSummary>(`${wsPath(ws)}/sections/${section}`, signal),
        staleTime: 30_000,
    });
}

export function useSectionSummary(ws: string, section: string) {
    return useQuery({ ...sectionSummaryQuery(ws, isSummarySection(section) ? section : 'settings'), enabled: isSummarySection(section) });
}

/** Los parámetros del texto de `next` como objeto (el servidor manda [] cuando no hay). */
export function nextParams(next: Pick<SectionNext, 'params'>): Record<string, number | string> {
    return Array.isArray(next.params) ? {} : next.params;
}
