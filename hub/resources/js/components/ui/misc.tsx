import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/cn';
import { isApiError } from '@/lib/api';
import { Button } from './button';

export function PageHeader({ title, description, actions }: { title: ReactNode; description?: ReactNode; actions?: ReactNode }) {
    return (
        <div className="igr:mb-5 igr:flex igr:flex-wrap igr:items-start igr:justify-between igr:gap-3">
            <div className="igr:min-w-0">
                <h1 className="igr:text-lg igr:font-semibold igr:tracking-tight igr:break-words">{title}</h1>
                {description ? <p className="igr:mt-0.5 igr:text-[13px] igr:text-muted-foreground">{description}</p> : null}
            </div>
            {actions ? <div className="igr:flex igr:max-w-full igr:flex-wrap igr:items-center igr:gap-2">{actions}</div> : null}
        </div>
    );
}

/** Estados vacíos que enseñan qué hacer (hub/CLAUDE.md §14). */
export function EmptyState({
    icon: Icon,
    title,
    description,
    action,
    className,
}: {
    icon: LucideIcon;
    title: ReactNode;
    description?: ReactNode;
    action?: ReactNode;
    className?: string;
}) {
    return (
        <div className={cn('igr:flex igr:flex-col igr:items-center igr:rounded-lg igr:border igr:border-dashed igr:px-6 igr:py-12 igr:text-center', className)}>
            <div className="igr:mb-3 igr:flex igr:size-10 igr:items-center igr:justify-center igr:rounded-full igr:bg-muted">
                <Icon className="igr:size-5 igr:text-muted-foreground" />
            </div>
            <p className="igr:text-sm igr:font-medium">{title}</p>
            {description ? <p className="igr:mt-1 igr:max-w-sm igr:text-[13px] igr:text-muted-foreground">{description}</p> : null}
            {action ? <div className="igr:mt-4">{action}</div> : null}
        </div>
    );
}

export function ErrorState({ error, onRetry }: { error: unknown; onRetry?: () => void }) {
    const { t } = useTranslation();
    const message = isApiError(error) && error.message !== '' ? error.message : t('errors.generic');
    return (
        <div role="alert" className="igr:rounded-lg igr:border igr:border-destructive/30 igr:bg-destructive/5 igr:p-4 igr:text-[13px]">
            <p className="igr:font-medium igr:text-destructive">{t('errors.title')}</p>
            <p className="igr:mt-1 igr:text-muted-foreground">{message}</p>
            {onRetry ? (
                <Button variant="outline" size="sm" className="igr:mt-3" onClick={onRetry}>
                    {t('common.retry')}
                </Button>
            ) : null}
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
