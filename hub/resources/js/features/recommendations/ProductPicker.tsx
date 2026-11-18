import { Search, X } from 'lucide-react';
import { useEffect, useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Input } from '@/components/ui/input';
import { useWorkspace } from '@/hooks/useWorkspace';
import { type CatalogProduct, useCatalogSearch } from './api';

/** Buscador de productos del catálogo del sitio (por nombre, SKU o ID). */
export function ProductPicker({
    site,
    value,
    onChange,
    label,
}: {
    site: string | null;
    value: CatalogProduct | null;
    onChange: (product: CatalogProduct | null) => void;
    label: string;
}) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const [q, setQ] = useState('');
    const [debounced, setDebounced] = useState('');
    const [open, setOpen] = useState(false);
    const listId = useId();
    useEffect(() => {
        const timer = window.setTimeout(() => setDebounced(q.trim()), 250);
        return () => window.clearTimeout(timer);
    }, [q]);
    const results = useCatalogSearch(workspace.id, site, debounced);

    if (value !== null) {
        return (
            <div className="igr:flex igr:h-8 igr:items-center igr:justify-between igr:gap-2 igr:rounded-md igr:border igr:bg-background igr:px-2.5 igr:text-[13px]">
                <span className="igr:truncate">
                    {value.name} <span className="igr:text-muted-foreground">· {value.id}</span>
                </span>
                <button type="button" onClick={() => onChange(null)} aria-label={t('onsiteCommerce.picker.clear')} className="igr:text-muted-foreground igr:hover:text-foreground">
                    <X className="igr:size-3.5" />
                </button>
            </div>
        );
    }

    return (
        <div className="igr:relative">
            <Search aria-hidden className="igr:pointer-events-none igr:absolute igr:top-2 igr:left-2.5 igr:size-4 igr:text-muted-foreground" />
            <Input
                aria-label={label}
                role="combobox"
                aria-expanded={open}
                aria-controls={listId}
                placeholder={t('onsiteCommerce.picker.placeholder')}
                className="igr:pl-8"
                value={q}
                onChange={(e) => {
                    setQ(e.target.value);
                    setOpen(true);
                }}
                onFocus={() => setOpen(true)}
                onBlur={() => window.setTimeout(() => setOpen(false), 150)}
            />
            {open ? (
                <ul id={listId} role="listbox" className="igr:absolute igr:z-20 igr:mt-1 igr:max-h-64 igr:w-full igr:overflow-auto igr:rounded-md igr:border igr:bg-popover igr:p-1 igr:shadow-md">
                    {(results.data?.data ?? []).length === 0 ? (
                        <li className="igr:px-2 igr:py-1.5 igr:text-xs igr:text-muted-foreground">{results.isFetching ? t('common.loading') : t('onsiteCommerce.picker.empty')}</li>
                    ) : (
                        (results.data?.data ?? []).map((p) => (
                            <li key={p.id} role="option" aria-selected={false}>
                                <button
                                    type="button"
                                    className="igr:flex igr:w-full igr:items-center igr:justify-between igr:gap-2 igr:rounded igr:px-2 igr:py-1.5 igr:text-left igr:text-[13px] igr:hover:bg-accent"
                                    onMouseDown={(e) => e.preventDefault()}
                                    onClick={() => {
                                        onChange(p);
                                        setQ('');
                                        setOpen(false);
                                    }}
                                >
                                    <span className="igr:truncate">{p.name}</span>
                                    <span className="igr:shrink-0 igr:text-xs igr:text-muted-foreground">
                                        {p.in_stock ? t('onsiteCommerce.picker.sold', { count: p.sold_90d }) : t('onsiteCommerce.stock.out')}
                                    </span>
                                </button>
                            </li>
                        ))
                    )}
                </ul>
            ) : null}
        </div>
    );
}
