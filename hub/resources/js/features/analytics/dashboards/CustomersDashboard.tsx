import { Grid3x3 } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router';
import { EmptyState } from '@/components/ui/misc';
import { cn } from '@/lib/cn';
import { RFM_SEGMENTS } from '../api';
import type { CustomersData } from '../api';
import { Heatmap, StackedBars } from '../components/charts';
import { DataTable } from '../components/DataTable';
import type { Column } from '../components/DataTable';
import { Section } from '../components/Section';
import { StatTile, TileGrid } from '../components/StatTile';
import { useChartTheme } from '../theme';
import { useExporter } from './shared';
import type { DashboardProps } from './shared';

type SegmentRow = CustomersData['rfm']['segments'][number];
type CohortRow = CustomersData['cohorts'][number];
type NvrRow = CustomersData['new_vs_returning'][number];

/** Orden del mapa RFM: el de inteligencia.md §3, de los mejores a los perdidos. */
const MAP_ORDER = RFM_SEGMENTS;

export function CustomersDashboard({ response, data, fmt, base }: DashboardProps<'customers'>) {
    const { t } = useTranslation();
    const navigate = useNavigate();
    const theme = useChartTheme();
    const exporter = useExporter(response);
    const openSegment = (system: string) => void navigate(`${base}/segmentos?system=${encodeURIComponent(system)}`);

    const segments = MAP_ORDER.map((key) => data.rfm.segments.find((s) => s.segment === key)).filter((s): s is SegmentRow => s !== undefined);
    const rfmCustomers = segments.reduce((sum, s) => sum + s.customers, 0);
    const gridMax = Math.max(0, ...data.rfm.grid.map((g) => g.customers));
    const cohortMax = Math.max(0, ...data.cohorts.flatMap((c) => c.retention.filter((v): v is number => v !== null)));

    const segmentColumns: Column<SegmentRow>[] = [
        { key: 'segment', label: t('analytics.customers.segment'), render: (r) => t(`analytics.rfm.${r.segment}.name`), value: (r) => t(`analytics.rfm.${r.segment}.name`) },
        { key: 'customers', label: t('analytics.col.customers'), align: 'right', render: (r) => fmt.number(r.customers), value: (r) => r.customers },
        { key: 'revenue', label: t('analytics.customers.historicRevenue'), align: 'right', render: (r) => fmt.money(r.revenue), value: (r) => r.revenue },
        { key: 'revenue_share', label: t('analytics.customers.revenueShare'), align: 'right', render: (r) => fmt.percent(r.revenue_share, 1), value: (r) => r.revenue_share },
        { key: 'recency_days', label: t('analytics.customers.recency'), align: 'right', render: (r) => (r.recency_days === null ? '—' : t('analytics.customers.days', { count: r.recency_days })), value: (r) => r.recency_days },
        { key: 'avg_orders', label: t('analytics.customers.avgOrders'), align: 'right', render: (r) => fmt.decimal(r.avg_orders, 1), value: (r) => r.avg_orders },
    ];
    const cohortColumns: Column<CohortRow>[] = [
        { key: 'cohort', label: t('analytics.customers.cohort'), render: (r) => fmt.month(r.cohort), value: (r) => r.cohort },
        { key: 'customers', label: t('analytics.col.customers'), align: 'right', render: (r) => fmt.number(r.customers), value: (r) => r.customers },
        ...Array.from({ length: 11 }, (_, k): Column<CohortRow> => ({
            key: `m${k + 1}`,
            label: t('analytics.customers.monthN', { n: k + 1 }),
            align: 'right',
            render: (r) => fmt.percent(r.retention[k] ?? null, 1),
            value: (r) => r.retention[k] ?? null,
        })),
    ];
    const nvrColumns: Column<NvrRow>[] = [
        { key: 'date', label: t('analytics.col.period'), render: (r) => fmt.bucket(r.date, response.period.granularity), value: (r) => r.date },
        { key: 'new_orders', label: t('analytics.customers.newOrders'), align: 'right', render: (r) => fmt.number(r.new_orders), value: (r) => r.new_orders },
        { key: 'returning_orders', label: t('analytics.customers.returningOrders'), align: 'right', render: (r) => fmt.number(r.returning_orders), value: (r) => r.returning_orders },
        { key: 'new_revenue', label: t('analytics.customers.newRevenue'), align: 'right', render: (r) => fmt.money(r.new_revenue), value: (r) => r.new_revenue },
        { key: 'returning_revenue', label: t('analytics.customers.returningRevenue'), align: 'right', render: (r) => fmt.money(r.returning_revenue), value: (r) => r.returning_revenue },
    ];

    return (
        <div className="igr:space-y-4">
            <TileGrid>
                <StatTile label={t('analytics.customers.repurchaseRate')} unit="ratio" value={data.repurchase.rate} fmt={fmt} help={t('analytics.customers.repurchaseHelp', { months: data.repurchase.window_months })} />
                <StatTile
                    label={t('analytics.customers.daysToSecond')}
                    unit="count"
                    value={data.repurchase.median_days_to_second}
                    fmt={fmt}
                    help={t('analytics.customers.daysToSecondHelp')}
                    hint={t('analytics.customers.secondPurchases', { count: data.repurchase.second_purchases })}
                />
                <StatTile label={t('analytics.customers.top10')} unit="ratio" value={data.concentration.top10_share} fmt={fmt} help={t('analytics.customers.concentrationHelp')} />
                <StatTile label={t('analytics.customers.top20')} unit="ratio" value={data.concentration.top20_share} fmt={fmt} hint={t('analytics.customers.buyers', { count: data.concentration.customers })} />
            </TileGrid>

            <Section
                title={t('analytics.customers.rfmTitle')}
                description={data.rfm.computed_at ? t('analytics.customers.rfmComputed', { date: fmt.date(data.rfm.computed_at) }) : t('analytics.customers.rfmDescription')}
                help={t('analytics.customers.rfmHelp')}
                onExport={exporter(t('analytics.customers.rfmTitle'), segmentColumns, segments)}
                chart={
                    rfmCustomers === 0 ? (
                        <EmptyState icon={Grid3x3} title={t('analytics.customers.rfmEmpty')} description={t('analytics.customers.rfmEmptyHelp')} />
                    ) : (
                        <div className="igr:grid igr:gap-2 igr:sm:grid-cols-2 igr:lg:grid-cols-4">
                            {segments.map((s) => (
                                <button
                                    key={s.segment}
                                    type="button"
                                    onClick={() => openSegment(s.system_segment)}
                                    className={cn('igr:rounded-md igr:border igr:p-3 igr:text-left igr:transition-colors igr:hover:border-ring igr:hover:bg-muted/40', s.customers === 0 && 'igr:opacity-60')}
                                    title={t('analytics.customers.openSegment')}
                                >
                                    <div className="igr:flex igr:items-baseline igr:justify-between igr:gap-2">
                                        <span className="igr:text-[13px] igr:font-medium">{t(`analytics.rfm.${s.segment}.name`)}</span>
                                        <span className="igr:text-[13px] igr:font-semibold igr:tabular-nums">{fmt.number(s.customers)}</span>
                                    </div>
                                    <p className="igr:mt-0.5 igr:line-clamp-2 igr:text-xs igr:text-muted-foreground">{t(`analytics.rfm.${s.segment}.description`)}</p>
                                    <div className="igr:mt-2 igr:h-1.5 igr:w-full igr:rounded-full igr:bg-muted" aria-hidden>
                                        <div className="igr:h-1.5 igr:rounded-full" style={{ width: `${Math.max(s.revenue_share > 0 ? 2 : 0, s.revenue_share * 100)}%`, background: theme.series[0] }} />
                                    </div>
                                    <p className="igr:mt-1 igr:text-[11px] igr:text-muted-foreground igr:tabular-nums">
                                        {t('analytics.customers.shareOfRevenue', { share: fmt.percent(s.revenue_share, 1) })}
                                    </p>
                                </button>
                            ))}
                        </div>
                    )
                }
                table={<DataTable columns={segmentColumns} rows={segments} rowKey={(r) => r.segment} onRowClick={(r) => openSegment(r.system_segment)} />}
            />

            <div className="igr:grid igr:gap-4 igr:xl:grid-cols-[380px_1fr]">
                <Section title={t('analytics.customers.gridTitle')} description={t('analytics.customers.gridDescription')}>
                    <Heatmap
                        corner={t('analytics.customers.gridCorner')}
                        rows={[5, 4, 3, 2, 1].map((f) => ({ key: String(f), label: `F${f}` }))}
                        columns={[1, 2, 3, 4, 5].map((r) => ({ key: String(r), label: `R${r}` }))}
                        cell={(f, r) => {
                            const g = data.rfm.grid.find((x) => String(x.r) === r && String(x.f) === f);
                            if (!g) return { t: 0, text: '0', detail: t('analytics.customers.gridCell', { r, f, count: 0 }) };
                            return { t: gridMax > 0 ? g.customers / gridMax : 0, text: fmt.compactNumber(g.customers), detail: t('analytics.customers.gridCell', { r, f, count: g.customers }) };
                        }}
                    />
                </Section>
                <Section
                    title={t('analytics.customers.cohortsTitle')}
                    description={t('analytics.customers.cohortsDescription')}
                    onExport={exporter(t('analytics.customers.cohortsTitle'), cohortColumns, data.cohorts)}
                    chart={
                        data.cohorts.length === 0 ? (
                            <p className="igr:py-6 igr:text-center igr:text-[13px] igr:text-muted-foreground">{t('analytics.table.empty')}</p>
                        ) : (
                            <Heatmap
                                corner={t('analytics.customers.cohort')}
                                rows={data.cohorts.map((c) => ({ key: c.cohort, label: fmt.month(c.cohort), sub: t('analytics.customers.buyers', { count: c.customers }) }))}
                                columns={Array.from({ length: 11 }, (_, k) => ({ key: String(k), label: `M${k + 1}` }))}
                                cell={(cohort, k) => {
                                    const row = data.cohorts.find((c) => c.cohort === cohort);
                                    const v = row?.retention[Number(k)] ?? null;
                                    if (v === null || row === undefined) return null;
                                    return {
                                        t: cohortMax > 0 ? v / cohortMax : 0,
                                        text: fmt.percent(v, 1),
                                        detail: t('analytics.customers.cohortCell', { cohort: fmt.month(cohort), n: Number(k) + 1, rate: fmt.percent(v, 1), size: fmt.number(row.customers) }),
                                    };
                                }}
                            />
                        )
                    }
                    table={<DataTable columns={cohortColumns} rows={data.cohorts} rowKey={(r) => r.cohort} />}
                />
            </div>

            <Section
                title={t('analytics.customers.nvrTitle')}
                description={t('analytics.customers.nvrDescription')}
                onExport={exporter(t('analytics.customers.nvrTitle'), nvrColumns, data.new_vs_returning)}
                chart={
                    <StackedBars
                        data={data.new_vs_returning}
                        series={[
                            { key: 'new_revenue', label: t('analytics.customers.newRevenue') },
                            { key: 'returning_revenue', label: t('analytics.customers.returningRevenue') },
                        ]}
                        unit="money"
                        granularity={response.period.granularity}
                        fmt={fmt}
                        ariaLabel={t('analytics.customers.nvrTitle')}
                    />
                }
                table={<DataTable columns={nvrColumns} rows={data.new_vs_returning} rowKey={(r) => r.date} />}
            />
        </div>
    );
}
