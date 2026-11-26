import { ArrowRight, BarChart3, Lock } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link, Navigate, NavLink, useNavigate, useParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { EmptyState, ErrorState, PageHeader } from '@/components/ui/misc';
import { Select } from '@/components/ui/input';
import { useSites } from '@/api/queries';
import type { Site } from '@/api/types';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { SitesHealthNotice, trackerMissing } from '@/features/sites/SiteHealth';
import { cn } from '@/lib/cn';
import { useDashboard, useMetricCatalog } from './api';
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
import { dashboardHref, slugFromPath } from './paths';
import { resultsAudience, TRAFFIC_ONLY, TRAFFIC_PARTIAL, visibleDashboards, visibleGroups } from './results';

/** Sin sesiones ni pedidos en el rango: no hay nada que dibujar. */
function isEmpty(slug: DashboardSlug, data: unknown): boolean {
    if (slug === 'overview') {
        const d = data as DashboardDataMap['overview'];
        return (d.kpis.sessions ?? 0) === 0 && (d.kpis.orders ?? 0) === 0 && (d.kpis.cod_pending_orders ?? 0) === 0;
    }
    if (slug === 'funnel') {
        return ((data as DashboardDataMap['funnel']).steps[0]?.value ?? 0) === 0;
    }
    if (slug === 'searches') {
        return (data as DashboardDataMap['searches']).total_searches === 0;
    }
    return false;
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

/**
 * Navegación entre los tableros: agrupados (ventas, clientes, tráfico, leads, mensajes) y solo los que aplican al
 * negocio. En el celular, un selector con los grupos (doce pestañas no caben ni se entienden en 390 px).
 */
function DashboardNav({ slug, groups, base, search }: { slug: DashboardSlug; groups: { key: string; dashboards: DashboardSlug[] }[]; base: string; search: string }) {
    const { t } = useTranslation();
    const navigate = useNavigate();
    const single = groups.length === 1 && (groups[0]?.dashboards.length ?? 0) <= 1;
    if (single) {
        return null;
    }
    return (
        <>
            <div className="igr:mb-4 igr:md:hidden">
                <label htmlFor="dashboard-picker" className="igr:mb-1 igr:block igr:text-caption igr:font-medium igr:text-muted-foreground">
                    {t('analytics.picker')}
                </label>
                <Select id="dashboard-picker" value={slug} onChange={(e) => void navigate(dashboardHref(base, e.target.value as DashboardSlug, search))}>
                    {groups.map((g) => (
                        <optgroup key={g.key} label={t(`analytics.groups.${g.key}`)}>
                            {g.dashboards.map((d) => (
                                <option key={d} value={d}>
                                    {t(`analytics.dashboards.${d}`)}
                                </option>
                            ))}
                        </optgroup>
                    ))}
                </Select>
            </div>
            <nav aria-label={t('analytics.dashboardsLabel')} className="igr:mb-4 igr:hidden igr:max-w-full igr:overflow-x-auto igr:border-b igr:scrollbar-none igr:md:block">
                <div className="igr:flex igr:w-max igr:gap-5 igr:px-0.5">
                    {groups.map((g) => (
                        <div key={g.key} role="group" aria-labelledby={`dash-group-${g.key}`} className="igr:shrink-0">
                            <p id={`dash-group-${g.key}`} className="igr:px-2.5 igr:text-[11px] igr:font-semibold igr:tracking-wide igr:text-muted-foreground igr:uppercase">
                                {t(`analytics.groups.${g.key}`)}
                            </p>
                            <div className="igr:flex igr:gap-0.5">
                                {g.dashboards.map((d) => (
                                    <NavLink
                                        key={d}
                                        end
                                        to={dashboardHref(base, d, search)}
                                        className={({ isActive }) =>
                                            cn(
                                                'igr:-mb-px igr:shrink-0 igr:rounded-t-md igr:border-b-2 igr:border-transparent igr:px-2.5 igr:py-2 igr:text-[13px] igr:font-medium igr:whitespace-nowrap igr:text-muted-foreground igr:outline-none igr:hover:text-foreground igr:focus-visible:ring-2 igr:focus-visible:ring-ring',
                                                isActive && 'igr:border-primary igr:text-foreground',
                                            )
                                        }
                                    >
                                        {t(`analytics.dashboards.${d}`)}
                                    </NavLink>
                                ))}
                            </div>
                        </div>
                    ))}
                </div>
            </nav>
        </>
    );
}

/** Sin las visitas del navegador, el embudo y las búsquedas no tienen qué mostrar: se dice qué falta y dónde arreglarlo. */
function TrackerMissing({ site, base }: { site: Site | undefined; base: string }) {
    const { t } = useTranslation();
    return (
        <EmptyState
            illustration="web"
            title={t('analytics.trackerMissing.title')}
            description={t('analytics.trackerMissing.description', { host: site?.host ?? t('analytics.trackerMissing.yourSite') })}
            action={
                <Button asChild>
                    <Link to={site ? `${base}/sitios/${site.id}` : `${base}/sitios`}>
                        {t('analytics.trackerMissing.action')}
                        <ArrowRight aria-hidden />
                    </Link>
                </Button>
            }
            steps={[t('analytics.trackerMissing.step1'), t('analytics.trackerMissing.step2'), t('analytics.trackerMissing.step3')]}
        />
    );
}

export function AnalyticsPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canView = useCan('viewDashboards');
    const { dashboard: path } = useParams();
    const slug = slugFromPath(path);
    const audience = resultsAudience(workspace);
    const visible = visibleDashboards(audience);
    const allowed = slug !== null && visible.includes(slug);
    const { params, update, query, search } = useAnalyticsParams(workspace.timezone);
    const result = useDashboard(workspace.id, slug ?? 'overview', query, canView && allowed);
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
                        <Link to={`${base}/resultados`}>{t('analytics.backToSummary')}</Link>
                    </Button>
                }
            />
        );
    }
    // Un tablero que no aplica al negocio (p. ej. ventas en una clínica): al primero que sí aplica.
    const first = visible[0];
    if (!allowed && first !== undefined) {
        return <Navigate to={dashboardHref(base, first, search)} replace />;
    }

    const loaded = result.data?.dashboard === slug ? (result.data as AnyDashboardResponse) : undefined;
    // El aviso de salud de los sitios y el tablero se pintan juntos: si el aviso llegara después empujaría las cifras
    // hacia abajo (docs/rendimiento.md §7.1, 4).
    const response = canViewSites && sites.isPending ? undefined : loaded;
    const noTracker = (sites.data ?? []).filter((s) => trackerMissing(s.health_status));

    return (
        <>
            <PageHeader title={t(`analytics.dashboards.${slug}`)} description={t(`analytics.descriptions.${slug}`)} />
            <DashboardNav slug={slug} groups={visibleGroups(audience)} base={base} search={search} />
            <FilterBar params={params} update={update} period={result.data?.period} fmt={fmt} periodOnly={slug === 'messaging'} />
            {TRAFFIC_PARTIAL.includes(slug) && response !== undefined ? <SitesHealthNotice sites={noTracker} base={base} className="igr:mb-4" /> : null}
            {result.isError ? (
                <ErrorState error={result.error} onRetry={() => void result.refetch()} />
            ) : response === undefined ? (
                <DashboardSkeleton />
            ) : isEmpty(slug, response.data) ? (
                TRAFFIC_ONLY.includes(slug) && noTracker.length > 0 ? (
                    <TrackerMissing site={noTracker[0]} base={base} />
                ) : (
                    <EmptyState
                        illustration="results"
                        title={t('analytics.empty.title')}
                        description={t('analytics.empty.description')}
                        action={
                            params.range !== '12m' ? (
                                <Button variant="outline" onClick={() => update({ range: '12m' })}>
                                    {t('analytics.empty.widen')}
                                </Button>
                            ) : undefined
                        }
                    />
                )
            ) : (
                <div className={cn('igr:transition-opacity', result.isFetching && result.isPlaceholderData && 'igr:opacity-60')} aria-busy={result.isFetching}>
                    <DashboardView response={response} base={base} catalog={catalog.data?.metrics} />
                </div>
            )}
        </>
    );
}
