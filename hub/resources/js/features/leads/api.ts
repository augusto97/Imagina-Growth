import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { wsPath } from '@/api/queries';
import { api } from '@/lib/api';
import { filtersQuery } from './logic';
import type { BoardResponse, Kpis, LeadDetail, LeadFilters, LeadSettings, ListResponse, Pipeline, Seller, Stage, TeamMember } from './types';

/* Leads (M18). */

export const leadKeys = {
    all: (ws: string) => ['ws', ws, 'leads'] as const,
    board: (ws: string, f: LeadFilters) => ['ws', ws, 'leads', 'board', f] as const,
    list: (ws: string, f: LeadFilters) => ['ws', ws, 'leads', 'list', f] as const,
    detail: (ws: string, id: string) => ['ws', ws, 'leads', 'detail', id] as const,
    kpis: (ws: string, days: number, pipeline: string) => ['ws', ws, 'leads', 'kpis', days, pipeline] as const,
    settings: (ws: string) => ['ws', ws, 'leads', 'settings'] as const,
    team: (ws: string) => ['ws', ws, 'leads', 'team'] as const,
};

export function useBoard(ws: string, filters: LeadFilters) {
    return useQuery({ queryKey: leadKeys.board(ws, filters), queryFn: ({ signal }) => api.get<BoardResponse>(`${wsPath(ws)}/leads/board${filtersQuery(filters)}`, signal) });
}

export function useLeadList(ws: string, filters: LeadFilters) {
    return useQuery({ queryKey: leadKeys.list(ws, filters), queryFn: ({ signal }) => api.get<ListResponse>(`${wsPath(ws)}/leads${filtersQuery(filters)}`, signal) });
}

export function useLead(ws: string, id: string | null) {
    return useQuery({ queryKey: leadKeys.detail(ws, id ?? ''), enabled: id !== null, queryFn: ({ signal }) => api.get<LeadDetail>(`${wsPath(ws)}/leads/${id ?? ''}`, signal) });
}

export function useLeadKpis(ws: string, days: number, pipeline: string) {
    return useQuery({
        queryKey: leadKeys.kpis(ws, days, pipeline),
        queryFn: ({ signal }) => api.get<Kpis>(`${wsPath(ws)}/leads/kpis?days=${days}${pipeline ? `&pipeline=${encodeURIComponent(pipeline)}` : ''}`, signal),
    });
}

export function useLeadSettings(ws: string) {
    return useQuery({ queryKey: leadKeys.settings(ws), queryFn: ({ signal }) => api.get<LeadSettings>(`${wsPath(ws)}/leads/settings`, signal) });
}

export function useTeam(ws: string) {
    return useQuery({ queryKey: leadKeys.team(ws), staleTime: 60_000, queryFn: ({ signal }) => api.get<{ data: TeamMember[] }>(`${wsPath(ws)}/leads/team`, signal) });
}

function useInvalidate(ws: string) {
    const qc = useQueryClient();
    return () => void qc.invalidateQueries({ queryKey: leadKeys.all(ws) });
}

export function useMoveLead(ws: string) {
    const invalidate = useInvalidate(ws);
    return useMutation({
        mutationFn: ({ id, stage, value, lost_reason }: { id: string; stage: string; value?: string | null; lost_reason?: string | null }) =>
            api.post<LeadDetail>(`${wsPath(ws)}/leads/${id}/stage`, { stage, value: value ?? undefined, lost_reason: lost_reason ?? undefined }),
        onSettled: invalidate,
    });
}

export function useUpdateLead(ws: string) {
    const invalidate = useInvalidate(ws);
    return useMutation({
        mutationFn: ({ id, ...body }: { id: string; owner_id?: string | null; value?: string | null; service?: string | null; next_step?: string | null; next_step_at?: string | null }) =>
            api.patch<LeadDetail>(`${wsPath(ws)}/leads/${id}`, body),
        onSuccess: invalidate,
    });
}

export function useAddNote(ws: string) {
    const invalidate = useInvalidate(ws);
    return useMutation({ mutationFn: ({ id, body }: { id: string; body: string }) => api.post<LeadDetail>(`${wsPath(ws)}/leads/${id}/notes`, { body }), onSuccess: invalidate });
}

export function useSaveLeadSettings(ws: string) {
    const invalidate = useInvalidate(ws);
    return useMutation({ mutationFn: (body: Record<string, unknown>) => api.put<LeadSettings>(`${wsPath(ws)}/leads/settings`, body), onSuccess: invalidate });
}

export function useSellers(ws: string) {
    const invalidate = useInvalidate(ws);
    return {
        create: useMutation({
            mutationFn: (body: { user_id: string; phone?: string; services?: string[] }) => api.post<{ sellers: Seller[] }>(`${wsPath(ws)}/leads/sellers`, body),
            onSuccess: invalidate,
        }),
        update: useMutation({
            mutationFn: ({ id, ...body }: { id: string; phone?: string | null; services?: string[]; active?: boolean }) => api.put<{ sellers: Seller[] }>(`${wsPath(ws)}/leads/sellers/${id}`, body),
            onSuccess: invalidate,
        }),
        remove: useMutation({ mutationFn: (id: string) => api.delete<{ sellers: Seller[] }>(`${wsPath(ws)}/leads/sellers/${id}`), onSuccess: invalidate }),
    };
}

export function usePipelines(ws: string) {
    const invalidate = useInvalidate(ws);
    return {
        create: useMutation({ mutationFn: (body: { name: string; stages: Stage[] }) => api.post<Pipeline>(`${wsPath(ws)}/leads/pipelines`, body), onSuccess: invalidate }),
        update: useMutation({
            mutationFn: ({ id, ...body }: { id: string; name: string; stages: Stage[] }) => api.put<Pipeline>(`${wsPath(ws)}/leads/pipelines/${id}`, body),
            onSuccess: invalidate,
        }),
        remove: useMutation({ mutationFn: (id: string) => api.delete(`${wsPath(ws)}/leads/pipelines/${id}`), onSuccess: invalidate }),
    };
}
