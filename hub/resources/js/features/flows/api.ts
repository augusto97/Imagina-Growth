import { keepPreviousData, queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { wsPath } from '@/api/queries';
import { api } from '@/lib/api';
import type { FlowAnalytics, FlowDetail, FlowOptions, FlowRunRow, FlowSummary, Graph, LibraryFlow, TestResult } from './types';

export const flowKeys = {
    all: (ws: string) => ['ws', ws, 'flows'] as const,
    one: (ws: string, id: string) => ['ws', ws, 'flows', id] as const,
    options: (ws: string) => ['ws', ws, 'flows', 'options'] as const,
    library: (ws: string) => ['ws', ws, 'flows', 'library'] as const,
    analytics: (ws: string, id: string, days: number) => ['ws', ws, 'flows', id, 'analytics', days] as const,
    runs: (ws: string, id: string, status: string, page: number) => ['ws', ws, 'flows', id, 'runs', status, page] as const,
};

const base = (ws: string) => `${wsPath(ws)}/flows`;

export function flowsQuery(ws: string) {
    return queryOptions({ queryKey: flowKeys.all(ws), queryFn: ({ signal }) => api.get<{ data: FlowSummary[]; archived_count: number }>(base(ws), signal) });
}

export function useFlows(ws: string) {
    return useQuery(flowsQuery(ws));
}

export function useFlow(ws: string, id: string | undefined) {
    return useQuery({
        queryKey: flowKeys.one(ws, id ?? ''),
        queryFn: ({ signal }) => api.get<FlowDetail>(`${base(ws)}/${encodeURIComponent(id ?? '')}`, signal),
        enabled: id !== undefined,
    });
}

export function useFlowOptions(ws: string) {
    return useQuery({ queryKey: flowKeys.options(ws), queryFn: ({ signal }) => api.get<FlowOptions>(`${base(ws)}/options`, signal), staleTime: 60_000 });
}

export function useFlowLibrary(ws: string) {
    return useQuery({
        queryKey: flowKeys.library(ws),
        queryFn: async ({ signal }) => {
            const res = await api.get<{ data: LibraryFlow[]; meta: { whatsapp_connected: boolean } }>(`${base(ws)}/library`, signal);
            return { flows: res.data, whatsappConnected: res.meta.whatsapp_connected };
        },
    });
}

export function useFlowAnalytics(ws: string, id: string, days: number, enabled: boolean) {
    return useQuery({
        queryKey: flowKeys.analytics(ws, id, days),
        queryFn: ({ signal }) => api.get<FlowAnalytics>(`${base(ws)}/${encodeURIComponent(id)}/analytics?days=${days}`, signal),
        enabled,
        placeholderData: keepPreviousData,
    });
}

export function useFlowRuns(ws: string, id: string, status: string, page: number, enabled: boolean) {
    return useQuery({
        queryKey: flowKeys.runs(ws, id, status, page),
        queryFn: ({ signal }) =>
            api.get<{ data: FlowRunRow[]; meta: { total: number; page: number; last_page: number } }>(
                `${base(ws)}/${encodeURIComponent(id)}/runs?status=${status}&page=${page}`,
                signal,
            ),
        enabled,
        placeholderData: keepPreviousData,
    });
}

/** Invalida la lista y deja el detalle nuevo en la caché. */
function useFlowMutation<V>(ws: string, fn: (vars: V) => Promise<FlowDetail>) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: fn,
        onSuccess: (flow) => {
            qc.setQueryData(flowKeys.one(ws, flow.id), flow);
            void qc.invalidateQueries({ queryKey: flowKeys.all(ws), exact: true });
            void qc.invalidateQueries({ queryKey: flowKeys.library(ws) });
        },
    });
}

export function useCreateFlow(ws: string) {
    return useFlowMutation(ws, (vars: { name: string; description?: string | null }) => api.post<FlowDetail>(base(ws), vars));
}

export interface FlowPatch {
    name?: string;
    description?: string | null;
    trigger?: unknown;
    goal?: unknown;
    reentry_policy?: unknown;
    exclusions?: string[];
    holdout_pct?: number;
    graph?: Graph;
}

export function useUpdateFlow(ws: string, id: string) {
    return useFlowMutation(ws, (patch: FlowPatch) => api.patch<FlowDetail>(`${base(ws)}/${encodeURIComponent(id)}`, patch));
}

export function usePublishFlow(ws: string, id: string) {
    return useFlowMutation(ws, (vars: { migrate: boolean }) => api.post<FlowDetail>(`${base(ws)}/${encodeURIComponent(id)}/publish`, vars));
}

export function useFlowStatus(ws: string, id: string) {
    return useFlowMutation(ws, (action: 'activate' | 'pause' | 'archive') => api.post<FlowDetail>(`${base(ws)}/${encodeURIComponent(id)}/${action}`));
}

export function useRestoreVersion(ws: string, id: string) {
    return useFlowMutation(ws, (versionId: string) => api.post<FlowDetail>(`${base(ws)}/${encodeURIComponent(id)}/restore/${encodeURIComponent(versionId)}`));
}

export function useCreateFromLibrary(ws: string) {
    return useFlowMutation(ws, (vars: { key: string; activate: boolean }) =>
        api.post<FlowDetail>(`${base(ws)}/library/${encodeURIComponent(vars.key)}`, { activate: vars.activate }),
    );
}

export function useDeleteFlow(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (id: string) => api.delete<{ deleted: boolean; archived?: boolean }>(`${base(ws)}/${encodeURIComponent(id)}`),
        onSuccess: () => void qc.invalidateQueries({ queryKey: flowKeys.all(ws) }),
    });
}

export function useTestFlow(ws: string, id: string) {
    return useMutation({
        mutationFn: (vars: { contact_id?: string | null; phone?: string | null }) => api.post<TestResult>(`${base(ws)}/${encodeURIComponent(id)}/test`, vars),
    });
}
