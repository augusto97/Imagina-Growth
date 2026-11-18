import { ImageOff, Star } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatMoney } from '@/lib/format';
import type { RecoProduct } from './api';

/** Tarjeta de producto como la ve el visitante (vista previa de un bloque de recomendaciones). */
export function ProductCard({ product }: { product: RecoProduct }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const onSale = product.sale_price !== null && product.regular_price !== null && Number(product.sale_price) < Number(product.regular_price);

    return (
        <div className="igr:flex igr:min-w-0 igr:flex-col igr:overflow-hidden igr:rounded-lg igr:border igr:bg-card">
            <div className="igr:flex igr:aspect-square igr:items-center igr:justify-center igr:bg-muted">
                {product.image ? (
                    <img src={product.image} alt="" loading="lazy" className="igr:size-full igr:object-cover" />
                ) : (
                    <ImageOff aria-hidden className="igr:size-6 igr:text-muted-foreground" />
                )}
            </div>
            <div className="igr:space-y-1 igr:p-2.5">
                <p className="igr:line-clamp-2 igr:text-[13px] igr:font-medium" title={product.name}>
                    {product.name}
                </p>
                <p className="igr:text-[13px] igr:tabular-nums">
                    {formatMoney(product.sale_price ?? product.price, product.currency, workspace.locale)}
                    {onSale ? <span className="igr:ml-1.5 igr:text-xs igr:text-muted-foreground igr:line-through">{formatMoney(product.regular_price, product.currency, workspace.locale)}</span> : null}
                </p>
                {product.rating_count > 0 && product.rating_avg !== null ? (
                    <p className="igr:flex igr:items-center igr:gap-1 igr:text-xs igr:text-muted-foreground">
                        <Star aria-hidden className="igr:size-3 igr:fill-current" />
                        {t('onsiteCommerce.card.rating', { avg: product.rating_avg.toFixed(1), count: product.rating_count })}
                    </p>
                ) : null}
            </div>
        </div>
    );
}
