import { Coins, Gift, HandCoins, ShoppingBag, UserCheck } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { EmptyState, ErrorState } from '@/components/ui/misc';
import { SegmentedControl } from '@/components/ui/segmented';
import { StatCard, StatCardSkeleton, StatGrid } from '@/components/ui/stat-card';
import { useWorkspace } from '@/hooks/useWorkspace';
import { StackedBars } from '@/features/analytics/components/charts';
import { DataTable, type Column } from '@/features/analytics/components/DataTable';
import { Section } from '@/features/analytics/components/Section';
import { useFormatters } from '@/features/analytics/format';
import { useAffiliates, useProgram, useReport } from './api';
import { isoDay } from './labels';
import type { Report } from './types';

const RANGES = [30, 90, 365] as const;

type SeriesRow = { date: string; referrals: number; affiliates: number };
type Referrer = Report['top_referrers'][number];
type Creator = Report['creators'][number];

/** Resumen de M14: ingresos por referidos y afiliados, costo por referido, quién refiere más y cada creador. */
export function ReportTab({ onConfigure }: { onConfigure: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const fmt = useFormatters();
    const [days, setDays] = useState<(typeof RANGES)[number]>(90);
    const { from, to } = useMemo(() => {
        const end = new Date();
        const start = new Date(end.getTime() - (days - 1) * 86_400_000);
        return { from: isoDay(start), to: isoDay(end) };
    }, [days]);
    const report = useReport(workspace.id, from, to);
    const program = useProgram(workspace.id);
    const affiliates = useAffiliates(workspace.id);

    if (report.isPending || program.isPending) {
        return (
            <StatGrid>
                {[0, 1, 2, 3].map((i) => (
                    <StatCardSkeleton key={i} />
                ))}
            </StatGrid>
        );
    }
    if (report.isError) {
        return <ErrorState error={report.error} onRetry={() => void report.refetch()} />;
    }
    const data = report.data;
    const k = data.kpis;
    const nothingYet = !(program.data?.exists ?? false) && (affiliates.data?.length ?? 0) === 0;
    if (nothingYet) {
        return (
            <EmptyState
                illustration="customers"
                title={t('referrals.report.emptyTitle')}
                description={t('referrals.report.emptyDescription')}
                action={
                    <Button size="lg" onClick={onConfigure}>
                        <Gift aria-hidden />
                        {t('referrals.report.configure')}
                    </Button>
                }
                steps={[t('referrals.report.emptyStep1'), t('referrals.report.emptyStep2'), t('referrals.report.emptyStep3')]}
            />
        );
    }
    const series: SeriesRow[] = data.series.points.map((p) => ({ date: p.period, referrals: Number(p.referrals), affiliates: Number(p.affiliates) }));
    const seriesColumns: Column<SeriesRow>[] = [
        { key: 'date', label: t(`referrals.report.period.${data.series.bucket}`), render: (r) => fmt.bucket(r.date, data.series.bucket), value: (r) => r.date },
        { key: 'referrals', label: t('referrals.report.series.referrals'), align: 'right', render: (r) => fmt.money(r.referrals), value: (r) => r.referrals },
        { key: 'affiliates', label: t('referrals.report.series.affiliates'), align: 'right', render: (r) => fmt.money(r.affiliates), value: (r) => r.affiliates },
    ];
    const referrerColumns: Column<Referrer>[] = [
        { key: 'name', label: t('referrals.report.referrer'), render: (r) => r.contact?.name ?? t('referrals.hiddenPerson') },
        { key: 'referrals', label: t('referrals.report.friendsWhoBought'), align: 'right', render: (r) => fmt.number(r.referrals), value: (r) => r.referrals },
        { key: 'revenue', label: t('referrals.report.revenue'), align: 'right', render: (r) => fmt.money(r.revenue), value: (r) => Number(r.revenue) },
    ];
    const creatorColumns: Column<Creator>[] = [
        { key: 'name', label: t('referrals.report.creator'), render: (r) => r.affiliate.name, value: (r) => r.affiliate.name },
        { key: 'orders', label: t('referrals.report.orders'), align: 'right', render: (r) => fmt.number(r.orders), value: (r) => r.orders },
        { key: 'revenue', label: t('referrals.report.revenue'), align: 'right', render: (r) => fmt.money(r.revenue), value: (r) => Number(r.revenue) },
        { key: 'commission', label: t('referrals.report.commission'), align: 'right', render: (r) => fmt.money(r.commission), value: (r) => Number(r.commission) },
        {
            key: 'best',
            label: t('referrals.report.bestCampaign'),
            help: t('referrals.report.bestCampaignHelp'),
            render: (r) =>
                r.best_campaign === null ? (
                    '—'
                ) : (
                    <span>
                        {r.best_campaign.campaign ?? t(`referrals.via.${r.best_campaign.via ?? 'link'}`)}
                        <span className="igr:text-muted-foreground"> · {fmt.money(r.best_campaign.revenue)}</span>
                    </span>
                ),
        },
    ];

    return (
        <div className="igr:space-y-4">
            <div className="igr:flex igr:justify-end">
                <SegmentedControl
                    size="sm"
                    label={t('referrals.report.range')}
                    value={String(days)}
                    onChange={(v) => setDays(Number(v) as (typeof RANGES)[number])}
                    options={RANGES.map((d) => ({ value: String(d), label: t('referrals.report.days', { count: d }) }))}
                />
            </div>
            <StatGrid>
                <StatCard
                    label={t('referrals.report.kpi.referralRevenue')}
                    value={fmt.money(Number(k.referral_revenue))}
                    hint={t('referrals.report.kpi.orders', { count: k.referral_orders })}
                    help={t('referrals.report.kpi.referralRevenueHelp')}
                    icon={ShoppingBag}
                    tone="emerald"
                />
                <StatCard
                    label={t('referrals.report.kpi.affiliateRevenue')}
                    value={fmt.money(Number(k.affiliate_revenue))}
                    hint={t('referrals.report.kpi.commissions', { amount: fmt.money(k.commissions) })}
                    help={t('referrals.report.kpi.affiliateRevenueHelp')}
                    icon={HandCoins}
                    tone="violet"
                />
                <StatCard
                    label={t('referrals.report.kpi.costPerReferral')}
                    value={k.cost_per_referral === null ? '—' : fmt.money(Number(k.cost_per_referral))}
                    help={t('referrals.report.kpi.costPerReferralHelp')}
                    hint={k.points_given > 0 ? t('referrals.report.kpi.points', { count: k.points_given, points: fmt.number(k.points_given) }) : undefined}
                    icon={Coins}
                    tone="amber"
                />
                <StatCard
                    label={t('referrals.report.kpi.rewarded')}
                    value={fmt.number(k.rewarded)}
                    hint={t('referrals.report.kpi.signups', { count: k.signups })}
                    help={t('referrals.report.kpi.rewardedHelp')}
                    icon={UserCheck}
                    tone="sky"
                />
            </StatGrid>
            <Section
                title={t('referrals.report.seriesTitle')}
                description={t('referrals.report.seriesDescription')}
                chart={
                    <StackedBars
                        data={series}
                        series={[
                            { key: 'referrals', label: t('referrals.report.series.referrals') },
                            { key: 'affiliates', label: t('referrals.report.series.affiliates') },
                        ]}
                        unit="money"
                        granularity={data.series.bucket}
                        fmt={fmt}
                        ariaLabel={t('referrals.report.seriesTitle')}
                    />
                }
                table={<DataTable columns={seriesColumns} rows={series} rowKey={(r) => r.date} />}
            />
            <div className="igr:grid igr:gap-4 igr:lg:grid-cols-2">
                <Section title={t('referrals.report.topReferrers')} description={t('referrals.report.topReferrersDescription')} flush>
                    <DataTable columns={referrerColumns} rows={data.top_referrers} rowKey={(r, i) => r.contact?.id ?? String(i)} empty={t('referrals.report.noReferrers')} />
                </Section>
                <Section title={t('referrals.report.creators')} description={t('referrals.report.creatorsDescription')} flush>
                    <DataTable
                        columns={creatorColumns}
                        rows={data.creators}
                        rowKey={(r) => r.affiliate.id}
                        initialSort={{ key: 'revenue', dir: 'desc' }}
                        empty={t('referrals.report.noCreators')}
                    />
                </Section>
            </div>
        </div>
    );
}
