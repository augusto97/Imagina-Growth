import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { wsPath } from '@/api/queries';
import { isApiError } from '@/lib/api';
import { api } from '@/lib/api';
import type { ChannelAccount } from '@/features/channels/api';
import type { Destination } from '@/features/measurement/api';

/* Conexiones con un clic (docs/auditoria-2026-11.md §6). Rutas en hub/routes/api/connections.php. */

export type ProviderKey = 'meta' | 'meta_whatsapp' | 'google' | 'tiktok';
export type RedirectProvider = Exclude<ProviderKey, 'meta_whatsapp'>;
export type ConnectionStatus = 'active' | 'expired' | 'error' | 'revoked';
export type ReturnTo = 'conexiones' | 'canales' | 'medicion' | 'agenda';

export interface Connection {
    id: string;
    provider: ProviderKey;
    kind: 'workspace' | 'whatsapp' | 'calendar';
    status: ConnectionStatus;
    account_name: string | null;
    scopes: string[];
    uses: string[];
    whatsapp: { waba_id: string | null; coexistence: boolean } | null;
    expires_at: string | null;
    connected_at: string | null;
    last_error: string | null;
    used_by: {
        destinations: { id: string; platform: string; name: string | null; status: string }[];
        channels: {
            id: string;
            channel: string;
            name: string;
            identity: string | null;
            external_id: string | null;
            status: string;
            last_error: string | null;
            /** Coexistencia: avance de la copia del historial de la app de WhatsApp Business. */
            history: { progress: number; messages: number; declined: boolean } | null;
        }[];
    };
}

export interface ProviderInfo {
    key: ProviderKey;
    configured: boolean;
    missing: string[];
    redirect_uri: string | null;
    connections: Connection[];
}

export interface ConnectionsResponse {
    providers: ProviderInfo[];
    calendar_connections: number;
}

export interface MetaAssets {
    ad_accounts: { id: string; name: string; currency: string | null }[];
    datasets: { id: string; name: string; ad_account_id: string }[];
    pages: { id: string; name: string; instagram: { id: string; username: string | null } | null }[];
}

export interface GoogleAssets {
    ads_customers: { id: string; name: string; manager: boolean; currency: string | null; login_customer_id: string | null }[];
    properties: { id: string; name: string; account: string }[];
    scopes: { ads: boolean; data_manager: boolean; analytics: boolean };
}

export interface TikTokAssets {
    advertisers: { id: string; name: string }[];
}

export interface Option {
    id: string;
    name: string;
}

export interface WhatsAppStart {
    state: string;
    app_id: string;
    config_id: string;
    graph_version: string;
    version: string;
}

export interface WhatsAppPhone {
    id: string;
    display_phone_number: string | null;
    verified_name: string | null;
    is_on_biz_app: boolean;
}

export interface WhatsAppComplete {
    connection: Connection;
    data: ChannelAccount | null;
    phones: WhatsAppPhone[];
}

export type MeasurementChoice = Partial<
    Record<
        | 'dataset_id'
        | 'ad_account_id'
        | 'ads_customer_id'
        | 'ads_login_customer_id'
        | 'conversion_action_purchase'
        | 'conversion_action_lead'
        | 'conversion_action_lead_won'
        | 'ga4_stream'
        | 'advertiser_id'
        | 'pixel_code',
        string | null
    >
>;

/** Motivo corto que manda el hub con cada error de conexión (`reason`). */
export function connectionReason(error: unknown): string | null {
    if (!isApiError(error) || typeof error.body !== 'object' || error.body === null) return null;
    const reason = (error.body as { reason?: unknown }).reason;
    return typeof reason === 'string' ? reason : null;
}

const base = (ws: string) => `${wsPath(ws)}/connections`;
export const connectionKeys = {
    all: (ws: string) => ['ws', ws, 'connections'] as const,
    assets: (ws: string, id: string, params: Record<string, string>) => ['ws', ws, 'connections', id, 'assets', params] as const,
};

export function useConnections(ws: string) {
    return useQuery({ queryKey: connectionKeys.all(ws), queryFn: ({ signal }) => api.get<ConnectionsResponse>(base(ws), signal) });
}

/** Lo que cambia al conectar: las conexiones, los canales y los destinos de Medición. */
function useInvalidate(ws: string) {
    const qc = useQueryClient();
    return () =>
        Promise.all([
            qc.invalidateQueries({ queryKey: connectionKeys.all(ws) }),
            qc.invalidateQueries({ queryKey: ['ws', ws, 'channels'] }),
            qc.invalidateQueries({ queryKey: ['ws', ws, 'measurement'] }),
        ]);
}

/** Pide la URL de consentimiento y navega a ella (la vuelta trae ?conexion=…&estado=…). */
export function useStartConnection(ws: string) {
    return useMutation({
        mutationFn: ({ provider, returnTo }: { provider: RedirectProvider; returnTo?: ReturnTo }) =>
            api.post<{ url: string }>(`${base(ws)}/${provider}/start`, { return: returnTo ?? 'conexiones' }),
        onSuccess: ({ url }) => window.location.assign(url),
    });
}

/** Estado que avisa el hub (ConnectionAlerts): vence en 7 días o menos, vence mañana, venció o Meta desconectó la cuenta. */
export type AlertState = 'expires_7d' | 'expires_1d' | 'expired' | 'disconnected';

export interface ConnectionAlert {
    kind: 'connection' | 'channel';
    id: string;
    provider: ProviderKey;
    /** De la conexión: del espacio de trabajo, de WhatsApp o del calendario de una persona; de una cuenta: su canal. */
    subject: 'workspace' | 'whatsapp' | 'calendar' | 'messenger' | 'instagram';
    state: AlertState;
    name: string | null;
    expires_at: string | null;
    message: string | null;
}

/** Lo que vence o se cayó, para el aviso con «Reconectar» del Inicio y de Conexiones. */
export function useConnectionAlerts(ws: string, enabled = true) {
    return useQuery({
        queryKey: [...connectionKeys.all(ws), 'alerts'] as const,
        queryFn: ({ signal }) => api.get<{ data: ConnectionAlert[] }>(`${base(ws)}/alerts`, signal),
        enabled,
        staleTime: 60_000,
    });
}

export function useConnectionAssets<T>(ws: string, id: string | null, params: Record<string, string> = {}, enabled = true) {
    const search = new URLSearchParams(params).toString();
    return useQuery({
        queryKey: connectionKeys.assets(ws, id ?? '-', params),
        queryFn: ({ signal }) => api.get<T>(`${base(ws)}/${id ?? ''}/assets${search ? `?${search}` : ''}`, signal),
        enabled: id !== null && enabled,
        retry: false,
        staleTime: 60_000,
    });
}

export function useUseForMeasurement(ws: string) {
    const invalidate = useInvalidate(ws);
    return useMutation({
        mutationFn: ({ id, ...body }: MeasurementChoice & { id: string }) => api.post<{ destination: Destination }>(`${base(ws)}/${id}/measurement`, body),
        onSuccess: invalidate,
    });
}

export function useUseForSocial(ws: string) {
    const invalidate = useInvalidate(ws);
    return useMutation({
        mutationFn: ({ id, channel, page_id }: { id: string; channel: 'messenger' | 'instagram'; page_id: string }) =>
            api.post<{ data: ChannelAccount }>(`${base(ws)}/${id}/social`, { channel, page_id }),
        onSuccess: invalidate,
    });
}

export function useCheckConnection(ws: string) {
    const invalidate = useInvalidate(ws);
    return useMutation({ mutationFn: (id: string) => api.post<{ data: Connection }>(`${base(ws)}/${id}/check`), onSettled: invalidate });
}

export function useDisconnectConnection(ws: string) {
    const invalidate = useInvalidate(ws);
    return useMutation({ mutationFn: (id: string) => api.delete<{ ok: true }>(`${base(ws)}/${id}`), onSuccess: invalidate });
}

export function useWhatsAppSignupApi(ws: string) {
    const invalidate = useInvalidate(ws);
    return {
        start: useMutation({ mutationFn: () => api.post<WhatsAppStart>(`${base(ws)}/meta_whatsapp/start`) }),
        complete: useMutation({
            mutationFn: (body: { state: string; code: string; event: string; waba_id: string; phone_number_id?: string; business_id?: string }) =>
                api.post<WhatsAppComplete>(`${base(ws)}/meta_whatsapp/complete`, body),
            onSuccess: invalidate,
        }),
        abandon: useMutation({
            mutationFn: (body: { state: string; event: 'CANCEL' | 'ERROR'; current_step?: string; error_code?: string; session_id?: string }) =>
                api.post<{ ok: true }>(`${base(ws)}/meta_whatsapp/abandon`, body),
        }),
        chooseNumber: useMutation({
            mutationFn: ({ id, phone_number_id }: { id: string; phone_number_id: string }) =>
                api.post<{ data: ChannelAccount }>(`${base(ws)}/${id}/whatsapp-number`, { phone_number_id }),
            onSuccess: invalidate,
        }),
    };
}
