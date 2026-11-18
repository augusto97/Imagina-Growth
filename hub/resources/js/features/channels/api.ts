import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { wsPath } from '@/api/queries';
import { api } from '@/lib/api';
import { postMultipart } from '@/features/contacts/api';

/* Canales de mensajería (M07). Rutas en hub/routes/api/messaging.php. */

export type ChannelKind = 'email' | 'whatsapp' | 'sms';
export type Provider = 'ses' | 'smtp' | 'meta_cloud' | 'zender' | 'twilio';
export type AccountStatus = 'pending' | 'active' | 'paused' | 'error' | 'disconnected';

export interface ChannelAccount {
    id: string;
    channel: ChannelKind;
    provider: Provider;
    display_name: string;
    identity: string | null;
    status: AccountStatus;
    is_default: boolean;
    quality_rating: 'GREEN' | 'YELLOW' | 'RED' | string | null;
    messaging_limit_tier: string | null;
    last_error: string | null;
    risk_accepted_at: string | null;
    from_email: string | null;
    from_name: string | null;
    reply_to: string | null;
    domain: string | null;
    warmup: { day: number; limit: number | null; sent_today: number } | null;
    caps: { hourly: number; daily: number } | null;
    capabilities: { marketing: boolean; templates: boolean; free_text: boolean; media: boolean; buttons: boolean; rate_per_second: number };
    webhook_url: string | null;
    created_at: string | null;
}

export interface ChannelsResponse {
    data: ChannelAccount[];
    meta: {
        embedded_signup: { app_id: string | null; config_id: string | null; graph_version: string };
        zender_caps: { hourly: number; daily: number };
    };
}

export interface DnsRecord {
    type: 'CNAME' | 'TXT';
    name: string;
    value: string;
    purpose: 'dkim' | 'spf' | 'dmarc';
    required: boolean;
    ok: boolean | null;
}

export interface DnsResponse {
    data: ChannelAccount;
    records: DnsRecord[];
    ses_status: string | null;
    verified: boolean;
}

export interface WaTemplateComponent {
    type: 'HEADER' | 'BODY' | 'FOOTER' | 'BUTTONS';
    format?: 'TEXT' | 'IMAGE' | 'VIDEO' | 'DOCUMENT';
    text?: string;
    buttons?: { type: 'URL' | 'QUICK_REPLY' | 'PHONE_NUMBER' | 'COPY_CODE'; text?: string; url?: string; phone_number?: string; example?: string[] | string }[];
    example?: Record<string, unknown>;
}

export interface WaTemplate {
    id: string;
    account_id: string;
    name: string;
    language: string;
    category: 'MARKETING' | 'UTILITY' | 'AUTHENTICATION';
    status: string;
    quality: string | null;
    components: WaTemplateComponent[];
    rejected_reason: string | null;
    synced_at: string | null;
}

export interface WaTemplateForm {
    account?: string;
    name?: string;
    language?: string;
    category?: WaTemplate['category'];
    header: { format: 'NONE' | 'TEXT' | 'IMAGE' | 'VIDEO' | 'DOCUMENT'; text: string; example: string };
    body: { text: string; examples: string[] };
    footer: { text: string };
    buttons: { type: 'URL' | 'QUICK_REPLY' | 'PHONE_NUMBER' | 'COPY_CODE'; text: string; url: string; tracked: boolean; phone: string; example: string }[];
    acknowledge_category_warning?: boolean;
}

export interface Suppression {
    id: number;
    channel: ChannelKind;
    reason: 'bounce' | 'complaint' | 'unsubscribe' | 'rne' | 'repep' | 'manual' | 'wa_block';
    created_at: string | null;
    contact: { id: string; name: string; value: string } | null;
}

export interface SuppressionsResponse {
    data: Suppression[];
    meta: { total: number; page: number; last_page: number };
    imports: { country: 'CO' | 'MX'; rows: number; added: number; imported_at: string }[];
}

export interface ContactMessage {
    id: string;
    direction: 'out' | 'in';
    channel: ChannelKind;
    kind: string;
    source_type: string;
    source_name: string | null;
    status: string;
    preview: string | null;
    button_payload: string | null;
    failed_reason: string | null;
    cost_estimated: string | null;
    created_at: string | null;
    sent_at: string | null;
    delivered_at: string | null;
    read_at: string | null;
    opened_at: string | null;
    clicked_at: string | null;
    replied_at: string | null;
}

const channelsKey = (ws: string) => ['ws', ws, 'channels'] as const;
const templatesKey = (ws: string, account: string) => ['ws', ws, 'wa-templates', account] as const;

export function useChannels(ws: string) {
    return useQuery({ queryKey: channelsKey(ws), queryFn: ({ signal }) => api.get<ChannelsResponse>(`${wsPath(ws)}/channels`, signal) });
}

function useChannelMutation<TBody, TResult>(ws: string, fn: (body: TBody) => Promise<TResult>) {
    const qc = useQueryClient();
    return useMutation({ mutationFn: fn, onSuccess: () => qc.invalidateQueries({ queryKey: channelsKey(ws) }) });
}

export interface EmailForm {
    provider: 'ses' | 'smtp';
    from_email: string;
    from_name: string;
    reply_to?: string;
    host?: string;
    port?: number;
    username?: string;
    password?: string;
    encryption?: 'tls' | 'ssl' | 'none';
}

export const useCreateEmail = (ws: string) =>
    useChannelMutation(ws, (body: EmailForm) => api.post<{ data: ChannelAccount; dns: DnsRecord[] }>(`${wsPath(ws)}/channels/email`, body));

export const useCheckDns = (ws: string) => useChannelMutation(ws, (id: string) => api.get<DnsResponse>(`${wsPath(ws)}/channels/${id}/dns`));

export const useConnectWhatsApp = (ws: string) =>
    useChannelMutation(ws, (body: { waba_id: string; phone_number_id: string; access_token: string; display_name?: string }) =>
        api.post<{ data: ChannelAccount }>(`${wsPath(ws)}/channels/whatsapp/manual`, body),
    );

export const useEmbeddedSignup = (ws: string) =>
    useChannelMutation(ws, (body: { code: string; waba_id: string; phone_number_id: string }) =>
        api.post<{ data: ChannelAccount }>(`${wsPath(ws)}/channels/whatsapp/embedded-signup`, body),
    );

export const useConnectZender = (ws: string) =>
    useChannelMutation(ws, (body: { base_url: string; api_secret: string; account_id: string; phone: string; display_name?: string; accept_risk: boolean }) =>
        api.post<{ data: ChannelAccount; webhook_secret: string }>(`${wsPath(ws)}/channels/whatsapp/zender`, body),
    );

export const useConnectTwilio = (ws: string) =>
    useChannelMutation(ws, (body: { account_sid: string; auth_token: string; from?: string; messaging_service_sid?: string; display_name?: string }) =>
        api.post<{ data: ChannelAccount }>(`${wsPath(ws)}/channels/sms/twilio`, body),
    );

export const useUpdateChannel = (ws: string) =>
    useChannelMutation(ws, ({ id, ...body }: { id: string; display_name?: string; is_default?: boolean; status?: 'active' | 'paused'; from_name?: string; reply_to?: string | null }) =>
        api.patch<{ data: ChannelAccount }>(`${wsPath(ws)}/channels/${id}`, body),
    );

export const useDisconnectChannel = (ws: string) => useChannelMutation(ws, (id: string) => api.delete<{ ok: true }>(`${wsPath(ws)}/channels/${id}`));

export const useSyncChannel = (ws: string) =>
    useChannelMutation(ws, (id: string) => api.post<{ data: ChannelAccount; templates: number }>(`${wsPath(ws)}/channels/${id}/sync`));

export function useTestSend(ws: string) {
    return useMutation({
        mutationFn: ({ id, to }: { id: string; to: string }) =>
            api.post<{ status: 'queued' | 'deferred' | 'denied' | 'duplicate'; message_id: string | null; reason: string | null }>(`${wsPath(ws)}/channels/${id}/test`, { to }),
    });
}

export function useWaTemplates(ws: string, account: string | null) {
    return useQuery({
        queryKey: templatesKey(ws, account ?? 'all'),
        queryFn: ({ signal }) => api.get<{ data: WaTemplate[] }>(`${wsPath(ws)}/wa-templates${account ? `?account=${account}` : ''}`, signal),
        enabled: account !== null,
    });
}

export function useSaveWaTemplate(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: ({ id, form }: { id: string | null; form: WaTemplateForm }) =>
            id === null ? api.post<{ data: WaTemplate }>(`${wsPath(ws)}/wa-templates`, form) : api.put<{ data: WaTemplate }>(`${wsPath(ws)}/wa-templates/${id}`, form),
        onSuccess: () => qc.invalidateQueries({ queryKey: ['ws', ws, 'wa-templates'] }),
    });
}

export function useDeleteWaTemplate(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (id: string) => api.delete<{ ok: true }>(`${wsPath(ws)}/wa-templates/${id}`),
        onSuccess: () => qc.invalidateQueries({ queryKey: ['ws', ws, 'wa-templates'] }),
    });
}

export function useSuppressions(ws: string, params: { q: string; channel: string; reason: string; page: number }) {
    const search = new URLSearchParams(Object.entries(params).filter(([, v]) => v !== '' && v !== 1).map(([k, v]) => [k, String(v)]));
    return useQuery({
        queryKey: ['ws', ws, 'suppressions', params],
        queryFn: ({ signal }) => api.get<SuppressionsResponse>(`${wsPath(ws)}/suppressions${search.size ? `?${search}` : ''}`, signal),
        placeholderData: keepPreviousData,
    });
}

export function useSuppressionMutations(ws: string) {
    const qc = useQueryClient();
    const done = () => qc.invalidateQueries({ queryKey: ['ws', ws, 'suppressions'] });
    return {
        add: useMutation({ mutationFn: (body: { channel: ChannelKind; value: string }) => api.post<{ added: boolean }>(`${wsPath(ws)}/suppressions`, body), onSuccess: done }),
        remove: useMutation({ mutationFn: (id: number) => api.delete<{ ok: true }>(`${wsPath(ws)}/suppressions/${id}`), onSuccess: done }),
        registry: useMutation({
            mutationFn: ({ country, file }: { country: 'CO' | 'MX'; file: File }) => {
                const form = new FormData();
                form.append('country', country);
                form.append('file', file);
                return postMultipart<{ rows: number; added: number }>(`${wsPath(ws)}/suppressions/registry`, form);
            },
            onSuccess: done,
        }),
    };
}

export function useContactMessages(ws: string, contactId: string, page: number) {
    return useQuery({
        queryKey: ['ws', ws, 'contact-messages', contactId, page],
        queryFn: ({ signal }) => api.get<{ data: ContactMessage[]; meta: { total: number; page: number; last_page: number } }>(`${wsPath(ws)}/contacts/${contactId}/messages?page=${page}`, signal),
        placeholderData: keepPreviousData,
    });
}
