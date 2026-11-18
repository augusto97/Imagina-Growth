import { useTranslation } from 'react-i18next';
import type { FunnelBreakdown, FunnelData } from '../api';
import { DataTable } from '../components/DataTable';
import type { Column } from '../components/DataTable';
import { Section } from '../components/Section';
import { StatTile, TileGrid } from '../components/StatTile';
import { useChartTheme } from '../theme';
import type { Formatters } from '../format';
import { compareModeOf, useExporter, useLabels } from './shared';
import type { DashboardProps } from './shared';

/** Embudo como barras horizontales de un solo tono, con la tasa de paso entre etapas. */
function FunnelBars({ steps, compare, fmt }: { steps: FunnelData['steps']; compare: FunnelData['compare']; fmt: Formatters }) {
    const { t } = useTranslation();
    const theme = useChartTheme();
    const top = steps[0]?.value ?? 0;
    return (
        <ol className="igr:space-y-3">
            {steps.map((s, i) => {
                const prev = compare?.[i];
                const width = top > 0 ? Math.max(0.5, (s.value / top) * 100) : 0;
                return (
                    <li key={s.step}>
                        {i > 0 ? (
                            <p className="igr:mb-1 igr:text-xs igr:text-muted-foreground">
                                {t('analytics.funnel.passRate', { rate: fmt.percent(s.rate_from_previous, 1) })}
                                {prev ? ` · ${t('analytics.funnel.before', { rate: fmt.percent(prev.rate_from_previous, 1) })}` : ''}
                            </p>
                        ) : null}
                        <div className="igr:flex igr:items-baseline igr:justify-between igr:gap-3 igr:text-[13px]">
                            <span className="igr:font-medium">{t(`analytics.funnel.step.${s.step}`)}</span>
                            <span className="igr:tabular-nums">
                                <span className="igr:font-semibold">{fmt.number(s.value)}</span>
                                <span className="igr:ml-2 igr:text-xs igr:text-muted-foreground">{fmt.percent(s.rate_from_start, 2)}</span>
                            </span>
                        </div>
                        <div className="igr:mt-1 igr:h-3 igr:w-full igr:rounded igr:bg-muted">
                            <div className="igr:h-3 igr:rounded" style={{ width: `${width}%`, background: theme.series[0] }} />
                        </div>
                    </li>
                );
            })}
        </ol>
    );
}

export function FunnelDashboard({ response, data, fmt }: DashboardProps<'funnel'>) {
    const { t } = useTranslation();
    const labels = useLabels();
    const exporter = useExporter(response);
    const mode = compareModeOf(response);
    const step = (list: FunnelData['steps'] | null, key: string) => list?.find((s) => s.step === key);
    const purchases = step(data.steps, 'purchases');
    const checkouts = step(data.steps, 'checkouts');
    const carts = step(data.steps, 'add_to_carts');

    const breakdown = (dimension: 'channel' | 'device'): Column<FunnelBreakdown>[] => [
        {
            key: 'key',
            label: t(`analytics.col.${dimension}`),
            render: (r) => (dimension === 'channel' ? labels.channel(r.key) : labels.device(r.key)),
            value: (r) => (dimension === 'channel' ? labels.channel(r.key) : labels.device(r.key)),
        },
        { key: 'sessions', label: t('analytics.funnel.step.sessions'), align: 'right', render: (r) => fmt.number(r.sessions), value: (r) => r.sessions },
        { key: 'product_views', label: t('analytics.funnel.step.product_views'), align: 'right', render: (r) => fmt.number(r.product_views), value: (r) => r.product_views },
        { key: 'add_to_carts', label: t('analytics.funnel.step.add_to_carts'), align: 'right', render: (r) => fmt.number(r.add_to_carts), value: (r) => r.add_to_carts },
        { key: 'checkouts', label: t('analytics.funnel.step.checkouts'), align: 'right', render: (r) => fmt.number(r.checkouts), value: (r) => r.checkouts },
        { key: 'purchases', label: t('analytics.funnel.step.purchases'), align: 'right', render: (r) => fmt.number(r.purchases), value: (r) => r.purchases },
        { key: 'conversion', label: t('analytics.col.conversion'), align: 'right', render: (r) => fmt.percent(r.conversion, 2), value: (r) => r.conversion },
    ];
    const channelColumns = breakdown('channel');
    const deviceColumns = breakdown('device');

    type Payment = FunnelData['by_payment_method'][number];
    const paymentColumns: Column<Payment>[] = [
        { key: 'method', label: t('analytics.col.paymentMethod'), render: (r) => labels.orNone(r.method), value: (r) => r.method },
        { key: 'created', label: t('analytics.funnel.created'), align: 'right', render: (r) => fmt.number(r.created), value: (r) => r.created },
        { key: 'paid', label: t('analytics.funnel.paid'), align: 'right', render: (r) => fmt.number(r.paid), value: (r) => r.paid },
        { key: 'paid_rate', label: t('analytics.funnel.paidRate'), help: t('analytics.funnel.paidRateHelp'), align: 'right', render: (r) => fmt.percent(r.paid_rate, 1), value: (r) => r.paid_rate },
        { key: 'cod_pending', label: t('analytics.funnel.codPending'), align: 'right', render: (r) => fmt.number(r.cod_pending), value: (r) => r.cod_pending },
        { key: 'failed', label: t('analytics.funnel.failed'), align: 'right', render: (r) => fmt.number(r.failed), value: (r) => r.failed },
    ];

    return (
        <div className="igr:space-y-4">
            <TileGrid>
                <StatTile label={t('analytics.funnel.conversion')} unit="ratio" value={purchases?.rate_from_start} previous={step(data.compare, 'purchases')?.rate_from_start} compareMode={mode} fmt={fmt} help={t('analytics.funnel.conversionHelp')} />
                <StatTile label={t('analytics.funnel.cartToCheckout')} unit="ratio" value={checkouts?.rate_from_previous} previous={step(data.compare, 'checkouts')?.rate_from_previous} compareMode={mode} fmt={fmt} />
                <StatTile label={t('analytics.funnel.checkoutToPurchase')} unit="ratio" value={purchases?.rate_from_previous} previous={step(data.compare, 'purchases')?.rate_from_previous} compareMode={mode} fmt={fmt} />
                <StatTile label={t('analytics.funnel.step.add_to_carts')} unit="count" value={carts?.value} previous={step(data.compare, 'add_to_carts')?.value} compareMode={mode} fmt={fmt} />
            </TileGrid>
            <Section title={t('analytics.funnel.title')} description={t('analytics.funnel.description')}>
                <FunnelBars steps={data.steps} compare={data.compare} fmt={fmt} />
            </Section>
            <Section title={t('analytics.funnel.byChannel')} onExport={exporter(t('analytics.funnel.byChannel'), channelColumns, data.by_channel)} flush>
                <DataTable columns={channelColumns} rows={data.by_channel} rowKey={(r) => r.key} limit={12} />
            </Section>
            <div className="igr:grid igr:gap-4 igr:xl:grid-cols-2">
                <Section title={t('analytics.funnel.byDevice')} onExport={exporter(t('analytics.funnel.byDevice'), deviceColumns, data.by_device)} flush>
                    <DataTable columns={deviceColumns} rows={data.by_device} rowKey={(r) => r.key} />
                </Section>
                <Section
                    title={t('analytics.funnel.byPayment')}
                    description={t('analytics.funnel.byPaymentDescription')}
                    onExport={exporter(t('analytics.funnel.byPayment'), paymentColumns, data.by_payment_method)}
                    flush
                >
                    <DataTable columns={paymentColumns} rows={data.by_payment_method} rowKey={(r) => r.method} />
                </Section>
            </div>
        </div>
    );
}
