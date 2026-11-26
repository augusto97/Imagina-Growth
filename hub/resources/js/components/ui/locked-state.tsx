import { Lock, Sparkles } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/cn';
import { Badge } from './badge';
import { Checklist, type ChecklistStep } from './checklist';
import { Illustration, type IllustrationName } from './illustrations';

/**
 * Bloqueo que enseña (rediseño v2 §3): en lugar de una pantalla vacía o un error, dice qué hace esta parte, qué le
 * falta para funcionar y el botón para resolverlo.
 *
 * - `variant="requirement"` (por defecto): falta algo que la persona puede hacer ya (conectar la web, un canal).
 * - `variant="plan"`: el módulo no está en el plan; `planName` dice en cuál está y la acción lleva a Facturación.
 * - `requirements`: «Qué hace falta», con el estado de cada cosa (listo, pendiente).
 * - `action` / `secondary`: el botón principal para resolverlo y uno secundario.
 */
export function LockedState({
    variant = 'requirement',
    title,
    description,
    illustration,
    requirements,
    planName,
    action,
    secondary,
    note,
    className,
}: {
    variant?: 'requirement' | 'plan';
    title: ReactNode;
    description?: ReactNode;
    illustration?: IllustrationName;
    requirements?: ChecklistStep[];
    planName?: string | null;
    action?: ReactNode;
    secondary?: ReactNode;
    /** Texto pequeño debajo de las acciones (p. ej. «Pídele a quien administra la cuenta»). */
    note?: ReactNode;
    className?: string;
}) {
    const { t } = useTranslation();
    const plan = variant === 'plan';
    return (
        <section className={cn('igr:overflow-hidden igr:rounded-2xl igr:border igr:bg-card igr:shadow-xs', className)}>
            <div className={cn('igr:grid igr:gap-0', requirements && requirements.length > 0 && 'igr:lg:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)]')}>
                <div className="igr:hero-surface igr:flex igr:flex-col igr:items-start igr:p-6 igr:sm:p-8">
                    <Illustration name={illustration ?? (plan ? 'plan' : 'locked')} className="igr:-ml-3 igr:mb-3 igr:w-36" />
                    <Badge variant={plan ? 'brand' : 'outline'} className="igr:mb-3">
                        {plan ? <Sparkles aria-hidden className="igr:size-3" /> : <Lock aria-hidden className="igr:size-3" />}
                        {plan ? (planName ? t('ui.locked.inPlan', { plan: planName }) : t('ui.locked.notInPlan')) : t('ui.locked.needsSetup')}
                    </Badge>
                    <h2 className="igr:text-section igr:font-semibold igr:tracking-tight igr:text-foreground igr:sm:text-[20px] igr:sm:leading-7">{title}</h2>
                    {description ? <p className="igr:mt-2 igr:max-w-xl igr:text-body igr:text-muted-foreground">{description}</p> : null}
                    {action || secondary ? (
                        <div className="igr:mt-6 igr:flex igr:flex-wrap igr:items-center igr:gap-2">
                            {action}
                            {secondary}
                        </div>
                    ) : null}
                    {note ? <p className="igr:mt-3 igr:text-caption igr:text-muted-foreground">{note}</p> : null}
                </div>
                {requirements && requirements.length > 0 ? (
                    <div className="igr:border-t igr:p-5 igr:sm:p-6 igr:lg:border-t-0 igr:lg:border-l">
                        <p className="igr:mb-3 igr:text-caption igr:font-semibold igr:tracking-wide igr:text-muted-foreground igr:uppercase">{t('ui.locked.whatsMissing')}</p>
                        <Checklist steps={requirements} />
                    </div>
                ) : null}
            </div>
        </section>
    );
}
