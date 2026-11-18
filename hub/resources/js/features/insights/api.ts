import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { wsPath } from '@/api/queries';
import { api } from '@/lib/api';

/* Hallazgos (M17, inteligencia.md §5). Rutas en hub/routes/api/insights.php. */

export type InsightStatus = 'new' | 'seen' | 'dismissed' | 'actioned' | 'expired';
export type InsightListStatus = 'open' | 'actioned' | 'dismissed' | 'expired';
export const LIST_STATUSES: InsightListStatus[] = ['open', 'actioned', 'dismissed', 'expired'];

export interface Insight {
    id: string;
    detector: string;
    severity: string;
    title: string;
    facts: Record<string, unknown>;
    impact: string | null;
    assumptions: string[];
    action: { label: string; to?: string; url?: string } | null;
    status: InsightStatus;
    period_start: string | null;
    period_end: string | null;
    created_at: string | null;
    updated_at: string | null;
}

const keys = {
    all: (ws: string) => ['ws', ws, 'insights'] as const,
    list: (ws: string, status: InsightListStatus) => ['ws', ws, 'insights', 'list', status] as const,
    one: (ws: string, id: string) => ['ws', ws, 'insights', 'one', id] as const,
};

export function useInsights(ws: string, status: InsightListStatus) {
    return useQuery({
        queryKey: keys.list(ws, status),
        queryFn: async ({ signal }) => (await api.get<{ data: Insight[] }>(`${wsPath(ws)}/insights?status=${status}`, signal)).data,
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
        mutationFn: ({ id, status }: { id: string; status: 'dismissed' | 'actioned' | 'seen' }) => api.patch<Insight>(`${wsPath(ws)}/insights/${encodeURIComponent(id)}`, { status }),
        onSuccess: (insight) => {
            qc.setQueryData(keys.one(ws, insight.id), insight);
            void qc.invalidateQueries({ queryKey: keys.all(ws) });
            void qc.invalidateQueries({ queryKey: ['ws', ws, 'analytics', 'overview'] });
        },
    });
}
