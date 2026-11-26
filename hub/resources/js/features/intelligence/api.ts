import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { wsPath } from '@/api/queries';
import { api } from '@/lib/api';

/* IA del panel (M17, Fase 4). Rutas en hub/routes/api/ai.php. */

export type AiSource = 'ai' | 'fallback';

export interface AiUsage {
    available: boolean;
    configured: boolean;
    spent_usd: number;
    budget_usd: number;
    features: { feature: string; requests: number; tokens_in: number; tokens_out: number; cost_usd: number }[];
}

export interface ReportAction {
    titulo: string;
    detalle: string;
    tipo: 'ruta' | 'flujo';
    destino: string;
    ruta: string;
}

export interface AiReport {
    id: string;
    kind: string;
    period_start: string;
    period_end: string;
    source: AiSource;
    body: string;
    actions: ReportAction[];
    delivered_at: string | null;
    created_at: string | null;
    facts?: Record<string, unknown>;
    validation?: { attempts?: { ok: boolean; checked: number; unknown: string[] }[]; fallback_reason?: string; words?: number } | null;
    model?: string | null;
}

export type QueryUnit = 'money' | 'count' | 'percent';

export interface AskColumn {
    key: string;
    label: string;
    kind: 'dimension' | 'metric';
    unit?: QueryUnit;
}

export interface AskResult {
    visual: 'kpi' | 'line' | 'bar' | 'table';
    columns: AskColumn[];
    rows: Record<string, string | number | null>[];
    totals: Record<string, number>;
    compare_totals?: Record<string, number> | null;
    segment_available?: boolean;
    source?: AiSource;
}

export interface AskQuery {
    metrics: string[];
    dimensions: string[];
    from: string;
    to: string;
    compare: string;
    granularity: string;
}

export interface AskAnswer {
    id: string;
    question: string;
    answer: string | null;
    status: 'answered' | 'unsupported' | 'failed';
    query: AskQuery | null;
    result: AskResult | null;
    source: AiSource;
    saved_as: 'segment' | 'dashboard' | null;
    created_at: string | null;
}

export interface ProfileSummary {
    text: string;
    source: AiSource;
    generated_at: string;
}

export interface ReviewSummary {
    available: boolean;
    summary: string | null;
    positives: string[];
    negatives: string[];
    source: AiSource | null;
    count: number;
    average: number | null;
}

const keys = {
    usage: (ws: string) => ['ws', ws, 'ai', 'usage'] as const,
    reports: (ws: string) => ['ws', ws, 'ai', 'reports'] as const,
    report: (ws: string, id: string) => ['ws', ws, 'ai', 'reports', id] as const,
    history: (ws: string) => ['ws', ws, 'ai', 'ask'] as const,
    saved: (ws: string) => ['ws', ws, 'ai', 'saved'] as const,
    run: (ws: string, id: string) => ['ws', ws, 'ai', 'run', id] as const,
    profile: (ws: string, contact: string) => ['ws', ws, 'ai', 'profile', contact] as const,
    reviews: (ws: string, product: string) => ['ws', ws, 'ai', 'reviews', product] as const,
};

export function useAiUsage(ws: string) {
    return useQuery({ queryKey: keys.usage(ws), queryFn: ({ signal }) => api.get<AiUsage>(`${wsPath(ws)}/ai/usage`, signal) });
}

export function useAiReports(ws: string) {
    return useQuery({ queryKey: keys.reports(ws), queryFn: async ({ signal }) => (await api.get<{ data: AiReport[] }>(`${wsPath(ws)}/ai/reports`, signal)).data });
}

export function useAiReport(ws: string, id: string | null) {
    return useQuery({
        queryKey: keys.report(ws, id ?? ''),
        enabled: id !== null,
        queryFn: ({ signal }) => api.get<AiReport>(`${wsPath(ws)}/ai/reports/${encodeURIComponent(id ?? '')}`, signal),
    });
}

export function useGenerateReport(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (week?: string) => api.post<AiReport>(`${wsPath(ws)}/ai/reports/generate`, week ? { week } : {}),
        onSuccess: (report) => {
            qc.setQueryData(keys.report(ws, report.id), report);
            void qc.invalidateQueries({ queryKey: keys.reports(ws) });
            void qc.invalidateQueries({ queryKey: keys.usage(ws) });
        },
    });
}

export function useSendReport(ws: string) {
    return useMutation({ mutationFn: (id: string) => api.post<{ status: string; recipients: number }>(`${wsPath(ws)}/ai/reports/${encodeURIComponent(id)}/send`) });
}

export function useAskHistory(ws: string) {
    return useQuery({ queryKey: keys.history(ws), queryFn: async ({ signal }) => (await api.get<{ data: AskAnswer[] }>(`${wsPath(ws)}/ai/ask`, signal)).data });
}

export function useAsk(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationKey: ['ws', ws, 'ai', 'ask'],
        mutationFn: (question: string) => api.post<AskAnswer>(`${wsPath(ws)}/ai/ask`, { question }),
        onSuccess: (answer) => {
            qc.setQueryData<AskAnswer[]>(keys.history(ws), (old) => [answer, ...(old ?? [])]);
            void qc.invalidateQueries({ queryKey: keys.usage(ws) });
        },
    });
}

export function useSaveAnswer(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: ({ id, as, name }: { id: string; as: 'segment' | 'dashboard'; name?: string }) =>
            api.post<AskAnswer & { segment_id?: string }>(`${wsPath(ws)}/ai/ask/${encodeURIComponent(id)}/save`, { as, name }),
        onSuccess: (answer) => {
            qc.setQueryData<AskAnswer[]>(keys.history(ws), (old) => (old ?? []).map((a) => (a.id === answer.id ? { ...a, saved_as: answer.saved_as } : a)));
            void qc.invalidateQueries({ queryKey: keys.saved(ws) });
        },
    });
}

export function useUnsave(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (id: string) => api.delete<AskAnswer>(`${wsPath(ws)}/ai/ask/${encodeURIComponent(id)}/saved`),
        onSuccess: () => {
            void qc.invalidateQueries({ queryKey: keys.saved(ws) });
            void qc.invalidateQueries({ queryKey: keys.history(ws) });
        },
    });
}

export function useSavedQueries(ws: string) {
    return useQuery({ queryKey: keys.saved(ws), queryFn: async ({ signal }) => (await api.get<{ data: AskAnswer[] }>(`${wsPath(ws)}/ai/saved`, signal)).data });
}

export function useRunSaved(ws: string, id: string) {
    return useQuery({
        queryKey: keys.run(ws, id),
        queryFn: async ({ signal }) => (await api.get<{ id: string; result: AskResult }>(`${wsPath(ws)}/ai/ask/${encodeURIComponent(id)}/run`, signal)).result,
    });
}

export function useProfileSummary(ws: string, contactId: string, enabled: boolean) {
    return useQuery({
        queryKey: keys.profile(ws, contactId),
        enabled,
        staleTime: 5 * 60_000,
        queryFn: ({ signal }) => api.get<ProfileSummary>(`${wsPath(ws)}/contacts/${encodeURIComponent(contactId)}/ai-summary`, signal),
    });
}

export function useReviewSummary(ws: string, productId: string | null) {
    return useQuery({
        queryKey: keys.reviews(ws, productId ?? ''),
        enabled: productId !== null && productId !== '',
        staleTime: 5 * 60_000,
        queryFn: ({ signal }) => api.get<ReviewSummary>(`${wsPath(ws)}/ai/review-summary?product_id=${encodeURIComponent(productId ?? '')}`, signal),
    });
}
