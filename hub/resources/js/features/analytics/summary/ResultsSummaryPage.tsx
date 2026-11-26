import { ArrowRight, ArrowUpRight, BarChart3, CalendarCheck, FileChartColumn, Lightbulb, Rocket, TrendingUp } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { HeroCard } from '@/components/ui/hero-card';
import { EmptyState, ErrorState, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { lockResolution } from '@/components/layout/LockedPage';
import { navContext, SECTIONS, sectionLock, sectionName, sectionPurpose } from '@/components/layout/nav';
import { useIntentPrefetch } from '@/app/prefetch';
import { SeverityBadge } from '@/features/insights/severity';
import { useServicesResults } from '@/features/services/api';
import type { ServicesResults } from '@/features/services/types';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { cn } from '@/lib/cn';
import { useSummary, type DashboardSlug, type SummaryData } from '../api';
import { dashboardHref } from '../paths';
import { BarList, TrendChart } from '../components/charts';
import { DataTable, type Column } from '../components/DataTable';
import { FilterBar } from '../components/FilterBar';
import { Section } from '../components/Section';
import { StatTile, TileGrid } from '../components/StatTile';
import { trendConclusion, type Conclusion } from '../conclusions';
import { makeFormatters, type Formatters } from '../format';
import { useAnalyticsParams } from '../params';
import { resultsAudience, visibleDashboards } from '../results';
import { useConclusion, useLabels } from '../dashboards/shared';
import { leadSourceName, servicesDrop, servicesNarrative, storeNarrative } from './narrative';

/*
 * Resumen de Resultados (rediseño v2 §3, la entrada de la sección): lo que vendiste y lo que generó la plataforma en
 * el periodo, por estrategia y por canal, con la comparación y una frase que lo explica. En un negocio sin tienda: el
 * embudo de contactos, la primera respuesta, las citas, las cotizaciones y lo ganado con lo que trajo cada parte de la
 * plataforma (GET services/results). Mismo periodo y comparación que los tableros (la URL se comparte).
 */
export function ResultsSummaryPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canView = useCan('viewDashboards');
    const audience = resultsAudience(workspace);
    const section = SECTIONS.find((s) => s.key === 'results');
    // Sin la web conectada (o fuera del plan), toda la sección espera lo mismo: se explica en lugar de mostrar ceros.
    const lock = section ? sectionLock(section, navContext(workspace)) : null;
    const { params, update, search } = useAnalyticsParams(workspace.timezone);
    const summary = useSummary(workspace.id, `from=${params.from}&to=${params.to}&compare=${params.compare}`, canView && lock === null);
    const services = useServicesResults(workspace.id, { from: params.from, to: params.to }, canView && lock === null && audience.services);
    const base = `/w/${workspace.id}`;
    const fmt = makeFormatters(workspace.locale, summary.data?.currency ?? workspace.currency);
    const dashboards = visibleDashboards(audience);
    const firstDashboard = dashboards[0] ?? 'overview';
    // En «tienda y servicios», el bloque de servicios solo aparece si hay contactos (una tienda sin leads no lo necesita).
    const showServices = audience.services && (!audience.store || (services.data !== undefined && (services.data.leads.total > 0 || services.data.won.count > 0)));

    if (!canView) {
        return <EmptyState illustration="locked" title={t('analytics.forbidden')} />;
    }
    const purpose = audience.store ? sectionPurpose(t, 'results', workspace.vertical) : t('analytics.summary.servicesPurpose');
    const header = <PageHeader title={sectionName(t, 'results', workspace.vertical)} description={purpose} icon={TrendingUp} tone="emerald" />;
    if (lock !== null) {
        const fix = lockResolution(lock);
        return (
            <>
                {header}
                <EmptyState
                    illustration={lock.kind === 'plan' ? 'plan' : 'results'}
                    title={t(`analytics.summary.locked.${lock.kind}.title`)}
                    description={t(`analytics.summary.locked.${lock.kind}.description`)}
                    action={
                        workspace.abilities.includes(fix.ability) ? (
                            <Button asChild>
                                <Link to={`${base}/${fix.to}`}>{t(fix.labelKey)}</Link>
                            </Button>
                        ) : undefined
                    }
                    steps={[t('analytics.summary.locked.step1'), t('analytics.summary.locked.step2'), t('analytics.summary.locked.step3')]}
                />
            </>
        );
    }

    return (
        <>
            <PageHeader
                title={sectionName(t, 'results', workspace.vertical)}
                description={purpose}
                icon={TrendingUp}
                tone="emerald"
                actions={
                    <>
                        <Button asChild variant="outline">
                            <Link to={`${base}/reportes`}>
                                <FileChartColumn aria-hidden />
                                {t('analytics.summary.reports')}
                            </Link>
                        </Button>
                        <Button asChild>
                            <Link to={dashboardHref(base, firstDashboard, search)}>
                                <BarChart3 aria-hidden />
                                {t('analytics.summary.dashboards')}
                            </Link>
                        </Button>
                    </>
                }
            />
            <FilterBar params={params} update={update} period={summary.data?.period} fmt={fmt} periodOnly />
            {summary.isError ? (
                <ErrorState error={summary.error} onRetry={() => void summary.refetch()} />
            ) : summary.data === undefined || (showServices && services.isPending) ? (
                <SummarySkeleton />
            ) : (
                <div className={cn('igr:space-y-6 igr:transition-opacity', summary.isFetching && summary.isPlaceholderData && 'igr:opacity-60')} aria-busy={summary.isFetching}>
                    {audience.store || !showServices ? <StoreSummary data={summary.data.data} granularity={summary.data.period.granularity} fmt={fmt} base={base} compare={params.compare} /> : null}
                    {showServices && services.data ? <ServicesSummary results={services.data} fmt={fmt} base={base} standalone={!audience.store} /> : null}
                    {services.isError && showServices ? <ErrorState error={services.error} onRetry={() => void services.refetch()} /> : null}
                    <Recommendations data={summary.data.data} fmt={fmt} base={base} />
                    <ExploreDashboards dashboards={dashboards} base={base} search={search} />
                </div>
            )}
        </>
    );
}

function SummarySkeleton() {
    return (
        <div className="igr:space-y-6" aria-busy>
            <Skeleton className="igr:h-56 igr:rounded-2xl" />
            <Skeleton className="igr:h-32 igr:rounded-xl" />
            <div className="igr:grid igr:gap-4 igr:lg:grid-cols-2">
                <Skeleton className="igr:h-72 igr:rounded-xl" />
                <Skeleton className="igr:h-72 igr:rounded-xl" />
            </div>
        </div>
    );
}

/** Las frases del resumen, en un párrafo. */
function Sentences({ items, context }: { items: Conclusion[]; context?: string | null }) {
    const { t } = useTranslation();
    return <>{items.map((c) => t(`analytics.summary.say.${c.key}`, { ...c.values, context: context ?? undefined })).join(' ')}</>;
}

/** Cambio frente al periodo de comparación, con flecha y texto (nunca solo color). */
function Change({ now, before, fmt }: { now: number; before: number | null | undefined; fmt: Formatters }) {
    const { t } = useTranslation();
    if (before === null || before === undefined) {
        return null;
    }
    if (before <= 0) {
        return now > 0 ? <Badge variant="info">{t('analytics.summary.new')}</Badge> : null;
    }
    const change = (now - before) / before;
    if (Math.abs(change) < 0.005) {
        return <span className="igr:text-caption igr:text-muted-foreground">{t('analytics.summary.same')}</span>;
    }
    return (
        <span className={cn('igr:text-caption igr:font-medium igr:tabular-nums', change > 0 ? 'igr:text-success' : 'igr:text-destructive')}>
            {change > 0 ? '↑' : '↓'} {fmt.percent(Math.abs(change), 0)}
        </span>
    );
}

/** Una fila con barra (estrategia, canal, fuente): nombre, cifra, barra proporcional al mayor y su detalle. */
function BarRow({ name, value, ratio, detail, change, to }: { name: ReactNode; value: string; ratio: number; detail?: ReactNode; change?: ReactNode; to?: string }) {
    const intent = useIntentPrefetch();
    const label = to ? (
        <Link to={to} {...intent(to)} className="igr:group igr:inline-flex igr:min-w-0 igr:items-center igr:gap-1 igr:font-medium igr:hover:text-primary igr:focus-visible:underline igr:outline-none">
            <span className="igr:truncate">{name}</span>
            <ArrowUpRight className="igr:size-3.5 igr:shrink-0 igr:opacity-0 igr:transition-opacity igr:group-hover:opacity-100" aria-hidden />
        </Link>
    ) : (
        <span className="igr:truncate igr:font-medium">{name}</span>
    );
    return (
        <li className="igr:py-2.5">
            <div className="igr:flex igr:items-baseline igr:justify-between igr:gap-3 igr:text-[13px]">
                <span className="igr:flex igr:min-w-0 igr:items-baseline igr:gap-2">{label}</span>
                <span className="igr:flex igr:shrink-0 igr:items-baseline igr:gap-2">
                    {change}
                    <span className="igr:font-semibold igr:tabular-nums">{value}</span>
                </span>
            </div>
            <div className="igr:mt-1.5 igr:h-1.5 igr:w-full igr:rounded-full igr:bg-muted" aria-hidden>
                <div className="igr:h-1.5 igr:rounded-full igr:bg-primary" style={{ width: `${Math.max(ratio > 0 ? 2 : 0, Math.min(1, ratio) * 100)}%` }} />
            </div>
            {detail ? <p className="igr:mt-1 igr:text-caption igr:text-muted-foreground">{detail}</p> : null}
        </li>
    );
}

function StoreSummary({ data, granularity, fmt, base, compare }: { data: SummaryData; granularity: 'day' | 'week' | 'month'; fmt: Formatters; base: string; compare: string }) {
    const { t } = useTranslation();
    const labels = useLabels();
    const conclude = useConclusion();
    const canManage = useCan('manageMarketing');
    const channelName = (c: string) => t(`analytics.summary.channels.${c}`);
    const g = data.generated;
    const totals = data.sales.totals;
    const prev = data.sales.compare;
    const mode = compare === 'none' ? null : (compare as 'previous' | 'year');
    const strategies = [...data.by_strategy].filter((r) => r.revenue > 0).sort((a, b) => b.revenue - a.revenue);
    const channels = [...data.by_channel].filter((r) => r.revenue > 0).sort((a, b) => b.revenue - a.revenue);
    const maxStrategy = strategies[0]?.revenue ?? 0;
    const maxChannel = channels[0]?.revenue ?? 0;
    type Row = SummaryData['series'][number];
    const seriesColumns: Column<Row>[] = [
        { key: 'date', label: t('analytics.col.period'), render: (r) => fmt.bucket(r.date, granularity), value: (r) => r.date },
        { key: 'revenue', label: labels.metric('revenue'), align: 'right', render: (r) => fmt.money(r.revenue), value: (r) => r.revenue },
        { key: 'orders', label: labels.metric('orders'), align: 'right', render: (r) => fmt.number(r.orders), value: (r) => r.orders },
    ];

    return (
        <>
            <HeroCard
                icon={TrendingUp}
                eyebrow={t('analytics.summary.heroEyebrow')}
                value={fmt.money(g.total)}
                description={<Sentences items={storeNarrative(data, fmt, channelName)} />}
                actions={
                    g.total <= 0 ? (
                        canManage ? (
                            <Button asChild variant="brand">
                                <Link to={`${base}/estrategias`}>
                                    <Rocket aria-hidden />
                                    {t('analytics.summary.startStrategy')}
                                </Link>
                            </Button>
                        ) : null
                    ) : (
                        <Button asChild variant="outline">
                            <Link to={`${base}/estrategias`}>
                                {t('analytics.summary.seeStrategies')}
                                <ArrowRight aria-hidden />
                            </Link>
                        </Button>
                    )
                }
                aside={
                    <dl className="igr:grid igr:w-56 igr:gap-3 igr:text-right">
                        <div>
                            <dt className="igr:text-caption igr:text-muted-foreground">{t('analytics.summary.share')}</dt>
                            <dd className="igr:text-section igr:font-semibold igr:tabular-nums">{fmt.percent(g.share_pct / 100, 1)}</dd>
                        </div>
                        <div>
                            <dt className="igr:text-caption igr:text-muted-foreground">{t('analytics.summary.salesHelped')}</dt>
                            <dd className="igr:text-section igr:font-semibold igr:tabular-nums">{fmt.number(g.orders)}</dd>
                        </div>
                        {g.previous ? (
                            <div>
                                <dt className="igr:text-caption igr:text-muted-foreground">{t(`analytics.summary.before.${compare}`)}</dt>
                                <dd className="igr:text-section igr:font-semibold igr:tabular-nums">{fmt.money(g.previous.total)}</dd>
                            </div>
                        ) : null}
                    </dl>
                }
            />

            <TileGrid>
                <StatTile
                    label={labels.metric('revenue')}
                    unit="money"
                    value={totals.revenue}
                    previous={prev?.revenue}
                    compareMode={mode}
                    fmt={fmt}
                    help={t('analytics.summary.help.revenue')}
                    trend={data.series.map((r) => r.revenue)}
                />
                <StatTile label={labels.metric('orders')} unit="count" value={totals.orders} previous={prev?.orders} compareMode={mode} fmt={fmt} trend={data.series.map((r) => r.orders)} />
                <StatTile label={labels.metric('aov')} unit="money" value={totals.aov} previous={prev?.aov} compareMode={mode} fmt={fmt} help={t('analytics.summary.help.aov')} />
                <StatTile label={labels.metric('new_customers')} unit="count" value={totals.new_customers} previous={prev?.new_customers} compareMode={mode} fmt={fmt} help={t('analytics.summary.help.newCustomers')} />
            </TileGrid>

            <div className="igr:grid igr:gap-4 igr:lg:grid-cols-2">
                <Card>
                    <CardHeader>
                        <p className="igr:text-caption igr:font-medium igr:text-muted-foreground">{t('analytics.summary.byStrategy')}</p>
                        <CardTitle>
                            {strategies[0] ? t('analytics.summary.byStrategyTop', { name: strategies[0].name, share: fmt.percent(g.total > 0 ? strategies[0].revenue / g.total : 0, 0) }) : t('analytics.summary.byStrategyEmpty')}
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {strategies.length === 0 ? (
                            <p className="igr:text-support igr:text-muted-foreground">{t('analytics.summary.byStrategyHelp')}</p>
                        ) : (
                            <ul className="igr:divide-y">
                                {strategies.slice(0, 8).map((r) => (
                                    <BarRow
                                        key={r.key}
                                        name={r.name}
                                        value={fmt.money(r.revenue)}
                                        ratio={maxStrategy > 0 ? r.revenue / maxStrategy : 0}
                                        to={r.strategy ? `${base}/estrategias/${r.strategy}` : undefined}
                                        change={<Change now={r.revenue} before={r.previous} fmt={fmt} />}
                                        detail={[
                                            t('analytics.summary.sales', { count: r.orders, formatted: fmt.number(r.orders) }),
                                            r.conversions && r.conversions.count > 0
                                                ? t(`analytics.summary.conversions.${r.conversions.kind}`, { count: r.conversions.count, formatted: fmt.number(r.conversions.count), defaultValue: '' })
                                                : '',
                                            r.messages ? t('analytics.summary.messages', { count: r.messages, formatted: fmt.number(r.messages) }) : '',
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    />
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <p className="igr:text-caption igr:font-medium igr:text-muted-foreground">{t('analytics.summary.byChannel')}</p>
                        <CardTitle>
                            {channels[0] ? t('analytics.summary.byChannelTop', { channel: channelName(channels[0].channel), share: fmt.percent(g.total > 0 ? channels[0].revenue / g.total : 0, 0) }) : t('analytics.summary.byChannelEmpty')}
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {channels.length === 0 ? (
                            <p className="igr:text-support igr:text-muted-foreground">{t('analytics.summary.byChannelHelp')}</p>
                        ) : (
                            <ul className="igr:divide-y">
                                {channels.map((r) => (
                                    <BarRow
                                        key={r.channel}
                                        name={channelName(r.channel)}
                                        value={fmt.money(r.revenue)}
                                        ratio={maxChannel > 0 ? r.revenue / maxChannel : 0}
                                        change={<Change now={r.revenue} before={r.previous} fmt={fmt} />}
                                        detail={t(`analytics.summary.channelHelp.${r.channel}`)}
                                    />
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>
            </div>

            <Section
                kicker={t('analytics.summary.trend')}
                title={conclude(
                    trendConclusion(
                        labels.metric('revenue'),
                        totals.revenue,
                        prev?.revenue,
                        data.series.map((r) => ({ date: r.date, value: r.revenue })),
                        granularity,
                        (v) => fmt.money(v),
                        fmt,
                    ),
                    t('analytics.summary.trend'),
                )}
                description={t('analytics.summary.trendHelp')}
                chart={<TrendChart data={data.series} metric="revenue" label={labels.metric('revenue')} unit="money" granularity={granularity} fmt={fmt} height={220} />}
                table={<DataTable columns={seriesColumns} rows={data.series} rowKey={(r) => r.date} />}
            />
        </>
    );
}

/** Los indicadores de servicios con la unidad de cada uno (minutos, porcentaje y dinero). */
function ServicesTiles({ results, fmt }: { results: ServicesResults; fmt: Formatters }) {
    const { t } = useTranslation();
    const k = results.kpis;
    const tile = (key: keyof ServicesResults['kpis']): ReactNode => {
        const kpi = k[key];
        const unit = results.units[key];
        const value = kpi.value ?? null;
        const previous = kpi.previous ?? null;
        const label = t(`analytics.summary.services.kpi.${key}`);
        const help = t(`analytics.summary.services.kpiHelp.${key}`);
        if (unit === 'minutes') {
            return (
                <StatTile
                    key={key}
                    label={label}
                    unit="count"
                    value={value}
                    previous={previous}
                    compareMode={previous !== null ? 'previous' : null}
                    better="down"
                    fmt={fmt}
                    help={help}
                    display={value === null ? '—' : t('analytics.summary.services.minutes', { count: Math.round(value), formatted: fmt.number(Math.round(value)) })}
                />
            );
        }
        if (unit === 'percent') {
            return (
                <StatTile
                    key={key}
                    label={label}
                    unit="ratio"
                    value={value === null ? null : value / 100}
                    previous={previous === null ? null : previous / 100}
                    compareMode={previous !== null ? 'previous' : null}
                    fmt={fmt}
                    help={help}
                    display={value === null ? '—' : fmt.percent(value / 100, 1)}
                />
            );
        }
        return (
            <StatTile
                key={key}
                label={label}
                unit={unit === 'money' ? 'money' : 'count'}
                value={value}
                previous={previous}
                compareMode={previous !== null ? 'previous' : null}
                fmt={fmt}
                help={help}
            />
        );
    };
    return (
        <TileGrid className="igr:lg:grid-cols-3">
            {(['leads', 'first_response_minutes', 'appointments_booked', 'appointments_attended', 'close_rate', 'won_value'] as const).map((key) => tile(key))}
        </TileGrid>
    );
}

function ServicesSummary({ results, fmt, base, standalone }: { results: ServicesResults; fmt: Formatters; base: string; standalone: boolean }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const platform = Number(results.attribution.platform_value);
    const groups = results.attribution.groups.filter((g) => g.count > 0).sort((a, b) => Number(b.value) - Number(a.value));
    const maxGroup = Number(groups[0]?.value ?? 0);
    const stages = results.leads.funnel.stages.filter((s) => s.kind !== 'lost');
    const lost = results.leads.funnel.stages.find((s) => s.kind === 'lost');
    const top = stages[0]?.leads ?? 0;
    const appts = results.appointments;
    const quotes = results.quotes;
    const sourceName = (row: ServicesResults['leads']['by_source'][number]) =>
        leadSourceName(row, (c) => t(`analytics.summary.services.source.${c}`, { defaultValue: t(`analytics.channel.${c}`, { defaultValue: c }) }));
    type Source = ServicesResults['leads']['by_source'][number];
    const sourceColumns: Column<Source>[] = [
        { key: 'channel', label: t('analytics.summary.services.sourceCol'), render: (r) => sourceName(r), value: (r) => sourceName(r) },
        { key: 'leads', label: t('analytics.summary.services.leadsCol'), align: 'right', render: (r) => fmt.number(r.leads), value: (r) => r.leads },
        { key: 'won', label: t('analytics.summary.services.wonCol'), align: 'right', render: (r) => fmt.number(r.won), value: (r) => r.won },
        { key: 'won_value', label: t('analytics.summary.services.valueCol'), align: 'right', render: (r) => fmt.money(Number(r.won_value)), value: (r) => Number(r.won_value) },
        { key: 'conversion_rate', label: t('analytics.summary.services.rateCol'), align: 'right', render: (r) => fmt.percent(r.conversion_rate, 1), value: (r) => r.conversion_rate },
    ];
    const bestSource = [...results.leads.by_source].sort((a, b) => b.leads - a.leads)[0];
    const drop = servicesDrop(results.leads.funnel.stages, fmt);

    return (
        <>
            {standalone ? (
                <HeroCard
                    icon={CalendarCheck}
                    eyebrow={t('analytics.summary.services.heroEyebrow')}
                    value={fmt.money(platform)}
                    description={<Sentences items={servicesNarrative(results, fmt)} context={workspace.vertical} />}
                    aside={
                        <dl className="igr:grid igr:w-56 igr:gap-3 igr:text-right">
                            <div>
                                <dt className="igr:text-caption igr:text-muted-foreground">{t('analytics.summary.services.wonTotal')}</dt>
                                <dd className="igr:text-section igr:font-semibold igr:tabular-nums">{fmt.money(Number(results.won.value))}</dd>
                            </div>
                            <div>
                                <dt className="igr:text-caption igr:text-muted-foreground">{t('analytics.summary.services.wonCount', { context: workspace.vertical ?? undefined })}</dt>
                                <dd className="igr:text-section igr:font-semibold igr:tabular-nums">{fmt.number(results.won.count)}</dd>
                            </div>
                        </dl>
                    }
                    actions={
                        <Button asChild variant="outline">
                            <Link to={`${base}/leads`}>
                                {t('analytics.summary.services.openLeads')}
                                <ArrowRight aria-hidden />
                            </Link>
                        </Button>
                    }
                />
            ) : (
                <h2 className="igr:text-section igr:font-semibold">{t('analytics.summary.services.sectionTitle')}</h2>
            )}

            <ServicesTiles results={results} fmt={fmt} />

            <div className="igr:grid igr:gap-4 igr:lg:grid-cols-2">
                <Card>
                    <CardHeader>
                        <p className="igr:text-caption igr:font-medium igr:text-muted-foreground">{t('analytics.summary.services.funnel', { name: results.leads.funnel.pipeline.name })}</p>
                        <CardTitle>{drop ? t(`analytics.summary.say.${drop.key}`, drop.values) : t('analytics.summary.services.funnelEmpty')}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ol className="igr:space-y-3">
                            {stages.map((s) => (
                                <li key={s.stage}>
                                    <div className="igr:flex igr:items-baseline igr:justify-between igr:gap-3 igr:text-[13px]">
                                        <span className="igr:font-medium">{s.label}</span>
                                        <span className="igr:tabular-nums">
                                            <span className="igr:font-semibold">{fmt.number(s.leads)}</span>
                                            <span className="igr:ml-2 igr:text-caption igr:text-muted-foreground">{fmt.percent(s.rate, 0)}</span>
                                        </span>
                                    </div>
                                    <div className="igr:mt-1 igr:h-2.5 igr:w-full igr:rounded igr:bg-muted" aria-hidden>
                                        <div className={cn('igr:h-2.5 igr:rounded', s.kind === 'won' ? 'igr:bg-success' : 'igr:bg-primary')} style={{ width: `${top > 0 ? Math.max(s.leads > 0 ? 1 : 0, (s.leads / top) * 100) : 0}%` }} />
                                    </div>
                                </li>
                            ))}
                        </ol>
                        {lost && lost.leads > 0 ? <p className="igr:mt-3 igr:text-caption igr:text-muted-foreground">{t('analytics.summary.services.lost', { count: lost.leads, label: lost.label })}</p> : null}
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <p className="igr:text-caption igr:font-medium igr:text-muted-foreground">{t('analytics.summary.services.attribution')}</p>
                        <CardTitle>
                            {groups[0] && Number(groups[0].value) > 0
                                ? t('analytics.summary.services.attributionTop', { source: groups[0].label, amount: fmt.money(Number(groups[0].value)) })
                                : t('analytics.summary.services.attributionEmpty')}
                        </CardTitle>
                        <CardDescription>{t('analytics.summary.services.attributionHelp', { days: results.attribution.window_days })}</CardDescription>
                    </CardHeader>
                    <CardContent>
                        {groups.length === 0 ? (
                            <p className="igr:text-support igr:text-muted-foreground">{t('analytics.summary.services.attributionNone')}</p>
                        ) : (
                            <ul className="igr:divide-y">
                                {groups.map((g) => (
                                    <BarRow
                                        key={g.key}
                                        name={g.label}
                                        value={fmt.money(Number(g.value))}
                                        ratio={maxGroup > 0 ? Number(g.value) / maxGroup : 0}
                                        detail={t('analytics.summary.services.wonN', { count: g.count, formatted: fmt.number(g.count) })}
                                    />
                                ))}
                            </ul>
                        )}
                        {results.attribution.flows.length + results.attribution.campaigns.length > 0 ? (
                            <ul className="igr:mt-3 igr:space-y-1 igr:border-t igr:pt-3 igr:text-caption igr:text-muted-foreground">
                                {[...results.attribution.flows.map((f) => ({ ...f, to: `flujos/${f.id}` })), ...results.attribution.campaigns.map((c) => ({ ...c, to: `campanas/${c.id}` }))].map((row) => (
                                    <li key={row.id} className="igr:flex igr:justify-between igr:gap-2">
                                        <Link to={`${base}/${row.to}`} className="igr:truncate igr:hover:text-foreground igr:hover:underline">
                                            {row.name}
                                        </Link>
                                        <span className="igr:tabular-nums">{fmt.money(Number(row.value))}</span>
                                    </li>
                                ))}
                            </ul>
                        ) : null}
                    </CardContent>
                </Card>
            </div>

            <div className="igr:grid igr:gap-4 igr:lg:grid-cols-2">
                <Card>
                    <CardHeader>
                        <p className="igr:text-caption igr:font-medium igr:text-muted-foreground">{t('analytics.summary.services.appointments')}</p>
                        <CardTitle>
                            {appts.booked > 0 && appts.attendance_rate !== null
                                ? t('analytics.summary.services.attendance', { rate: fmt.percent(appts.attendance_rate, 0), noShow: fmt.number(appts.no_show), count: appts.no_show })
                                : t('analytics.summary.services.appointmentsEmpty')}
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl className="igr:grid igr:grid-cols-2 igr:gap-px igr:overflow-hidden igr:rounded-lg igr:border igr:bg-border igr:sm:grid-cols-4">
                            {(['booked', 'completed', 'no_show', 'cancelled'] as const).map((key) => (
                                <div key={key} className="igr:bg-card igr:px-3 igr:py-2.5">
                                    <dt className="igr:text-caption igr:text-muted-foreground">{t(`analytics.summary.services.appt.${key}`)}</dt>
                                    <dd className="igr:text-[17px] igr:font-semibold igr:tabular-nums">{fmt.number(appts[key])}</dd>
                                </div>
                            ))}
                        </dl>
                        {appts.upcoming > 0 ? <p className="igr:mt-2 igr:text-caption igr:text-muted-foreground">{t('analytics.summary.services.upcoming', { count: appts.upcoming })}</p> : null}
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <p className="igr:text-caption igr:font-medium igr:text-muted-foreground">{t('analytics.summary.services.quotes')}</p>
                        <CardTitle>
                            {quotes.sent > 0
                                ? t('analytics.summary.services.quotesTitle', { accepted: fmt.number(quotes.accepted), sent: fmt.number(quotes.sent), count: quotes.sent, amount: fmt.money(Number(quotes.accepted_value)) })
                                : t('analytics.summary.services.quotesEmpty')}
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl className="igr:grid igr:grid-cols-2 igr:gap-px igr:overflow-hidden igr:rounded-lg igr:border igr:bg-border igr:sm:grid-cols-4">
                            {(
                                [
                                    ['sent', fmt.number(quotes.sent)],
                                    ['from_calculator', fmt.number(quotes.from_calculator)],
                                    ['accepted', fmt.number(quotes.accepted)],
                                    ['acceptance_rate', fmt.percent(quotes.acceptance_rate, 0)],
                                ] as const
                            ).map(([key, value]) => (
                                <div key={key} className="igr:bg-card igr:px-3 igr:py-2.5">
                                    <dt className="igr:text-caption igr:text-muted-foreground">{t(`analytics.summary.services.quote.${key}`)}</dt>
                                    <dd className="igr:text-[17px] igr:font-semibold igr:tabular-nums">{value}</dd>
                                </div>
                            ))}
                        </dl>
                        {quotes.sent === 0 ? (
                            <Button asChild size="sm" variant="outline" className="igr:mt-3">
                                <Link to={`${base}/en-el-sitio/cotizador`}>{t('analytics.summary.services.setupQuotes')}</Link>
                            </Button>
                        ) : null}
                    </CardContent>
                </Card>
            </div>

            <Section
                kicker={t('analytics.summary.services.sources')}
                title={bestSource ? t('analytics.summary.services.sourcesTop', { source: sourceName(bestSource), count: bestSource.leads }) : t('analytics.summary.services.sources')}
                description={t('analytics.summary.services.sourcesHelp')}
                chart={
                    <BarList
                        rows={[...results.leads.by_source].sort((a, b) => b.leads - a.leads)}
                        label={sourceName}
                        value={(r) => r.leads}
                        format={(v) => fmt.number(v)}
                        detail={(r) => t('analytics.summary.services.sourceDetail', { won: fmt.number(r.won), value: fmt.money(Number(r.won_value)), rate: fmt.percent(r.conversion_rate, 0) })}
                    />
                }
                table={<DataTable columns={sourceColumns} rows={results.leads.by_source} rowKey={(r) => r.channel} />}
            />
        </>
    );
}

/** Las 3 recomendaciones abiertas con más dinero en juego, para pasar de ver a hacer. */
function Recommendations({ data, fmt, base }: { data: SummaryData; fmt: Formatters; base: string }) {
    const { t } = useTranslation();
    return (
        <Card>
            <CardHeader className="igr:flex igr:flex-row igr:flex-wrap igr:items-start igr:justify-between igr:gap-2 igr:space-y-0">
                <div className="igr:min-w-0 igr:flex-1 igr:basis-56">
                    <CardTitle className="igr:flex igr:items-center igr:gap-1.5">
                        <Lightbulb className="igr:size-4 igr:text-warning" aria-hidden />
                        {t('analytics.summary.recommendations')}
                    </CardTitle>
                    <CardDescription>{t('analytics.summary.recommendationsHelp')}</CardDescription>
                </div>
                <Button asChild variant="ghost" size="sm">
                    <Link to={`${base}/hallazgos`}>
                        {t('analytics.summary.allRecommendations')}
                        <ArrowRight aria-hidden />
                    </Link>
                </Button>
            </CardHeader>
            <CardContent>
                {data.insights.length === 0 ? (
                    <p className="igr:py-2 igr:text-support igr:text-muted-foreground">{t('analytics.summary.noRecommendations')}</p>
                ) : (
                    <ul className="igr:divide-y">
                        {data.insights.map((i) => (
                            <li key={i.id}>
                                <Link to={`${base}/hallazgos/${i.id}`} className="igr:flex igr:flex-wrap igr:items-start igr:gap-x-3 igr:gap-y-1 igr:py-3 igr:outline-none igr:hover:text-primary igr:focus-visible:underline">
                                    <SeverityBadge severity={i.severity} />
                                    <span className="igr:min-w-0 igr:flex-1 igr:basis-48 igr:text-[13px]">{i.title}</span>
                                    {i.impact !== null && Number(i.impact) > 0 ? (
                                        <span className="igr:shrink-0 igr:text-caption igr:text-muted-foreground igr:tabular-nums">{t('analytics.summary.atStake', { amount: fmt.money(i.impact) })}</span>
                                    ) : null}
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}

/** Los tableros que aplican al negocio, con su para qué, para seguir mirando el detalle. */
function ExploreDashboards({ dashboards, base, search }: { dashboards: DashboardSlug[]; base: string; search: string }) {
    const { t } = useTranslation();
    const intent = useIntentPrefetch();
    return (
        <section aria-labelledby="explore-title">
            <h2 id="explore-title" className="igr:mb-3 igr:text-section igr:font-semibold">
                {t('analytics.summary.explore')}
            </h2>
            <div className="igr:grid igr:grid-cols-2 igr:gap-3 igr:xl:grid-cols-3">
                {dashboards.map((d) => {
                    const to = dashboardHref(base, d, search);
                    return (
                        <Link
                            key={d}
                            to={to}
                            {...intent(to)}
                            className="igr:group igr:flex igr:min-w-0 igr:flex-col igr:rounded-xl igr:border igr:bg-card igr:p-4 igr:shadow-xs igr:transition-[box-shadow,border-color] igr:outline-none igr:hover:border-primary/25 igr:hover:shadow-md igr:focus-visible:ring-2 igr:focus-visible:ring-ring"
                        >
                            <span className="igr:flex igr:items-center igr:justify-between igr:gap-2 igr:text-[14px] igr:font-semibold">
                                {t(`analytics.dashboards.${d}`)}
                                <ArrowUpRight className="igr:size-4 igr:text-muted-foreground igr:opacity-0 igr:transition-opacity igr:group-hover:opacity-100" aria-hidden />
                            </span>
                            <span className="igr:mt-1 igr:hidden igr:text-support igr:text-muted-foreground igr:sm:block">{t(`analytics.descriptions.${d}`)}</span>
                        </Link>
                    );
                })}
            </div>
        </section>
    );
}
