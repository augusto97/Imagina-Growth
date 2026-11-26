import { MessageCircle, RadioTower, Workflow } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/misc';
import type { MessagingCounts, MessagingData } from '../api';
import { TrendChart } from '../components/charts';
import { CostNote, formatCost, formatRoi } from '../cost';
import { DataTable } from '../components/DataTable';
import type { Column } from '../components/DataTable';
import { Notice, Section } from '../components/Section';
import { StatTile, TileGrid } from '../components/StatTile';
import { messagingRevenue } from '../conclusions';
import { compareModeOf, useConclusion, useExporter } from './shared';
import type { DashboardProps } from './shared';

/* 9. Mensajería (docs/auditoria-2026-11.md F11): resultados reales de WhatsApp, correo y SMS. */

const rate = (part: number, whole: number): number | null => (whole > 0 ? part / whole : null);

/** Tasas de un canal o de un flujo: sobre lo entregado (o lo enviado si el canal no confirma entregas). */
export function messagingRates(c: MessagingCounts) {
    const base = c.delivered > 0 ? c.delivered : c.sent;
    return {
        delivery: rate(c.delivered, c.sent),
        read: rate(c.read, base),
        open: rate(c.opened, base),
        click: rate(c.clicked, base),
        reply: rate(c.replied, base),
        unsubscribe: rate(c.unsubscribed, base),
        problems: rate(c.bounced + c.failed, c.total),
    };
}

type ChannelRow = MessagingData['by_channel'][number];
type SourceRow = MessagingData['by_source'][number];

export function MessagingDashboard({ response, data, fmt, base }: DashboardProps<'messaging'>) {
    const { t } = useTranslation();
    const exporter = useExporter(response);
    const mode = compareModeOf(response);
    const conclude = useConclusion();
    const channelName = (c: string) => t(`analytics.messaging.channel.${c}`, { defaultValue: c });
    const costMeta = data.cost;

    if (data.empty) {
        return (
            <EmptyState
                icon={MessageCircle}
                title={t('analytics.messaging.emptyTitle')}
                description={t('analytics.messaging.emptyDescription')}
                action={
                    <div className="igr:flex igr:flex-wrap igr:justify-center igr:gap-2">
                        <Button asChild variant="outline">
                            <Link to={`${base}/canales`}>
                                <RadioTower />
                                {t('analytics.messaging.connectChannel')}
                            </Link>
                        </Button>
                        <Button asChild>
                            <Link to={`${base}/flujos?tab=biblioteca`}>
                                <Workflow />
                                {t('analytics.messaging.activateFlow')}
                            </Link>
                        </Button>
                    </div>
                }
            />
        );
    }

    const tot = data.totals;
    const prev = data.compare;
    const r = messagingRates(tot);
    const wa = data.by_channel.find((c) => c.channel === 'whatsapp');
    const email = data.by_channel.find((c) => c.channel === 'email');
    const pct = (v: number | null) => fmt.percent(v, 1);

    /** Lecturas solo existen en WhatsApp y aperturas solo en el correo: en las demás filas, «—». */
    const channelsOf = (row: MessagingCounts): string[] =>
        'channel' in row ? [String(row.channel)] : 'channels' in row && Array.isArray(row.channels) ? (row.channels as string[]) : [];
    const countCol = (key: keyof MessagingCounts, label: string, rateOf?: (row: MessagingCounts) => number | null, help?: string, onlyFor?: string) =>
        ({
            key,
            label,
            help,
            align: 'right' as const,
            render: (row: MessagingCounts) =>
                onlyFor && !channelsOf(row).includes(onlyFor) ? (
                    <span className="igr:text-muted-foreground">—</span>
                ) : (
                    <span className="igr:tabular-nums">
                        {fmt.number(row[key])}
                        {rateOf ? <span className="igr:ml-1 igr:text-muted-foreground">{pct(rateOf(row))}</span> : null}
                    </span>
                ),
            value: (row: MessagingCounts) => row[key],
        }) satisfies Column<MessagingCounts>;

    const metrics: Column<MessagingCounts>[] = [
        countCol('sent', t('analytics.messaging.sent')),
        countCol('delivered', t('analytics.messaging.delivered'), (row) => messagingRates(row).delivery),
        countCol('read', t('analytics.messaging.read'), (row) => messagingRates(row).read, t('analytics.messaging.readHelp'), 'whatsapp'),
        countCol('opened', t('analytics.messaging.opened'), (row) => messagingRates(row).open, data.opens_exclude_mpp ? t('analytics.messaging.openedHelp') : undefined, 'email'),
        countCol('clicked', t('analytics.messaging.clicked'), (row) => messagingRates(row).click),
        countCol('replied', t('analytics.messaging.replied')),
        countCol('unsubscribed', t('analytics.messaging.unsubscribed')),
        {
            key: 'problems',
            label: t('analytics.messaging.problems'),
            help: t('analytics.messaging.problemsHelp'),
            align: 'right',
            render: (row) => fmt.number(row.bounced + row.failed),
            value: (row) => row.bounced + row.failed,
        },
        countCol('orders', t('analytics.messaging.orders')),
        { key: 'revenue', label: t('analytics.messaging.revenue'), align: 'right', render: (row) => fmt.money(row.revenue), value: (row) => row.revenue },
        {
            key: 'cost',
            label: t('analytics.cost.costCol'),
            help: t('analytics.cost.costHelp'),
            align: 'right',
            render: (row) => <span className="igr:tabular-nums">{formatCost(row, data.cost.currency, fmt.locale)}</span>,
            value: (row) => row.cost ?? row.cost_usd,
        },
        {
            key: 'roi',
            label: t('analytics.cost.roi'),
            help: t('analytics.cost.roiHelp'),
            align: 'right',
            render: (row) => <span className="igr:tabular-nums">{formatRoi(row.roi, fmt.locale)}</span>,
            value: (row) => row.roi,
        },
        {
            key: 'cost_per_sale',
            label: t('analytics.cost.perSale'),
            align: 'right',
            render: (row) => <span className="igr:tabular-nums">{row.cost_per_sale === null ? '—' : fmt.money(row.cost_per_sale)}</span>,
            value: (row) => row.cost_per_sale,
        },
    ];
    const channelColumns: Column<ChannelRow>[] = [
        { key: 'channel', label: t('analytics.messaging.channelCol'), render: (row) => channelName(row.channel), value: (row) => channelName(row.channel) },
        ...(metrics as Column<ChannelRow>[]),
    ];
    const sourceName = (row: SourceRow) =>
        row.type === 'inbox'
            ? t('analytics.messaging.source.inbox')
            : row.type === 'other'
              ? t('analytics.messaging.source.other')
              : row.name || t('analytics.messaging.source.unnamed');
    const sourceColumns: Column<SourceRow>[] = [
        {
            key: 'name',
            label: t('analytics.messaging.sourceCol'),
            render: (row) => {
                const to = row.type === 'flow' && row.id ? `${base}/flujos/${row.id}` : row.type === 'campaign' && row.id ? `${base}/campanas/${row.id}` : null;
                return (
                    <span className="igr:block igr:min-w-40">
                        {to ? (
                            <Link to={to} className="igr:font-medium igr:hover:underline">
                                {sourceName(row)}
                            </Link>
                        ) : (
                            <span className="igr:font-medium">{sourceName(row)}</span>
                        )}
                        <span className="igr:block igr:text-xs igr:text-muted-foreground">
                            {t(`analytics.messaging.source.type.${row.type}`)}
                            {row.channels.length > 0 ? ` · ${row.channels.map(channelName).join(', ')}` : ''}
                        </span>
                    </span>
                );
            },
            value: (row) => sourceName(row),
        },
        ...(metrics as Column<SourceRow>[]),
    ];

    return (
        <div className="igr:space-y-4">
            <TileGrid>
                <StatTile
                    label={t('analytics.messaging.sent')}
                    unit="count"
                    value={tot.sent}
                    previous={prev?.sent}
                    compareMode={mode}
                    fmt={fmt}
                    help={t('analytics.messaging.sentHelp')}
                />
                <StatTile
                    label={t('analytics.messaging.deliveryRate')}
                    unit="ratio"
                    value={r.delivery}
                    previous={prev ? messagingRates(prev).delivery : null}
                    compareMode={mode}
                    fmt={fmt}
                    hint={t('analytics.messaging.countHint', { count: tot.delivered, formatted: fmt.number(tot.delivered) })}
                />
                <StatTile
                    label={t('analytics.messaging.clickRate')}
                    unit="ratio"
                    value={r.click}
                    previous={prev ? messagingRates(prev).click : null}
                    compareMode={mode}
                    fmt={fmt}
                    help={t('analytics.messaging.clickRateHelp')}
                    hint={t('analytics.messaging.clicksHint', { count: tot.clicked, formatted: fmt.number(tot.clicked) })}
                />
                <StatTile
                    label={t('analytics.messaging.revenue')}
                    unit="money"
                    value={tot.revenue}
                    previous={prev?.revenue}
                    compareMode={mode}
                    fmt={fmt}
                    help={t('analytics.messaging.revenueHelp')}
                    hint={
                        tot.sent > 0
                            ? `${t('analytics.messaging.ordersHint', { count: tot.orders, formatted: fmt.number(tot.orders) })} · ${t('analytics.messaging.perMessage', { value: fmt.money(tot.revenue / tot.sent) })}`
                            : t('analytics.messaging.ordersHint', { count: tot.orders, formatted: fmt.number(tot.orders) })
                    }
                />
            </TileGrid>
            <TileGrid className="igr:lg:grid-cols-5">
                <StatTile label={t('analytics.messaging.readRate')} unit="ratio" value={wa ? messagingRates(wa).read : null} fmt={fmt} help={t('analytics.messaging.readHelp')} />
                <StatTile
                    label={t('analytics.messaging.openRate')}
                    unit="ratio"
                    value={email ? messagingRates(email).open : null}
                    fmt={fmt}
                    help={data.opens_exclude_mpp ? t('analytics.messaging.openedHelp') : t('analytics.messaging.openedMppHelp')}
                />
                <StatTile label={t('analytics.messaging.replied')} unit="count" value={tot.replied} previous={prev?.replied} compareMode={mode} fmt={fmt} />
                <StatTile
                    label={t('analytics.messaging.unsubscribeRate')}
                    unit="ratio"
                    value={r.unsubscribe}
                    previous={prev ? messagingRates(prev).unsubscribe : null}
                    compareMode={mode}
                    fmt={fmt}
                    better="down"
                    hint={t('analytics.messaging.countHint', { count: tot.unsubscribed, formatted: fmt.number(tot.unsubscribed) })}
                />
                <StatTile
                    label={t('analytics.messaging.problems')}
                    unit="count"
                    value={tot.bounced + tot.failed}
                    previous={prev ? prev.bounced + prev.failed : null}
                    compareMode={mode}
                    fmt={fmt}
                    better="down"
                    help={t('analytics.messaging.problemsHelp')}
                />
            </TileGrid>
            <TileGrid className="igr:lg:grid-cols-3">
                <StatTile
                    label={t('analytics.cost.cost')}
                    unit="money"
                    value={tot.cost}
                    previous={prev?.cost ?? null}
                    compareMode={mode}
                    fmt={fmt}
                    better="down"
                    help={t('analytics.cost.costHelp')}
                    display={formatCost(tot, costMeta.currency, fmt.locale)}
                    hint={tot.cost === null ? t('analytics.cost.inUsd') : t('analytics.cost.estimated')}
                />
                <StatTile
                    label={t('analytics.cost.roi')}
                    unit="count"
                    value={tot.roi}
                    previous={prev?.roi ?? null}
                    compareMode={mode}
                    fmt={fmt}
                    help={t('analytics.cost.roiHelp')}
                    display={formatRoi(tot.roi, fmt.locale)}
                    hint={t('analytics.cost.estimated')}
                />
                <StatTile
                    label={t('analytics.cost.perSale')}
                    unit="money"
                    value={tot.cost_per_sale}
                    previous={prev?.cost_per_sale ?? null}
                    compareMode={mode}
                    fmt={fmt}
                    better="down"
                    help={t('analytics.cost.perSaleHelp')}
                    hint={t('analytics.cost.estimated')}
                />
            </TileGrid>
            <CostNote meta={costMeta} locale={fmt.locale} />
            <Section
                kicker={t('analytics.messaging.seriesTitle')}
                title={conclude(messagingRevenue(data.totals, fmt), t('analytics.messaging.seriesTitle'))}
                description={t('analytics.messaging.seriesDescription')}
            >
                <TrendChart data={data.series} metric="sent" label={t('analytics.messaging.sent')} unit="count" granularity={response.period.granularity} fmt={fmt} height={200} />
            </Section>
            <Section
                title={t('analytics.messaging.byChannelTitle')}
                description={t('analytics.messaging.byChannelDescription')}
                onExport={exporter(t('analytics.messaging.byChannelTitle'), channelColumns, data.by_channel)}
                flush
            >
                <DataTable columns={channelColumns} rows={data.by_channel} rowKey={(row) => row.channel} />
            </Section>
            <Section
                title={t('analytics.messaging.bySourceTitle')}
                description={t('analytics.messaging.bySourceDescription')}
                onExport={exporter(t('analytics.messaging.bySourceTitle'), sourceColumns, data.by_source)}
                flush
            >
                <DataTable columns={sourceColumns} rows={data.by_source} rowKey={(row, i) => `${row.type}-${row.id ?? i}`} limit={15} />
            </Section>
            {!data.opens_exclude_mpp ? <Notice icon={MessageCircle}>{t('analytics.messaging.openedMppHelp')}</Notice> : null}
        </div>
    );
}
