import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { wsPath } from '@/api/queries';
import { api } from '@/lib/api';
import type { Platform } from '../api';

/* Audiencias hacia anuncios (M06, Fase 3). Rutas en hub/routes/api/audiences.php. */

export type AudiencePurpose = 'target' | 'exclusion' | 'lookalike_seed';
export type AudienceStatus = 'pending' | 'active' | 'paused' | 'error';
export type AudiencePreset = 'recent_buyers_exclusion' | 'high_value_seed' | 'at_risk_reactivation';

export interface Audience {
    id: string;
    name: string;
    purpose: AudiencePurpose;
    status: AudienceStatus;
    platform: Platform | null;
    destination: { id: string; name: string | null } | null;
    segment: { id: string; name: string; members: number } | null;
    platform_audience_id: string | null;
    members_count: number;
    matched_count: number | null;
    last_added: number;
    last_removed: number;
    skipped_no_consent: number;
    last_synced_at: string | null;
    last_attempt_at: string | null;
    next_sync_at: string | null;
    last_error: string | null;
}

export interface AudiencesResponse {
    data: Audience[];
    destinations: { id: string; platform: Platform; name: string | null; active: boolean; missing: string | null }[];
    segments: { id: string; name: string; members: number; system_key: string | null }[];
    presets: { key: AudiencePreset; purpose: AudiencePurpose; system_key: string | null }[];
    consent_rule: 'explicit' | 'unless_revoked';
    min_recommended_size: number;
}

export interface AudienceInput {
    destination_id: string;
    segment_id?: string;
    preset?: AudiencePreset;
    purpose?: AudiencePurpose;
    name?: string;
}

const base = (ws: string) => `${wsPath(ws)}/measurement/audiences`;
export const audienceKeys = { list: (ws: string) => ['ws', ws, 'measurement', 'audiences'] as const };

export function useAudiences(ws: string) {
    return useQuery({ queryKey: audienceKeys.list(ws), queryFn: ({ signal }) => api.get<AudiencesResponse>(base(ws), signal) });
}

export function useCreateAudience(ws: string) {
    const qc = useQueryClient();
    return useMutation({ mutationFn: (input: AudienceInput) => api.post<Audience>(base(ws), input), onSuccess: () => qc.invalidateQueries({ queryKey: audienceKeys.list(ws) }) });
}

export function useUpdateAudience(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: ({ id, input }: { id: string; input: { name?: string; status?: 'active' | 'paused'; purpose?: AudiencePurpose } }) => api.patch<Audience>(`${base(ws)}/${encodeURIComponent(id)}`, input),
        onSuccess: () => qc.invalidateQueries({ queryKey: audienceKeys.list(ws) }),
    });
}

export function useSyncAudience(ws: string) {
    const qc = useQueryClient();
    return useMutation({ mutationFn: (id: string) => api.post<{ queued: boolean }>(`${base(ws)}/${encodeURIComponent(id)}/sync`, {}), onSuccess: () => qc.invalidateQueries({ queryKey: audienceKeys.list(ws) }) });
}

export function useDeleteAudience(ws: string) {
    const qc = useQueryClient();
    return useMutation({ mutationFn: (id: string) => api.delete(`${base(ws)}/${encodeURIComponent(id)}`), onSuccess: () => qc.invalidateQueries({ queryKey: audienceKeys.list(ws) }) });
}
