import { Activity, ArrowRight, Check, CircleDollarSign, Lightbulb, Plug, Truck, Users } from 'lucide-react';
import type { ReactNode } from 'react';
import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { useMembers, useOverview, useSites } from '@/api/queries';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState, ErrorState, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Tooltip } from '@/components/ui/tooltip';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { cn } from '@/lib/cn';
import { formatNumber } from '@/lib/format';
import { useDashboard, useMetricCatalog } from '@/features/analytics/api';
import { TrendChart } from '@/features/analytics/components/charts';
import { StatTile, TileGrid } from '@/features/analytics/components/StatTile';
import { makeFormatters } from '@/features/analytics/format';
import { apiQuery, readParams } from '@/features/analytics/params';
import { SeverityBadge } from '@/features/insights/severity';

const HOME_TILES = [
    { key: 'revenue', unit: 'money' },
    { key: 'orders', unit: 'count' },
    { key: 'conversion_rate', unit: 'ratio' },
    { key: 'sessions', unit: 'count' },
] as const;

function Step({ done, title, description, action }: { done: boolean; title: string; description: string; action?: ReactNode }) {
    return (
        <li className="igr:flex igr:items-start igr:gap-3 igr:py-3">
            <span
                className={cn(
                    'igr:mt-0.5 igr:flex igr:size-5 igr:shrink-0 igr:items-center igr:justify-center igr:rounded-full igr:border',
                    done ? 'igr:border-success igr:bg-success igr:text-white' : 'igr:border-input',
                )}
            >
                {done ? <Check className="igr:size-3" /> : null}
            </span>
            <div className="igr:min-w-0 igr:flex-1">
                <p className={cn('igr:text-[13px] igr:font-medium', done && 'igr:text-muted-foreground igr:line-through')}>{title}</p>
                <p className="igr:text-xs igr:text-muted-foreground">{description}</p>
            </div>
            {!done && action ? <div className="igr:shrink-0">{action}</div> : null}
        </li>
    );
}

export function HomePage() {
    const { t } = useTranslation();
    const { workspace, me } = useWorkspace();
    const overview = useOverview(workspace.id);
    const sites = useSites(workspace.id);
    const canManageSites = useCan('manageSites');
    const canManageMembers = useCan('manageMembers');
    const members = useMembers(workspace.id);
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

    const backfillDone = (sites.data ?? []).some((s) => s.backfill?.status === 'completed');
    const hasTeam = (members.data?.members.length ?? 0) + (members.data?.invitations.length ?? 0) > 1;
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
                            : Array.from({ length: 4 }, (_, i) => <Skeleton key={i} className="igr:h-[98px]" />)}
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

            <div className="igr:mt-6 igr:grid igr:gap-4 igr:lg:grid-cols-[1fr_340px]">
                {data && !hasSites ? (
                    <EmptyState
                        icon={Plug}
                        title={t('home.emptyTitle')}
                        description={t('home.emptyDescription')}
                        action={
                            canManageSites ? (
                                <Button asChild>
                                    <Link to={`${base}/sitios?conectar=1`}>
                                        <Plug />
                                        {t('sites.connect')}
                                    </Link>
                                </Button>
                            ) : (
                                <p className="igr:text-xs igr:text-muted-foreground">{t('home.askAdmin')}</p>
                            )
                        }
                    />
                ) : (
                    <div className="igr:min-w-0 igr:space-y-4">
                        <Card>
                            <CardHeader>
                                <CardTitle>{t('home.salesTitle')}</CardTitle>
                                <CardDescription>{t('home.salesDescription')}</CardDescription>
                            </CardHeader>
                            <CardContent>
                                {kpis ? (
                                    <TrendChart data={kpis.data.series} metric="revenue" label={t('analytics.metric.revenue')} unit="money" granularity={kpis.period.granularity} fmt={fmt} height={200} />
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
                                                    <Link to={`${base}/hallazgos/${i.id}`} className="igr:flex igr:items-start igr:gap-2.5 igr:py-2 igr:text-[13px] igr:hover:text-primary">
                                                        <SeverityBadge severity={i.severity} />
                                                        <span className="igr:min-w-0 igr:flex-1">{i.title}</span>
                                                    </Link>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </CardContent>
                            </Card>
                            <Card>
                                <CardHeader>
                                    <CardTitle className="igr:flex igr:items-center igr:gap-1.5">
                                        <CircleDollarSign className="igr:size-4 igr:text-muted-foreground" />
                                        {t('home.attributedTitle')}
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <p className="igr:text-2xl igr:font-semibold igr:tabular-nums">{fmt.money(kpis?.data.attributed.revenue ?? 0)}</p>
                                    <p className="igr:mt-1 igr:text-xs igr:text-muted-foreground">{t('home.attributedHelp', { phase: kpis?.data.attributed.phase ?? 2 })}</p>
                                </CardContent>
                            </Card>
                        </div>
                    </div>
                )}

                <div className="igr:space-y-4">
                    <Card>
                        <CardHeader>
                            <CardTitle>{t('home.stepsTitle')}</CardTitle>
                        </CardHeader>
                        <CardContent className="igr:pt-0">
                            <ul className="igr:divide-y">
                                <Step done={me.user.two_factor_enabled} title={t('home.step2fa')} description={t('home.step2faHelp')} action={<Button asChild size="sm" variant="outline"><Link to="/seguridad">{t('common.activate')}</Link></Button>} />
                                <Step
                                    done={hasSites}
                                    title={t('home.stepConnect')}
                                    description={t('home.stepConnectHelp')}
                                    action={canManageSites ? <Button asChild size="sm" variant="outline"><Link to={`${base}/sitios?conectar=1`}>{t('common.start')}</Link></Button> : undefined}
                                />
                                <Step done={backfillDone} title={t('home.stepBackfill')} description={t('home.stepBackfillHelp')} />
                                <Step
                                    done={hasTeam}
                                    title={t('home.stepTeam')}
                                    description={t('home.stepTeamHelp')}
                                    action={canManageMembers ? <Button asChild size="sm" variant="outline"><Link to={`${base}/equipo?invitar=1`}>{t('team.invite')}</Link></Button> : undefined}
                                />
                            </ul>
                        </CardContent>
                    </Card>
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
                                <p className="igr:text-xs igr:text-muted-foreground">{t('home.contactsHint', { customers: formatNumber(data.contacts.customers, workspace.locale) })}</p>
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
        </>
    );
}
