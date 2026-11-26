import { Activity, ArrowRight, Lightbulb, Truck, Users } from 'lucide-react';
import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { useOverview, useSites } from '@/api/queries';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ErrorState, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { StatCardSkeleton } from '@/components/ui/stat-card';
import { Tooltip } from '@/components/ui/tooltip';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { formatNumber } from '@/lib/format';
import { useDashboard, useMetricCatalog } from '@/features/analytics/api';
import { TrendChart } from '@/features/analytics/components/charts';
import { MoneyGeneratedCard } from '@/features/analytics/components/MoneyGeneratedCard';
import { StatTile, TileGrid } from '@/features/analytics/components/StatTile';
import { makeFormatters } from '@/features/analytics/format';
import { apiQuery, readParams } from '@/features/analytics/params';
import { useConnectionAlerts } from '@/features/connections/api';
import { ConnectionAlertsNotice } from '@/features/connections/AlertsNotice';
import { SeverityBadge } from '@/features/insights/severity';
import { SitesHealthNotice } from '@/features/sites/SiteHealth';
import { GettingStartedCard } from './GettingStartedCard';
import { useGettingStarted } from './gettingStarted';

const HOME_TILES = [
    { key: 'revenue', unit: 'money' },
    { key: 'orders', unit: 'count' },
    { key: 'conversion_rate', unit: 'ratio' },
    { key: 'sessions', unit: 'count' },
] as const;

/** El Inicio mientras llegan sus datos: el encabezado, la guía, las cifras y la gráfica en su lugar. */
function HomeSkeleton() {
    return (
        <div aria-busy>
            <Skeleton className="igr:h-8 igr:w-56" />
            <Skeleton className="igr:mt-2 igr:mb-6 igr:h-5 igr:w-80 igr:max-w-full" />
            <Skeleton className="igr:mb-6 igr:h-64 igr:rounded-xl" />
            <TileGrid>
                {Array.from({ length: 4 }, (_, i) => (
                    <StatCardSkeleton key={i} />
                ))}
            </TileGrid>
        </div>
    );
}

export function HomePage() {
    const { t } = useTranslation();
    const { workspace, me } = useWorkspace();
    const overview = useOverview(workspace.id);
    const canViewSites = useCan('viewSites');
    const sites = useSites(workspace.id, canViewSites);
    // Lo que se pinta arriba de las cifras (la guía y los avisos) llega con sus propias consultas: el Inicio espera a
    // tenerlas todas y se pinta de una vez, sin que nada empuje lo de abajo (docs/rendimiento.md §7.1, 4).
    const guide = useGettingStarted(workspace.id);
    const alerts = useConnectionAlerts(workspace.id);
    const settled = !overview.isPending && !guide.isPending && !alerts.isPending && !(canViewSites && sites.isPending);
    const base = `/w/${workspace.id}`;

    const data = overview.data;
    const hasSites = (data?.sites.total ?? 0) > 0;
    // Últimos 30 días contra los 30 anteriores: la misma consulta que abre Analítica (comparte caché).
    const query = useMemo(() => apiQuery(readParams(new URLSearchParams(), workspace.timezone)), [workspace.timezone]);
    const analytics = useDashboard(workspace.id, 'overview', query, hasSites);
    const catalog = useMetricCatalog(workspace.id);
    const kpis = analytics.data?.dashboard === 'overview' ? analytics.data : undefined;
    const fmt = makeFormatters(workspace.locale, kpis?.currency ?? data?.currency ?? workspace.currency);

    if (overview.isError) {
        return <ErrorState error={overview.error} onRetry={() => void overview.refetch()} />;
    }
    if (!settled) {
        return <HomeSkeleton />;
    }

    const mode = kpis?.period.compare?.mode ?? null;

    return (
        <>
            <PageHeader
                title={t('home.title', { name: me.user.name.split(' ')[0] ?? '' })}
                description={t('home.description', { workspace: workspace.name })}
                actions={
                    hasSites ? (
                        <Button asChild variant="outline" size="sm">
                            <Link to={`${base}/analitica`}>
                                {t('home.openAnalytics')}
                                <ArrowRight />
                            </Link>
                        </Button>
                    ) : undefined
                }
            />

            <GettingStartedCard />
            <SitesHealthNotice sites={sites.data ?? []} base={base} className="igr:mb-4" />
            <ConnectionAlertsNotice className="igr:mb-4" />

            {data && !hasSites ? null : (
                <>
                    <p className="igr:mb-2 igr:text-xs igr:font-medium igr:text-muted-foreground">{t('home.last30')}</p>
                    <TileGrid>
                        {kpis
                            ? HOME_TILES.map((tile) => (
                                  <StatTile
                                      key={tile.key}
                                      label={t(`analytics.metric.${tile.key}`)}
                                      value={kpis.data.kpis[tile.key]}
                                      previous={kpis.data.compare?.[tile.key]}
                                      compareMode={mode}
                                      unit={tile.unit}
                                      fmt={fmt}
                                      help={catalog.data?.metrics[tile.key]?.definition}
                                  />
                              ))
                            : Array.from({ length: 4 }, (_, i) => <StatCardSkeleton key={i} />)}
                    </TileGrid>
                </>
            )}

            {data && data.orders.cod_pending > 0 ? (
                <div className="igr:mt-3 igr:flex igr:items-center igr:gap-2 igr:rounded-lg igr:border igr:bg-card igr:px-4 igr:py-3 igr:text-[13px]">
                    <Truck className="igr:size-4 igr:text-muted-foreground" />
                    <span>{t('home.codPending', { count: data.orders.cod_pending })}</span>
                    <Tooltip content={t('home.codHelp')}>
                        <span className="igr:ml-1 igr:cursor-help igr:text-xs igr:text-muted-foreground igr:underline igr:decoration-dotted">{t('common.whatIsThis')}</span>
                    </Tooltip>
                </div>
            ) : null}

            {data && !hasSites ? null : (
                <div className="igr:mt-6 igr:grid igr:gap-4 igr:lg:grid-cols-[1fr_300px]">
                    <div className="igr:min-w-0 igr:space-y-4">
                        <Card>
                            <CardHeader>
                                <CardTitle>{t('home.salesTitle')}</CardTitle>
                                <CardDescription>{t('home.salesDescription')}</CardDescription>
                            </CardHeader>
                            <CardContent>
                                {kpis ? (
                                    <TrendChart
                                        data={kpis.data.series}
                                        metric="revenue"
                                        label={t('analytics.metric.revenue')}
                                        unit="money"
                                        granularity={kpis.period.granularity}
                                        fmt={fmt}
                                        height={200}
                                    />
                                ) : (
                                    <Skeleton className="igr:h-[200px]" />
                                )}
                            </CardContent>
                        </Card>
                        <div className="igr:grid igr:gap-4 igr:md:grid-cols-[1fr_260px]">
                            <Card>
                                <CardHeader className="igr:flex igr:flex-row igr:items-center igr:justify-between igr:space-y-0">
                                    <CardTitle className="igr:flex igr:items-center igr:gap-1.5">
                                        <Lightbulb className="igr:size-4 igr:text-muted-foreground" />
                                        {t('home.insightsTitle')}
                                    </CardTitle>
                                    <Button asChild variant="ghost" size="sm">
                                        <Link to={`${base}/hallazgos`}>{t('home.allInsights')}</Link>
                                    </Button>
                                </CardHeader>
                                <CardContent>
                                    {!kpis ? (
                                        <Skeleton className="igr:h-24" />
                                    ) : kpis.data.insights.length === 0 ? (
                                        <p className="igr:text-[13px] igr:text-muted-foreground">{t('home.noInsights')}</p>
                                    ) : (
                                        <ul className="igr:divide-y">
                                            {kpis.data.insights.map((i) => (
                                                <li key={i.id}>
                                                    <Link
                                                        to={`${base}/hallazgos/${i.id}`}
                                                        className="igr:flex igr:items-start igr:gap-2.5 igr:py-2 igr:text-[13px] igr:hover:text-primary"
                                                    >
                                                        <SeverityBadge severity={i.severity} />
                                                        <span className="igr:min-w-0 igr:flex-1">{i.title}</span>
                                                    </Link>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </CardContent>
                            </Card>
                            <MoneyGeneratedCard data={kpis?.data.attributed} money={(v) => fmt.money(v)} base={base} />
                        </div>
                    </div>

                    <div className="igr:space-y-4">
                        {data ? (
                            <Card>
                                <CardContent className="igr:space-y-2 igr:pt-4 igr:text-[13px]">
                                    <div className="igr:flex igr:items-center igr:justify-between igr:gap-2">
                                        <span className="igr:flex igr:items-center igr:gap-2 igr:text-muted-foreground">
                                            <Users className="igr:size-4" />
                                            {t('home.contacts')}
                                        </span>
                                        <span className="igr:font-medium igr:tabular-nums">{formatNumber(data.contacts.total, workspace.locale)}</span>
                                    </div>
                                    <p className="igr:text-xs igr:text-muted-foreground">
                                        {t('home.contactsHint', { customers: formatNumber(data.contacts.customers, workspace.locale) })}
                                    </p>
                                    <div className="igr:flex igr:items-center igr:justify-between igr:gap-2 igr:border-t igr:pt-2">
                                        <span className="igr:flex igr:items-center igr:gap-2 igr:text-muted-foreground">
                                            <Activity className="igr:size-4" />
                                            {t('home.events')}
                                        </span>
                                        <span className="igr:font-medium igr:tabular-nums">{data.events_24h === null ? '—' : formatNumber(data.events_24h, workspace.locale)}</span>
                                    </div>
                                </CardContent>
                            </Card>
                        ) : null}
                    </div>
                </div>
            )}
        </>
    );
}
