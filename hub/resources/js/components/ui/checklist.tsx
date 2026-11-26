import { Check, Lock } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/cn';
import { Progress } from './progress';

/**
 * Lista de pasos con su estado (configuración, requisitos de una estrategia, «qué hace falta» de un bloqueo) y su
 * versión en línea (`Stepper`, para asistentes). El estado de cada paso lo decide quien llama; aquí solo se cuenta.
 */
export type StepStatus = 'done' | 'current' | 'todo' | 'blocked';

export interface ChecklistStep {
    key: string;
    title: ReactNode;
    description?: ReactNode;
    status: StepStatus;
    /** Botón o enlace para resolverlo (se muestra en el paso actual y en los pendientes). */
    action?: ReactNode;
}

export interface ChecklistProgress {
    done: number;
    total: number;
    /** 0 a 1. */
    ratio: number;
    /** El primer paso sin hacer (o -1 si todo está listo). */
    next: number;
    complete: boolean;
}

/** Cuántos pasos están listos y cuál sigue. Un paso bloqueado cuenta como pendiente. */
export function checklistProgress(steps: readonly Pick<ChecklistStep, 'status'>[]): ChecklistProgress {
    const done = steps.filter((s) => s.status === 'done').length;
    const total = steps.length;
    const current = steps.findIndex((s) => s.status === 'current');
    const next = current >= 0 ? current : steps.findIndex((s) => s.status !== 'done');
    return { done, total, ratio: total === 0 ? 0 : done / total, next, complete: total > 0 && done === total };
}

/**
 * Marca como `current` el primer paso pendiente cuando nadie lo hizo (los demás pendientes quedan `todo`). Útil cuando
 * el estado llega del servidor como «listo / no listo».
 */
export function withCurrentStep<T extends Pick<ChecklistStep, 'status'>>(steps: readonly T[]): T[] {
    if (steps.some((s) => s.status === 'current')) {
        return [...steps];
    }
    const index = steps.findIndex((s) => s.status === 'todo');
    return steps.map((s, i) => (i === index ? { ...s, status: 'current' as const } : s));
}

function StepMarker({ status, index }: { status: StepStatus; index: number }) {
    if (status === 'done') {
        return (
            <span className="igr:flex igr:size-6 igr:shrink-0 igr:items-center igr:justify-center igr:rounded-full igr:bg-success igr:text-success-foreground">
                <Check className="igr:size-3.5" strokeWidth={3} aria-hidden />
            </span>
        );
    }
    if (status === 'blocked') {
        return (
            <span className="igr:flex igr:size-6 igr:shrink-0 igr:items-center igr:justify-center igr:rounded-full igr:bg-muted igr:text-muted-foreground">
                <Lock className="igr:size-3" aria-hidden />
            </span>
        );
    }
    return (
        <span
            className={cn(
                'igr:flex igr:size-6 igr:shrink-0 igr:items-center igr:justify-center igr:rounded-full igr:text-[11px] igr:font-semibold igr:tabular-nums',
                status === 'current' ? 'igr:bg-primary igr:text-primary-foreground igr:ring-4 igr:ring-primary/15' : 'igr:border igr:border-input igr:text-muted-foreground',
            )}
        >
            {index + 1}
        </span>
    );
}

/** Lista vertical de pasos. Con `showProgress`, una barra y «2 de 5 listos» arriba. */
export function Checklist({ steps, showProgress = false, className }: { steps: ChecklistStep[]; showProgress?: boolean; className?: string }) {
    const { t } = useTranslation();
    const progress = checklistProgress(steps);
    return (
        <div className={className}>
            {showProgress ? (
                <div className="igr:mb-3 igr:flex igr:items-center igr:gap-3">
                    <Progress value={progress.ratio} label={t('ui.checklist.progress', { done: progress.done, total: progress.total })} className="igr:flex-1" />
                    <span className="igr:shrink-0 igr:text-caption igr:font-medium igr:text-muted-foreground igr:tabular-nums">
                        {t('ui.checklist.progress', { done: progress.done, total: progress.total })}
                    </span>
                </div>
            ) : null}
            <ol className="igr:space-y-1">
                {steps.map((step, i) => (
                    <li
                        key={step.key}
                        aria-current={step.status === 'current' ? 'step' : undefined}
                        className={cn(
                            'igr:flex igr:flex-wrap igr:items-start igr:gap-x-3 igr:gap-y-2 igr:rounded-lg igr:px-3 igr:py-2.5',
                            step.status === 'current' && 'igr:bg-brand-soft/60 igr:ring-1 igr:ring-primary/15',
                        )}
                    >
                        <StepMarker status={step.status} index={i} />
                        <div className="igr:min-w-0 igr:flex-1 igr:basis-48">
                            <p className={cn('igr:text-support igr:font-medium', step.status === 'done' ? 'igr:text-muted-foreground' : 'igr:text-foreground')}>
                                {step.title}
                                <span className="igr:sr-only"> · {t(`ui.checklist.status.${step.status}`)}</span>
                            </p>
                            {step.description ? <p className="igr:mt-0.5 igr:text-caption igr:text-muted-foreground">{step.description}</p> : null}
                        </div>
                        {step.action && step.status !== 'done' ? <div className="igr:shrink-0 igr:max-sm:w-full igr:max-sm:pl-9">{step.action}</div> : null}
                    </li>
                ))}
            </ol>
        </div>
    );
}

/** Pasos en línea para un asistente: número, nombre debajo y una línea entre ellos. En el celular solo «Paso 2 de 5 · nombre». */
export function Stepper({ steps, className }: { steps: Pick<ChecklistStep, 'key' | 'title' | 'status'>[]; className?: string }) {
    const { t } = useTranslation();
    const progress = checklistProgress(steps);
    const current = steps[progress.next] ?? steps[steps.length - 1];
    return (
        <div className={className}>
            <p className="igr:text-caption igr:font-medium igr:text-muted-foreground igr:md:hidden">
                {t('ui.stepper.mobile', { current: Math.min(progress.next + 1 || steps.length, steps.length), total: steps.length })}
                {current ? <span className="igr:text-foreground"> · {current.title}</span> : null}
            </p>
            <Progress value={progress.ratio} className="igr:mt-2 igr:md:hidden" label={t('ui.checklist.progress', { done: progress.done, total: progress.total })} />
            <ol className="igr:hidden igr:md:flex">
                {steps.map((step, i) => (
                    <li
                        key={step.key}
                        aria-current={step.status === 'current' ? 'step' : undefined}
                        className={cn(
                            'igr:relative igr:flex igr:min-w-0 igr:flex-1 igr:flex-col igr:items-center igr:gap-1.5 igr:px-1 igr:text-center',
                            // La línea hasta el paso siguiente, detrás de los números.
                            i < steps.length - 1 &&
                                'igr:after:absolute igr:after:top-3 igr:after:right-[calc(-50%+16px)] igr:after:left-[calc(50%+16px)] igr:after:h-px igr:after:content-[""]',
                            i < steps.length - 1 && (step.status === 'done' ? 'igr:after:bg-success/60' : 'igr:after:bg-border'),
                        )}
                    >
                        <StepMarker status={step.status} index={i} />
                        <span
                            className={cn(
                                'igr:line-clamp-2 igr:text-caption igr:font-medium',
                                step.status === 'todo' || step.status === 'blocked' ? 'igr:text-muted-foreground' : 'igr:text-foreground',
                            )}
                        >
                            {step.title}
                            <span className="igr:sr-only"> · {t(`ui.checklist.status.${step.status}`)}</span>
                        </span>
                    </li>
                ))}
            </ol>
        </div>
    );
}
