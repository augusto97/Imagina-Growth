import { queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { wsPath } from '@/api/queries';
import { api } from '@/lib/api';
import type { Audience, CalendarResponse, CampaignChannel, CampaignDetail, CampaignOptions, CampaignReport, CampaignSummary, ReviewResponse } from './types';

export const campaignKeys = {
    all: (ws: string) => ['ws', ws, 'campaigns'] as const,
    one: (ws: string, id: string) => ['ws', ws, 'campaigns', id] as const,
    report: (ws: string, id: string) => ['ws', ws, 'campaigns', id, 'report'] as const,
    options: (ws: string) => ['ws', ws, 'campaigns', 'options'] as const,
    calendar: (ws: string, year: number, country: string) => ['ws', ws, 'campaigns', 'calendar', year, country] as const,
};

const base = (ws: string) => `${wsPath(ws)}/campaigns`;
const one = (ws: string, id: string) => `${base(ws)}/${encodeURIComponent(id)}`;

export function campaignsQuery(ws: string) {
    return queryOptions({ queryKey: campaignKeys.all(ws), queryFn: async ({ signal }) => (await api.get<{ data: CampaignSummary[] }>(base(ws), signal)).data });
}

export function useCampaigns(ws: string) {
    return useQuery(campaignsQuery(ws));
}

export function useCampaign(ws: string, id: string | undefined) {
    return useQuery({
        queryKey: campaignKeys.one(ws, id ?? ''),
        queryFn: ({ signal }) => api.get<CampaignDetail>(one(ws, id ?? ''), signal),
        enabled: id !== undefined,
    });
}

export function useCampaignReport(ws: string, id: string) {
    return useQuery({
        queryKey: campaignKeys.report(ws, id),
        queryFn: ({ signal }) => api.get<{ campaign: CampaignDetail; report: CampaignReport }>(`${one(ws, id)}/report`, signal),
        // Mientras envía, se refresca solo.
        refetchInterval: (query) => (query.state.data?.campaign.status === 'sending' ? 10_000 : false),
    });
}

export function useCampaignOptions(ws: string) {
    return useQuery({ queryKey: campaignKeys.options(ws), queryFn: ({ signal }) => api.get<CampaignOptions>(`${base(ws)}/options`, signal), staleTime: 60_000 });
}

export function useCalendar(ws: string, year: number, country: string) {
    return useQuery({
        queryKey: campaignKeys.calendar(ws, year, country),
        queryFn: ({ signal }) => api.get<CalendarResponse>(`${base(ws)}/calendar?year=${year}&country=${country}`, signal),
        staleTime: 60_000,
    });
}

export function useCreateCampaign(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (data: { name: string; channel: CampaignChannel }) => api.post<CampaignDetail>(base(ws), data),
        onSuccess: (c) => {
            void qc.invalidateQueries({ queryKey: campaignKeys.all(ws) });
            qc.setQueryData(campaignKeys.one(ws, c.id), c);
        },
    });
}

export type CampaignPatch = Partial<Pick<CampaignDetail, 'name' | 'channel' | 'audience' | 'template_id' | 'variants' | 'ab_config' | 'holdout_pct' | 'send_mode' | 'send_at'>>;

/** Acciones sobre una campaña; todas refrescan la lista y el detalle. */
export function useCampaignActions(ws: string, id: string) {
    const qc = useQueryClient();
    const refresh = (c?: CampaignDetail) => {
        void qc.invalidateQueries({ queryKey: campaignKeys.all(ws) });
        void qc.invalidateQueries({ queryKey: campaignKeys.report(ws, id) });
        if (c) qc.setQueryData(campaignKeys.one(ws, id), c);
    };
    return {
        update: useMutation({ mutationFn: (data: CampaignPatch) => api.patch<CampaignDetail>(one(ws, id), data), onSuccess: refresh }),
        review: useMutation({ mutationFn: () => api.post<ReviewResponse>(`${one(ws, id)}/review`), onSuccess: (r) => refresh(r.campaign) }),
        schedule: useMutation({ mutationFn: () => api.post<CampaignDetail>(`${one(ws, id)}/schedule`), onSuccess: refresh }),
        pause: useMutation({ mutationFn: () => api.post<CampaignDetail>(`${one(ws, id)}/pause`), onSuccess: refresh }),
        resume: useMutation({ mutationFn: () => api.post<CampaignDetail>(`${one(ws, id)}/resume`), onSuccess: refresh }),
        cancel: useMutation({ mutationFn: () => api.post<CampaignDetail>(`${one(ws, id)}/cancel`), onSuccess: refresh }),
        duplicate: useMutation({ mutationFn: () => api.post<CampaignDetail>(`${one(ws, id)}/duplicate`), onSuccess: () => refresh() }),
        remove: useMutation({ mutationFn: () => api.delete<{ ok: boolean }>(one(ws, id)), onSuccess: () => refresh() }),
        count: useMutation({ mutationFn: (audience: Audience) => api.post<{ count: number }>(`${one(ws, id)}/audience-count`, { audience }) }),
    };
}
