import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { wsPath } from '@/api/queries';
import { api } from '@/lib/api';

/* Comercio en el sitio (M16): recomendaciones, «avísame» y banners. Rutas en hub/routes/api/recommendations.php. */

export const RECO_KINDS = ['para_ti', 'bought_together', 'recently_viewed', 'best_sellers_category', 'trending', 'complete_cart'] as const;
export type RecoKind = (typeof RECO_KINDS)[number];
export type RecoNeeds = 'product' | 'category' | 'cart' | 'contact' | null;

export interface RecoProduct {
    id: string;
    name: string;
    price: string;
    regular_price: string | null;
    sale_price: string | null;
    currency: string;
    image: string | null;
    url: string | null;
    rating_avg: number | null;
    rating_count: number;
}

export interface RecoOverview {
    sites: { id: string; name: string; url: string }[];
    site_id: string | null;
    settings: { enabled: boolean; kinds: Record<RecoKind, boolean> };
    kinds: { kind: RecoKind; enabled: boolean; personal: boolean; needs: RecoNeeds; shortcode: string }[];
    stats: {
        products: number;
        in_stock: number;
        with_sales_90d: number;
        trending: number;
        rules: number;
        products_with_rules: number;
        stats_computed_at: string | null;
        rules_computed_at: string | null;
    } | null;
    config: { min_pair_orders: number; window_days: number; best_sellers_days: number };
}

export interface RecoPreview {
    kind: RecoKind;
    products: RecoProduct[];
    fallback: boolean;
}

export interface PreviewParams {
    kind: RecoKind;
    site_id?: string | null;
    product_id?: string;
    category_id?: string;
    contact_id?: string;
    cart?: string;
}

export interface AssociationRow {
    product: { id: string; name: string | null; image: string | null };
    related: { id: string; name: string | null; image: string | null; in_stock: boolean };
    pair_orders: number;
    support: number;
    confidence: number;
    lift: number;
}

export interface CatalogProduct {
    id: string;
    name: string;
    image: string | null;
    price: string;
    in_stock: boolean;
    sold_90d: number;
    category_ids: string[];
}

export type WatchKind = 'back_in_stock' | 'price_drop';
export type WatchStatus = 'active' | 'notified' | 'cancelled';
export type MessageChannel = 'whatsapp' | 'email' | 'sms';

export interface WatchSettings {
    enabled: boolean;
    channels: MessageChannel[];
    templates: Record<WatchKind, Record<MessageChannel, string | null>>;
    library: Record<WatchKind, Partial<Record<MessageChannel, string>>>;
    available_templates: { id: string; name: string; channel: MessageChannel; category: string; library_key: string | null }[];
}

export interface WatchSummary {
    days: number;
    currency: string;
    totals: {
        active: number;
        active_back_in_stock: number;
        active_price_drop: number;
        notified: number;
        notified_in_range: number;
        cancelled: number;
        orders_after: number;
        revenue_after: string;
        buyers_after: number;
        conversion_rate: number | null;
    };
    weekly: { week: string; created: number; notified: number }[];
    top_products: { id: string; name: string | null; image: string | null; in_stock: boolean; price: string | null; back_in_stock: number; price_drop: number }[];
    sale_window_days: number;
    settings: WatchSettings;
}

export interface WatchRow {
    id: string;
    kind: WatchKind;
    status: WatchStatus;
    channel: MessageChannel;
    product: { id: string; name: string | null; image: string | null; in_stock: boolean };
    contact: { id: string; name: string | null } | null;
    threshold: string | null;
    notified_price: string | null;
    created_at: string | null;
    notified_at: string | null;
    next_attempt_at: string | null;
    last_reason: string | null;
}

export interface Paged<T> {
    data: T[];
    page: number;
    per_page: number;
    total: number;
}

export type BannerAudience = 'segment' | 'points' | 'pending_cart' | 'at_risk';
export type BannerStyle = 'brand' | 'info' | 'success' | 'warning';

export interface Banner {
    id: string;
    name: string;
    placement: 'top' | 'account';
    audience: BannerAudience;
    segment: { id: string; name: string | null } | null;
    min_points: number | null;
    text: string;
    text_public: string | null;
    cta: string | null;
    url: string | null;
    style: BannerStyle;
    priority: number;
    status: 'active' | 'paused';
    site_id: string | null;
    starts_at: string | null;
    ends_at: string | null;
    live: boolean;
}

export interface BannersResponse {
    data: Banner[];
    segments: { id: string; name: string; members: number }[];
    sites: { id: string; name: string }[];
    variables: { private: string[]; public: string[] };
}

export interface BannerInput {
    name?: string;
    placement?: Banner['placement'];
    audience?: BannerAudience;
    segment_id?: string | null;
    min_points?: number | null;
    text?: string;
    text_public?: string | null;
    cta?: string | null;
    url?: string | null;
    style?: BannerStyle;
    priority?: number;
    status?: Banner['status'];
    site_id?: string | null;
}

const base = (ws: string) => `${wsPath(ws)}/onsite-commerce`;

function qs(params: Record<string, string | number | null | undefined>): string {
    const search = new URLSearchParams();
    for (const [key, value] of Object.entries(params)) {
        if (value !== null && value !== undefined && value !== '') {
            search.set(key, String(value));
        }
    }
    const s = search.toString();
    return s === '' ? '' : `?${s}`;
}

export const recoKeys = {
    overview: (ws: string, site: string | null) => ['ws', ws, 'onsite-commerce', 'overview', site] as const,
    preview: (ws: string, p: PreviewParams) => ['ws', ws, 'onsite-commerce', 'preview', p] as const,
    rules: (ws: string, site: string | null, product: string, page: number) => ['ws', ws, 'onsite-commerce', 'rules', site, product, page] as const,
    products: (ws: string, site: string | null, q: string) => ['ws', ws, 'onsite-commerce', 'products', site, q] as const,
    watchSummary: (ws: string, days: number) => ['ws', ws, 'onsite-commerce', 'watches', 'summary', days] as const,
    watches: (ws: string, f: WatchFilters) => ['ws', ws, 'onsite-commerce', 'watches', 'list', f] as const,
    banners: (ws: string) => ['ws', ws, 'onsite-commerce', 'banners'] as const,
};

export function useRecoOverview(ws: string, site: string | null) {
    return useQuery({ queryKey: recoKeys.overview(ws, site), queryFn: ({ signal }) => api.get<RecoOverview>(`${base(ws)}/recommendations${qs({ site_id: site })}`, signal) });
}

export function useRecoPreview(ws: string, params: PreviewParams, enabled: boolean) {
    return useQuery({
        queryKey: recoKeys.preview(ws, params),
        queryFn: ({ signal }) => api.get<RecoPreview>(`${base(ws)}/recommendations/preview${qs({ ...params })}`, signal),
        enabled,
        placeholderData: keepPreviousData,
        retry: false,
    });
}

export function useAssociations(ws: string, site: string | null, product: string, page: number) {
    return useQuery({
        queryKey: recoKeys.rules(ws, site, product, page),
        queryFn: ({ signal }) => api.get<Paged<AssociationRow>>(`${base(ws)}/recommendations/associations${qs({ site_id: site, product_id: product, page })}`, signal),
        placeholderData: keepPreviousData,
    });
}

export function useCatalogSearch(ws: string, site: string | null, q: string) {
    return useQuery({
        queryKey: recoKeys.products(ws, site, q),
        queryFn: ({ signal }) => api.get<{ data: CatalogProduct[] }>(`${base(ws)}/products${qs({ site_id: site, q })}`, signal),
        placeholderData: keepPreviousData,
    });
}

export function useSaveRecoSettings(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (input: { enabled?: boolean; kinds?: Partial<Record<RecoKind, boolean>> }) => api.patch<{ settings: RecoOverview['settings'] }>(`${base(ws)}/recommendations/settings`, input),
        onSuccess: () => qc.invalidateQueries({ queryKey: ['ws', ws, 'onsite-commerce', 'overview'] }),
    });
}

export function useRebuildReco(ws: string) {
    return useMutation({ mutationFn: () => api.post<{ queued: boolean }>(`${base(ws)}/recommendations/rebuild`, {}) });
}

export interface WatchFilters {
    status: WatchStatus | '';
    kind: WatchKind | '';
    page: number;
}

export function useWatchSummary(ws: string, days: number) {
    return useQuery({ queryKey: recoKeys.watchSummary(ws, days), queryFn: ({ signal }) => api.get<WatchSummary>(`${base(ws)}/watches/summary${qs({ days })}`, signal) });
}

export function useWatches(ws: string, filters: WatchFilters) {
    return useQuery({
        queryKey: recoKeys.watches(ws, filters),
        queryFn: ({ signal }) => api.get<Paged<WatchRow>>(`${base(ws)}/watches${qs({ ...filters })}`, signal),
        placeholderData: keepPreviousData,
    });
}

export function useSaveWatchSettings(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (input: { enabled?: boolean; channels?: MessageChannel[]; templates?: Partial<Record<WatchKind, Partial<Record<MessageChannel, string | null>>>> }) =>
            api.patch<{ settings: WatchSettings }>(`${base(ws)}/watches/settings`, input),
        onSuccess: () => qc.invalidateQueries({ queryKey: ['ws', ws, 'onsite-commerce', 'watches'] }),
    });
}

export function useCancelWatch(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (id: string) => api.post<{ id: string; status: WatchStatus }>(`${base(ws)}/watches/${encodeURIComponent(id)}/cancel`, {}),
        onSuccess: () => qc.invalidateQueries({ queryKey: ['ws', ws, 'onsite-commerce', 'watches'] }),
    });
}

export function useBanners(ws: string) {
    return useQuery({ queryKey: recoKeys.banners(ws), queryFn: ({ signal }) => api.get<BannersResponse>(`${base(ws)}/banners`, signal) });
}

export function useSaveBanner(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: ({ id, input }: { id: string | null; input: BannerInput }) =>
            id === null ? api.post<Banner>(`${base(ws)}/banners`, input) : api.patch<Banner>(`${base(ws)}/banners/${encodeURIComponent(id)}`, input),
        onSuccess: () => qc.invalidateQueries({ queryKey: recoKeys.banners(ws) }),
    });
}

export function useDeleteBanner(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (id: string) => api.delete(`${base(ws)}/banners/${encodeURIComponent(id)}`),
        onSuccess: () => qc.invalidateQueries({ queryKey: recoKeys.banners(ws) }),
    });
}

/** Variables que solo se llenan con sesión iniciada: no pueden ir en el texto sin sesión. */
export const PRIVATE_BANNER_VARIABLES = ['nombre', 'puntos'] as const;

export function usesPrivateVariables(text: string | null | undefined): boolean {
    if (!text) return false;
    return PRIVATE_BANNER_VARIABLES.some((v) => new RegExp(`\\{\\{\\s*${v}\\s*\\}\\}`).test(text));
}

/** Vista previa del texto del banner, como lo arma el hub (ResolveBanner::render). */
export function renderBannerText(text: string, vars: Record<string, string>): string {
    return text
        .replace(/\{\{\s*([a-z_]+)\s*\}\}/g, (_, name: string) => vars[name] ?? '')
        .replace(/\s{2,}/g, ' ')
        .trim();
}
