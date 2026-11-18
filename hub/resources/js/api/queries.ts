import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, isApiError } from '@/lib/api';
import type {
    AuditEntry,
    ContactRow,
    InvitationPreview,
    Me,
    MembersResponse,
    Overview,
    Paginated,
    Site,
    SiteShowResponse,
    Workspace,
} from './types';

export const keys = {
    me: ['me'] as const,
    workspace: (ws: string) => ['ws', ws] as const,
    overview: (ws: string) => ['ws', ws, 'overview'] as const,
    sites: (ws: string) => ['ws', ws, 'sites'] as const,
    site: (ws: string, site: string) => ['ws', ws, 'sites', site] as const,
    members: (ws: string) => ['ws', ws, 'members'] as const,
    contacts: (ws: string, q: string, page: number) => ['ws', ws, 'contacts', q, page] as const,
    audit: (ws: string) => ['ws', ws, 'audit'] as const,
    invitation: (token: string) => ['invitation', token] as const,
};

const w = (ws: string) => `/api/w/${encodeURIComponent(ws)}`;

/** La persona con sesión. null si no hay sesión (401). */
export function useMe() {
    return useQuery({
        queryKey: keys.me,
        queryFn: async ({ signal }) => {
            try {
                return await api.get<Me>('/api/me', signal);
            } catch (error) {
                if (isApiError(error, 'unauthenticated')) {
                    return null;
                }
                throw error;
            }
        },
        staleTime: 30_000,
    });
}

export function useOverview(ws: string) {
    return useQuery({ queryKey: keys.overview(ws), queryFn: ({ signal }) => api.get<Overview>(`${w(ws)}/overview`, signal) });
}

export function useSites(ws: string) {
    return useQuery({
        queryKey: keys.sites(ws),
        queryFn: async ({ signal }) => (await api.get<{ sites: Site[] }>(`${w(ws)}/sites`, signal)).sites,
    });
}

export function useSite(ws: string, site: string) {
    return useQuery({
        queryKey: keys.site(ws, site),
        queryFn: ({ signal }) => api.get<SiteShowResponse>(`${w(ws)}/sites/${encodeURIComponent(site)}`, signal),
        // Mientras corre la importación, el progreso se refresca solo.
        refetchInterval: (query) => {
            const status = query.state.data?.site.backfill?.status;
            return status === 'running' || status === 'queued' ? 4000 : false;
        },
    });
}

export function useMembers(ws: string) {
    return useQuery({ queryKey: keys.members(ws), queryFn: ({ signal }) => api.get<MembersResponse>(`${w(ws)}/members`, signal) });
}

export function useContacts(ws: string, q: string, page: number) {
    return useQuery({
        queryKey: keys.contacts(ws, q, page),
        queryFn: ({ signal }) => {
            const params = new URLSearchParams({ page: String(page) });
            if (q !== '') {
                params.set('q', q);
            }
            return api.get<Paginated<ContactRow>>(`${w(ws)}/contacts?${params.toString()}`, signal);
        },
        placeholderData: keepPreviousData,
    });
}

export function useAudit(ws: string) {
    return useQuery({
        queryKey: keys.audit(ws),
        queryFn: async ({ signal }) => (await api.get<{ data: AuditEntry[] }>(`${w(ws)}/audit-logs`, signal)).data,
    });
}

export function useInvitation(token: string) {
    return useQuery({
        queryKey: keys.invitation(token),
        queryFn: ({ signal }) => api.get<InvitationPreview>(`/api/invitations/${encodeURIComponent(token)}`, signal),
        retry: false,
    });
}

export function useUpdateWorkspace(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (data: Partial<Workspace>) => api.patch<Workspace>(w(ws), data),
        onSuccess: () => {
            void qc.invalidateQueries({ queryKey: keys.me });
            void qc.invalidateQueries({ queryKey: keys.workspace(ws) });
        },
    });
}

export const wsPath = w;
