import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { wsPath } from '@/api/queries';
import { api } from '@/lib/api';

/* Hallazgos (M17, inteligencia.md §5). Rutas en hub/routes/api/insights.php. */

export type InsightStatus = 'new' | 'seen' | 'snoozed' | 'dismissed' | 'actioned' | 'expired';
export type InsightListStatus = 'open' | 'snoozed' | 'actioned' | 'dismissed' | 'expired';
export const LIST_STATUSES: InsightListStatus[] = ['open', 'snoozed', 'actioned', 'dismissed', 'expired'];
/** Días para posponer una recomendación (Insight::SNOOZE_DAYS en el hub). */
export const SNOOZE_DAYS = [1, 3, 7, 30] as const;

/** Categorías de App\Domain\Intelligence\Insights\DetectorCatalog::CATEGORIES. */
export const CATEGORIES = ['sales', 'customers', 'messaging', 'measurement', 'services'] as const;
export type InsightCategory = (typeof CATEGORIES)[number];

export type TermUnit = 'count' | 'rate' | 'money' | 'days' | 'number';

export interface ImpactTerm {
    label: string;
    value: number;
    unit: TermUnit;
    /** Clave del supuesto editable (config/igr-insights.php), si el factor es un supuesto. */
    assumption: string | null;
}

export interface InsightAction {
    label: string;
    to?: string;
    url?: string;
    /** link (por defecto), open_leads (enlace), activate_flow, create_campaign o calendar_campaign: estos tres se hacen con 1 clic. */
    kind?: 'link' | 'open_leads' | 'activate_flow' | 'create_campaign' | 'calendar_campaign';
}

export interface Insight {
    id: string;
    detector: string;
    number: number | null;
    category: InsightCategory;
    severity: string;
    title: string;
    facts: Record<string, unknown>;
    impact: string | null;
    formula: { terms: ImpactTerm[]; total: number; text: string } | null;
    assumptions: string[];
    action: InsightAction | null;
    status: InsightStatus;
    period_start: string | null;
    period_end: string | null;
    valid_until: string | null;
    snoozed_until?: string | null;
    created_at: string | null;
    updated_at: string | null;
}

export interface InsightsSummary {
    open: number;
    impact_open: number;
    actioned_month: number;
    impact_actioned_month: number;
    snoozed?: number;
    by_category: Record<InsightCategory, number>;
}

export interface Assumption {
    key: string;
    value: number;
    default: number;
    unit: 'rate';
    min: number;
    max: number;
    edited: boolean;
}

export interface DetectorInfo {
    number: number;
    key: string;
    category: InsightCategory;
    cadence: 'nightly' | 'weekly';
    phase: number;
    enabled: boolean;
    last_run: { status: 'ok' | 'skipped' | 'failed' | 'disabled'; found?: number; reason?: string; at?: string | null } | null;
}

export interface InsightFilters {
    status: InsightListStatus;
    category?: InsightCategory | '';
    severity?: string;
}

const keys = {
    all: (ws: string) => ['ws', ws, 'insights'] as const,
    list: (ws: string, f: InsightFilters) => ['ws', ws, 'insights', 'list', f.status, f.category ?? '', f.severity ?? ''] as const,
    one: (ws: string, id: string) => ['ws', ws, 'insights', 'one', id] as const,
    settings: (ws: string) => ['ws', ws, 'insights', 'settings'] as const,
};

export function useInsights(ws: string, filters: InsightFilters) {
    return useQuery({
        queryKey: keys.list(ws, filters),
        queryFn: async ({ signal }) => {
            const q = new URLSearchParams({ status: filters.status });
            if (filters.category) q.set('category', filters.category);
            if (filters.severity) q.set('severity', filters.severity);
            return api.get<{ data: Insight[]; meta: InsightsSummary }>(`${wsPath(ws)}/insights?${q.toString()}`, signal);
        },
    });
}

export function useInsight(ws: string, id: string) {
    const qc = useQueryClient();
    return useQuery({
        queryKey: keys.one(ws, id),
        queryFn: async ({ signal }) => {
            const insight = await api.get<Insight>(`${wsPath(ws)}/insights/${encodeURIComponent(id)}`, signal);
            // Abrirlo lo marca «visto»: la lista y el Inicio deben reflejarlo.
            void qc.invalidateQueries({ queryKey: [...keys.all(ws), 'list'] });
            return insight;
        },
    });
}

export function useUpdateInsight(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: ({ id, status, days }: { id: string; status: 'dismissed' | 'actioned' | 'seen' | 'snoozed'; days?: number }) =>
            api.patch<Insight>(`${wsPath(ws)}/insights/${encodeURIComponent(id)}`, status === 'snoozed' ? { status, days } : { status }),
        onSuccess: (insight) => {
            qc.setQueryData(keys.one(ws, insight.id), insight);
            void qc.invalidateQueries({ queryKey: keys.all(ws) });
            void qc.invalidateQueries({ queryKey: ['ws', ws, 'analytics', 'overview'] });
        },
    });
}

/** «Hacer ahora» con 1 clic: activa el flujo o crea la campaña y devuelve a dónde ir. */
export function useRunInsightAction(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (id: string) => api.post<{ to: string; result: string; insight: Insight }>(`${wsPath(ws)}/insights/${encodeURIComponent(id)}/act`),
        onSuccess: (res) => {
            qc.setQueryData(keys.one(ws, res.insight.id), res.insight);
            void qc.invalidateQueries({ queryKey: keys.all(ws) });
            void qc.invalidateQueries({ queryKey: ['ws', ws, 'flows'] });
            void qc.invalidateQueries({ queryKey: ['ws', ws, 'campaigns'] });
        },
    });
}

export function useInsightSettings(ws: string) {
    return useQuery({
        queryKey: keys.settings(ws),
        queryFn: async ({ signal }) => api.get<{ assumptions: Assumption[]; detectors: DetectorInfo[] }>(`${wsPath(ws)}/insights/settings`, signal),
    });
}

/** Cambia un supuesto (o lo devuelve a su valor por defecto con null) y recalcula los hallazgos abiertos. */
export function useUpdateAssumption(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: ({ key, value }: { key: string; value: number | null }) =>
            api.put<{ assumptions: Assumption[]; updated: number }>(`${wsPath(ws)}/insights/assumptions/${encodeURIComponent(key)}`, { value }),
        onSuccess: () => void qc.invalidateQueries({ queryKey: keys.all(ws) }),
    });
}
