import { BellRing, Table2, BarChart3 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import type { TooltipContentProps } from 'recharts';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Select } from '@/components/ui/input';
import { EmptyState, ErrorState, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { Legend } from '@/features/analytics/components/charts';
import { useChartTheme } from '@/features/analytics/theme';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { formatDate, formatDateTime, formatMoney, formatNumber, formatPercent } from '@/lib/format';
import { toast } from '@/stores/toast';
import {
    type MessageChannel,
    type WatchFilters,
    type WatchKind,
    type WatchSettings,
    type WatchSummary,
    useCancelWatch,
    useSaveWatchSettings,
    useWatchSummary,
    useWatches,
} from './api';
import { CommerceNav } from './CommerceNav';

const RANGES = [30, 90, 365] as const;
const CHANNELS: MessageChannel[] = ['whatsapp', 'email', 'sms'];
const KINDS: WatchKind[] = ['back_in_stock', 'price_drop'];

/** «Avísame» (M16): quién espera qué producto, los avisos enviados y lo que se vendió después. */
export function WatchesPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const [days, setDays] = useState<number>(90);
    const summary = useWatchSummary(workspace.id, days);

    return (
        <>
            <CommerceNav />
            <PageHeader
                title={t('onsiteCommerce.watches.title')}
                description={t('onsiteCommerce.watches.description')}
                actions={
                    <Select aria-label={t('onsiteCommerce.watches.range')} value={days} onChange={(e) => setDays(Number(e.target.value))} className="igr:w-40">
                        {RANGES.map((d) => (
                            <option key={d} value={d}>
                                {t('onsiteCommerce.watches.lastDays', { count: d })}
                            </option>
                        ))}
                    </Select>
                }
            />
            {summary.isPending ? (
                <div className="igr:grid igr:gap-3">
                    <Skeleton className="igr:h-24" />
                    <Skeleton className="igr:h-64" />
                </div>
            ) : summary.isError ? (
                <ErrorState error={summary.error} onRetry={() => void summary.refetch()} />
            ) : (
                <div className="igr:space-y-5">
                    <Kpis summary={summary.data} />
                    {summary.data.totals.active + summary.data.totals.notified + summary.data.totals.cancelled === 0 ? (
                        <EmptyState icon={BellRing} title={t('onsiteCommerce.watches.empty.title')} description={t('onsiteCommerce.watches.empty.description')} />
                    ) : (
                        <>
                            <div className="igr:grid igr:gap-5 igr:xl:grid-cols-[1.4fr_1fr]">
                                <WeeklyChart summary={summary.data} />
                                <TopProducts summary={summary.data} />
                            </div>
                            <WatchList />
                        </>
                    )}
                    <SettingsCard settings={summary.data.settings} />
                </div>
            )}
        </>
    );
}

function Kpis({ summary }: { summary: WatchSummary }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const s = summary.totals;
    const tiles = [
        { label: t('onsiteCommerce.watches.kpi.active'), value: formatNumber(s.active, workspace.locale), detail: t('onsiteCommerce.watches.kpi.activeSplit', { stock: s.active_back_in_stock, price: s.active_price_drop }) },
        { label: t('onsiteCommerce.watches.kpi.notified'), value: formatNumber(s.notified_in_range, workspace.locale), detail: t('onsiteCommerce.watches.kpi.notifiedTotal', { count: s.notified }) },
        {
            label: t('onsiteCommerce.watches.kpi.buyers'),
            value: formatNumber(s.buyers_after, workspace.locale),
            detail: s.conversion_rate === null ? t('onsiteCommerce.watches.kpi.noRate') : t('onsiteCommerce.watches.kpi.rate', { rate: formatPercent(s.conversion_rate, workspace.locale, 1) }),
        },
        { label: t('onsiteCommerce.watches.kpi.revenue'), value: formatMoney(s.revenue_after, summary.currency, workspace.locale), detail: t('onsiteCommerce.watches.kpi.revenueHelp', { days: summary.sale_window_days, count: s.orders_after }) },
    ];

    return (
        <div className="igr:grid igr:grid-cols-2 igr:gap-3 igr:lg:grid-cols-4">
            {tiles.map((tile) => (
                <Card key={tile.label}>
                    <CardContent className="igr:py-4">
                        <p className="igr:text-xs igr:text-muted-foreground">{tile.label}</p>
                        <p className="igr:mt-1 igr:text-2xl igr:font-semibold igr:tracking-tight igr:tabular-nums">{tile.value}</p>
                        <p className="igr:mt-1 igr:text-xs igr:text-muted-foreground">{tile.detail}</p>
                    </CardContent>
                </Card>
            ))}
        </div>
    );
}

/** Suscripciones y avisos por semana: dos series de conteo en el mismo eje, barras agrupadas. */
function WeeklyChart({ summary }: { summary: WatchSummary }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const theme = useChartTheme();
    const [asTable, setAsTable] = useState(false);
    const series = [
        { key: 'created' as const, label: t('onsiteCommerce.watches.chart.created'), color: theme.series[0] ?? '#2a78d6' },
        { key: 'notified' as const, label: t('onsiteCommerce.watches.chart.notified'), color: theme.series[1] ?? '#eb6834' },
    ];
    const week = (iso: string) => formatDate(iso, workspace.locale);

    return (
        <Card>
            <CardHeader className="igr:flex igr:flex-row igr:items-start igr:justify-between igr:gap-2 igr:space-y-0">
                <div>
                    <CardTitle className="igr:text-sm">{t('onsiteCommerce.watches.chart.title')}</CardTitle>
                    <CardDescription className="igr:text-xs">{t('onsiteCommerce.watches.chart.help')}</CardDescription>
                </div>
                <Button variant="ghost" size="sm" onClick={() => setAsTable(!asTable)} aria-pressed={asTable}>
                    {asTable ? <BarChart3 /> : <Table2 />}
                    {asTable ? t('onsiteCommerce.watches.chart.asChart') : t('onsiteCommerce.watches.chart.asTable')}
                </Button>
            </CardHeader>
            <CardContent>
                {asTable ? (
                    <div className="igr:max-h-64 igr:overflow-auto">
                        <Table>
                            <THead>
                                <TR>
                                    <TH>{t('onsiteCommerce.watches.chart.week')}</TH>
                                    {series.map((s) => (
                                        <TH key={s.key} className="igr:text-right">
                                            {s.label}
                                        </TH>
                                    ))}
                                </TR>
                            </THead>
                            <TBody>
                                {summary.weekly.map((w) => (
                                    <TR key={w.week}>
                                        <TD>{week(w.week)}</TD>
                                        <TD className="igr:text-right igr:tabular-nums">{w.created}</TD>
                                        <TD className="igr:text-right igr:tabular-nums">{w.notified}</TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                    </div>
                ) : (
                    <>
                        <Legend items={series.map((s) => ({ label: s.label, color: s.color }))} />
                        <div className="igr:h-56" role="img" aria-label={t('onsiteCommerce.watches.chart.title')}>
                            <ResponsiveContainer width="100%" height="100%">
                                <BarChart data={summary.weekly} margin={{ top: 4, right: 4, bottom: 0, left: 0 }} barCategoryGap="24%" barGap={2}>
                                    <CartesianGrid vertical={false} stroke={theme.grid} />
                                    <XAxis dataKey="week" tickFormatter={week} tick={{ fill: theme.muted, fontSize: 11 }} tickLine={false} axisLine={{ stroke: theme.axis }} minTickGap={16} />
                                    <YAxis allowDecimals={false} width={36} tick={{ fill: theme.muted, fontSize: 11 }} tickLine={false} axisLine={false} />
                                    <Tooltip
                                        cursor={{ fill: theme.grid, opacity: 0.5 }}
                                        isAnimationActive={false}
                                        content={(props: TooltipContentProps) => {
                                            const row = props.payload[0]?.payload as WatchSummary['weekly'][number] | undefined;
                                            if (!props.active || row === undefined) return null;
                                            return (
                                                <div className="igr:min-w-40 igr:rounded-md igr:border igr:bg-popover igr:px-2.5 igr:py-2 igr:text-xs igr:shadow-md">
                                                    <p className="igr:mb-1 igr:font-medium">{t('onsiteCommerce.watches.chart.weekOf', { date: week(row.week) })}</p>
                                                    {series.map((s) => (
                                                        <div key={s.key} className="igr:flex igr:items-center igr:justify-between igr:gap-4">
                                                            <span className="igr:flex igr:items-center igr:gap-1.5 igr:text-muted-foreground">
                                                                <span className="igr:size-2 igr:rounded-full" style={{ background: s.color }} />
                                                                {s.label}
                                                            </span>
                                                            <span className="igr:font-medium igr:tabular-nums">{row[s.key]}</span>
                                                        </div>
                                                    ))}
                                                </div>
                                            );
                                        }}
                                    />
                                    {series.map((s) => (
                                        <Bar key={s.key} dataKey={s.key} name={s.label} fill={s.color} radius={[4, 4, 0, 0]} maxBarSize={18} isAnimationActive={false} />
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

function TopProducts({ summary }: { summary: WatchSummary }) {
    const { t } = useTranslation();
    return (
        <Card>
            <CardHeader>
                <CardTitle className="igr:text-sm">{t('onsiteCommerce.watches.top.title')}</CardTitle>
                <CardDescription className="igr:text-xs">{t('onsiteCommerce.watches.top.help')}</CardDescription>
            </CardHeader>
            <CardContent>
                {summary.top_products.length === 0 ? (
                    <p className="igr:py-6 igr:text-center igr:text-[13px] igr:text-muted-foreground">{t('onsiteCommerce.watches.top.empty')}</p>
                ) : (
                    <Table>
                        <THead>
                            <TR>
                                <TH>{t('onsiteCommerce.watches.top.product')}</TH>
                                <TH className="igr:text-right">{t('onsiteCommerce.watchKind.back_in_stock')}</TH>
                                <TH className="igr:text-right">{t('onsiteCommerce.watchKind.price_drop')}</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {summary.top_products.map((p) => (
                                <TR key={p.id}>
                                    <TD className="igr:max-w-48">
                                        <span className="igr:block igr:truncate">{p.name ?? p.id}</span>
                                        <span className="igr:text-[11px] igr:text-muted-foreground">{p.in_stock ? t('onsiteCommerce.stock.in') : t('onsiteCommerce.stock.out')}</span>
                                    </TD>
                                    <TD className="igr:text-right igr:tabular-nums">{p.back_in_stock}</TD>
                                    <TD className="igr:text-right igr:tabular-nums">{p.price_drop}</TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                )}
            </CardContent>
        </Card>
    );
}

function WatchList() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageMarketing');
    const [filters, setFilters] = useState<WatchFilters>({ status: 'active', kind: '', page: 1 });
    const list = useWatches(workspace.id, filters);
    const cancel = useCancelWatch(workspace.id);
    const pages = list.data ? Math.max(1, Math.ceil(list.data.total / list.data.per_page)) : 1;

    return (
        <Card>
            <CardHeader className="igr:flex igr:flex-row igr:flex-wrap igr:items-center igr:justify-between igr:gap-2 igr:space-y-0">
                <CardTitle className="igr:text-sm">{t('onsiteCommerce.watches.list.title')}</CardTitle>
                <div className="igr:flex igr:gap-2">
                    <Select aria-label={t('onsiteCommerce.watches.list.status')} value={filters.status} onChange={(e) => setFilters({ ...filters, status: e.target.value as WatchFilters['status'], page: 1 })} className="igr:w-36">
                        <option value="">{t('onsiteCommerce.watches.list.allStatuses')}</option>
                        {(['active', 'notified', 'cancelled'] as const).map((s) => (
                            <option key={s} value={s}>
                                {t(`onsiteCommerce.watchStatus.${s}`)}
                            </option>
                        ))}
                    </Select>
                    <Select aria-label={t('onsiteCommerce.watches.list.kind')} value={filters.kind} onChange={(e) => setFilters({ ...filters, kind: e.target.value as WatchFilters['kind'], page: 1 })} className="igr:w-44">
                        <option value="">{t('onsiteCommerce.watches.list.allKinds')}</option>
                        {KINDS.map((k) => (
                            <option key={k} value={k}>
                                {t(`onsiteCommerce.watchKind.${k}`)}
                            </option>
                        ))}
                    </Select>
                </div>
            </CardHeader>
            <CardContent>
                {list.isError ? (
                    <ErrorState error={list.error} onRetry={() => void list.refetch()} />
                ) : list.isPending ? (
                    <Skeleton className="igr:h-40" />
                ) : list.data.data.length === 0 ? (
                    <p className="igr:py-6 igr:text-center igr:text-[13px] igr:text-muted-foreground">{t('onsiteCommerce.watches.list.empty')}</p>
                ) : (
                    <>
                        <div className="igr:overflow-x-auto">
                            <Table>
                                <THead>
                                    <TR>
                                        <TH>{t('onsiteCommerce.watches.list.date')}</TH>
                                        <TH>{t('onsiteCommerce.watches.top.product')}</TH>
                                        <TH>{t('onsiteCommerce.watches.list.kind')}</TH>
                                        <TH>{t('onsiteCommerce.watches.list.contact')}</TH>
                                        <TH>{t('onsiteCommerce.watches.list.status')}</TH>
                                        {canManage ? <TH className="igr:w-0" /> : null}
                                    </TR>
                                </THead>
                                <TBody>
                                    {list.data.data.map((w) => (
                                        <TR key={w.id}>
                                            <TD className="igr:whitespace-nowrap igr:text-muted-foreground">{formatDate(w.created_at, workspace.locale, workspace.timezone)}</TD>
                                            <TD className="igr:max-w-56 igr:truncate">{w.product.name ?? w.product.id}</TD>
                                            <TD className="igr:whitespace-nowrap">
                                                {t(`onsiteCommerce.watchKind.${w.kind}`)}
                                                <span className="igr:ml-1 igr:text-xs igr:text-muted-foreground">· {t(`onsiteCommerce.channel.${w.channel}`)}</span>
                                            </TD>
                                            <TD>
                                                {w.contact ? (
                                                    <Link to={`/w/${workspace.id}/contactos/${w.contact.id}`} className="igr:hover:underline">
                                                        {w.contact.name ?? t('onsiteCommerce.watches.list.noName')}
                                                    </Link>
                                                ) : (
                                                    <span className="igr:text-muted-foreground">—</span>
                                                )}
                                            </TD>
                                            <TD>
                                                <Badge variant={w.status === 'notified' ? 'success' : w.status === 'active' ? 'default' : 'outline'}>{t(`onsiteCommerce.watchStatus.${w.status}`)}</Badge>
                                                <WatchDetail notifiedAt={w.notified_at} nextAttempt={w.next_attempt_at} reason={w.last_reason} />
                                            </TD>
                                            {canManage ? (
                                                <TD>
                                                    {w.status === 'active' ? (
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            loading={cancel.isPending && cancel.variables === w.id}
                                                            onClick={() => cancel.mutate(w.id, { onError: (e) => toast.error(t('errors.title'), isApiError(e) ? e.message : undefined) })}
                                                        >
                                                            {t('onsiteCommerce.watches.list.cancel')}
                                                        </Button>
                                                    ) : null}
                                                </TD>
                                            ) : null}
                                        </TR>
                                    ))}
                                </TBody>
                            </Table>
                        </div>
                        {pages > 1 ? (
                            <div className="igr:mt-3 igr:flex igr:items-center igr:justify-end igr:gap-2 igr:text-xs igr:text-muted-foreground">
                                <Button variant="outline" size="sm" disabled={filters.page <= 1} onClick={() => setFilters({ ...filters, page: filters.page - 1 })}>
                                    {t('common.previous')}
                                </Button>
                                {t('onsiteCommerce.pagination', { page: filters.page, pages })}
                                <Button variant="outline" size="sm" disabled={filters.page >= pages} onClick={() => setFilters({ ...filters, page: filters.page + 1 })}>
                                    {t('common.next')}
                                </Button>
                            </div>
                        ) : null}
                    </>
                )}
            </CardContent>
        </Card>
    );
}

const KNOWN_REASONS = ['frequency_cap', 'no_template', 'no_channel', 'no_consent', 'unsubscribed', 'suppressed', 'outside_hours', 'sunday', 'holiday', 'frequency_day', 'frequency_week', 'channel_unavailable', 'expired', 'product_gone', 'cancelled_by_team', 'disabled'];

function WatchDetail({ notifiedAt, nextAttempt, reason }: { notifiedAt: string | null; nextAttempt: string | null; reason: string | null }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    let text: string | null = null;
    if (notifiedAt) {
        text = t('onsiteCommerce.watches.list.notifiedAt', { date: formatDateTime(notifiedAt, workspace.locale, workspace.timezone) });
    } else if (reason) {
        const why = KNOWN_REASONS.includes(reason) ? t(`onsiteCommerce.reason.${reason}`) : reason;
        text = nextAttempt ? t('onsiteCommerce.watches.list.retry', { why, date: formatDateTime(nextAttempt, workspace.locale, workspace.timezone) }) : why;
    }
    return text ? <span className="igr:mt-0.5 igr:block igr:text-[11px] igr:text-muted-foreground">{text}</span> : null;
}

function SettingsCard({ settings }: { settings: WatchSettings }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageMarketing');
    const save = useSaveWatchSettings(workspace.id);
    const onError = (e: unknown) => toast.error(t('errors.title'), isApiError(e) ? e.message : undefined);
    const toggleChannel = (c: MessageChannel, on: boolean) => {
        const next = on ? [...settings.channels, c] : settings.channels.filter((x) => x !== c);
        save.mutate({ channels: CHANNELS.filter((x) => next.includes(x)) }, { onError });
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle className="igr:text-sm">{t('onsiteCommerce.watches.settings.title')}</CardTitle>
                <CardDescription>{t('onsiteCommerce.watches.settings.help')}</CardDescription>
            </CardHeader>
            <CardContent className="igr:space-y-4 igr:text-[13px]">
                <label className="igr:flex igr:items-center igr:gap-2">
                    <input type="checkbox" checked={settings.enabled} disabled={!canManage || save.isPending} onChange={(e) => save.mutate({ enabled: e.target.checked }, { onError })} />
                    {t('onsiteCommerce.watches.settings.enabled')}
                </label>
                <fieldset className="igr:space-y-1.5">
                    <legend className="igr:mb-1 igr:text-xs igr:font-medium igr:text-muted-foreground">{t('onsiteCommerce.watches.settings.channels')}</legend>
                    <div className="igr:flex igr:flex-wrap igr:gap-4">
                        {CHANNELS.map((c) => (
                            <label key={c} className="igr:flex igr:items-center igr:gap-1.5">
                                <input type="checkbox" checked={settings.channels.includes(c)} disabled={!canManage || save.isPending} onChange={(e) => toggleChannel(c, e.target.checked)} />
                                {t(`onsiteCommerce.channel.${c}`)}
                            </label>
                        ))}
                    </div>
                </fieldset>
                <div className="igr:overflow-x-auto">
                    <Table>
                        <THead>
                            <TR>
                                <TH>{t('onsiteCommerce.watches.settings.template')}</TH>
                                {CHANNELS.map((c) => (
                                    <TH key={c}>{t(`onsiteCommerce.channel.${c}`)}</TH>
                                ))}
                            </TR>
                        </THead>
                        <TBody>
                            {KINDS.map((kind) => (
                                <TR key={kind}>
                                    <TD className="igr:whitespace-nowrap igr:font-medium">{t(`onsiteCommerce.watchKind.${kind}`)}</TD>
                                    {CHANNELS.map((channel) => {
                                        const library = settings.library[kind][channel];
                                        const options = settings.available_templates.filter((tpl) => tpl.channel === channel);
                                        return (
                                            <TD key={channel} className="igr:min-w-44">
                                                <Select
                                                    aria-label={t('onsiteCommerce.watches.settings.templateFor', { kind: t(`onsiteCommerce.watchKind.${kind}`), channel: t(`onsiteCommerce.channel.${channel}`) })}
                                                    value={settings.templates[kind][channel] ?? ''}
                                                    disabled={!canManage || save.isPending}
                                                    onChange={(e) => save.mutate({ templates: { [kind]: { [channel]: e.target.value === '' ? null : e.target.value } } }, { onError })}
                                                >
                                                    <option value="">{library ? t('onsiteCommerce.watches.settings.library') : t('onsiteCommerce.watches.settings.none')}</option>
                                                    {options.map((tpl) => (
                                                        <option key={tpl.id} value={tpl.id}>
                                                            {tpl.name}
                                                        </option>
                                                    ))}
                                                </Select>
                                            </TD>
                                        );
                                    })}
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                </div>
                <p className="igr:text-xs igr:text-muted-foreground">{t('onsiteCommerce.watches.settings.whatsappNote')}</p>
            </CardContent>
        </Card>
    );
}
