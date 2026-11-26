import { TriangleAlert } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import type { ProductRow, ProductsData } from '../api';
import { DataTable } from '../components/DataTable';
import type { Column } from '../components/DataTable';
import { Section } from '../components/Section';
import { STATUS } from '../theme';
import { entryTop, pairsTop, productsTop, viewedNotSold } from '../conclusions';
import { useConclusion, useExporter } from './shared';
import type { DashboardProps } from './shared';

/** Días de inventario por debajo de este umbral se marcan (detector #6 usa 14). */
const LOW_STOCK_DAYS = 14;

type Entry = ProductsData['entry_products'][number];
type Pair = ProductsData['bought_together'][number];

export function ProductsDashboard({ response, data, fmt }: DashboardProps<'products'>) {
    const { t } = useTranslation();
    const exporter = useExporter(response);
    const conclude = useConclusion();

    const productColumns: Column<ProductRow>[] = [
        { key: 'name', label: t('analytics.col.product'), render: (r) => <span className="igr:line-clamp-1 igr:max-w-72">{r.name}</span>, value: (r) => r.name },
        { key: 'views', label: t('analytics.products.views'), align: 'right', render: (r) => fmt.number(r.views), value: (r) => r.views },
        { key: 'add_to_carts', label: t('analytics.products.carts'), align: 'right', render: (r) => fmt.number(r.add_to_carts), value: (r) => r.add_to_carts },
        { key: 'orders', label: t('analytics.metric.orders'), align: 'right', render: (r) => fmt.number(r.orders), value: (r) => r.orders },
        { key: 'units', label: t('analytics.products.units'), align: 'right', render: (r) => fmt.decimal(r.units, 0), value: (r) => r.units },
        { key: 'revenue', label: t('analytics.metric.revenue'), align: 'right', render: (r) => fmt.money(r.revenue), value: (r) => r.revenue },
        { key: 'view_to_purchase', label: t('analytics.products.viewToPurchase'), help: t('analytics.products.viewToPurchaseHelp'), align: 'right', render: (r) => fmt.percent(r.view_to_purchase, 1), value: (r) => r.view_to_purchase },
        { key: 'cart_abandonment', label: t('analytics.products.cartAbandonment'), help: t('analytics.products.cartAbandonmentHelp'), align: 'right', render: (r) => fmt.percent(r.cart_abandonment, 0), value: (r) => r.cart_abandonment },
        { key: 'stock_qty', label: t('analytics.products.stock'), align: 'right', render: (r) => fmt.decimal(r.stock_qty, 0), value: (r) => r.stock_qty },
        {
            key: 'days_of_stock',
            label: t('analytics.products.daysOfStock'),
            help: t('analytics.products.daysOfStockHelp'),
            align: 'right',
            render: (r) =>
                r.days_of_stock === null ? (
                    '—'
                ) : r.days_of_stock < LOW_STOCK_DAYS ? (
                    <span className="igr:inline-flex igr:items-center igr:gap-1 igr:font-medium">
                        <TriangleAlert className="igr:size-3.5" style={{ color: STATUS.warning }} aria-hidden />
                        {t('analytics.products.lowStock', { days: fmt.decimal(r.days_of_stock, 0) })}
                    </span>
                ) : (
                    fmt.decimal(r.days_of_stock, 0)
                ),
            value: (r) => r.days_of_stock,
        },
    ];
    const notSoldColumns = productColumns.filter((c) => ['name', 'views', 'add_to_carts', 'orders', 'view_to_purchase'].includes(c.key));
    const entryColumns: Column<Entry>[] = [
        { key: 'name', label: t('analytics.col.product'), render: (r) => r.name, value: (r) => r.name },
        { key: 'customers', label: t('analytics.products.firstBuyers'), align: 'right', render: (r) => fmt.number(r.customers), value: (r) => r.customers },
        { key: 'avg_ltv', label: t('analytics.products.avgLtv'), help: t('analytics.products.avgLtvHelp'), align: 'right', render: (r) => fmt.money(r.avg_ltv), value: (r) => r.avg_ltv },
        { key: 'avg_orders', label: t('analytics.products.avgOrders'), align: 'right', render: (r) => fmt.decimal(r.avg_orders, 1), value: (r) => r.avg_orders },
    ];
    const pairColumns: Column<Pair>[] = [
        { key: 'a', label: t('analytics.products.productA'), render: (r) => r.a.name, value: (r) => r.a.name },
        { key: 'b', label: t('analytics.products.productB'), render: (r) => r.b.name, value: (r) => r.b.name },
        { key: 'orders', label: t('analytics.products.together'), align: 'right', render: (r) => fmt.number(r.orders), value: (r) => r.orders },
        { key: 'confidence', label: t('analytics.products.confidence'), help: t('analytics.products.confidenceHelp'), align: 'right', render: (r) => fmt.percent(r.confidence, 0), value: (r) => r.confidence },
        { key: 'lift', label: t('analytics.products.lift'), help: t('analytics.products.liftHelp'), align: 'right', render: (r) => `${fmt.decimal(r.lift, 1)}×`, value: (r) => r.lift },
    ];

    return (
        <div className="igr:space-y-4">
            <Section
                kicker={t('analytics.products.title')}
                title={conclude(productsTop(data.products, fmt), t('analytics.products.title'))}
                description={t('analytics.products.description')} onExport={exporter(t('analytics.products.title'), productColumns, data.products)} flush>
                <DataTable columns={productColumns} rows={data.products} rowKey={(r) => r.product_id} limit={20} initialSort={{ key: 'revenue', dir: 'desc' }} />
            </Section>
            <div className="igr:grid igr:gap-4 igr:xl:grid-cols-2">
                <Section
                    kicker={t('analytics.products.notSoldTitle')}
                    title={conclude(viewedNotSold(data.viewed_not_sold, fmt), t('analytics.products.notSoldTitle'))}
                    description={t('analytics.products.notSoldDescription')}
                    onExport={exporter(t('analytics.products.notSoldTitle'), notSoldColumns, data.viewed_not_sold)}
                    flush
                >
                    <DataTable columns={notSoldColumns} rows={data.viewed_not_sold} rowKey={(r) => r.product_id} limit={10} />
                </Section>
                <Section
                    kicker={t('analytics.products.entryTitle')}
                    title={conclude(entryTop(data.entry_products, fmt), t('analytics.products.entryTitle'))}
                    description={t('analytics.products.entryDescription')}
                    onExport={exporter(t('analytics.products.entryTitle'), entryColumns, data.entry_products)}
                    flush
                >
                    <DataTable columns={entryColumns} rows={data.entry_products} rowKey={(r) => r.product_id} limit={10} />
                </Section>
            </div>
            <Section
                kicker={t('analytics.products.pairsTitle')}
                title={conclude(pairsTop(data.bought_together, fmt), t('analytics.products.pairsTitle'))}
                description={t('analytics.products.pairsDescription')}
                onExport={exporter(t('analytics.products.pairsTitle'), pairColumns, data.bought_together)}
                flush
            >
                <DataTable columns={pairColumns} rows={data.bought_together} rowKey={(r) => `${r.a.product_id}-${r.b.product_id}`} limit={15} />
            </Section>
        </div>
    );
}
