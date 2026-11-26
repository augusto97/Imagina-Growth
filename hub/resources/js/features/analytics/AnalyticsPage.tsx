import { BarChart3, Lock } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link, NavLink, useParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { EmptyState, ErrorState, PageHeader } from '@/components/ui/misc';
import { useSites } from '@/api/queries';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { SitesHealthNotice, trackerMissing } from '@/features/sites/SiteHealth';
import { cn } from '@/lib/cn';
import { DASHBOARDS, isDashboard, useDashboard, useMetricCatalog } from './api';
import type { DashboardDataMap, DashboardResponse, DashboardSlug, MetricDefinition } from './api';
import { FilterBar } from './components/FilterBar';
import { DashboardSkeleton } from './components/StatTile';
import { ChannelsDashboard } from './dashboards/ChannelsDashboard';
import { CustomersDashboard } from './dashboards/CustomersDashboard';
import { FunnelDashboard } from './dashboards/FunnelDashboard';
import { MessagingDashboard } from './dashboards/MessagingDashboard';
import { CouponsDashboard, GeographyDashboard, HdyhauDashboard, ProfitabilityDashboard, SearchesDashboard, ServicesDashboard } from './dashboards/MoreDashboards';
import { OverviewDashboard } from './dashboards/OverviewDashboard';
import { ProductsDashboard } from './dashboards/ProductsDashboard';
import { makeFormatters } from './format';
import { useAnalyticsParams } from './params';

/** Ruta de cada tablero dentro de /w/:ws/analitica (en español, como el resto de la SPA). */
export const DASHBOARD_PATHS: Record<DashboardSlug, string> = {
    overview: '',
    funnel: 'embudo',
    channels: 'canales',
    customers: 'clientes',
    products: 'productos',
    searches: 'busquedas',
    geography: 'geografia',
    coupons: 'cupones',
    messaging: 'mensajeria',
    profitability: 'rentabilidad',
    services: 'servicios',
    hdyhau: 'como-nos-conociste',
};

export function slugFromPath(path: string | undefined): DashboardSlug | null {
    if (path === undefined || path === '') {
        return 'overview';
    }
    const found = (Object.entries(DASHBOARD_PATHS) as [DashboardSlug, string][]).find(([, p]) => p === path);
    if (found) {
        return found[0];
    }
    return isDashboard(path) ? path : null;
}

/** Tableros que dependen de las visitas del navegador (sin tracker salen en cero). */
const TRAFFIC_DASHBOARDS: DashboardSlug[] = ['overview', 'funnel', 'channels', 'products', 'searches', 'geography', 'services'];

/** Sin sesiones ni pedidos en el rango: no hay nada que dibujar. */
function isEmpty(slug: DashboardSlug, data: unknown): boolean {
    if (slug !== 'overview' && slug !== 'funnel') {
        return false;
    }
    if (slug === 'overview') {
        const d = data as DashboardDataMap['overview'];
        return (d.kpis.sessions ?? 0) === 0 && (d.kpis.orders ?? 0) === 0 && (d.kpis.cod_pending_orders ?? 0) === 0;
    }
    return ((data as DashboardDataMap['funnel']).steps[0]?.value ?? 0) === 0;
}

/** Respuesta de cualquier tablero, discriminada por `dashboard`. */
type AnyDashboardResponse = { [K in DashboardSlug]: Omit<DashboardResponse<DashboardDataMap[K]>, 'dashboard'> & { dashboard: K } }[DashboardSlug];

function DashboardView({ response, base, catalog }: { response: AnyDashboardResponse; base: string; catalog: Record<string, MetricDefinition> | undefined }) {
    const { workspace } = useWorkspace();
    const common = { fmt: makeFormatters(workspace.locale, response.currency), base, catalog };
    switch (response.dashboard) {
        case 'overview':
            return <OverviewDashboard response={response} data={response.data} {...common} />;
        case 'funnel':
            return <FunnelDashboard response={response} data={response.data} {...common} />;
        case 'channels':
            return <ChannelsDashboard response={response} data={response.data} {...common} />;
        case 'customers':
            return <CustomersDashboard response={response} data={response.data} {...common} />;
        case 'products':
            return <ProductsDashboard response={response} data={response.data} {...common} />;
        case 'searches':
            return <SearchesDashboard response={response} data={response.data} {...common} />;
        case 'geography':
            return <GeographyDashboard response={response} data={response.data} {...common} />;
        case 'coupons':
            return <CouponsDashboard response={response} data={response.data} {...common} />;
        case 'messaging':
            return <MessagingDashboard response={response} data={response.data} {...common} />;
        case 'profitability':
            return <ProfitabilityDashboard response={response} data={response.data} {...common} />;
        case 'services':
            return <ServicesDashboard response={response} data={response.data} {...common} />;
        case 'hdyhau':
            return <HdyhauDashboard response={response} data={response.data} {...common} />;
    }
}

export function AnalyticsPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canView = useCan('viewDashboards');
    const { dashboard: path } = useParams();
    const slug = slugFromPath(path);
    const { params, update, query, search } = useAnalyticsParams(workspace.timezone);
    const result = useDashboard(workspace.id, slug ?? 'overview', query, canView && slug !== null);
    const catalog = useMetricCatalog(workspace.id);
    const canViewSites = useCan('viewSites');
    const sites = useSites(workspace.id, canViewSites);
    const base = `/w/${workspace.id}`;
    const fmt = makeFormatters(workspace.locale, result.data?.currency ?? workspace.currency);

    if (!canView) {
        return <EmptyState icon={Lock} title={t('analytics.forbidden')} />;
    }
    if (slug === null) {
        return (
            <EmptyState
                icon={BarChart3}
                title={t('analytics.notFound')}
                action={
                    <Button asChild variant="outline">
                        <Link to={`${base}/analitica`}>{t('analytics.dashboards.overview')}</Link>
                    </Button>
                }
            />
        );
    }

    const loaded = result.data?.dashboard === slug ? (result.data as AnyDashboardResponse) : undefined;
    // El aviso de salud de los sitios y el tablero se pintan juntos: si el aviso llegara después empujaría las cifras
    // hacia abajo (docs/rendimiento.md §7.1, 4).
    const response = canViewSites && sites.isPending ? undefined : loaded;

    return (
        <>
            <PageHeader title={t('analytics.title')} description={t(`analytics.descriptions.${slug}`)} />
            <nav aria-label={t('analytics.dashboardsLabel')} className="igr:-mx-1 igr:mb-4 igr:flex igr:gap-1 igr:overflow-x-auto igr:border-b igr:px-1 igr:pb-px">
                {DASHBOARDS.map((d) => (
                    <NavLink
                        key={d}
                        end
                        to={{ pathname: `${base}/analitica${DASHBOARD_PATHS[d] ? `/${DASHBOARD_PATHS[d]}` : ''}`, search: search ? `?${search}` : '' }}
                        className={({ isActive }) =>
                            cn(
                                'igr:-mb-px igr:shrink-0 igr:border-b-2 igr:border-transparent igr:px-2.5 igr:py-2 igr:text-[13px] igr:font-medium igr:whitespace-nowrap igr:text-muted-foreground igr:hover:text-foreground',
                                isActive && 'igr:border-primary igr:text-foreground',
                            )
                        }
                    >
                        {t(`analytics.dashboards.${d}`)}
                    </NavLink>
                ))}
            </nav>
            <FilterBar params={params} update={update} period={result.data?.period} fmt={fmt} periodOnly={slug === 'messaging'} />
            {TRAFFIC_DASHBOARDS.includes(slug) && response !== undefined ? (
                <SitesHealthNotice sites={(sites.data ?? []).filter((s) => trackerMissing(s.health_status))} base={base} className="igr:mb-4" />
            ) : null}
            {result.isError ? (
                <ErrorState error={result.error} onRetry={() => void result.refetch()} />
            ) : response === undefined ? (
                <DashboardSkeleton />
            ) : isEmpty(slug, response.data) ? (
                <EmptyState icon={BarChart3} title={t('analytics.empty.title')} description={t('analytics.empty.description')} />
            ) : (
                <div className={cn('igr:transition-opacity', result.isFetching && result.isPlaceholderData && 'igr:opacity-60')} aria-busy={result.isFetching}>
                    <DashboardView response={response} base={base} catalog={catalog.data?.metrics} />
                </div>
            )}
        </>
    );
}
