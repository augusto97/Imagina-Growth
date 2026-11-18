import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { wsPath } from '@/api/queries';
import { api } from '@/lib/api';
import type { AgentSettings, AgentSettingsResponse, ChatMessage, ReviewFilter, ReviewResponse, SandboxResult, TurnDetail } from './types';

/* Agente de ventas con IA (M11). */

export const agentKeys = {
    all: (ws: string) => ['ws', ws, 'agent'] as const,
    settings: (ws: string) => ['ws', ws, 'agent', 'settings'] as const,
    review: (ws: string, filter: ReviewFilter, days: number, page: number) => ['ws', ws, 'agent', 'review', filter, days, page] as const,
    turn: (ws: string, id: string) => ['ws', ws, 'agent', 'turn', id] as const,
};

export function useAgentSettings(ws: string) {
    return useQuery({ queryKey: agentKeys.settings(ws), queryFn: ({ signal }) => api.get<AgentSettingsResponse>(`${wsPath(ws)}/agent/settings`, signal) });
}

export function useSaveAgentSettings(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (settings: AgentSettings) => api.put<{ settings: AgentSettings }>(`${wsPath(ws)}/agent/settings`, settings),
        onSuccess: () => void qc.invalidateQueries({ queryKey: agentKeys.settings(ws) }),
    });
}

export function useAgentReview(ws: string, filter: ReviewFilter, days: number, page: number) {
    return useQuery({
        queryKey: agentKeys.review(ws, filter, days, page),
        queryFn: ({ signal }) => api.get<ReviewResponse>(`${wsPath(ws)}/agent/review?filter=${filter}&days=${days}&page=${page}`, signal),
    });
}

export function useAgentTurn(ws: string, id: string | null) {
    return useQuery({
        queryKey: agentKeys.turn(ws, id ?? ''),
        enabled: id !== null,
        queryFn: ({ signal }) => api.get<TurnDetail>(`${wsPath(ws)}/agent/turns/${id ?? ''}`, signal),
    });
}

export function useReviewTurn(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: ({ id, status }: { id: string; status: 'ok' | 'wrong' | 'needs_fix' }) => api.put(`${wsPath(ws)}/agent/turns/${id}/review`, { review_status: status }),
        onSuccess: () => void qc.invalidateQueries({ queryKey: agentKeys.all(ws) }),
    });
}

export function useSandbox(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (messages: ChatMessage[]) => api.post<SandboxResult>(`${wsPath(ws)}/agent/sandbox`, { messages }),
        onSuccess: () => void qc.invalidateQueries({ queryKey: agentKeys.all(ws) }),
    });
}
