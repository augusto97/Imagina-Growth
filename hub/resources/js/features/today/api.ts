import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { wsPath } from '@/api/queries';
import { api } from '@/lib/api';
import type { SendResult, Suggestion, TodayResponse } from './types';

/* «Hoy» (M18): lista del día, mensaje sugerido, enviar, hecho y posponer. */

export const todayKeys = {
    all: (ws: string) => ['ws', ws, 'leads', 'today'] as const,
    list: (ws: string, owner: string) => ['ws', ws, 'leads', 'today', 'list', owner] as const,
    suggestion: (ws: string, key: string) => ['ws', ws, 'leads', 'today', 'suggest', key] as const,
};

export function useToday(ws: string, owner: string) {
    return useQuery({
        queryKey: todayKeys.list(ws, owner),
        refetchInterval: 60_000,
        queryFn: ({ signal }) => api.get<TodayResponse>(`${wsPath(ws)}/leads/today${owner ? `?owner=${encodeURIComponent(owner)}` : ''}`, signal),
    });
}

/** El mensaje sugerido por la IA para un ítem (el servidor lo guarda 12 h). */
export function useSuggestion(ws: string, key: string, enabled: boolean) {
    return useQuery({
        queryKey: todayKeys.suggestion(ws, key),
        enabled,
        staleTime: 30 * 60_000,
        retry: false,
        queryFn: () => api.post<Suggestion>(`${wsPath(ws)}/leads/today/suggest`, { key }),
    });
}

function useRefresh(ws: string) {
    const qc = useQueryClient();
    return () => {
        void qc.invalidateQueries({ queryKey: todayKeys.all(ws) });
    };
}

export function useSendToday(ws: string) {
    const refresh = useRefresh(ws);
    return useMutation({
        mutationFn: (body: { key: string; text: string; client_id: string }) => api.post<SendResult>(`${wsPath(ws)}/leads/today/send`, body),
        onSuccess: refresh,
    });
}

export function useDoneToday(ws: string) {
    const refresh = useRefresh(ws);
    return useMutation({ mutationFn: (key: string) => api.post<{ ok: boolean }>(`${wsPath(ws)}/leads/today/done`, { key }), onSuccess: refresh });
}

export function useSnoozeToday(ws: string) {
    const refresh = useRefresh(ws);
    return useMutation({
        mutationFn: ({ key, days }: { key: string; days: number }) => api.post<{ ok: boolean; until: string }>(`${wsPath(ws)}/leads/today/snooze`, { key, days }),
        onSuccess: refresh,
    });
}
