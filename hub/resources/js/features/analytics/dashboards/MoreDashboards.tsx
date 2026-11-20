import { Clock, MessageCircle, PackageSearch, Search, Sparkles, Ticket, Wallet } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { EmptyState } from '@/components/ui/misc';
import type { CouponsData, GeographyData, HdyhauData, SearchTerm, ServicesData } from '../api';
import { BarList, TrendChart } from '../components/charts';
import { DataTable } from '../components/DataTable';
import type { Column } from '../components/DataTable';
import { Notice, Section } from '../components/Section';
import { StatTile, TileGrid } from '../components/StatTile';
import { useExporter, useLabels } from './shared';
import type { DashboardProps } from './shared';

/* 6. Búsquedas */
export function SearchesDashboard({ response, data, fmt }: DashboardProps<'searches'>) {
    const { t } = useTranslation();
    const exporter = useExporter(response);
    if (data.total_searches === 0) {
        return <EmptyState icon={Search} title={t('analytics.searches.empty')} description={t('analytics.searches.emptyHelp')} />;
    }
    const columns: Column<SearchTerm>[] = [
        { key: 'query', label: t('analytics.searches.term'), render: (r) => r.query, value: (r) => r.query },
        { key: 'searches', label: t('analytics.searches.searches'), align: 'right', render: (r) => fmt.number(r.searches), value: (r) => r.searches },
        { key: 'sessions', label: t('analytics.metric.sessions'), align: 'right', render: (r) => fmt.number(r.sessions), value: (r) => r.sessions },
        { key: 'zero_results', label: t('analytics.searches.zeroResults'), align: 'right', render: (r) => fmt.number(r.zero_results), value: (r) => r.zero_results },
        { key: 'avg_results', label: t('analytics.searches.avgResults'), align: 'right', render: (r) => fmt.decimal(r.avg_results, 1), value: (r) => r.avg_results },
        { key: 'conversion', label: t('analytics.col.conversion'), help: t('analytics.searches.conversionHelp'), align: 'right', render: (r) => fmt.percent(r.conversion, 1), value: (r) => r.conversion },
    ];
    return (
        <div className="igr:space-y-4">
            <TileGrid>
                <StatTile label={t('analytics.searches.total')} unit="count" value={data.total_searches} fmt={fmt} />
                <StatTile label={t('analytics.searches.zeroShare')} unit="ratio" value={data.zero_result_share} fmt={fmt} better="down" help={t('analytics.searches.zeroShareHelp')} />
            </TileGrid>
            <Section title={t('analytics.searches.title')} onExport={exporter(t('analytics.searches.title'), columns, data.terms)} flush>
                <DataTable columns={columns} rows={data.terms} rowKey={(r) => r.query} limit={20} />
            </Section>
            <Section
                title={t('analytics.searches.zeroTitle')}
                description={t('analytics.searches.zeroDescription')}
                onExport={exporter(t('analytics.searches.zeroTitle'), columns, data.zero_results)}
                flush
            >
                <DataTable columns={columns} rows={data.zero_results} rowKey={(r) => r.query} empty={t('analytics.searches.zeroNone')} />
            </Section>
        </div>
    );
}

/* 7. Geografía */
export function GeographyDashboard({ response, data, fmt }: DashboardProps<'geography'>) {
    const { t } = useTranslation();
    const labels = useLabels();
    const exporter = useExporter(response);
    type Region = GeographyData['regions'][number];
    type City = GeographyData['cities'][number];
    const regionColumns: Column<Region>[] = [
        { key: 'region', label: t('analytics.geo.region'), render: (r) => labels.orNone(r.region), value: (r) => r.region },
        { key: 'orders', label: t('analytics.metric.orders'), align: 'right', render: (r) => fmt.number(r.orders), value: (r) => r.orders },
        { key: 'revenue', label: t('analytics.metric.revenue'), align: 'right', render: (r) => fmt.money(r.revenue), value: (r) => r.revenue },
        { key: 'aov', label: t('analytics.metric.aov'), align: 'right', render: (r) => fmt.money(r.aov), value: (r) => r.aov },
    ];
    const cityColumns: Column<City>[] = [
        { key: 'city', label: t('analytics.geo.city'), render: (r) => labels.orNone(r.city), value: (r) => r.city },
        { key: 'region', label: t('analytics.geo.region'), render: (r) => labels.orNone(r.region), value: (r) => r.region },
        { key: 'orders', label: t('analytics.metric.orders'), align: 'right', render: (r) => fmt.number(r.orders), value: (r) => r.orders },
        { key: 'revenue', label: t('analytics.metric.revenue'), align: 'right', render: (r) => fmt.money(r.revenue), value: (r) => r.revenue },
        { key: 'sessions', label: t('analytics.metric.sessions'), align: 'right', render: (r) => fmt.number(r.sessions), value: (r) => r.sessions },
        { key: 'conversion', label: t('analytics.col.conversion'), align: 'right', render: (r) => fmt.percent(r.conversion, 2), value: (r) => r.conversion },
    ];
    return (
        <div className="igr:grid igr:gap-4 igr:xl:grid-cols-[1fr_1.4fr]">
            <Section
                title={t('analytics.geo.regionsTitle')}
                description={t('analytics.geo.regionsDescription')}
                onExport={exporter(t('analytics.geo.regionsTitle'), regionColumns, data.regions)}
                chart={<BarList rows={data.regions} label={(r) => labels.orNone(r.region)} value={(r) => r.revenue} format={(v) => fmt.money(v)} max={15} detail={(r) => t('analytics.geo.regionDetail', { orders: fmt.number(r.orders), aov: fmt.money(r.aov) })} />}
                table={<DataTable columns={regionColumns} rows={data.regions} rowKey={(r) => r.region} />}
            />
            <Section title={t('analytics.geo.citiesTitle')} description={t('analytics.geo.citiesDescription')} onExport={exporter(t('analytics.geo.citiesTitle'), cityColumns, data.cities)} flush>
                <DataTable columns={cityColumns} rows={data.cities} rowKey={(r) => `${r.city}-${r.region}`} limit={20} />
            </Section>
        </div>
    );
}

/* 8. Cupones */
export function CouponsDashboard({ response, data, fmt }: DashboardProps<'coupons'>) {
    const { t } = useTranslation();
    const exporter = useExporter(response);
    type Code = CouponsData['codes'][number];
    const codeColumns: Column<Code>[] = [
        { key: 'code', label: t('analytics.coupons.code'), render: (r) => <code className="igr:text-xs">{r.code}</code>, value: (r) => r.code },
        { key: 'orders', label: t('analytics.metric.orders'), align: 'right', render: (r) => fmt.number(r.orders), value: (r) => r.orders },
        { key: 'discount', label: t('analytics.coupons.discount'), align: 'right', render: (r) => fmt.money(r.discount), value: (r) => r.discount },
        { key: 'revenue', label: t('analytics.metric.revenue'), align: 'right', render: (r) => fmt.money(r.revenue), value: (r) => r.revenue },
    ];
    const trend = data.trend.map((r) => ({ ...r, date: `${r.month}-01` }));
    type Trend = (typeof trend)[number];
    const trendColumns: Column<Trend>[] = [
        { key: 'date', label: t('analytics.col.period'), render: (r) => fmt.month(r.month), value: (r) => r.month },
        { key: 'orders', label: t('analytics.metric.orders'), align: 'right', render: (r) => fmt.number(r.orders), value: (r) => r.orders },
        { key: 'coupon_share', label: t('analytics.coupons.share'), align: 'right', render: (r) => fmt.percent(r.coupon_share, 1), value: (r) => r.coupon_share },
        { key: 'discount', label: t('analytics.coupons.discount'), align: 'right', render: (r) => fmt.money(r.discount), value: (r) => r.discount },
    ];
    if (data.orders === 0) {
        return <EmptyState icon={Ticket} title={t('analytics.empty.title')} description={t('analytics.empty.description')} />;
    }
    return (
        <div className="igr:space-y-4">
            <TileGrid>
                <StatTile label={t('analytics.coupons.share')} unit="ratio" value={data.coupon_share} fmt={fmt} hint={t('analytics.coupons.ordersWith', { count: data.orders_with_coupon })} />
                <StatTile label={t('analytics.coupons.discount')} unit="money" value={data.discount} fmt={fmt} />
                <StatTile label={t('analytics.coupons.aovWith')} unit="money" value={data.aov_with_coupon} fmt={fmt} />
                <StatTile label={t('analytics.coupons.aovWithout')} unit="money" value={data.aov_without_coupon} fmt={fmt} />
            </TileGrid>
            <Notice icon={Sparkles}>
                {t('analytics.coupons.dependency', {
                    only: fmt.number(data.dependency.only_with_discount),
                    repeat: fmt.number(data.dependency.repeat_customers),
                    never: fmt.number(data.dependency.never_use_coupons),
                })}{' '}
                {t('analytics.coupons.incrementalSoon', { phase: data.incremental.phase ?? 2 })}
            </Notice>
            <div className="igr:grid igr:gap-4 igr:xl:grid-cols-2">
                <Section title={t('analytics.coupons.codesTitle')} onExport={exporter(t('analytics.coupons.codesTitle'), codeColumns, data.codes)} flush>
                    <DataTable columns={codeColumns} rows={data.codes} rowKey={(r) => r.code} limit={15} empty={t('analytics.coupons.none')} />
                </Section>
                <Section
                    title={t('analytics.coupons.trendTitle')}
                    description={t('analytics.coupons.trendDescription')}
                    onExport={exporter(t('analytics.coupons.trendTitle'), trendColumns, trend)}
                    chart={<TrendChart data={trend} metric="coupon_share" label={t('analytics.coupons.share')} unit="ratio" granularity="month" fmt={fmt} height={220} />}
                    table={<DataTable columns={trendColumns} rows={trend} rowKey={(r) => r.month} />}
                />
            </div>
        </div>
    );
}

/* 9. Mensajería: dashboards/MessagingDashboard.tsx */

/* 10. Rentabilidad */
export function ProfitabilityDashboard({ response, data, fmt }: DashboardProps<'profitability'>) {
    const { t } = useTranslation();
    const exporter = useExporter(response);
    if (!data.available) {
        return <EmptyState icon={Wallet} title={t('analytics.profit.noCosts')} description={t('analytics.profit.noCostsHelp')} />;
    }
    type Product = (typeof data.products)[number];
    const columns: Column<Product>[] = [
        { key: 'name', label: t('analytics.col.product'), render: (r) => r.name, value: (r) => r.name },
        { key: 'units', label: t('analytics.products.units'), align: 'right', render: (r) => fmt.decimal(r.units, 0), value: (r) => r.units },
        { key: 'revenue', label: t('analytics.metric.revenue'), align: 'right', render: (r) => fmt.money(r.revenue), value: (r) => r.revenue },
        { key: 'cost', label: t('analytics.profit.cost'), align: 'right', render: (r) => fmt.money(r.cost), value: (r) => r.cost },
        { key: 'margin', label: t('analytics.profit.margin'), align: 'right', render: (r) => fmt.money(r.margin), value: (r) => r.margin },
        { key: 'margin_rate', label: t('analytics.profit.marginRate'), align: 'right', render: (r) => fmt.percent(r.margin_rate, 1), value: (r) => r.margin_rate },
    ];
    const platforms = Object.entries(data.ad_spend);
    return (
        <div className="igr:space-y-4">
            <TileGrid>
                <StatTile label={t('analytics.profit.grossMargin')} unit="money" value={data.gross_margin} fmt={fmt} help={t('analytics.profit.grossMarginHelp')} />
                <StatTile label={t('analytics.profit.marginRate')} unit="ratio" value={data.gross_margin_rate} fmt={fmt} />
                <StatTile label={t('analytics.profit.perOrder')} unit="money" value={data.avg_margin_per_order} fmt={fmt} />
                <StatTile label={t('analytics.profit.coverage')} unit="ratio" value={data.coverage} fmt={fmt} help={t('analytics.profit.coverageHelp')} />
            </TileGrid>
            <Section title={t('analytics.profit.adsTitle')} description={t('analytics.profit.adsDescription')}>
                {platforms.length === 0 ? (
                    <p className="igr:text-[13px] igr:text-muted-foreground">{t('analytics.profit.noSpend')}</p>
                ) : (
                    <div className="igr:space-y-3">
                        <p className="igr:text-[13px]">
                            {t('analytics.profit.mer')}: <span className="igr:font-semibold igr:tabular-nums">{data.mer === null ? '—' : `${fmt.decimal(data.mer, 2)}×`}</span>
                        </p>
                        <ul className="igr:grid igr:gap-2 igr:sm:grid-cols-3">
                            {platforms.map(([platform, spend]) => (
                                <li key={platform} className="igr:rounded-md igr:border igr:p-3 igr:text-[13px]">
                                    <p className="igr:font-medium">{t(`analytics.platform.${platform}`, { defaultValue: platform })}</p>
                                    <p className="igr:text-muted-foreground igr:tabular-nums">
                                        {t('analytics.profit.spendRoas', { spend: fmt.money(spend), roas: data.roas_by_platform[platform] == null ? '—' : `${fmt.decimal(data.roas_by_platform[platform], 2)}×` })}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </Section>
            <Section title={t('analytics.profit.productsTitle')} onExport={exporter(t('analytics.profit.productsTitle'), columns, data.products)} flush>
                <DataTable columns={columns} rows={data.products} rowKey={(r) => r.product_id} limit={20} />
            </Section>
        </div>
    );
}

/* 11. Servicios (sitios de leads) */
export function ServicesDashboard({ response, data, fmt }: DashboardProps<'services'>) {
    const { t } = useTranslation();
    const labels = useLabels();
    const exporter = useExporter(response);
    type Form = ServicesData['forms'][number];
    type ByChannel = ServicesData['by_channel'][number];
    type Page = ServicesData['pages'][number];
    const submissions = data.forms.reduce((sum, f) => sum + f.submissions, 0);
    const clicks = (kind: string) => data.cta_clicks.filter((c) => c.kind === kind).reduce((sum, c) => sum + c.clicks, 0);
    if (submissions === 0 && data.cta_clicks.length === 0 && data.pages.length === 0) {
        return <EmptyState icon={PackageSearch} title={t('analytics.services.empty')} description={t('analytics.services.emptyHelp')} />;
    }
    const formColumns: Column<Form>[] = [
        { key: 'form', label: t('analytics.services.form'), render: (r) => labels.orNone(r.form), value: (r) => r.form },
        { key: 'plugin', label: t('analytics.services.plugin'), render: (r) => labels.orNone(r.plugin), value: (r) => r.plugin },
        { key: 'page', label: t('analytics.services.page'), render: (r) => <span className="igr:font-mono igr:text-xs">{r.page}</span>, value: (r) => r.page },
        { key: 'submissions', label: t('analytics.services.submissions'), align: 'right', render: (r) => fmt.number(r.submissions), value: (r) => r.submissions },
    ];
    const channelColumns: Column<ByChannel>[] = [
        { key: 'channel', label: t('analytics.col.channel'), render: (r) => labels.channel(r.channel), value: (r) => labels.channel(r.channel) },
        { key: 'forms', label: t('analytics.services.submissions'), align: 'right', render: (r) => fmt.number(r.forms), value: (r) => r.forms },
        { key: 'whatsapp', label: t('analytics.services.whatsapp'), align: 'right', render: (r) => fmt.number(r.whatsapp), value: (r) => r.whatsapp },
        { key: 'phone', label: t('analytics.services.phone'), align: 'right', render: (r) => fmt.number(r.phone), value: (r) => r.phone },
    ];
    const pageColumns: Column<Page>[] = [
        { key: 'path', label: t('analytics.services.page'), render: (r) => <span className="igr:font-mono igr:text-xs">{r.path}</span>, value: (r) => r.path },
        { key: 'views', label: t('analytics.services.views'), align: 'right', render: (r) => fmt.number(r.views), value: (r) => r.views },
        { key: 'forms', label: t('analytics.services.submissions'), align: 'right', render: (r) => fmt.number(r.forms), value: (r) => r.forms },
        { key: 'whatsapp', label: t('analytics.services.whatsapp'), align: 'right', render: (r) => fmt.number(r.whatsapp), value: (r) => r.whatsapp },
        { key: 'calls', label: t('analytics.services.phone'), align: 'right', render: (r) => fmt.number(r.calls), value: (r) => r.calls },
        { key: 'form_rate', label: t('analytics.services.formRate'), align: 'right', render: (r) => fmt.percent(r.form_rate, 1), value: (r) => r.form_rate },
    ];
    return (
        <div className="igr:space-y-4">
            <TileGrid>
                <StatTile label={t('analytics.services.submissions')} unit="count" value={submissions} fmt={fmt} />
                <StatTile label={t('analytics.services.whatsapp')} unit="count" value={clicks('whatsapp')} fmt={fmt} />
                <StatTile label={t('analytics.services.phone')} unit="count" value={clicks('phone')} fmt={fmt} />
                <StatTile label={t('analytics.services.email')} unit="count" value={clicks('email')} fmt={fmt} />
            </TileGrid>
            <Notice icon={Clock}>{t('analytics.services.responseSoon', { phase: data.response_time.phase ?? 4 })}</Notice>
            <div className="igr:grid igr:gap-4 igr:xl:grid-cols-2">
                <Section title={t('analytics.services.formsTitle')} onExport={exporter(t('analytics.services.formsTitle'), formColumns, data.forms)} flush>
                    <DataTable columns={formColumns} rows={data.forms} rowKey={(r, i) => `${r.form}-${r.page}-${i}`} limit={10} />
                </Section>
                <Section title={t('analytics.services.channelsTitle')} onExport={exporter(t('analytics.services.channelsTitle'), channelColumns, data.by_channel)} flush>
                    <DataTable columns={channelColumns} rows={data.by_channel} rowKey={(r) => r.channel} />
                </Section>
            </div>
            <Section title={t('analytics.services.pagesTitle')} description={t('analytics.services.pagesDescription')} onExport={exporter(t('analytics.services.pagesTitle'), pageColumns, data.pages)} flush>
                <DataTable columns={pageColumns} rows={data.pages} rowKey={(r) => r.path} limit={15} />
            </Section>
        </div>
    );
}

/* 12. ¿Cómo nos conociste? */
export function HdyhauDashboard({ response, data, fmt }: DashboardProps<'hdyhau'>) {
    const { t } = useTranslation();
    const labels = useLabels();
    const exporter = useExporter(response);
    if (data.responses === 0) {
        return <EmptyState icon={MessageCircle} title={t('analytics.hdyhau.empty')} description={t('analytics.hdyhau.emptyHelp')} />;
    }
    type Answer = HdyhauData['answers'][number];
    const answerColumns: Column<Answer>[] = [
        { key: 'answer', label: t('analytics.hdyhau.answer'), render: (r) => labels.orNone(r.answer), value: (r) => r.answer },
        { key: 'responses', label: t('analytics.hdyhau.responses'), align: 'right', render: (r) => fmt.number(r.responses), value: (r) => r.responses },
        { key: 'share', label: t('analytics.hdyhau.share'), align: 'right', render: (r) => fmt.percent(r.share, 1), value: (r) => r.share },
        { key: 'revenue', label: t('analytics.hdyhau.revenue'), align: 'right', render: (r) => fmt.money(r.revenue), value: (r) => r.revenue },
    ];
    // Matriz respuesta × canal registrado (lo que la gente dice frente a lo que mide el sitio).
    const channels = [...new Set(data.matrix.map((m) => m.channel))];
    type MatrixRow = { answer: string } & Record<string, number | string>;
    const matrixRows: MatrixRow[] = data.answers.map((a) => {
        const row: MatrixRow = { answer: a.answer };
        for (const c of channels) row[c] = data.matrix.find((m) => m.answer === a.answer && m.channel === c)?.responses ?? 0;
        return row;
    });
    const matrixColumns: Column<MatrixRow>[] = [
        { key: 'answer', label: t('analytics.hdyhau.answer'), render: (r) => labels.orNone(r.answer), value: (r) => r.answer },
        ...channels.map((c): Column<MatrixRow> => ({ key: c, label: labels.channel(c), align: 'right', render: (r) => fmt.number(Number(r[c] ?? 0)), value: (r) => Number(r[c] ?? 0) })),
    ];
    return (
        <div className="igr:space-y-4">
            <TileGrid>
                <StatTile label={t('analytics.hdyhau.responses')} unit="count" value={data.responses} fmt={fmt} />
                <StatTile label={t('analytics.hdyhau.rate')} unit="ratio" value={data.response_rate} fmt={fmt} help={t('analytics.hdyhau.rateHelp')} />
            </TileGrid>
            <Section
                title={t('analytics.hdyhau.answersTitle')}
                onExport={exporter(t('analytics.hdyhau.answersTitle'), answerColumns, data.answers)}
                chart={<BarList rows={data.answers} label={(r) => labels.orNone(r.answer)} value={(r) => r.responses} format={(v) => fmt.number(v)} detail={(r) => t('analytics.hdyhau.detail', { share: fmt.percent(r.share, 1), revenue: fmt.money(r.revenue) })} />}
                table={<DataTable columns={answerColumns} rows={data.answers} rowKey={(r) => r.answer} />}
            />
            <Section title={t('analytics.hdyhau.matrixTitle')} description={t('analytics.hdyhau.matrixDescription')} onExport={exporter(t('analytics.hdyhau.matrixTitle'), matrixColumns, matrixRows)} flush>
                <DataTable columns={matrixColumns} rows={matrixRows} rowKey={(r) => r.answer} />
            </Section>
        </div>
    );
}
