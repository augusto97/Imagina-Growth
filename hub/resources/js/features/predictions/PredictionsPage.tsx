import { BarChart3, BrainCircuit, CircleAlert, RefreshCw, Table2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate } from 'react-router';
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import type { TooltipContentProps } from 'recharts';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ErrorState, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { Legend } from '@/features/analytics/components/charts';
import { useChartTheme } from '@/features/analytics/theme';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { formatDateTime, formatMoney, formatNumber } from '@/lib/format';
import { toast } from '@/stores/toast';
import { type Decile, type ModelRun, PRESETS, type PredictionsOverview, type Preset, reliabilityFacts, useCreatePresetSegment, usePredictions, useRunPredictions } from './api';

const MAIN: ModelRun['key'][] = ['edno', 'churn', 'clv'];

export function PredictionsPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageMarketing');
    const query = usePredictions(workspace.id);
    const run = useRunPredictions(workspace.id);
    const start = (train: boolean) =>
        run.mutate(train, {
            onSuccess: () => toast.success(t(train ? 'predictions.queuedTrain' : 'predictions.queued')),
            onError: (e) => toast.error(t('errors.title'), isApiError(e) ? e.message : undefined),
        });

    return (
        <div>
            <PageHeader
                title={t('predictions.title')}
                description={t('predictions.description')}
                actions={
                    canManage ? (
                        <>
                            <Button variant="outline" size="sm" loading={run.isPending && !run.variables} onClick={() => start(false)}>
                                <RefreshCw />
                                {t('predictions.recalculate')}
                            </Button>
                            <Button size="sm" loading={run.isPending && run.variables} onClick={() => start(true)}>
                                <BrainCircuit />
                                {t('predictions.retrain')}
                            </Button>
                        </>
                    ) : null
                }
            />
            {query.isError ? (
                <ErrorState error={query.error} onRetry={() => void query.refetch()} />
            ) : query.isPending ? (
                <div className="igr:grid igr:gap-3 igr:md:grid-cols-3">
                    {MAIN.map((k) => (
                        <Skeleton key={k} className="igr:h-44" />
                    ))}
                </div>
            ) : (
                <Overview data={query.data} />
            )}
        </div>
    );
}

function Overview({ data }: { data: PredictionsOverview }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const byKey = Object.fromEntries(data.models.map((m) => [m.key, m])) as Record<ModelRun['key'], ModelRun>;
    const clv = byKey.clv;
    const n = (v: number) => formatNumber(v, workspace.locale);

    return (
        <div className="igr:space-y-4">
            {!data.ml_configured ? (
                <p className="igr:flex igr:items-start igr:gap-2 igr:rounded-md igr:border igr:bg-muted/40 igr:px-3 igr:py-2 igr:text-xs igr:text-muted-foreground">
                    <CircleAlert className="igr:mt-0.5 igr:size-3.5 igr:shrink-0" aria-hidden />
                    {t('predictions.mlMissing')}
                </p>
            ) : null}
            <dl className="igr:grid igr:grid-cols-2 igr:gap-px igr:overflow-hidden igr:rounded-lg igr:border igr:bg-border igr:md:grid-cols-4">
                <Stat label={t('predictions.coverage.clv')} value={`${n(data.coverage.with_clv)} / ${n(data.coverage.customers)}`} />
                <Stat label={t('predictions.coverage.churn')} value={`${n(data.coverage.with_churn)} / ${n(data.coverage.customers)}`} />
                <Stat label={t('predictions.coverage.nextOrder')} value={`${n(data.coverage.with_next_order)} / ${n(data.coverage.customers)}`} />
                <Stat label={t('predictions.coverage.clvTotal')} value={formatMoney(data.distributions.clv_total, workspace.currency, workspace.locale)} />
            </dl>
            <div className="igr:grid igr:gap-3 igr:md:grid-cols-3">
                {MAIN.map((k) => (
                    <ModelCard key={k} run={byKey[k]} />
                ))}
            </div>
            <div className="igr:grid igr:gap-3 igr:lg:grid-cols-2">
                <CalibrationChart deciles={clv.status === 'completed' ? (clv.metrics.purchases_by_decile ?? []) : []} days={data.holdout_days} />
                <AliveHistogram data={data} />
            </div>
            <NextOrderTiles data={data} />
            <SegmentShortcuts />
            <section>
                <h2 className="igr:mb-2 igr:text-[13px] igr:font-semibold">{t('predictions.others')}</h2>
                <div className="igr:grid igr:gap-3 igr:md:grid-cols-2 igr:xl:grid-cols-3">
                    {(['propensity', 'affinity', 'next_product', 'price_band', 'best_hour', 'engagement'] as const).map((k) => (
                        <ModelCard key={k} run={byKey[k]} compact />
                    ))}
                </div>
            </section>
        </div>
    );
}

function Stat({ label, value }: { label: string; value: string }) {
    return (
        <div className="igr:bg-card igr:px-4 igr:py-3">
            <dt className="igr:text-xs igr:text-muted-foreground">{label}</dt>
            <dd className="igr:mt-0.5 igr:text-base igr:font-semibold igr:tabular-nums">{value}</dd>
        </div>
    );
}

const STATUS_VARIANT = { completed: 'success', heuristic: 'secondary', insufficient_data: 'outline', failed: 'destructive', never: 'outline' } as const;
const RELIABILITY_VARIANT = { high: 'success', medium: 'warning', low: 'destructive' } as const;

function ModelCard({ run, compact = false }: { run: ModelRun; compact?: boolean }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const facts = reliabilityFacts(run);

    return (
        <Card className="igr:flex igr:flex-col">
            <CardHeader className="igr:space-y-1 igr:pb-2">
                <div className="igr:flex igr:items-start igr:justify-between igr:gap-2">
                    <CardTitle className="igr:text-sm">{t(`predictions.models.${run.key}.title`)}</CardTitle>
                    <Badge variant={STATUS_VARIANT[run.status]}>{t(`predictions.status.${run.status}`)}</Badge>
                </div>
                {!compact ? <CardDescription className="igr:text-xs">{t(`predictions.models.${run.key}.help`)}</CardDescription> : null}
            </CardHeader>
            <CardContent className="igr:mt-auto igr:space-y-2 igr:text-[13px]">
                {run.reliability ? (
                    <p className="igr:flex igr:items-center igr:gap-2">
                        <span className="igr:text-xs igr:text-muted-foreground">{t('predictions.reliability.label')}</span>
                        <Badge variant={RELIABILITY_VARIANT[run.reliability]}>{t(`predictions.reliability.${run.reliability}`)}</Badge>
                    </p>
                ) : null}
                {facts ? <p>{t(`predictions.facts.${facts.key}`, formatFacts(facts.values, workspace.locale))}</p> : null}
                {run.status === 'completed' && !facts ? <p className="igr:text-muted-foreground">{t(`predictions.models.${run.key}.done`, { ...summaryValues(run) })}</p> : null}
                {run.status === 'heuristic' ? <p className="igr:text-muted-foreground">{t('predictions.heuristicHelp')}</p> : null}
                {run.reason && run.status !== 'heuristic' ? <p className="igr:text-muted-foreground">{run.reason}</p> : null}
                <p className="igr:text-xs igr:text-muted-foreground">
                    {run.trained_at ? t('predictions.trainedAt', { date: formatDateTime(run.trained_at, workspace.locale, workspace.timezone) }) : t('predictions.neverTrained')}
                </p>
            </CardContent>
        </Card>
    );
}

/** Cifras de confiabilidad con el formato del país (13,9 en es-CO), máximo un decimal. */
function formatFacts(values: Record<string, number>, locale: string): Record<string, string> {
    const fmt = new Intl.NumberFormat(locale, { maximumFractionDigits: 1 });
    return Object.fromEntries(Object.entries(values).map(([k, v]) => [k, fmt.format(v)]));
}

function summaryValues(run: ModelRun): Record<string, number | string> {
    const m = run.metrics;
    const channels = run.params?.channels ?? {};
    return {
        contacts: m.contacts ?? m.contacts_with_hour ?? 0,
        transitions: m.transitions ?? 0,
        withChannel: m.contacts_with_channel ?? 0,
        whatsapp: channels.whatsapp ?? '—',
        email: channels.email ?? '—',
    };
}

function CalibrationChart({ deciles, days }: { deciles: Decile[]; days: number }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const theme = useChartTheme();
    const [asTable, setAsTable] = useState(false);
    const series = [
        { key: 'predicted' as const, label: t('predictions.calibration.predicted'), color: theme.series[0] ?? '#2a78d6' },
        { key: 'actual' as const, label: t('predictions.calibration.actual'), color: theme.series[1] ?? '#eb6834' },
    ];
    const num = (v: number) => formatNumber(Math.round(v * 100) / 100, workspace.locale);

    return (
        <Card>
            <CardHeader className="igr:flex igr:flex-row igr:items-start igr:justify-between igr:gap-2 igr:space-y-0">
                <div>
                    <CardTitle className="igr:text-sm">{t('predictions.calibration.title')}</CardTitle>
                    <CardDescription className="igr:text-xs">{t('predictions.calibration.help', { days })}</CardDescription>
                </div>
                {deciles.length > 0 ? (
                    <Button variant="ghost" size="sm" onClick={() => setAsTable(!asTable)} aria-pressed={asTable}>
                        {asTable ? <BarChart3 /> : <Table2 />}
                        {asTable ? t('predictions.asChart') : t('predictions.asTable')}
                    </Button>
                ) : null}
            </CardHeader>
            <CardContent>
                {deciles.length === 0 ? (
                    <p className="igr:py-10 igr:text-center igr:text-[13px] igr:text-muted-foreground">{t('predictions.calibration.empty')}</p>
                ) : asTable ? (
                    <Table>
                        <THead>
                            <TR>
                                <TH>{t('predictions.calibration.decile')}</TH>
                                <TH className="igr:text-right">{t('predictions.calibration.customers')}</TH>
                                {series.map((s) => (
                                    <TH key={s.key} className="igr:text-right">
                                        {s.label}
                                    </TH>
                                ))}
                            </TR>
                        </THead>
                        <TBody>
                            {deciles.map((d) => (
                                <TR key={d.decile}>
                                    <TD>{t('predictions.calibration.decileN', { n: d.decile })}</TD>
                                    <TD className="igr:text-right igr:tabular-nums">{formatNumber(d.customers, workspace.locale)}</TD>
                                    <TD className="igr:text-right igr:tabular-nums">{num(d.predicted)}</TD>
                                    <TD className="igr:text-right igr:tabular-nums">{num(d.actual)}</TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                ) : (
                    <>
                        <Legend items={series.map((s) => ({ label: s.label, color: s.color }))} />
                        <div className="igr:h-56" role="img" aria-label={t('predictions.calibration.title')}>
                            <ResponsiveContainer width="100%" height="100%">
                                <BarChart data={deciles} margin={{ top: 4, right: 4, bottom: 0, left: 0 }} barCategoryGap="22%" barGap={2}>
                                    <CartesianGrid vertical={false} stroke={theme.grid} />
                                    <XAxis dataKey="decile" tickFormatter={(v: number) => t('predictions.calibration.decileShort', { n: v })} tick={{ fill: theme.muted, fontSize: 11 }} tickLine={false} axisLine={{ stroke: theme.axis }} />
                                    <YAxis width={36} tick={{ fill: theme.muted, fontSize: 11 }} tickLine={false} axisLine={false} tickFormatter={(v: number) => num(v)} />
                                    <Tooltip
                                        cursor={{ fill: theme.grid, opacity: 0.5 }}
                                        isAnimationActive={false}
                                        content={(props: TooltipContentProps) => {
                                            const row = props.payload[0]?.payload as Decile | undefined;
                                            if (!props.active || row === undefined) return null;
                                            return (
                                                <div className="igr:min-w-44 igr:rounded-md igr:border igr:bg-popover igr:px-2.5 igr:py-2 igr:text-xs igr:shadow-md">
                                                    <p className="igr:mb-1 igr:font-medium">
                                                        {t('predictions.calibration.decileN', { n: row.decile })} · {t('predictions.calibration.customersN', { count: row.customers })}
                                                    </p>
                                                    {series.map((s) => (
                                                        <div key={s.key} className="igr:flex igr:items-center igr:justify-between igr:gap-4">
                                                            <span className="igr:flex igr:items-center igr:gap-1.5 igr:text-muted-foreground">
                                                                <span className="igr:size-2 igr:rounded-full" style={{ background: s.color }} />
                                                                {s.label}
                                                            </span>
                                                            <span className="igr:font-medium igr:tabular-nums">{num(row[s.key])}</span>
                                                        </div>
                                                    ))}
                                                </div>
                                            );
                                        }}
                                    />
                                    {series.map((s) => (
                                        <Bar key={s.key} dataKey={s.key} name={s.label} fill={s.color} radius={[4, 4, 0, 0]} maxBarSize={16} isAnimationActive={false} />
                                    ))}
                                </BarChart>
                            </ResponsiveContainer>
                        </div>
                    </>
                )}
            </CardContent>
        </Card>
    );
}

function AliveHistogram({ data }: { data: PredictionsOverview }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const theme = useChartTheme();
    const rows = data.distributions.p_alive.map((b) => ({ ...b, label: `${Math.round(b.from * 100)}–${Math.round(b.to * 100)}%` }));
    const total = rows.reduce((sum, r) => sum + r.n, 0);

    return (
        <Card>
            <CardHeader>
                <CardTitle className="igr:text-sm">{t('predictions.alive.title')}</CardTitle>
                <CardDescription className="igr:text-xs">{t('predictions.alive.help')}</CardDescription>
            </CardHeader>
            <CardContent>
                {total === 0 ? (
                    <p className="igr:py-10 igr:text-center igr:text-[13px] igr:text-muted-foreground">{t('predictions.alive.empty')}</p>
                ) : (
                    <div className="igr:h-56" role="img" aria-label={t('predictions.alive.title')}>
                        <ResponsiveContainer width="100%" height="100%">
                            <BarChart data={rows} margin={{ top: 4, right: 4, bottom: 0, left: 0 }} barCategoryGap={2}>
                                <CartesianGrid vertical={false} stroke={theme.grid} />
                                <XAxis dataKey="label" tick={{ fill: theme.muted, fontSize: 10 }} tickLine={false} axisLine={{ stroke: theme.axis }} interval={1} />
                                <YAxis allowDecimals={false} width={40} tick={{ fill: theme.muted, fontSize: 11 }} tickLine={false} axisLine={false} />
                                <Tooltip
                                    cursor={{ fill: theme.grid, opacity: 0.5 }}
                                    isAnimationActive={false}
                                    content={(props: TooltipContentProps) => {
                                        const row = props.payload[0]?.payload as (typeof rows)[number] | undefined;
                                        if (!props.active || row === undefined) return null;
                                        return (
                                            <div className="igr:rounded-md igr:border igr:bg-popover igr:px-2.5 igr:py-2 igr:text-xs igr:shadow-md">
                                                <p className="igr:font-medium">{t('predictions.alive.bin', { range: row.label })}</p>
                                                <p className="igr:tabular-nums igr:text-muted-foreground">{t('predictions.alive.customers', { count: row.n, formatted: formatNumber(row.n, workspace.locale) })}</p>
                                            </div>
                                        );
                                    }}
                                />
                                <Bar dataKey="n" fill={theme.series[0] ?? '#2a78d6'} radius={[4, 4, 0, 0]} isAnimationActive={false} />
                            </BarChart>
                        </ResponsiveContainer>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function NextOrderTiles({ data }: { data: PredictionsOverview }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const d = data.distributions.next_order;
    return (
        <Card>
            <CardHeader className="igr:pb-2">
                <CardTitle className="igr:text-sm">{t('predictions.nextOrder.title')}</CardTitle>
            </CardHeader>
            <CardContent>
                <dl className="igr:grid igr:grid-cols-2 igr:gap-px igr:overflow-hidden igr:rounded-md igr:border igr:bg-border igr:md:grid-cols-4">
                    {(['overdue', 'next_7', 'next_30', 'later'] as const).map((k) => (
                        <Stat key={k} label={t(`predictions.nextOrder.${k}`)} value={formatNumber(d[k], workspace.locale)} />
                    ))}
                </dl>
            </CardContent>
        </Card>
    );
}

function SegmentShortcuts() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageMarketing');
    const create = useCreatePresetSegment(workspace.id);
    const navigate = useNavigate();
    const go = (preset: Preset) =>
        create.mutate(preset, {
            onSuccess: (s) => {
                toast.success(t('predictions.presets.created', { name: s.name }));
                void navigate(`/w/${workspace.id}/segmentos/${s.id}`);
            },
            onError: (e) => toast.error(t('errors.title'), isApiError(e) ? e.message : undefined),
        });
    return (
        <section>
            <h2 className="igr:mb-2 igr:text-[13px] igr:font-semibold">{t('predictions.presets.title')}</h2>
            <div className="igr:grid igr:gap-3 igr:md:grid-cols-3">
                {PRESETS.map((p) => (
                    <Card key={p}>
                        <CardContent className="igr:flex igr:h-full igr:flex-col igr:gap-2 igr:pt-4">
                            <h3 className="igr:text-[13px] igr:font-semibold">{t(`predictions.presets.${p}.title`)}</h3>
                            <p className="igr:text-xs igr:text-muted-foreground">{t(`predictions.presets.${p}.help`)}</p>
                            <div className="igr:mt-auto igr:pt-1">
                                {canManage ? (
                                    <Button size="sm" variant="outline" loading={create.isPending && create.variables === p} onClick={() => go(p)}>
                                        {t('predictions.presets.create')}
                                    </Button>
                                ) : (
                                    <Link to={`/w/${workspace.id}/segmentos`} className="igr:text-xs igr:underline">
                                        {t('predictions.presets.see')}
                                    </Link>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                ))}
            </div>
        </section>
    );
}
