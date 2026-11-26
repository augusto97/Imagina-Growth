import { CircleAlert, CircleCheck, Info, Lightbulb, TriangleAlert, X, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/cn';

export type CalloutTone = 'info' | 'success' | 'warning' | 'danger' | 'brand' | 'neutral';

const TONES: Record<CalloutTone, { box: string; icon: string; Icon: LucideIcon }> = {
    info: { box: 'igr:border-info/25 igr:bg-info/6', icon: 'igr:text-info', Icon: Info },
    success: { box: 'igr:border-success/25 igr:bg-success/6', icon: 'igr:text-success', Icon: CircleCheck },
    warning: { box: 'igr:border-warning/30 igr:bg-warning/8', icon: 'igr:text-warning', Icon: TriangleAlert },
    danger: { box: 'igr:border-destructive/25 igr:bg-destructive/6', icon: 'igr:text-destructive', Icon: CircleAlert },
    brand: { box: 'igr:border-primary/20 igr:bg-brand-soft/70', icon: 'igr:text-primary', Icon: Lightbulb },
    neutral: { box: 'igr:bg-muted/50', icon: 'igr:text-muted-foreground', Icon: Info },
};

/**
 * Aviso dentro de una página (rediseño v2 §7): algo que conviene saber o hacer, con su ícono, un título corto, el
 * detalle y, si hay, la acción. El color nunca va solo: siempre lleva ícono y texto. `role="status"` para avisos
 * informativos; los de peligro usan `role="alert"`.
 */
export function Callout({
    tone = 'info',
    title,
    children,
    action,
    icon,
    onDismiss,
    className,
}: {
    tone?: CalloutTone;
    title?: ReactNode;
    children?: ReactNode;
    action?: ReactNode;
    icon?: LucideIcon;
    onDismiss?: () => void;
    className?: string;
}) {
    const { t } = useTranslation();
    const spec = TONES[tone];
    const Icon = icon ?? spec.Icon;
    return (
        <div
            role={tone === 'danger' ? 'alert' : 'status'}
            className={cn('igr:flex igr:flex-wrap igr:items-start igr:gap-x-3 igr:gap-y-2 igr:rounded-xl igr:border igr:px-4 igr:py-3 igr:text-support', spec.box, className)}
        >
            <Icon className={cn('igr:mt-0.5 igr:size-4 igr:shrink-0', spec.icon)} aria-hidden />
            <div className="igr:min-w-0 igr:flex-1 igr:basis-48">
                {title ? <p className="igr:font-medium igr:text-foreground">{title}</p> : null}
                {children ? <div className={cn('igr:text-muted-foreground', title ? 'igr:mt-0.5' : null)}>{children}</div> : null}
            </div>
            {action ? <div className="igr:flex igr:shrink-0 igr:flex-wrap igr:items-center igr:gap-2 igr:max-sm:w-full igr:max-sm:pl-7">{action}</div> : null}
            {onDismiss ? (
                <button
                    type="button"
                    onClick={onDismiss}
                    aria-label={t('common.close')}
                    className="igr:-mr-1 igr:rounded-md igr:p-1 igr:text-muted-foreground igr:transition-colors igr:hover:bg-accent igr:hover:text-foreground"
                >
                    <X className="igr:size-3.5" aria-hidden />
                </button>
            ) : null}
        </div>
    );
}
