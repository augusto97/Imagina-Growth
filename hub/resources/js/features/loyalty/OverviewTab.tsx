import { Table2, BarChart3 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip as RTooltip, XAxis, YAxis } from 'recharts';
import type { TooltipContentProps } from 'recharts';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatMoney, formatNumber } from '@/lib/format';
import { BarList, Legend } from '@/features/analytics/components/charts';
import { useChartTheme } from '@/features/analytics/theme';
import { useLoyaltyStats } from './api';
import type { LoyaltyOverview, MonthlyStat } from './types';

/** Resumen del programa: cifras clave, puntos por mes y miembros por nivel (skill dataviz). */
export function OverviewTab({ data }: { data: LoyaltyOverview }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const stats = useLoyaltyStats(workspace.id, 6);
    const money = (v: string | number) => formatMoney(v, data.currency, workspace.locale);
    const num = (v: number) => formatNumber(v, workspace.locale);

    if (stats.isError) {
        return <ErrorState error={stats.error} onRetry={() => void stats.refetch()} />;
    }
    if (stats.isPending) {
        return (
            <div className="igr:grid igr:gap-3 igr:md:grid-cols-4">
                {[0, 1, 2, 3].map((i) => (
                    <Skeleton key={i} className="igr:h-24" />
                ))}
                <Skeleton className="igr:h-72 igr:md:col-span-4" />
            </div>
        );
    }
    const s = stats.data;
    const tiles = [
        { key: 'members', value: num(s.members), help: t('loyalty.overview.membersHelp') },
        { key: 'outstanding', value: num(s.points_outstanding), help: t('loyalty.overview.outstandingHelp', { pending: num(s.points_pending), name: data.program.currency_name }) },
        {
            key: 'liability',
            value: s.points_liability !== null ? money(s.points_liability) : '—',
            help: s.point_value !== null ? t('loyalty.overview.liabilityHelp', { value: money(s.point_value) }) : t('loyalty.overview.liabilityNoRewards'),
        },
        { key: 'wallet', value: money(s.wallet_outstanding), help: t('loyalty.overview.walletHelp') },
        { key: 'redemptions', value: num(s.redemptions_30d), help: t('loyalty.overview.redemptionsHelp', { revenue: money(s.redeemed_orders_revenue_30d) }) },
    ];

    return (
        <div className="igr:space-y-4">
            <dl className="igr:grid igr:gap-3 igr:sm:grid-cols-2 igr:lg:grid-cols-5">
                {tiles.map((tile) => (
                    <div key={tile.key} className="igr:rounded-lg igr:border igr:bg-card igr:p-3">
                        <dt className="igr:text-xs igr:text-muted-foreground">{t(`loyalty.overview.${tile.key}`)}</dt>
                        <dd className="igr:mt-1 igr:text-xl igr:font-semibold igr:tabular-nums">{tile.value}</dd>
                        <p className="igr:mt-1 igr:text-[11.5px] igr:text-muted-foreground">{tile.help}</p>
                    </div>
                ))}
            </dl>
            <div className="igr:grid igr:items-start igr:gap-4 igr:lg:grid-cols-[minmax(0,1fr)_320px]">
                <MonthlyCard monthly={s.monthly} name={data.program.currency_name} />
                <Card>
                    <CardHeader>
                        <CardTitle>{t('loyalty.overview.byTier')}</CardTitle>
                        <CardDescription>{t('loyalty.overview.byTierHelp')}</CardDescription>
                    </CardHeader>
                    <CardContent>
                        {s.by_tier.length === 0 ? (
                            <p className="igr:py-6 igr:text-center igr:text-[13px] igr:text-muted-foreground">{t('loyalty.overview.noTiers')}</p>
                        ) : (
                            <BarList rows={s.by_tier} label={(r) => r.name} value={(r) => r.members} format={num} />
                        )}
                    </CardContent>
                </Card>
            </div>
        </div>
    );
}

const SERIES = ['issued', 'redeemed', 'expired'] as const;

function MonthlyCard({ monthly, name }: { monthly: MonthlyStat[]; name: string }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const theme = useChartTheme();
    const [asTable, setAsTable] = useState(false);
    const month = (m: string) => new Intl.DateTimeFormat(workspace.locale, { month: 'short', year: '2-digit', timeZone: 'UTC' }).format(new Date(`${m}-01T00:00:00Z`));
    const num = (v: number) => formatNumber(v, workspace.locale);
    const colors = SERIES.map((_, i) => theme.series[i] ?? theme.muted);
    const labels = SERIES.map((k) => t(`loyalty.overview.series.${k}`));
    const empty = monthly.every((m) => m.issued === 0 && m.redeemed === 0 && m.expired === 0);

    return (
        <Card>
            <CardHeader className="igr:flex-row igr:items-start igr:justify-between igr:gap-3">
                <div>
                    <CardTitle>{t('loyalty.overview.monthly', { name })}</CardTitle>
                    <CardDescription>{t('loyalty.overview.monthlyHelp')}</CardDescription>
                </div>
                <Button variant="ghost" size="sm" onClick={() => setAsTable(!asTable)} aria-pressed={asTable}>
                    {asTable ? <BarChart3 /> : <Table2 />}
                    {asTable ? t('loyalty.overview.showChart') : t('loyalty.overview.showTable')}
                </Button>
            </CardHeader>
            <CardContent>
                {empty ? (
                    <p className="igr:py-10 igr:text-center igr:text-[13px] igr:text-muted-foreground">{t('loyalty.overview.noMovements')}</p>
                ) : asTable ? (
                    <Table>
                        <THead>
                            <TR>
                                <TH>{t('loyalty.overview.month')}</TH>
                                {labels.map((l) => (
                                    <TH key={l} className="igr:text-right">
                                        {l}
                                    </TH>
                                ))}
                            </TR>
                        </THead>
                        <TBody>
                            {monthly.map((m) => (
                                <TR key={m.month}>
                                    <TD>{month(m.month)}</TD>
                                    {SERIES.map((k) => (
                                        <TD key={k} className="igr:text-right igr:tabular-nums">
                                            {num(m[k])}
                                        </TD>
                                    ))}
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                ) : (
                    <>
                        <Legend items={labels.map((label, i) => ({ label, color: colors[i] ?? theme.muted }))} />
                        <div style={{ height: 240 }} role="img" aria-label={t('loyalty.overview.monthly', { name })}>
                            <ResponsiveContainer width="100%" height="100%">
                                <BarChart data={monthly} margin={{ top: 4, right: 8, bottom: 0, left: 0 }} barCategoryGap="24%" barGap={2}>
                                    <CartesianGrid vertical={false} stroke={theme.grid} />
                                    <XAxis dataKey="month" tickFormatter={month} tick={{ fill: theme.muted, fontSize: 11 }} tickLine={false} axisLine={{ stroke: theme.axis }} />
                                    <YAxis tickFormatter={(v: number) => new Intl.NumberFormat(workspace.locale, { notation: 'compact' }).format(v)} tick={{ fill: theme.muted, fontSize: 11 }} tickLine={false} axisLine={false} width={48} allowDecimals={false} />
                                    <RTooltip
                                        cursor={{ fill: theme.grid, opacity: 0.5 }}
                                        content={(props: TooltipContentProps) => {
                                            const row = props.payload[0]?.payload as MonthlyStat | undefined;
                                            if (!props.active || row === undefined) return null;
                                            return (
                                                <div className="igr:min-w-40 igr:rounded-md igr:border igr:bg-popover igr:px-2.5 igr:py-2 igr:text-xs igr:text-popover-foreground igr:shadow-md">
                                                    <p className="igr:mb-1 igr:font-medium">{month(row.month)}</p>
                                                    {SERIES.map((k, i) => (
                                                        <div key={k} className="igr:flex igr:items-center igr:justify-between igr:gap-4">
                                                            <span className="igr:flex igr:items-center igr:gap-1.5 igr:text-muted-foreground">
                                                                <span className="igr:size-2 igr:rounded-full" style={{ background: colors[i] }} />
                                                                {labels[i]}
                                                            </span>
                                                            <span className="igr:font-medium igr:tabular-nums">{num(row[k])}</span>
                                                        </div>
                                                    ))}
                                                </div>
                                            );
                                        }}
                                    />
                                    {SERIES.map((k, i) => (
                                        <Bar key={k} dataKey={k} name={labels[i]} fill={colors[i]} maxBarSize={24} radius={[4, 4, 0, 0]} isAnimationActive={false} />
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
