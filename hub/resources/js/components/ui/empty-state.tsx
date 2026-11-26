import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/cn';
import { Illustration, type IllustrationName } from './illustrations';

/**
 * Estado vacío que vende (rediseño v2 §7): ilustración, el beneficio concreto (título y descripción en segunda
 * persona), la acción principal y, si ayuda, «Cómo funciona» en pasos cortos.
 *
 * - `illustration`: una de `illustrations.tsx`; sin ella, `icon` en un círculo (compatibilidad con la versión 1).
 * - `action`: el botón principal; `secondary`, uno secundario (enlace o `outline`).
 * - `steps`: de 2 a 4 pasos de «Cómo funciona».
 * - `size="compact"`: dentro de una tarjeta o un panel (menos aire y la ilustración más chica).
 * - `bordered={false}`: sin el borde punteado, cuando ya está dentro de una tarjeta.
 */
export function EmptyState({
    icon: Icon,
    illustration,
    title,
    description,
    action,
    secondary,
    steps,
    size = 'default',
    bordered = true,
    className,
}: {
    icon?: LucideIcon;
    illustration?: IllustrationName;
    title: ReactNode;
    description?: ReactNode;
    action?: ReactNode;
    secondary?: ReactNode;
    steps?: ReactNode[];
    size?: 'default' | 'compact';
    bordered?: boolean;
    className?: string;
}) {
    const { t } = useTranslation();
    const compact = size === 'compact';
    return (
        <div
            className={cn(
                'igr:flex igr:flex-col igr:items-center igr:text-center',
                bordered && 'igr:rounded-xl igr:border igr:border-dashed igr:bg-card/60',
                compact ? 'igr:px-4 igr:py-8' : 'igr:px-6 igr:py-12',
                className,
            )}
        >
            {illustration ? (
                <Illustration name={illustration} className={cn('igr:mb-4', compact ? 'igr:w-28' : 'igr:w-44')} />
            ) : Icon ? (
                <div className="igr:mb-3 igr:flex igr:size-11 igr:items-center igr:justify-center igr:rounded-full igr:bg-brand-soft igr:text-brand-soft-foreground">
                    <Icon className="igr:size-5" aria-hidden />
                </div>
            ) : null}
            <p className={cn('igr:font-semibold igr:tracking-tight igr:text-foreground', compact ? 'igr:text-sm' : 'igr:text-base')}>{title}</p>
            {description ? <p className="igr:mt-1.5 igr:max-w-md igr:text-support igr:text-muted-foreground">{description}</p> : null}
            {action || secondary ? (
                <div className="igr:mt-5 igr:flex igr:flex-wrap igr:items-center igr:justify-center igr:gap-2">
                    {action}
                    {secondary}
                </div>
            ) : null}
            {steps && steps.length > 0 ? (
                <div className="igr:mt-7 igr:w-full igr:max-w-2xl igr:text-left">
                    <p className="igr:mb-2 igr:text-center igr:text-caption igr:font-medium igr:tracking-wide igr:text-muted-foreground igr:uppercase">{t('ui.howItWorks')}</p>
                    <ol className="igr:grid igr:gap-2 igr:sm:grid-cols-3">
                        {steps.map((step, i) => (
                            <li key={i} className="igr:flex igr:gap-2.5 igr:rounded-lg igr:border igr:bg-card igr:p-3 igr:text-support">
                                <span className="igr:flex igr:size-5 igr:shrink-0 igr:items-center igr:justify-center igr:rounded-full igr:bg-brand-soft igr:text-[11px] igr:font-semibold igr:text-brand-soft-foreground igr:tabular-nums">
                                    {i + 1}
                                </span>
                                <span className="igr:min-w-0 igr:text-muted-foreground">{step}</span>
                            </li>
                        ))}
                    </ol>
                </div>
            ) : null}
        </div>
    );
}
