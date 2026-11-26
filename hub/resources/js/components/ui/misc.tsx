import { CircleAlert } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { isApiError } from '@/lib/api';
import { Button } from './button';

/*
 * `PageHeader` (v2, con la miga de la sección) y `EmptyState` (v2, con ilustraciones) viven en su propio archivo; se
 * reexportan aquí para que las pantallas que los importan de `misc` sigan igual.
 */
export { PageHeader } from './page-header';
export { EmptyState } from './empty-state';

export function ErrorState({ error, onRetry }: { error: unknown; onRetry?: () => void }) {
    const { t } = useTranslation();
    const message = isApiError(error) && error.message !== '' ? error.message : t('errors.generic');
    return (
        <div role="alert" className="igr:flex igr:items-start igr:gap-3 igr:rounded-xl igr:border igr:border-destructive/25 igr:bg-destructive/5 igr:p-4 igr:text-support">
            <CircleAlert className="igr:mt-0.5 igr:size-4 igr:shrink-0 igr:text-destructive" aria-hidden />
            <div className="igr:min-w-0">
                <p className="igr:font-medium igr:text-destructive">{t('errors.title')}</p>
                <p className="igr:mt-1 igr:text-muted-foreground">{message}</p>
                {onRetry ? (
                    <Button variant="outline" size="sm" className="igr:mt-3" onClick={onRetry}>
                        {t('common.retry')}
                    </Button>
                ) : null}
            </div>
        </div>
    );
}

export function FieldError({ message }: { message?: string | undefined }) {
    if (!message) {
        return null;
    }
    return <p className="igr:text-xs igr:text-destructive">{message}</p>;
}

export function DescriptionList({ items }: { items: { label: ReactNode; value: ReactNode }[] }) {
    return (
        <dl className="igr:grid igr:grid-cols-[minmax(120px,max-content)_minmax(0,1fr)] igr:gap-x-6 igr:gap-y-2 igr:text-[13px]">
            {items.map((item, i) => (
                <div key={i} className="igr:contents">
                    <dt className="igr:text-muted-foreground">{item.label}</dt>
                    <dd className="igr:min-w-0 igr:break-words">{item.value}</dd>
                </div>
            ))}
        </dl>
    );
}
