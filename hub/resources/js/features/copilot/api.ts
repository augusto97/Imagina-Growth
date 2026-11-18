import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { wsPath } from '@/api/queries';
import { api } from '@/lib/api';
import type { BrandTone, CampaignEdits, Draft, DraftKind, DraftSummary } from './types';

export const copilotKeys = {
    status: (ws: string) => ['ws', ws, 'copilot', 'status'] as const,
    drafts: (ws: string, kind?: DraftKind) => ['ws', ws, 'copilot', 'drafts', kind ?? 'all'] as const,
    draft: (ws: string, id: string) => ['ws', ws, 'copilot', 'draft', id] as const,
};

const base = (ws: string) => `${wsPath(ws)}/copilot`;

export function useCopilotStatus(ws: string) {
    return useQuery({
        queryKey: copilotKeys.status(ws),
        queryFn: ({ signal }) => api.get<{ available: boolean; tone: BrandTone | null }>(`${base(ws)}/status`, signal),
        staleTime: 60_000,
    });
}

export function useDrafts(ws: string, kind?: DraftKind) {
    return useQuery({
        queryKey: copilotKeys.drafts(ws, kind),
        queryFn: async ({ signal }) => (await api.get<{ data: DraftSummary[] }>(`${base(ws)}/drafts${kind ? `?kind=${kind}` : ''}`, signal)).data,
    });
}

export function useDraft(ws: string, id: string | null) {
    return useQuery({
        queryKey: copilotKeys.draft(ws, id ?? ''),
        queryFn: ({ signal }) => api.get<Draft>(`${base(ws)}/drafts/${encodeURIComponent(id ?? '')}`, signal),
        enabled: id !== null,
    });
}

function useDraftMutation<V>(ws: string, fn: (v: V) => Promise<Draft>) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: fn,
        onSuccess: (draft) => {
            qc.setQueryData(copilotKeys.draft(ws, draft.id), draft);
            void qc.invalidateQueries({ queryKey: ['ws', ws, 'copilot', 'drafts'] });
        },
    });
}

export function usePropose(ws: string, kind: 'campaign' | 'flow') {
    return useDraftMutation(ws, (prompt: string) => api.post<Draft>(`${base(ws)}/${kind === 'campaign' ? 'campaigns' : 'flows'}`, { prompt }));
}

export function useEditDraft(ws: string, id: string) {
    return useDraftMutation(ws, (edits: CampaignEdits) => api.patch<Draft>(`${base(ws)}/drafts/${encodeURIComponent(id)}`, edits));
}

export function useApplyDraft(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (id: string) => api.post<Draft>(`${base(ws)}/drafts/${encodeURIComponent(id)}/apply`),
        onSuccess: (draft) => {
            qc.setQueryData(copilotKeys.draft(ws, draft.id), draft);
            void qc.invalidateQueries({ queryKey: ['ws', ws, 'copilot', 'drafts'] });
            void qc.invalidateQueries({ queryKey: ['ws', ws, draft.kind === 'flow' ? 'flows' : 'campaigns'] });
        },
    });
}

export function useDiscardDraft(ws: string) {
    return useDraftMutation(ws, (id: string) => api.post<Draft>(`${base(ws)}/drafts/${encodeURIComponent(id)}/discard`));
}

export interface Brief {
    goal: string;
    offer?: string | null;
    audience?: string | null;
}

export function useSuggestSubjects(ws: string) {
    return useMutation({
        mutationFn: (v: Brief & { count?: number }) => api.post<{ id: string; variants: { subject: string; preheader: string }[] }>(`${base(ws)}/content/subjects`, v),
    });
}

export function useSuggestWhatsapp(ws: string) {
    return useMutation({
        mutationFn: (v: Brief & { category?: 'marketing' | 'utility' }) =>
            api.post<{ id: string; body: string; variables: { index: number; meaning: string; example: string }[]; category: string; problems: string[] }>(`${base(ws)}/content/whatsapp`, v),
    });
}

export function useSuggestVariants(ws: string) {
    return useMutation({
        mutationFn: (v: { text: string; kind?: 'subject' | 'body' | 'whatsapp'; count?: number }) => api.post<{ id: string; variants: string[] }>(`${base(ws)}/content/variants`, v),
    });
}

export function useLearnTone(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: () => api.post<{ tone: BrandTone }>(`${base(ws)}/tone`),
        onSuccess: () => void qc.invalidateQueries({ queryKey: copilotKeys.status(ws) }),
    });
}
