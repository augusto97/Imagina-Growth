import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { wsPath } from '@/api/queries';
import { api } from '@/lib/api';
import { pollInterval } from '@/lib/realtime';
import type {
    ContactPanelData,
    ConversationDetail,
    ConversationList,
    ConversationStatus,
    AiMode,
    InboxAgent,
    InboxMetrics,
    InboxProduct,
    InboxSettings,
    InboxTemplate,
    InboxView,
    QuickReply,
    SendBody,
    SendResult,
} from './types';

/* Bandeja (M11). La lista y el chat abiertos se consultan cada 5 s; con Reverb conectado, cada 30 s (los cambios llegan por el canal). */

export const POLL_MS = 5000;

export interface ListFilters {
    view: InboxView;
    status: ConversationStatus | 'all';
    q: string;
    label: string;
    intent: string;
}

export const inboxKeys = {
    all: (ws: string) => ['ws', ws, 'inbox'] as const,
    list: (ws: string, f: ListFilters) => ['ws', ws, 'inbox', 'list', f] as const,
    detail: (ws: string, id: string) => ['ws', ws, 'inbox', 'conversation', id] as const,
    panel: (ws: string, id: string) => ['ws', ws, 'inbox', 'panel', id] as const,
    replies: (ws: string) => ['ws', ws, 'inbox', 'replies'] as const,
    templates: (ws: string) => ['ws', ws, 'inbox', 'templates'] as const,
    agents: (ws: string) => ['ws', ws, 'inbox', 'agents'] as const,
    settings: (ws: string) => ['ws', ws, 'inbox', 'settings'] as const,
    metrics: (ws: string, days: number) => ['ws', ws, 'inbox', 'metrics', days] as const,
    products: (ws: string, q: string) => ['ws', ws, 'inbox', 'products', q] as const,
};

export function listQuery(f: ListFilters): string {
    const p = new URLSearchParams();
    if (f.view !== 'all') p.set('view', f.view);
    p.set('status', f.status);
    if (f.q.trim() !== '') p.set('q', f.q.trim());
    if (f.label !== '') p.set('label', f.label);
    if (f.intent !== '') p.set('intent', f.intent);
    return `?${p.toString()}`;
}

export function useConversations(ws: string, f: ListFilters) {
    return useQuery({
        queryKey: inboxKeys.list(ws, f),
        queryFn: ({ signal }) => api.get<ConversationList>(`${wsPath(ws)}/inbox/conversations${listQuery(f)}`, signal),
        placeholderData: keepPreviousData,
        refetchInterval: () => pollInterval(POLL_MS),
    });
}

export function useConversation(ws: string, id: string | undefined) {
    return useQuery({
        queryKey: inboxKeys.detail(ws, id ?? ''),
        queryFn: ({ signal }) => api.get<ConversationDetail>(`${wsPath(ws)}/inbox/conversations/${id ?? ''}`, signal),
        enabled: id !== undefined,
        refetchInterval: () => pollInterval(POLL_MS),
    });
}

export function useContactPanel(ws: string, id: string | undefined) {
    return useQuery({
        queryKey: inboxKeys.panel(ws, id ?? ''),
        queryFn: ({ signal }) => api.get<ContactPanelData>(`${wsPath(ws)}/inbox/conversations/${id ?? ''}/panel`, signal),
        enabled: id !== undefined,
        staleTime: 30_000,
    });
}

/** Acciones sobre una conversación; todas refrescan la lista y el chat. */
export function useConversationActions(ws: string, id: string) {
    const qc = useQueryClient();
    const base = `${wsPath(ws)}/inbox/conversations/${id}`;
    const refresh = (data?: ConversationDetail) => {
        if (data && 'messages' in data) qc.setQueryData(inboxKeys.detail(ws, id), data);
        void qc.invalidateQueries({ queryKey: inboxKeys.all(ws) });
    };
    return {
        send: useMutation({
            mutationFn: (body: SendBody) => api.post<SendResult>(`${base}/messages`, body),
            onSuccess: () => refresh(),
        }),
        assign: useMutation({
            mutationFn: (userId: string | null) =>
                api.post<ConversationDetail>(`${base}/assign`, {
                    user_id: userId,
                }),
            onSuccess: refresh,
        }),
        update: useMutation({
            mutationFn: (body: { status?: ConversationStatus; labels?: string[]; ai_mode?: AiMode }) => api.patch<ConversationDetail>(base, body),
            onSuccess: refresh,
        }),
        handoff: useMutation({
            mutationFn: (reason: string) => api.post<ConversationDetail>(`${base}/handoff`, { reason }),
            onSuccess: refresh,
        }),
        note: useMutation({
            mutationFn: (body: string) => api.post<{ id: string }>(`${base}/notes`, { body }),
            onSuccess: () => refresh(),
        }),
        read: useMutation({
            mutationFn: () => api.post<{ ok: boolean }>(`${base}/read`),
            onSuccess: () =>
                void qc.invalidateQueries({
                    queryKey: [...inboxKeys.all(ws), 'list'],
                }),
        }),
        suggest: useMutation({
            mutationFn: () => api.post<{ suggestion: string | null }>(`${base}/suggest`),
        }),
    };
}

export function useQuickReplies(ws: string) {
    return useQuery({
        queryKey: inboxKeys.replies(ws),
        queryFn: ({ signal }) => api.get<{ data: QuickReply[] }>(`${wsPath(ws)}/inbox/quick-replies`, signal),
        staleTime: 60_000,
    });
}

export function useQuickReplyActions(ws: string) {
    const qc = useQueryClient();
    const done = () => void qc.invalidateQueries({ queryKey: inboxKeys.replies(ws) });
    return {
        create: useMutation({
            mutationFn: (r: Omit<QuickReply, 'id'>) => api.post<QuickReply>(`${wsPath(ws)}/inbox/quick-replies`, r),
            onSuccess: done,
        }),
        update: useMutation({
            mutationFn: (r: QuickReply) => api.put<QuickReply>(`${wsPath(ws)}/inbox/quick-replies/${r.id}`, r),
            onSuccess: done,
        }),
        remove: useMutation({
            mutationFn: (id: string) => api.delete<{ ok: boolean }>(`${wsPath(ws)}/inbox/quick-replies/${id}`),
            onSuccess: done,
        }),
    };
}

export function useInboxTemplates(ws: string) {
    return useQuery({
        queryKey: inboxKeys.templates(ws),
        queryFn: ({ signal }) => api.get<{ data: InboxTemplate[] }>(`${wsPath(ws)}/inbox/templates`, signal),
        staleTime: 60_000,
    });
}

export function useInboxProducts(ws: string, q: string, enabled: boolean) {
    return useQuery({
        queryKey: inboxKeys.products(ws, q),
        queryFn: ({ signal }) => api.get<{ data: InboxProduct[] }>(`${wsPath(ws)}/inbox/products?q=${encodeURIComponent(q)}`, signal),
        enabled,
        placeholderData: keepPreviousData,
    });
}

export function useInboxAgents(ws: string) {
    return useQuery({
        queryKey: inboxKeys.agents(ws),
        queryFn: ({ signal }) => api.get<{ data: InboxAgent[] }>(`${wsPath(ws)}/inbox/agents`, signal),
        refetchInterval: 60_000,
    });
}

export function usePresence(ws: string) {
    return useMutation({
        mutationFn: (available: boolean) =>
            api.post<{ ok: boolean }>(`${wsPath(ws)}/inbox/presence`, {
                available,
            }),
    });
}

export function useInboxSettings(ws: string) {
    return useQuery({
        queryKey: inboxKeys.settings(ws),
        queryFn: ({ signal }) => api.get<InboxSettings>(`${wsPath(ws)}/inbox/settings`, signal),
    });
}

export function useSaveInboxSettings(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (s: Omit<InboxSettings, 'push_public_key'>) => api.put<InboxSettings>(`${wsPath(ws)}/inbox/settings`, s),
        onSuccess: (data) => qc.setQueryData(inboxKeys.settings(ws), data),
    });
}

export function useInboxMetrics(ws: string, days: number) {
    return useQuery({
        queryKey: inboxKeys.metrics(ws, days),
        queryFn: ({ signal }) => api.get<InboxMetrics>(`${wsPath(ws)}/inbox/metrics?days=${days}`, signal),
        placeholderData: keepPreviousData,
    });
}

export function usePushSubscribe(ws: string) {
    return useMutation({
        mutationFn: (sub: { endpoint: string; keys: { p256dh: string; auth: string } }) => api.post<{ id: string }>(`${wsPath(ws)}/inbox/push/subscriptions`, sub),
    });
}
