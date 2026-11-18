import { keepPreviousData, useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { wsPath } from '@/api/queries';
import type { Paginated } from '@/api/types';
import { ApiError, api, ensureCsrf, toApiError } from '@/lib/api';

/* Perfil 360, lista e importación de contactos (modulos.md M03). Formas de hub/app/Domain/Contacts. */

export type ContactSort = 'last_seen' | 'last_order' | 'revenue' | 'orders' | 'created';
export type ConsentChannel = 'email' | 'whatsapp' | 'sms';

export interface ContactFilters {
    q: string;
    stage: string;
    city: string;
    rfm_segment: string;
    consent: '' | ConsentChannel;
    buyers: '' | '1' | '0';
    tag: string;
    sort: ContactSort;
    page: number;
}

export interface ContactListRow {
    id: string;
    name: string;
    email: string | null;
    phone: string | null;
    city: string | null;
    lifecycle_stage: string | null;
    orders_count: number;
    revenue_total_ws: string;
    last_order_at: string | null;
    last_seen_at: string | null;
    rfm_segment: string | null;
    hdyhau: string | null;
    tags: string[];
}

export interface ConsentState {
    status: 'granted' | 'revoked' | (string & {});
    source: string | null;
    version: string | null;
    at: string | null;
}

export interface Touch {
    channel?: string;
    utm_source?: string;
    utm_medium?: string;
    utm_campaign?: string;
    at?: string;
    via?: string;
}

export interface MonthlyPoint {
    month: string;
    orders: number;
    revenue: string;
}

export interface ContactProfile {
    id: string;
    name: string;
    first_name: string | null;
    last_name: string | null;
    email: string | null;
    phone: string | null;
    pii_visible: boolean;
    city: string | null;
    region_code: string | null;
    country: string | null;
    birthday: string | null;
    renewal_at: string | null;
    lifecycle_stage: string | null;
    tags: string[];
    custom_attributes: Record<string, unknown>;
    hdyhau: string | null;
    first_source: Touch | null;
    first_order_source: Touch | null;
    rfm: { segment: string; r: number | null; f: number | null; m: number | null; computed_at: string | null } | null;
    clv: { historic: string; predicted_12m: string | null; predicted_reason: string | null };
    churn: { band: string | null; reason?: string; risk?: number | null };
    expected_next_order_at: string | null;
    predictions?: {
        p_alive: number | null;
        expected_orders_90d: number | null;
        next_order_reason: string | null;
        method: { churn: 'model' | 'heuristic' };
        reliability: { clv: 'high' | 'medium' | 'low' | null; churn: 'high' | 'medium' | 'low' | null; edno: 'high' | 'medium' | 'low' | null };
        engagement_score: number | null;
        preferred_hour: number | null;
        preferred_channel: string | null;
        price_band: string | null;
    };
    consents: Record<string, ConsentState>;
    stats: {
        orders_count: number;
        revenue_total_ws: string;
        aov_ws: string;
        first_order_at: string | null;
        last_order_at: string | null;
        avg_days_between_orders: number | string | null;
        refunds_count: number;
        coupon_orders_ratio: number | string | null;
    };
    monthly: MonthlyPoint[];
    merged_count: number;
    /** Si se fusionó en otro contacto, el ID del que quedó. */
    merged_into: string | null;
    first_seen_at: string | null;
    last_seen_at: string | null;
    currency: string;
}

export interface TimelineEvent {
    id: string;
    event: string;
    occurred_at: string;
    source: string | null;
    channel: string | null;
    device: string | null;
    path: string | null;
    summary: Record<string, string | number>;
}

export interface TimelinePage {
    data: TimelineEvent[];
    next_before: string | null;
}

export interface ContactOrder {
    id: string;
    number: string | null;
    status: string;
    is_paid: boolean;
    is_cod: boolean;
    created_at: string | null;
    paid_at: string | null;
    total: string;
    refunded: string | null;
    currency: string | null;
    payment_method: string | null;
    coupons: string[];
    city: string | null;
    channel_last: string | null;
    order_index: number | null;
    items: { name: string; sku: string | null; qty: string; line_total: string }[];
}

export interface AffinityRow {
    id?: string;
    name: string;
    purchases: number;
    revenue?: number;
    views?: number;
}

export interface ContactProducts {
    categories: AffinityRow[];
    brands: AffinityRow[];
    products: AffinityRow[];
    affinity: unknown;
}

export interface ContactIdentity {
    type: string;
    label: string;
    display: string;
    site: string | null;
    first_seen_at: string | null;
    last_seen_at: string | null;
}

export interface ContactMerge {
    id: string;
    loser_id: string;
    reason: string;
    created_at: string | null;
    reverted_at: string | null;
    can_revert: boolean;
}

export interface ContactConsentRow {
    channel: string;
    purpose: string;
    status: string;
    source: string | null;
    version: string | null;
    granted_at: string | null;
    revoked_at: string | null;
}

export interface ConsentEvidence {
    channel: string;
    action: string;
    source: string | null;
    version: string | null;
    occurred_at: string | null;
    evidence: { page_url?: string; event?: string; order_id?: string; form_snapshot?: unknown; note?: string };
}

export interface ContactPrivacy {
    consents: ContactConsentRow[];
    evidence: ConsentEvidence[];
    requests: { id: string; kind: 'access' | 'delete'; status: string; due_at: string | null; downloadable: boolean }[];
}

export interface ContactNote {
    id: string;
    body: string;
    author: string | null;
    mine: boolean;
    created_at: string | null;
}

export interface ContactUpdate {
    first_name?: string | null;
    last_name?: string | null;
    city?: string | null;
    region_code?: string | null;
    birthday?: string | null;
    renewal_at?: string | null;
    tags?: string[];
    custom_attributes?: Record<string, unknown>;
}

const contact = (ws: string, id: string) => `${wsPath(ws)}/contacts/${encodeURIComponent(id)}`;

export const contactKeys = {
    list: (ws: string, filters: ContactFilters) => ['ws', ws, 'contacts', 'list', filters] as const,
    all: (ws: string) => ['ws', ws, 'contacts'] as const,
    profile: (ws: string, id: string) => ['ws', ws, 'contact', id] as const,
    part: (ws: string, id: string, part: string, ...rest: unknown[]) => ['ws', ws, 'contact', id, part, ...rest] as const,
    imports: (ws: string) => ['ws', ws, 'contact-imports'] as const,
    import: (ws: string, id: string) => ['ws', ws, 'contact-imports', id] as const,
};

/** Convierte los filtros en la consulta de GET contacts (solo lo que tiene valor). */
export function filtersToParams(filters: ContactFilters): URLSearchParams {
    const params = new URLSearchParams({ page: String(filters.page) });
    for (const key of ['q', 'stage', 'city', 'rfm_segment', 'consent', 'buyers', 'tag'] as const) {
        const value = filters[key].trim();
        if (value !== '') {
            params.set(key, value);
        }
    }
    if (filters.sort !== 'last_seen') {
        params.set('sort', filters.sort);
    }
    return params;
}

export function useContactList(ws: string, filters: ContactFilters, enabled = true) {
    return useQuery({
        queryKey: contactKeys.list(ws, filters),
        queryFn: ({ signal }) => api.get<Paginated<ContactListRow>>(`${wsPath(ws)}/contacts?${filtersToParams(filters).toString()}`, signal),
        placeholderData: keepPreviousData,
        enabled,
    });
}

export function useContactProfile(ws: string, id: string) {
    return useQuery({
        queryKey: contactKeys.profile(ws, id),
        queryFn: ({ signal }) => api.get<ContactProfile>(contact(ws, id), signal),
    });
}

export function useTimeline(ws: string, id: string, groups: string[]) {
    return useInfiniteQuery({
        queryKey: contactKeys.part(ws, id, 'timeline', groups),
        initialPageParam: null as string | null,
        queryFn: ({ pageParam, signal }) => {
            const params = new URLSearchParams();
            for (const g of groups) {
                params.append('groups[]', g);
            }
            if (pageParam !== null) {
                params.set('before', pageParam);
            }
            return api.get<TimelinePage>(`${contact(ws, id)}/timeline?${params.toString()}`, signal);
        },
        getNextPageParam: (last) => last.next_before,
    });
}

export function useContactOrders(ws: string, id: string, page: number, enabled: boolean) {
    return useQuery({
        queryKey: contactKeys.part(ws, id, 'orders', page),
        queryFn: ({ signal }) => api.get<Paginated<ContactOrder>>(`${contact(ws, id)}/orders?page=${page}`, signal),
        placeholderData: keepPreviousData,
        enabled,
    });
}

export function useContactProducts(ws: string, id: string) {
    return useQuery({
        queryKey: contactKeys.part(ws, id, 'products'),
        queryFn: ({ signal }) => api.get<ContactProducts>(`${contact(ws, id)}/products`, signal),
    });
}

export function useContactIdentities(ws: string, id: string) {
    return useQuery({
        queryKey: contactKeys.part(ws, id, 'identities'),
        queryFn: ({ signal }) => api.get<{ identities: ContactIdentity[]; merges: ContactMerge[] }>(`${contact(ws, id)}/identities`, signal),
    });
}

export function useContactPrivacy(ws: string, id: string) {
    return useQuery({
        queryKey: contactKeys.part(ws, id, 'privacy'),
        queryFn: ({ signal }) => api.get<ContactPrivacy>(`${contact(ws, id)}/privacy`, signal),
    });
}

export function useContactNotes(ws: string, id: string) {
    return useQuery({
        queryKey: contactKeys.part(ws, id, 'notes'),
        queryFn: async ({ signal }) => (await api.get<{ data: ContactNote[] }>(`${contact(ws, id)}/notes`, signal)).data,
    });
}

export function useUpdateContact(ws: string, id: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (data: ContactUpdate) => api.patch<ContactProfile>(contact(ws, id), data),
        onSuccess: (profile) => {
            qc.setQueryData(contactKeys.profile(ws, id), profile);
            void qc.invalidateQueries({ queryKey: contactKeys.all(ws) });
        },
    });
}

export function useAddNote(ws: string, id: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (body: string) => (await api.post<{ data: ContactNote[] }>(`${contact(ws, id)}/notes`, { body })).data,
        onSuccess: (notes) => qc.setQueryData(contactKeys.part(ws, id, 'notes'), notes),
    });
}

export function useDeleteNote(ws: string, id: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (noteId: string) => api.delete<{ ok: boolean }>(`${wsPath(ws)}/notes/${encodeURIComponent(noteId)}`),
        onSuccess: () => void qc.invalidateQueries({ queryKey: contactKeys.part(ws, id, 'notes') }),
    });
}

/** Invalida todo lo del perfil (tras fusionar, revertir o cambiar consentimientos). */
function useInvalidateProfile(ws: string) {
    const qc = useQueryClient();
    return (id: string) => {
        void qc.invalidateQueries({ queryKey: ['ws', ws, 'contact', id] });
        void qc.invalidateQueries({ queryKey: contactKeys.all(ws) });
    };
}

export function useMergeContact(ws: string, id: string) {
    const invalidate = useInvalidateProfile(ws);
    return useMutation({
        mutationFn: (otherId: string) => api.post<ContactProfile & { merge_id: string }>(`${contact(ws, id)}/merge`, { other_id: otherId }),
        onSuccess: () => invalidate(id),
    });
}

export function useRevertMerge(ws: string, id: string) {
    const invalidate = useInvalidateProfile(ws);
    return useMutation({
        mutationFn: (mergeId: string) => api.post<{ ok: boolean; contact_id: string }>(`${wsPath(ws)}/merges/${encodeURIComponent(mergeId)}/revert`),
        onSuccess: () => invalidate(id),
    });
}

export function useRecordConsent(ws: string, id: string) {
    const qc = useQueryClient();
    const invalidate = useInvalidateProfile(ws);
    return useMutation({
        mutationFn: (data: { channel: string; action: 'granted' | 'revoked'; note: string }) => api.post<ContactPrivacy>(`${contact(ws, id)}/consents`, data),
        onSuccess: (privacy) => {
            qc.setQueryData(contactKeys.part(ws, id, 'privacy'), privacy);
            invalidate(id);
        },
    });
}

/* Importación CSV ------------------------------------------------------- */

export type ImportField = 'email' | 'phone' | 'first_name' | 'last_name' | 'city' | 'region_code' | 'country' | 'birthday' | 'renewal_at' | 'tags' | 'ignore' | `custom:${string}`;

export interface ImportPreview {
    headers: string[];
    rows: string[][];
    suggested: Record<string, ImportField>;
    fields: ImportField[];
}

export interface ImportConsent {
    channels: ConsentChannel[];
    source: string;
    date: string;
}

export interface ContactImport {
    id: string;
    status: 'queued' | 'running' | 'completed' | 'failed' | (string & {});
    filename: string;
    totals: { rows: number; created: number; updated: number; skipped: number; invalid: number; consents?: number };
    errors: { row: number; message: string }[];
    consent: ImportConsent | null;
    created_at: string | null;
    finished_at: string | null;
}

/** POST multipart con la sesión de Sanctum (lib/api solo envía JSON). */
export async function postMultipart<T>(path: string, form: FormData): Promise<T> {
    const send = async () => {
        await ensureCsrf();
        const xsrf = document.cookie.split('; ').find((row) => row.startsWith('XSRF-TOKEN='));
        const headers: Record<string, string> = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
        if (xsrf) {
            headers['X-XSRF-TOKEN'] = decodeURIComponent(xsrf.slice('XSRF-TOKEN='.length));
        }
        return fetch(path, { method: 'POST', body: form, headers, credentials: 'same-origin' });
    };
    let response: Response;
    try {
        response = await send();
        if (response.status === 419) {
            await ensureCsrf(true);
            response = await send();
        }
    } catch {
        throw new ApiError(0, 'network', '', {}, null);
    }
    const text = await response.text();
    let parsed: unknown;
    try {
        parsed = text === '' ? null : JSON.parse(text);
    } catch {
        parsed = text;
    }
    if (!response.ok) {
        throw toApiError(response.status, parsed);
    }
    return parsed as T;
}

export function usePreviewImport(ws: string) {
    return useMutation({
        mutationFn: (file: File) => {
            const form = new FormData();
            form.append('file', file);
            return postMultipart<ImportPreview>(`${wsPath(ws)}/contacts/imports/preview`, form);
        },
    });
}

export function useStartImport(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (data: { file: File; mapping: Record<string, ImportField>; consent: ImportConsent | null }) => {
            const form = new FormData();
            form.append('file', data.file);
            form.append('mapping', JSON.stringify(data.mapping));
            if (data.consent !== null) {
                form.append('consent', JSON.stringify(data.consent));
            }
            return postMultipart<ContactImport>(`${wsPath(ws)}/contacts/imports`, form);
        },
        onSuccess: () => void qc.invalidateQueries({ queryKey: contactKeys.imports(ws) }),
    });
}

export function useImports(ws: string, enabled = true) {
    return useQuery({
        queryKey: contactKeys.imports(ws),
        queryFn: async ({ signal }) => (await api.get<{ data: ContactImport[] }>(`${wsPath(ws)}/contacts/imports`, signal)).data,
        enabled,
    });
}

export function useImport(ws: string, id: string) {
    const qc = useQueryClient();
    return useQuery({
        queryKey: contactKeys.import(ws, id),
        queryFn: async ({ signal }) => {
            const data = await api.get<ContactImport>(`${wsPath(ws)}/contacts/imports/${encodeURIComponent(id)}`, signal);
            if (data.status === 'completed') {
                void qc.invalidateQueries({ queryKey: contactKeys.all(ws) });
            }
            return data;
        },
        refetchInterval: (query) => (query.state.data && ['completed', 'failed'].includes(query.state.data.status) ? false : 1500),
    });
}
