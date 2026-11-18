import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { wsPath } from '@/api/queries';
import { api } from '@/lib/api';

/* API e integraciones (M19): docs/api-publica.md. */

export interface EcosystemPreset {
    key: string;
    name: string;
    scopes: string[];
    events: string[];
    connected: boolean;
}

export interface IntegrationsOverview {
    plan: { api: boolean; mcp: boolean };
    rate_limit_per_minute: number;
    requests_this_month: number;
    base_url: string;
    mcp_url: string;
    docs_url: string;
    scopes: string[];
    default_scopes: string[];
    events: Record<string, string[]>;
    ecosystem: EcosystemPreset[];
}

export interface ApiClientRow {
    id: string;
    name: string;
    kind: 'token' | 'mcp';
    preset: string | null;
    scopes: string[];
    token_prefix: string | null;
    last_used_at: string | null;
    revoked_at: string | null;
    created_at: string | null;
}

export interface WebhookRow {
    id: string;
    name: string | null;
    url: string;
    events: string[];
    include_pii: boolean;
    preset: string | null;
    status: 'active' | 'failing' | 'paused';
    consecutive_failures: number;
    last_success_at: string | null;
    created_at: string | null;
}

export interface DeliveryRow {
    id: string;
    event: string;
    event_id: string;
    attempt: number;
    kind: 'event' | 'retry' | 'replay' | 'ping';
    state: 'ok' | 'failed' | 'pending' | 'sending';
    status_code: number | null;
    duration_ms: number | null;
    error: string | null;
    next_attempt_at: string | null;
    created_at: string;
}

export interface McpDraft {
    id: string;
    status: 'draft' | 'applied' | 'discarded';
    name: string | null;
    description: string | null;
    definition: unknown;
    client_id: string | null;
    applied_id: string | null;
    created_at: string | null;
}

export interface WebhookInput {
    name?: string | null;
    url?: string;
    events?: string[];
    include_pii?: boolean;
    status?: 'active' | 'paused';
}

const base = (ws: string) => ['ws', ws, 'integrations'] as const;

export function useIntegrations(ws: string) {
    return useQuery({ queryKey: [...base(ws), 'overview'], queryFn: ({ signal }) => api.get<IntegrationsOverview>(`${wsPath(ws)}/integrations`, signal) });
}

export function useApiTokens(ws: string) {
    return useQuery({ queryKey: [...base(ws), 'tokens'], queryFn: ({ signal }) => api.get<{ data: ApiClientRow[] }>(`${wsPath(ws)}/integrations/tokens`, signal).then((r) => r.data) });
}

export function useCreateToken(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (input: { name: string; kind: 'token' | 'mcp'; scopes: string[] }) => api.post<{ data: ApiClientRow; token: string }>(`${wsPath(ws)}/integrations/tokens`, input),
        onSuccess: () => void qc.invalidateQueries({ queryKey: base(ws) }),
    });
}

export function useRevokeToken(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (id: string) => api.delete<{ data: ApiClientRow }>(`${wsPath(ws)}/integrations/tokens/${id}`),
        onSuccess: () => void qc.invalidateQueries({ queryKey: base(ws) }),
    });
}

export function useConnectPreset(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (input: { preset: string; webhook_url: string | null }) =>
            api.post<{ data: ApiClientRow; token: string; webhook: WebhookRow | null; secret: string | null }>(`${wsPath(ws)}/integrations/ecosystem/${input.preset}`, { webhook_url: input.webhook_url }),
        onSuccess: () => void qc.invalidateQueries({ queryKey: base(ws) }),
    });
}

export function useWebhooks(ws: string) {
    return useQuery({ queryKey: [...base(ws), 'webhooks'], queryFn: ({ signal }) => api.get<{ data: WebhookRow[] }>(`${wsPath(ws)}/webhooks`, signal).then((r) => r.data) });
}

export function useSaveWebhook(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: ({ id, ...input }: WebhookInput & { id?: string }) =>
            id ? api.patch<{ data: WebhookRow; secret?: string }>(`${wsPath(ws)}/webhooks/${id}`, input) : api.post<{ data: WebhookRow; secret: string }>(`${wsPath(ws)}/webhooks`, input),
        onSuccess: () => void qc.invalidateQueries({ queryKey: base(ws) }),
    });
}

export function useDeleteWebhook(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (id: string) => api.delete<{ ok: boolean }>(`${wsPath(ws)}/webhooks/${id}`),
        onSuccess: () => void qc.invalidateQueries({ queryKey: base(ws) }),
    });
}

export function useRotateSecret(ws: string) {
    return useMutation({ mutationFn: (id: string) => api.post<{ secret: string }>(`${wsPath(ws)}/webhooks/${id}/rotate-secret`) });
}

export function useWebhookDeliveries(ws: string, id: string | null, failedOnly: boolean) {
    return useQuery({
        queryKey: [...base(ws), 'deliveries', id, failedOnly],
        enabled: id !== null,
        queryFn: ({ signal }) => api.get<{ data: DeliveryRow[]; next_before: string | null }>(`${wsPath(ws)}/webhooks/${id}/deliveries${failedOnly ? '?status=failed' : ''}`, signal),
    });
}

export function usePingWebhook(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (id: string) => api.post<{ data: DeliveryRow }>(`${wsPath(ws)}/webhooks/${id}/ping`),
        onSuccess: () => void qc.invalidateQueries({ queryKey: base(ws) }),
    });
}

export function useReplayDelivery(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (id: string) => api.post<{ data: DeliveryRow }>(`${wsPath(ws)}/webhook-deliveries/${id}/replay`),
        onSuccess: () => void qc.invalidateQueries({ queryKey: base(ws) }),
    });
}

export function useMcpDrafts(ws: string, enabled: boolean) {
    return useQuery({
        queryKey: [...base(ws), 'mcp-drafts'],
        enabled,
        queryFn: ({ signal }) => api.get<{ data: McpDraft[] }>(`${wsPath(ws)}/integrations/mcp-drafts`, signal).then((r) => r.data),
    });
}

export function useReviewMcpDraft(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: ({ id, action }: { id: string; action: 'approve' | 'discard' }) => api.post<{ data: McpDraft }>(`${wsPath(ws)}/integrations/mcp-drafts/${id}/${action}`),
        onSuccess: () => {
            void qc.invalidateQueries({ queryKey: base(ws) });
            void qc.invalidateQueries({ queryKey: ['ws', ws, 'segments'] });
        },
    });
}

/** Alcances sugeridos para un token MCP de solo lectura más borradores. */
export const MCP_SCOPES = ['analytics:read', 'segments:read', 'contacts:read', 'campaigns:write'];

/** Comando para conectar el servidor en Claude Code. */
export function claudeCodeCommand(mcpUrl: string): string {
    return `claude mcp add --transport http imagina-growth ${mcpUrl} --header "Authorization: Bearer igr_…"`;
}
