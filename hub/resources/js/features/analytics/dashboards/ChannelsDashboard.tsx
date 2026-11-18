import { Bot, Megaphone } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { ATTRIBUTION_MODELS } from '../api';
import type { ChannelsData } from '../api';
import { BarList } from '../components/charts';
import { DataTable } from '../components/DataTable';
import type { Column } from '../components/DataTable';
import { Notice, Section } from '../components/Section';
import { useExporter, useLabels } from './shared';
import type { DashboardProps } from './shared';

type ChannelRow = ChannelsData['channels'][number];
type ModelRow = ChannelsData['models'][number];
type LtvRow = ChannelsData['ltv'][number];

export function ChannelsDashboard({ response, data, fmt, base }: DashboardProps<'channels'>) {
    const { t } = useTranslation();
    const labels = useLabels();
    const exporter = useExporter(response);

    const channelColumns: Column<ChannelRow>[] = [
        { key: 'channel', label: t('analytics.col.channel'), render: (r) => labels.channel(r.channel), value: (r) => labels.channel(r.channel) },
        { key: 'sessions', label: t('analytics.metric.sessions'), align: 'right', render: (r) => fmt.number(r.sessions), value: (r) => r.sessions },
        { key: 'users', label: t('analytics.metric.users'), align: 'right', render: (r) => fmt.number(r.users), value: (r) => r.users },
        { key: 'conversion', label: t('analytics.col.conversion'), align: 'right', render: (r) => fmt.percent(r.conversion, 2), value: (r) => r.conversion },
        { key: 'orders', label: t('analytics.metric.orders'), help: t('analytics.channels.lastClickHelp'), align: 'right', render: (r) => fmt.decimal(r.orders, 1), value: (r) => r.orders },
        { key: 'revenue', label: t('analytics.metric.revenue'), help: t('analytics.channels.lastClickHelp'), align: 'right', render: (r) => fmt.money(r.revenue), value: (r) => r.revenue },
        { key: 'new_customers', label: t('analytics.metric.new_customers'), align: 'right', render: (r) => fmt.decimal(r.new_customers, 1), value: (r) => r.new_customers },
        { key: 'spend', label: t('analytics.channels.spend'), align: 'right', render: (r) => fmt.money(r.spend), value: (r) => r.spend },
        { key: 'roas', label: t('analytics.channels.roas'), help: t('analytics.channels.roasHelp'), align: 'right', render: (r) => (r.roas === null ? '—' : `${fmt.decimal(r.roas, 2)}×`), value: (r) => r.roas },
        { key: 'cac', label: t('analytics.channels.cac'), help: t('analytics.channels.cacHelp'), align: 'right', render: (r) => fmt.money(r.cac), value: (r) => r.cac },
    ];

    const modelColumns: Column<ModelRow>[] = [
        { key: 'channel', label: t('analytics.col.channel'), render: (r) => labels.channel(r.channel), value: (r) => labels.channel(r.channel) },
        ...ATTRIBUTION_MODELS.map(
            (m): Column<ModelRow> => ({ key: m, label: t(`analytics.channels.model.${m}`), help: t(`analytics.channels.modelHelp.${m}`), align: 'right', render: (r) => fmt.money(r[m]), value: (r) => r[m] }),
        ),
    ];

    const ltvColumns: Column<LtvRow>[] = [
        { key: 'channel', label: t('analytics.channels.acquisitionChannel'), render: (r) => labels.channel(r.channel), value: (r) => labels.channel(r.channel) },
        { key: 'customers', label: t('analytics.col.customers'), align: 'right', render: (r) => fmt.number(r.customers), value: (r) => r.customers },
        { key: 'ltv_90', label: t('analytics.channels.ltv90'), help: t('analytics.channels.ltvHelp', { days: 90 }), align: 'right', render: (r) => fmt.money(r.ltv_90), value: (r) => r.ltv_90 },
        { key: 'customers_90', label: t('analytics.channels.base90'), align: 'right', render: (r) => fmt.number(r.customers_90), value: (r) => r.customers_90 },
        { key: 'ltv_365', label: t('analytics.channels.ltv365'), help: t('analytics.channels.ltvHelp', { days: 365 }), align: 'right', render: (r) => fmt.money(r.ltv_365), value: (r) => r.ltv_365 },
        { key: 'customers_365', label: t('analytics.channels.base365'), align: 'right', render: (r) => fmt.number(r.customers_365), value: (r) => r.customers_365 },
    ];

    const ai = data.channels.find((c) => c.channel === 'ai_assistants');
    const totalSessions = data.channels.reduce((sum, c) => sum + c.sessions, 0);
    const byRevenue = [...data.channels].filter((c) => c.revenue > 0).sort((a, b) => b.revenue - a.revenue);

    return (
        <div className="igr:space-y-4">
            {!data.spend_available ? (
                <Notice icon={Megaphone}>
                    {t('analytics.channels.noSpend')}{' '}
                    <Link className="igr:font-medium igr:text-primary igr:hover:underline" to={`${base}/medicion`}>
                        {t('analytics.channels.goToMeasurement')}
                    </Link>
                </Notice>
            ) : null}
            <Section
                title={t('analytics.channels.title')}
                description={t('analytics.channels.description', { days: data.attribution_window_days })}
                onExport={exporter(t('analytics.channels.title'), channelColumns, data.channels)}
                chart={
                    <BarList
                        rows={byRevenue}
                        label={(r) => labels.channel(r.channel)}
                        value={(r) => r.revenue}
                        format={(v) => fmt.money(v)}
                        max={12}
                        detail={(r) => t('analytics.channels.barDetail', { sessions: fmt.number(r.sessions), orders: fmt.decimal(r.orders, 1), conversion: fmt.percent(r.conversion, 2) })}
                    />
                }
                table={<DataTable columns={channelColumns} rows={data.channels} rowKey={(r) => r.channel} />}
            />
            <Notice icon={Bot}>
                {ai && ai.sessions > 0
                    ? t('analytics.channels.ai', { sessions: fmt.number(ai.sessions), share: fmt.percent(totalSessions > 0 ? ai.sessions / totalSessions : 0, 1), revenue: fmt.money(ai.revenue) })
                    : t('analytics.channels.aiNone')}
            </Notice>
            <Section
                title={t('analytics.channels.modelsTitle')}
                description={t('analytics.channels.modelsDescription')}
                onExport={exporter(t('analytics.channels.modelsTitle'), modelColumns, data.models)}
                flush
            >
                <DataTable columns={modelColumns} rows={data.models} rowKey={(r) => r.channel} initialSort={{ key: 'last_click', dir: 'desc' }} />
            </Section>
            <Section
                title={t('analytics.channels.ltvTitle')}
                description={t('analytics.channels.ltvDescription')}
                onExport={exporter(t('analytics.channels.ltvTitle'), ltvColumns, data.ltv)}
                flush
            >
                <DataTable columns={ltvColumns} rows={data.ltv} rowKey={(r) => r.channel} />
            </Section>
        </div>
    );
}
