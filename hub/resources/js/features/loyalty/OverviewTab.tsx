import { BarChart3, Coins, Scale, ShoppingBag, Table2, Users, Wallet } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { StatCard, StatCardSkeleton, StatGrid } from '@/components/ui/stat-card';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatMoney, formatNumber } from '@/lib/format';
import { BarList, StackedBars } from '@/features/analytics/components/charts';
import { useFormatters } from '@/features/analytics/format';
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
            <div className="igr:space-y-4">
                <StatGrid className="igr:lg:grid-cols-3 igr:xl:grid-cols-5">
                    {[0, 1, 2, 3, 4].map((i) => (
                        <StatCardSkeleton key={i} />
                    ))}
                </StatGrid>
                <Skeleton className="igr:h-80 igr:rounded-xl" />
            </div>
        );
    }
    const s = stats.data;

    return (
        <div className="igr:space-y-4">
            <StatGrid className="igr:lg:grid-cols-3 igr:xl:grid-cols-5">
                <StatCard label={t('loyalty.overview.members')} value={num(s.members)} help={t('loyalty.overview.membersHelp')} icon={Users} tone="sky" />
                <StatCard
                    label={t('loyalty.overview.redemptions')}
                    value={num(s.redemptions_30d)}
                    hint={t('loyalty.overview.redemptionsHint', { revenue: money(s.redeemed_orders_revenue_30d) })}
                    help={t('loyalty.overview.redemptionsHelp', { revenue: money(s.redeemed_orders_revenue_30d) })}
                    icon={ShoppingBag}
                    tone="emerald"
                />
                <StatCard
                    label={t('loyalty.overview.outstanding')}
                    value={num(s.points_outstanding)}
                    help={t('loyalty.overview.outstandingHelp', { pending: num(s.points_pending), name: data.program.currency_name })}
                    icon={Coins}
                    tone="amber"
                />
                <StatCard
                    label={t('loyalty.overview.liability')}
                    value={s.points_liability !== null ? money(s.points_liability) : '—'}
                    help={s.point_value !== null ? t('loyalty.overview.liabilityHelp', { value: money(s.point_value) }) : t('loyalty.overview.liabilityNoRewards')}
                    icon={Scale}
                    tone="violet"
                />
                <StatCard label={t('loyalty.overview.wallet')} value={money(s.wallet_outstanding)} help={t('loyalty.overview.walletHelp')} icon={Wallet} tone="rose" />
            </StatGrid>
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

/** Puntos por mes (dados, canjeados y vencidos), apilados: la gráfica perezosa de Resultados y su vista de tabla. */
function MonthlyCard({ monthly, name }: { monthly: MonthlyStat[]; name: string }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const fmt = useFormatters();
    const [asTable, setAsTable] = useState(false);
    const month = (m: string) => fmt.month(m);
    const num = (v: number) => formatNumber(v, workspace.locale);
    const labels = SERIES.map((k) => t(`loyalty.overview.series.${k}`));
    const empty = monthly.every((m) => m.issued === 0 && m.redeemed === 0 && m.expired === 0);

    return (
        <Card>
            <CardHeader className="igr:flex-row igr:items-start igr:justify-between igr:gap-3">
                <div className="igr:min-w-0">
                    <CardTitle>{t('loyalty.overview.monthly', { name })}</CardTitle>
                    <CardDescription>{t('loyalty.overview.monthlyHelp')}</CardDescription>
                </div>
                <Button variant="ghost" size="sm" onClick={() => setAsTable(!asTable)} aria-pressed={asTable}>
                    {asTable ? <BarChart3 aria-hidden /> : <Table2 aria-hidden />}
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
                    <StackedBars
                        data={monthly.map((m) => ({ date: `${m.month.slice(0, 7)}-01`, issued: m.issued, redeemed: m.redeemed, expired: m.expired }))}
                        series={SERIES.map((key, i) => ({ key, label: labels[i] ?? key }))}
                        unit="count"
                        granularity="month"
                        fmt={fmt}
                        height={240}
                        ariaLabel={t('loyalty.overview.monthly', { name })}
                    />
                )}
            </CardContent>
        </Card>
    );
}
