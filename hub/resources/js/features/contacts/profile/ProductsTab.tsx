import { Shapes } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState, ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatMoney, formatNumber } from '@/lib/format';
import { type AffinityRow, useContactProducts } from '../api';

/** Afinidad por categoría, marca y producto: compras (MariaDB) y vistas (ClickHouse). */
export function ProductsTab({ contactId }: { contactId: string }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const products = useContactProducts(workspace.id, contactId);

    if (products.isError) {
        return <ErrorState error={products.error} onRetry={() => void products.refetch()} />;
    }
    if (products.isPending) {
        return (
            <div className="igr:grid igr:gap-4 igr:md:grid-cols-2">
                <Skeleton className="igr:h-48" />
                <Skeleton className="igr:h-48" />
            </div>
        );
    }
    const { categories, brands, products: items } = products.data;
    if (categories.length === 0 && brands.length === 0 && items.length === 0) {
        return <EmptyState icon={Shapes} title={t('contacts.products.emptyTitle')} description={t('contacts.products.emptyDescription')} />;
    }
    const money = (v: number | undefined) => formatMoney(v ?? 0, workspace.currency, workspace.locale);

    return (
        <div className="igr:grid igr:gap-4 igr:md:grid-cols-2">
            <AffinityCard
                title={t('contacts.products.categories')}
                rows={categories}
                value={(r) => r.purchases}
                detail={(r) => `${t('contacts.products.purchases', { count: r.purchases })} · ${money(r.revenue)}`}
            />
            <AffinityCard
                title={t('contacts.products.brands')}
                rows={brands}
                value={(r) => r.purchases}
                detail={(r) => `${t('contacts.products.purchases', { count: r.purchases })} · ${money(r.revenue)}`}
                empty={t('contacts.products.noBrands')}
            />
            <div className="igr:md:col-span-2">
                <AffinityCard
                    title={t('contacts.products.products')}
                    rows={items}
                    value={(r) => r.purchases * 5 + (r.views ?? 0)}
                    detail={(r) =>
                        [
                            r.purchases > 0 ? t('contacts.products.units', { count: r.purchases, formatted: formatNumber(r.purchases, workspace.locale) }) : null,
                            (r.views ?? 0) > 0 ? t('contacts.products.views', { count: r.views ?? 0 }) : null,
                        ]
                            .filter(Boolean)
                            .join(' · ')
                    }
                    help={t('contacts.products.productsHelp')}
                />
            </div>
        </div>
    );
}

function AffinityCard({
    title,
    rows,
    value,
    detail,
    empty,
    help,
}: {
    title: string;
    rows: AffinityRow[];
    value: (r: AffinityRow) => number;
    detail: (r: AffinityRow) => string;
    empty?: string;
    help?: string;
}) {
    const { t } = useTranslation();
    const max = Math.max(1, ...rows.map(value));
    return (
        <Card>
            <CardHeader>
                <CardTitle>{title}</CardTitle>
                {help && <p className="igr:text-xs igr:text-muted-foreground">{help}</p>}
            </CardHeader>
            <CardContent>
                {rows.length === 0 ? (
                    <p className="igr:text-xs igr:text-muted-foreground">{empty ?? t('contacts.products.none')}</p>
                ) : (
                    <ul className="igr:space-y-2.5">
                        {rows.map((r, i) => (
                            <li key={r.id ?? `${r.name}-${i}`}>
                                <div className="igr:flex igr:items-baseline igr:justify-between igr:gap-3 igr:text-[13px]">
                                    <span className="igr:truncate">{r.name}</span>
                                    <span className="igr:shrink-0 igr:text-xs igr:tabular-nums igr:text-muted-foreground">{detail(r)}</span>
                                </div>
                                <div className="igr:mt-1 igr:h-1 igr:rounded-full igr:bg-muted" aria-hidden="true">
                                    <div className="igr:h-1 igr:rounded-full igr:bg-primary" style={{ width: `${Math.max(2, (value(r) / max) * 100)}%` }} />
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}
