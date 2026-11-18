import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { wsPath } from '@/api/queries';
import { api } from '@/lib/api';
import type { Adjustment, ContactLoyalty, LedgerEntry, LoyaltyOverview, LoyaltyStats, Member, Paged, Reward } from './types';

export const loyaltyKeys = {
    all: (ws: string) => ['ws', ws, 'loyalty'] as const,
    overview: (ws: string) => ['ws', ws, 'loyalty', 'overview'] as const,
    stats: (ws: string, months: number) => ['ws', ws, 'loyalty', 'stats', months] as const,
    members: (ws: string, params: string) => ['ws', ws, 'loyalty', 'members', params] as const,
    contact: (ws: string, id: string, page: number) => ['ws', ws, 'loyalty', 'contact', id, page] as const,
};

const base = (ws: string) => `${wsPath(ws)}/loyalty`;

export function useLoyalty(ws: string) {
    return useQuery({ queryKey: loyaltyKeys.overview(ws), queryFn: ({ signal }) => api.get<LoyaltyOverview>(base(ws), signal) });
}

export function useLoyaltyStats(ws: string, months = 6, enabled = true) {
    return useQuery({ queryKey: loyaltyKeys.stats(ws, months), queryFn: ({ signal }) => api.get<LoyaltyStats>(`${base(ws)}/stats?months=${months}`, signal), enabled });
}

export function useLoyaltyMembers(ws: string, params: { q: string; tier: string; sort: string; page: number }) {
    const qs = new URLSearchParams();
    if (params.q !== '') qs.set('q', params.q);
    if (params.tier !== '') qs.set('tier', params.tier);
    qs.set('sort', params.sort);
    qs.set('page', String(params.page));
    const query = qs.toString();
    return useQuery({
        queryKey: loyaltyKeys.members(ws, query),
        queryFn: ({ signal }) => api.get<Paged<Member>>(`${base(ws)}/members?${query}`, signal),
        placeholderData: keepPreviousData,
    });
}

export function useContactLoyalty(ws: string, contactId: string, page: number, enabled = true) {
    return useQuery({
        queryKey: loyaltyKeys.contact(ws, contactId, page),
        queryFn: ({ signal }) => api.get<ContactLoyalty>(`${base(ws)}/contacts/${encodeURIComponent(contactId)}?page=${page}`, signal),
        placeholderData: keepPreviousData,
        enabled,
    });
}

/** Guardar el programa, los niveles o una recompensa refresca todo el módulo. */
export function useLoyaltyMutations(ws: string) {
    const qc = useQueryClient();
    const done = (data?: LoyaltyOverview) => {
        if (data) qc.setQueryData(loyaltyKeys.overview(ws), data);
        void qc.invalidateQueries({ queryKey: loyaltyKeys.all(ws) });
    };
    return {
        saveProgram: useMutation({ mutationFn: (data: unknown) => api.put<LoyaltyOverview>(`${base(ws)}/program`, data), onSuccess: done }),
        saveTiers: useMutation({ mutationFn: (tiers: unknown[]) => api.put<LoyaltyOverview>(`${base(ws)}/tiers`, { tiers }), onSuccess: done }),
        createReward: useMutation({ mutationFn: (data: unknown) => api.post<Reward>(`${base(ws)}/rewards`, data), onSuccess: () => done() }),
        updateReward: useMutation({ mutationFn: ({ id, data }: { id: string; data: unknown }) => api.patch<Reward>(`${base(ws)}/rewards/${encodeURIComponent(id)}`, data), onSuccess: () => done() }),
        archiveReward: useMutation({ mutationFn: (id: string) => api.delete<{ ok: boolean }>(`${base(ws)}/rewards/${encodeURIComponent(id)}`), onSuccess: () => done() }),
    };
}

export function useAdjust(ws: string, contactId: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (data: Adjustment) => api.post<LedgerEntry>(`${base(ws)}/contacts/${encodeURIComponent(contactId)}/adjust`, data),
        onSuccess: () => void qc.invalidateQueries({ queryKey: loyaltyKeys.all(ws) }),
    });
}
