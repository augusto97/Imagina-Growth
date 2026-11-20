import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { wsPath } from '@/api/queries';
import { api } from '@/lib/api';

/* Tipos y consultas de Medición (M06). Rutas en hub/routes/api/measurement.php. */

export type Platform = 'meta' | 'google' | 'tiktok';
export type DestinationMode = 'server_only' | 'with_pixel' | 'full';
export type DeliveryStatus = 'delivered' | 'retrying' | 'failed' | 'skipped';

export interface Destination {
    id: string;
    platform: Platform;
    name: string | null;
    mode: DestinationMode;
    pixel_id: string | null;
    events_enabled: string[];
    test_event_code: string | null;
    status: 'active' | 'paused';
    last_error: string | null;
    last_delivered_at: string | null;
    credentials: Record<string, string | null>;
    endpoints: Record<string, boolean>;
    spend_import: boolean;
    /** Conexión con un clic que da los tokens (Conexiones), o null si los datos se pegaron a mano. */
    connection: { id: string; provider: string; status: string; account_name: string | null } | null;
    created_at: string | null;
}

export interface CatalogEvent {
    event: string;
    meta: string;
    google: string;
    tiktok: string;
    default: boolean;
}

export interface DestinationsResponse {
    destinations: Destination[];
    events: CatalogEvent[];
}

export interface DeliveryRow {
    at: string;
    destination_id: string | null;
    platform: Platform;
    target: string;
    conversion: string;
    source_event: string;
    event_id: string;
    status: DeliveryStatus;
    http_status: number;
    error: string | null;
    error_code: string | null;
    match_keys: string[];
    attempt: number;
    final: boolean;
    test: boolean;
}

export interface DeliveriesResponse {
    data: DeliveryRow[];
    page: number;
    per_page: number;
    has_more: boolean;
}

export interface QualityStats {
    total: number;
    delivered: number;
    failed: number;
    retrying: number;
    email_rate: number | null;
    phone_rate: number | null;
    click_id_rate: number | null;
    browser_id_rate: number | null;
    external_id_rate: number | null;
    last_errors: { at: string; conversion: string; message: string; code: string }[];
}

export interface QualityResponse {
    days: number;
    destinations: Record<string, QualityStats>;
}

export interface SpendRow {
    platform: Platform;
    currency: string;
    spend: string;
    impressions?: number;
    clicks?: number;
    campaign_id?: string;
    campaign_name?: string | null;
}

export interface SpendResponse {
    from: string;
    to: string;
    by_platform: SpendRow[];
    by_campaign: SpendRow[];
    daily: { date: string; platform: Platform; currency: string; spend: string }[];
}

export interface DestinationInput {
    platform?: Platform;
    name?: string | null;
    mode?: DestinationMode;
    events_enabled?: string[] | null;
    test_event_code?: string | null;
    status?: 'active' | 'paused';
    credentials?: Record<string, string>;
}

export interface TestResponse {
    ok: boolean;
    results: { target: string | null; ok: boolean; message: string }[];
}

const m = (ws: string) => `${wsPath(ws)}/measurement`;

export const measurementKeys = {
    destinations: (ws: string) => ['ws', ws, 'measurement', 'destinations'] as const,
    quality: (ws: string, days: number) => ['ws', ws, 'measurement', 'quality', days] as const,
    deliveries: (ws: string, filters: DeliveryFilters) => ['ws', ws, 'measurement', 'deliveries', filters] as const,
    spend: (ws: string, days: number) => ['ws', ws, 'measurement', 'spend', days] as const,
};

export interface DeliveryFilters {
    platform: Platform | '';
    status: DeliveryStatus | '';
    destination: string;
    days: number;
    page: number;
}

function query(params: Record<string, string | number>): string {
    const search = new URLSearchParams();
    for (const [key, value] of Object.entries(params)) {
        if (value !== '' && value !== 0) {
            search.set(key, String(value));
        }
    }
    const s = search.toString();
    return s === '' ? '' : `?${s}`;
}

export function useDestinations(ws: string) {
    return useQuery({ queryKey: measurementKeys.destinations(ws), queryFn: ({ signal }) => api.get<DestinationsResponse>(`${m(ws)}/destinations`, signal) });
}

export function useQuality(ws: string, days: number) {
    return useQuery({ queryKey: measurementKeys.quality(ws, days), queryFn: ({ signal }) => api.get<QualityResponse>(`${m(ws)}/quality${query({ days })}`, signal) });
}

export function useDeliveries(ws: string, filters: DeliveryFilters) {
    return useQuery({
        queryKey: measurementKeys.deliveries(ws, filters),
        queryFn: ({ signal }) => api.get<DeliveriesResponse>(`${m(ws)}/deliveries${query({ ...filters })}`, signal),
        placeholderData: keepPreviousData,
    });
}

export function useSpend(ws: string, days: number) {
    return useQuery({
        queryKey: measurementKeys.spend(ws, days),
        queryFn: ({ signal }) => {
            const to = new Date(Date.now() - 86_400_000);
            const from = new Date(to.getTime() - (days - 1) * 86_400_000);
            const iso = (d: Date) => d.toISOString().slice(0, 10);
            return api.get<SpendResponse>(`${m(ws)}/spend${query({ from: iso(from), to: iso(to) })}`, signal);
        },
    });
}

export function useSaveDestination(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: ({ id, input }: { id: string | null; input: DestinationInput }) =>
            id === null ? api.post<Destination>(`${m(ws)}/destinations`, input) : api.patch<Destination>(`${m(ws)}/destinations/${encodeURIComponent(id)}`, input),
        onSuccess: () => qc.invalidateQueries({ queryKey: measurementKeys.destinations(ws) }),
    });
}

export function useDeleteDestination(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (id: string) => api.delete(`${m(ws)}/destinations/${encodeURIComponent(id)}`),
        onSuccess: () => qc.invalidateQueries({ queryKey: measurementKeys.destinations(ws) }),
    });
}

export function useTestDestination(ws: string) {
    return useMutation({ mutationFn: (id: string) => api.post<TestResponse>(`${m(ws)}/destinations/${encodeURIComponent(id)}/test`, {}) });
}

/** Campos de credenciales por plataforma; `secret` = no vuelve del servidor. */
export const CREDENTIAL_FIELDS: Record<Platform, { key: string; secret: boolean; required?: boolean; group: 'conversions' | 'google_ads' | 'spend' }[]> = {
    meta: [
        { key: 'dataset_id', secret: false, required: true, group: 'conversions' },
        { key: 'access_token', secret: true, required: true, group: 'conversions' },
        { key: 'ad_account_id', secret: false, group: 'spend' },
        { key: 'ads_access_token', secret: true, group: 'spend' },
    ],
    google: [
        { key: 'measurement_id', secret: false, group: 'conversions' },
        { key: 'api_secret', secret: true, group: 'conversions' },
        { key: 'ads_customer_id', secret: false, group: 'google_ads' },
        { key: 'ads_login_customer_id', secret: false, group: 'google_ads' },
        { key: 'conversion_action_purchase', secret: false, group: 'google_ads' },
        { key: 'conversion_action_lead', secret: false, group: 'google_ads' },
        { key: 'conversion_action_lead_won', secret: false, group: 'google_ads' },
        { key: 'oauth_client_id', secret: false, group: 'google_ads' },
        { key: 'oauth_client_secret', secret: true, group: 'google_ads' },
        { key: 'oauth_refresh_token', secret: true, group: 'google_ads' },
        { key: 'ads_developer_token', secret: true, group: 'spend' },
    ],
    tiktok: [
        { key: 'pixel_code', secret: false, required: true, group: 'conversions' },
        { key: 'access_token', secret: true, required: true, group: 'conversions' },
        { key: 'advertiser_id', secret: false, group: 'spend' },
        { key: 'ads_access_token', secret: true, group: 'spend' },
    ],
};
