import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { wsPath } from '@/api/queries';
import { api } from '@/lib/api';
import type { OnsiteOptions, WidgetCaptureRow, WidgetDetail, WidgetDraft, WidgetKind, WidgetStatsDetail, WidgetSummary } from './types';

export const onsiteKeys = {
    all: (ws: string) => ['ws', ws, 'onsite'] as const,
    list: (ws: string, status: string) => ['ws', ws, 'onsite', 'list', status] as const,
    one: (ws: string, id: string) => ['ws', ws, 'onsite', 'widget', id] as const,
    stats: (ws: string, id: string, days: number) => ['ws', ws, 'onsite', 'widget', id, 'stats', days] as const,
    captures: (ws: string, id: string) => ['ws', ws, 'onsite', 'widget', id, 'captures'] as const,
    options: (ws: string) => ['ws', ws, 'onsite', 'options'] as const,
};

const base = (ws: string) => `${wsPath(ws)}/onsite`;
const one = (ws: string, id: string) => `${base(ws)}/widgets/${encodeURIComponent(id)}`;

export function useWidgets(ws: string, status = '') {
    return useQuery({
        queryKey: onsiteKeys.list(ws, status),
        queryFn: async ({ signal }) => (await api.get<{ data: WidgetSummary[] }>(`${base(ws)}/widgets${status ? `?status=${status}` : ''}`, signal)).data,
    });
}

export function useWidget(ws: string, id: string | undefined) {
    return useQuery({
        queryKey: onsiteKeys.one(ws, id ?? ''),
        queryFn: ({ signal }) => api.get<WidgetDetail>(one(ws, id ?? ''), signal),
        enabled: id !== undefined,
    });
}

export function useOnsiteOptions(ws: string) {
    return useQuery({ queryKey: onsiteKeys.options(ws), queryFn: ({ signal }) => api.get<OnsiteOptions>(`${base(ws)}/options`, signal), staleTime: 60_000 });
}

export function useWidgetStats(ws: string, id: string, days: number) {
    return useQuery({ queryKey: onsiteKeys.stats(ws, id, days), queryFn: ({ signal }) => api.get<WidgetStatsDetail>(`${one(ws, id)}/stats?days=${days}`, signal) });
}

export function useWidgetCaptures(ws: string, id: string) {
    return useQuery({
        queryKey: onsiteKeys.captures(ws, id),
        queryFn: async ({ signal }) => (await api.get<{ data: WidgetCaptureRow[] }>(`${one(ws, id)}/captures`, signal)).data,
    });
}

export function useCreateWidget(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        /** Con `template` (galería) el hub decide el tipo y los textos; sin él, `kind` con sus valores por defecto. */
        mutationFn: (data: { name: string; site_id: string | null } & ({ kind: WidgetKind; template?: undefined } | { template: string; kind?: undefined })) =>
            api.post<WidgetDetail>(`${base(ws)}/widgets`, data),
        onSuccess: (w) => {
            void qc.invalidateQueries({ queryKey: onsiteKeys.all(ws) });
            qc.setQueryData(onsiteKeys.one(ws, w.id), w);
        },
    });
}

/** Acciones sobre un widget; todas refrescan la lista y el detalle. */
export function useWidgetActions(ws: string, id: string) {
    const qc = useQueryClient();
    const refresh = (w?: WidgetDetail) => {
        void qc.invalidateQueries({ queryKey: onsiteKeys.all(ws), predicate: (q) => q.queryKey[3] === 'list' });
        if (w) qc.setQueryData(onsiteKeys.one(ws, id), w);
    };
    return {
        save: useMutation({ mutationFn: (data: Partial<WidgetDraft>) => api.patch<WidgetDetail>(one(ws, id), data), onSuccess: refresh }),
        activate: useMutation({ mutationFn: () => api.post<WidgetDetail>(`${one(ws, id)}/activate`), onSuccess: refresh }),
        pause: useMutation({ mutationFn: () => api.post<WidgetDetail>(`${one(ws, id)}/pause`), onSuccess: refresh }),
        archive: useMutation({ mutationFn: () => api.post<WidgetDetail>(`${one(ws, id)}/archive`), onSuccess: refresh }),
        duplicate: useMutation({ mutationFn: () => api.post<WidgetDetail>(`${one(ws, id)}/duplicate`), onSuccess: () => refresh() }),
        remove: useMutation({ mutationFn: () => api.delete<{ ok: boolean }>(one(ws, id)), onSuccess: () => refresh() }),
    };
}
