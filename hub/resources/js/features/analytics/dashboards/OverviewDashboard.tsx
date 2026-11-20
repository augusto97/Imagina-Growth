import { ArrowRight, Lightbulb, Truck } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from '@/lib/cn';
import { SeverityBadge } from '@/features/insights/severity';
import type { MetricUnit, OverviewData } from '../api';
import { TrendChart } from '../components/charts';
import { DataTable } from '../components/DataTable';
import { MoneyGeneratedCard } from '../components/MoneyGeneratedCard';
import type { Column } from '../components/DataTable';
import { Notice, Section } from '../components/Section';
import { StatTile, TileGrid } from '../components/StatTile';
import { compareModeOf, useExporter, useLabels } from './shared';
import type { DashboardProps } from './shared';

export const OVERVIEW_TILES: { key: string; unit: MetricUnit; better?: 'up' | 'down' | 'neutral' }[] = [
    { key: 'revenue', unit: 'money' },
    { key: 'orders', unit: 'count' },
    { key: 'aov', unit: 'money' },
    { key: 'conversion_rate', unit: 'ratio' },
    { key: 'sessions', unit: 'count' },
    { key: 'revenue_per_visitor', unit: 'money' },
    { key: 'new_customers', unit: 'count' },
    { key: 'returning_customers', unit: 'count' },
];

const TREND_METRICS: { key: 'revenue' | 'orders' | 'sessions'; unit: MetricUnit }[] = [
    { key: 'revenue', unit: 'money' },
    { key: 'orders', unit: 'count' },
    { key: 'sessions', unit: 'count' },
];

type SeriesRow = OverviewData['series'][number];

export function OverviewDashboard({ response, data, fmt, base, catalog }: DashboardProps<'overview'>) {
    const { t } = useTranslation();
    const labels = useLabels();
    const exporter = useExporter(response);
    const [metric, setMetric] = useState<(typeof TREND_METRICS)[number]>(TREND_METRICS[0] ?? { key: 'revenue', unit: 'money' });
    const mode = compareModeOf(response);

    const columns: Column<SeriesRow>[] = [
        { key: 'date', label: t('analytics.col.period'), render: (r) => fmt.bucket(r.date, response.period.granularity), value: (r) => r.date },
        { key: 'revenue', label: labels.metric('revenue'), align: 'right', render: (r) => fmt.money(r.revenue), value: (r) => r.revenue },
        { key: 'orders', label: labels.metric('orders'), align: 'right', render: (r) => fmt.number(r.orders), value: (r) => r.orders },
        { key: 'sessions', label: labels.metric('sessions'), align: 'right', render: (r) => fmt.number(r.sessions), value: (r) => r.sessions },
    ];

    return (
        <div className="igr:space-y-4">
            <TileGrid>
                {OVERVIEW_TILES.map((tile) => (
                    <StatTile
                        key={tile.key}
                        label={labels.metric(tile.key)}
                        value={data.kpis[tile.key]}
                        previous={data.compare?.[tile.key]}
                        compareMode={mode}
                        unit={tile.unit}
                        fmt={fmt}
                        help={catalog?.[tile.key]?.definition}
                        better={tile.better}
                    />
                ))}
            </TileGrid>

            {(data.kpis.cod_pending_orders ?? 0) > 0 ? (
                <Notice icon={Truck}>
                    {t('analytics.overview.codPending', {
                        count: data.kpis.cod_pending_orders ?? 0,
                        amount: fmt.money(data.kpis.cod_pending_revenue ?? 0),
                    })}
                </Notice>
            ) : null}

            <Section
                title={t('analytics.overview.trendTitle')}
                description={t('analytics.overview.trendDescription')}
                help={catalog?.[metric.key]?.definition}
                onExport={exporter(t('analytics.overview.trendTitle'), columns, data.series)}
                actions={
                    <div className="igr:mr-1 igr:flex igr:rounded-md igr:border igr:p-0.5" role="group" aria-label={t('analytics.overview.metricPicker')}>
                        {TREND_METRICS.map((m) => (
                            <button
                                key={m.key}
                                type="button"
                                aria-pressed={metric.key === m.key}
                                onClick={() => setMetric(m)}
                                className={cn(
                                    'igr:rounded igr:px-2 igr:py-0.5 igr:text-xs igr:font-medium igr:text-muted-foreground',
                                    metric.key === m.key && 'igr:bg-secondary igr:text-foreground',
                                )}
                            >
                                {labels.metric(m.key)}
                            </button>
                        ))}
                    </div>
                }
                chart={<TrendChart data={data.series} metric={metric.key} label={labels.metric(metric.key)} unit={metric.unit} granularity={response.period.granularity} fmt={fmt} />}
                table={<DataTable columns={columns} rows={data.series} rowKey={(r) => r.date} />}
            />

            <div className="igr:grid igr:gap-4 igr:lg:grid-cols-[1fr_340px]">
                <Card>
                    <CardHeader className="igr:flex igr:flex-row igr:items-center igr:justify-between igr:space-y-0">
                        <div>
                            <CardTitle className="igr:flex igr:items-center igr:gap-1.5">
                                <Lightbulb className="igr:size-4 igr:text-muted-foreground" />
                                {t('analytics.overview.insightsTitle')}
                            </CardTitle>
                            <CardDescription>{t('analytics.overview.insightsDescription')}</CardDescription>
                        </div>
                        <Button asChild variant="ghost" size="sm">
                            <Link to={`${base}/hallazgos`}>
                                {t('analytics.overview.allInsights')}
                                <ArrowRight />
                            </Link>
                        </Button>
                    </CardHeader>
                    <CardContent>
                        {data.insights.length === 0 ? (
                            <p className="igr:py-4 igr:text-[13px] igr:text-muted-foreground">{t('analytics.overview.noInsights')}</p>
                        ) : (
                            <ul className="igr:divide-y">
                                {data.insights.map((i) => (
                                    <li key={i.id}>
                                        <Link to={`${base}/hallazgos/${i.id}`} className="igr:flex igr:items-start igr:gap-3 igr:py-2.5 igr:hover:text-primary">
                                            <SeverityBadge severity={i.severity} />
                                            <span className="igr:min-w-0 igr:flex-1 igr:text-[13px]">{i.title}</span>
                                            {i.impact !== null && Number(i.impact) > 0 ? (
                                                <span className="igr:shrink-0 igr:text-xs igr:text-muted-foreground igr:tabular-nums">{fmt.money(i.impact)}</span>
                                            ) : null}
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>
                <MoneyGeneratedCard data={data.attributed} money={(v) => fmt.money(v)} base={base} />
            </div>
        </div>
    );
}
