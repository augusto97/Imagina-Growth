import { keepPreviousData, queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { wsPath } from '@/api/queries';
import type { Paginated } from '@/api/types';
import { api, apiRequest } from '@/lib/api';
import type { Group, LibraryResponse, PickerItem, Preview, SegmentContact, SegmentDetail, SegmentOptions, SegmentSummary } from './types';

export const segmentKeys = {
    all: (ws: string) => ['ws', ws, 'segments'] as const,
    one: (ws: string, id: string) => ['ws', ws, 'segments', id] as const,
    members: (ws: string, id: string, page: number) => ['ws', ws, 'segments', id, 'members', page] as const,
    options: (ws: string) => ['ws', ws, 'segments', 'options'] as const,
    library: (ws: string) => ['ws', ws, 'segments', 'library'] as const,
    preview: (ws: string, definition: string) => ['ws', ws, 'segments', 'preview', definition] as const,
    picker: (ws: string, kind: string, q: string, ids: string) => ['ws', ws, 'segments', 'picker', kind, q, ids] as const,
};

const base = (ws: string) => `${wsPath(ws)}/segments`;

export function segmentsQuery(ws: string) {
    return queryOptions({ queryKey: segmentKeys.all(ws), queryFn: async ({ signal }) => (await api.get<{ data: SegmentSummary[] }>(base(ws), signal)).data });
}

export function useSegments(ws: string) {
    return useQuery(segmentsQuery(ws));
}

/**
 * «Usar este grupo»: una campaña de una vez (se crea con el canal y el grupo como audiencia y sigue en el paso del
 * mensaje) o una automatización que arranca cuando alguien entra al grupo. Las dos quedan en borrador.
 */
export function useUseGroup(ws: string) {
    const qc = useQueryClient();
    return {
        campaign: useMutation({
            mutationFn: async ({ segmentId, name, channel }: { segmentId: string; name: string; channel: 'whatsapp' | 'email' }) => {
                const created = await api.post<{ id: string }>(`${wsPath(ws)}/campaigns`, { name, channel });
                await api.patch(`${wsPath(ws)}/campaigns/${encodeURIComponent(created.id)}`, {
                    audience: { segments_include: [segmentId], segments_exclude: [], all_contacts: false, rules_include: [], rules_exclude: [] },
                });
                return created;
            },
            onSuccess: () => void qc.invalidateQueries({ queryKey: ['ws', ws, 'campaigns'] }),
        }),
        flow: useMutation({
            mutationFn: async ({ segmentId, name }: { segmentId: string; name: string }) => {
                const created = await api.post<{ id: string }>(`${wsPath(ws)}/flows`, { name });
                await api.patch(`${wsPath(ws)}/flows/${encodeURIComponent(created.id)}`, { trigger: { type: 'segment', segment_id: segmentId, on: 'entered' } });
                return created;
            },
            onSuccess: () => void qc.invalidateQueries({ queryKey: ['ws', ws, 'flows'] }),
        }),
    };
}

export function useSegment(ws: string, id: string | undefined) {
    return useQuery({
        queryKey: segmentKeys.one(ws, id ?? ''),
        queryFn: ({ signal }) => api.get<SegmentDetail>(`${base(ws)}/${encodeURIComponent(id ?? '')}`, signal),
        enabled: id !== undefined,
        // Mientras el primer cálculo está pendiente, se refresca solo.
        refetchInterval: (query) => (query.state.data && query.state.data.kind === 'dynamic' && query.state.data.last_computed_at === null ? 3000 : false),
    });
}

export function useSegmentMembers(ws: string, id: string, page: number) {
    return useQuery({
        queryKey: segmentKeys.members(ws, id, page),
        queryFn: ({ signal }) => api.get<Paginated<SegmentContact>>(`${base(ws)}/${encodeURIComponent(id)}/members?page=${page}`, signal),
        placeholderData: keepPreviousData,
    });
}

export function useSegmentOptions(ws: string) {
    return useQuery({ queryKey: segmentKeys.options(ws), queryFn: ({ signal }) => api.get<SegmentOptions>(`${base(ws)}/options`, signal), staleTime: 60_000 });
}

export function useLibrary(ws: string) {
    return useQuery({ queryKey: segmentKeys.library(ws), queryFn: ({ signal }) => api.get<LibraryResponse>(`${base(ws)}/library`, signal) });
}

/** Vista previa en vivo: la clave es la definición serializada (llega ya con debounce). */
export function useSegmentPreview(ws: string, definition: Group | null) {
    const serialized = definition ? JSON.stringify(definition) : '';
    return useQuery({
        queryKey: segmentKeys.preview(ws, serialized),
        queryFn: ({ signal }) => apiRequest<Preview>(`${base(ws)}/preview`, { method: 'POST', body: { definition }, signal }),
        enabled: definition !== null && definition.conditions.length > 0,
        placeholderData: keepPreviousData,
        retry: false,
        staleTime: 30_000,
    });
}

export function usePicker(ws: string, kind: 'products' | 'categories', q: string, ids: string[]) {
    const idKey = ids.join(',');
    return useQuery({
        queryKey: segmentKeys.picker(ws, kind, q, idKey),
        queryFn: async ({ signal }) => {
            const params = new URLSearchParams();
            if (q !== '') {
                params.set('q', q);
            }
            ids.forEach((id) => params.append('ids[]', id));
            return (await api.get<{ data: PickerItem[] }>(`${base(ws)}/options/${kind}?${params.toString()}`, signal)).data;
        },
        placeholderData: keepPreviousData,
        staleTime: 60_000,
    });
}

export interface SegmentInput {
    name: string;
    description?: string | null;
    folder?: string | null;
    definition?: Group;
}

export function useSaveSegment(ws: string, id?: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (data: SegmentInput) => (id ? api.patch<SegmentDetail>(`${base(ws)}/${encodeURIComponent(id)}`, data) : api.post<SegmentDetail>(base(ws), data)),
        onSuccess: (segment) => {
            void qc.invalidateQueries({ queryKey: segmentKeys.all(ws) });
            qc.setQueryData(segmentKeys.one(ws, segment.id), segment);
        },
    });
}

export function useSegmentAction(ws: string, id: string) {
    const qc = useQueryClient();
    const refresh = () => {
        void qc.invalidateQueries({ queryKey: segmentKeys.all(ws) });
    };
    return {
        recompute: useMutation({ mutationFn: () => api.post<{ queued: boolean }>(`${base(ws)}/${encodeURIComponent(id)}/recompute`), onSuccess: refresh }),
        snapshot: useMutation({ mutationFn: (name: string) => api.post<SegmentSummary>(`${base(ws)}/${encodeURIComponent(id)}/snapshot`, { name }), onSuccess: refresh }),
        remove: useMutation({ mutationFn: () => api.delete<{ ok: boolean }>(`${base(ws)}/${encodeURIComponent(id)}`), onSuccess: refresh }),
    };
}

export function useCreateStatic(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (data: { name: string; folder?: string | null; values: string[] }) =>
            api.post<{ segment: SegmentSummary; matched: number; unmatched: number }>(`${base(ws)}/static`, data),
        onSuccess: () => void qc.invalidateQueries({ queryKey: segmentKeys.all(ws) }),
    });
}

export function useFromTemplate(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (data: { key: string; name?: string; params: Record<string, string[]> }) =>
            api.post<SegmentDetail>(`${base(ws)}/library/${data.key}`, { name: data.name, params: data.params }),
        onSuccess: () => void qc.invalidateQueries({ queryKey: segmentKeys.all(ws) }),
    });
}

export function exportUrl(ws: string, id: string): string {
    return `${base(ws)}/${encodeURIComponent(id)}/export`;
}
