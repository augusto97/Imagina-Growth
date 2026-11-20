import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { wsPath } from '@/api/queries';
import { api } from '@/lib/api';

/* Tipos y consultas de Analítica (M05). Rutas en hub/routes/api/analytics.php. */

/** Dinero generado por la plataforma en el periodo (CLAUDE.md §3.7). */
export interface AttributedRevenue {
    revenue: number;
    orders: number;
    share_pct: number;
    available: boolean;
    modules: { key: string; revenue: number; orders: number }[];
}

export const DASHBOARDS = [
    'overview',
    'funnel',
    'channels',
    'customers',
    'products',
    'searches',
    'geography',
    'coupons',
    'messaging',
    'profitability',
    'services',
    'hdyhau',
] as const;
export type DashboardSlug = (typeof DASHBOARDS)[number];

export function isDashboard(value: string | undefined): value is DashboardSlug {
    return value !== undefined && (DASHBOARDS as readonly string[]).includes(value);
}

export type CompareMode = 'previous' | 'year' | 'none';
export type Granularity = 'day' | 'week' | 'month';

export interface PeriodInfo {
    from: string;
    to: string;
    timezone: string;
    granularity: Granularity;
    compare: { mode: CompareMode; from: string; to: string } | null;
}

export interface DashboardResponse<T> {
    dashboard: DashboardSlug;
    period: PeriodInfo;
    filters: Record<string, string | null>;
    currency: string;
    data: T;
    took_ms: number;
}

export type MetricUnit = 'money' | 'count' | 'ratio';

export interface MetricDefinition {
    label: string;
    unit: MetricUnit;
    source: string;
    definition: string;
}

export interface Unavailable {
    available: false;
    phase?: number;
    reason?: string;
}

/* 1. Inicio */
export interface OverviewInsight {
    id: string;
    severity: string;
    title: string;
    impact: string | null;
    detector: string;
}
export interface OverviewData {
    kpis: Record<string, number>;
    compare: Record<string, number> | null;
    series: { date: string; revenue: number; orders: number; sessions: number }[];
    attributed: AttributedRevenue;
    insights: OverviewInsight[];
}

/* 2. Embudo */
export interface FunnelStep {
    step: 'sessions' | 'product_views' | 'add_to_carts' | 'checkouts' | 'purchases';
    value: number;
    rate_from_previous: number;
    rate_from_start: number;
}
export interface FunnelBreakdown {
    key: string;
    sessions: number;
    product_views: number;
    add_to_carts: number;
    checkouts: number;
    purchases: number;
    conversion: number;
}
export interface FunnelData {
    steps: FunnelStep[];
    compare: FunnelStep[] | null;
    by_channel: FunnelBreakdown[];
    by_device: FunnelBreakdown[];
    by_payment_method: { method: string; created: number; paid: number; cod_pending: number; failed: number; paid_rate: number }[];
}

/* 3. Canales */
export type AttributionModel = 'last_click' | 'first_click' | 'linear' | 'position';
export const ATTRIBUTION_MODELS: AttributionModel[] = ['last_click', 'first_click', 'linear', 'position'];
export interface ChannelsData {
    channels: {
        channel: string;
        sessions: number;
        users: number;
        conversion: number;
        orders: number;
        revenue: number;
        new_customers: number;
        spend: number | null;
        roas: number | null;
        cac: number | null;
    }[];
    models: ({ channel: string } & Record<AttributionModel, number>)[];
    ltv: { channel: string; customers: number; ltv_90: number | null; ltv_365: number | null; customers_90: number; customers_365: number }[];
    spend_available: boolean;
    attribution_window_days: number;
}

/* 4. Clientes */
/** Segmentos RFM en el orden de inteligencia.md §3 (gana la primera regla). */
export const RFM_SEGMENTS = [
    'champions',
    'loyal',
    'cant_lose',
    'at_risk',
    'potential_loyal',
    'new',
    'promising',
    'need_attention',
    'about_to_sleep',
    'hibernating',
    'lost',
] as const;
export type RfmSegmentKey = (typeof RFM_SEGMENTS)[number];
export interface CustomersData {
    rfm: {
        segments: {
            segment: RfmSegmentKey;
            customers: number;
            revenue: number;
            revenue_share: number;
            recency_days: number | null;
            avg_orders: number | null;
            system_segment: string;
        }[];
        grid: { r: number; f: number; customers: number }[];
        computed_at: string | null;
    };
    cohorts: { cohort: string; customers: number; retention: (number | null)[] }[];
    repurchase: { window_months: number; buyers: number; repeat_buyers: number; rate: number; median_days_to_second: number | null; second_purchases: number };
    concentration: { customers: number; top10_share: number; top20_share: number; total_revenue: number };
    new_vs_returning: { date: string; new_orders: number; returning_orders: number; new_revenue: number; returning_revenue: number }[];
}

/* 5. Productos */
export interface ProductRow {
    product_id: string;
    name: string;
    views: number;
    add_to_carts: number;
    orders: number;
    units: number;
    revenue: number;
    view_to_purchase: number;
    cart_abandonment: number | null;
    stock_qty: number | null;
    days_of_stock: number | null;
}
export interface ProductsData {
    products: ProductRow[];
    viewed_not_sold: ProductRow[];
    entry_products: { product_id: string; name: string; customers: number; avg_ltv: number; avg_orders: number }[];
    bought_together: { a: { product_id: string; name: string }; b: { product_id: string; name: string }; orders: number; support: number; confidence: number; lift: number }[];
}

/* 6. Búsquedas */
export interface SearchTerm {
    query: string;
    searches: number;
    sessions: number;
    zero_results: number;
    avg_results: number | null;
    conversion: number;
}
export interface SearchesData {
    total_searches: number;
    zero_result_share: number;
    terms: SearchTerm[];
    zero_results: SearchTerm[];
}

/* 7. Geografía */
export interface GeographyData {
    regions: { region: string; orders: number; revenue: number; aov: number }[];
    /** conversion es null sin visitas de esa ciudad o con menos de min_sessions (low_sample). */
    cities: { city: string; region: string; orders: number; revenue: number; sessions: number | null; conversion: number | null; low_sample?: boolean }[];
    sessions?: { total: number; without_city: number; without_city_share: number };
    min_sessions?: number;
    /** Hay pedidos pero ninguna visita terminó en compra: la conversión no se puede calcular. */
    conversion_unavailable?: boolean;
}

/* 8. Cupones */
export interface CouponsData {
    orders: number;
    orders_with_coupon: number;
    coupon_share: number;
    discount: number;
    revenue_with_coupon: number;
    aov_with_coupon: number;
    aov_without_coupon: number;
    codes: { code: string; orders: number; discount: number; revenue: number }[];
    trend: { month: string; orders: number; coupon_share: number; discount: number }[];
    dependency: { repeat_customers: number; only_with_discount: number; never_use_coupons: number };
    incremental: Unavailable;
}

/* 10. Rentabilidad */
export type ProfitabilityData =
    | (Unavailable & { orders: number })
    | {
          available: true;
          coverage: number;
          revenue: number;
          cost: number;
          gross_margin: number;
          gross_margin_rate: number;
          avg_margin_per_order: number;
          products: { product_id: string; name: string; units: number; revenue: number; cost: number; margin: number; margin_rate: number }[];
          ad_spend: Record<string, number>;
          mer: number | null;
          roas_by_platform: Record<string, number | null>;
      };

/* 11. Servicios */
export interface ServicesData {
    forms: { form: string; plugin: string; submissions: number; page: string }[];
    by_channel: { channel: string; forms: number; whatsapp: number; phone: number }[];
    pages: { path: string; views: number; forms: number; whatsapp: number; calls: number; form_rate: number }[];
    cta_clicks: { kind: string; clicks: number }[];
    response_time: Unavailable;
}

/* 12. ¿Cómo nos conociste? */
export interface HdyhauData {
    responses: number;
    response_rate: number;
    answers: { answer: string; responses: number; share: number; revenue: number }[];
    matrix: { answer: string; channel: string; responses: number }[];
}

/* 9. Mensajería */
export interface MessagingCounts {
    total: number;
    sent: number;
    delivered: number;
    read: number;
    opened: number;
    clicked: number;
    replied: number;
    unsubscribed: number;
    complained: number;
    bounced: number;
    failed: number;
    orders: number;
    revenue: number;
}
export interface MessagingData {
    available: true;
    empty: boolean;
    totals: MessagingCounts;
    compare: MessagingCounts | null;
    by_channel: (MessagingCounts & { channel: string })[];
    by_source: (MessagingCounts & { type: 'flow' | 'campaign' | 'inbox' | 'other'; id: string | null; name: string | null; channels: string[] })[];
    series: { date: string; sent: number; delivered: number; clicked: number }[];
    /** Las aperturas no cuentan las automáticas de Apple (con ClickHouse disponible). */
    opens_exclude_mpp: boolean;
}

export interface DashboardDataMap {
    overview: OverviewData;
    funnel: FunnelData;
    channels: ChannelsData;
    customers: CustomersData;
    products: ProductsData;
    searches: SearchesData;
    geography: GeographyData;
    coupons: CouponsData;
    messaging: MessagingData;
    profitability: ProfitabilityData;
    services: ServicesData;
    hdyhau: HdyhauData;
}

export function useDashboard<S extends DashboardSlug>(ws: string, slug: S, query: string, enabled = true) {
    return useQuery({
        queryKey: ['ws', ws, 'analytics', slug, query],
        queryFn: ({ signal }) => api.get<DashboardResponse<DashboardDataMap[S]>>(`${wsPath(ws)}/analytics/${slug}${query ? `?${query}` : ''}`, signal),
        placeholderData: keepPreviousData,
        staleTime: 60_000,
        enabled,
    });
}

export function useMetricCatalog(ws: string) {
    return useQuery({
        queryKey: ['ws', ws, 'analytics', 'metrics'],
        queryFn: ({ signal }) => api.get<{ metrics: Record<string, MetricDefinition>; dimensions: Record<string, string> }>(`${wsPath(ws)}/analytics/metrics`, signal),
        staleTime: 60 * 60_000,
    });
}
