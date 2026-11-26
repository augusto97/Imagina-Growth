import { CalendarDays, CalendarRange, Check, CircleDashed, Sun } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Illustration } from '@/components/ui/illustrations';
import { cn } from '@/lib/cn';
import { stepOf, timelineText } from '../flow';
import { StepFrame } from '../StepFrame';
import type { Onboarding } from '../types';

/*
 * Paso 6 (docs/diseno/rediseno-v2.md §4.2): «Listo». Lo que quedó configurado y lo que falta (sin culpa: se hace
 * después desde el Inicio), y qué va a pasar ahora en una línea de tiempo (hoy, esta semana, este mes) que arma el
 * servidor según lo que quedó prendido. «Ir a mi Inicio» termina el asistente.
 */

const WHEN_ICON = { today: Sun, week: CalendarDays, month: CalendarRange } as const;

export function DoneStep({ state, onBack, onFinish, busy }: { state: Onboarding; onBack: () => void; onFinish: () => void; busy: boolean }) {
    const { t } = useTranslation();
    const checks = (['business', 'connect', 'history', 'channels', 'strategies'] as const).map((key) => ({ key, done: stepOf(state, key)?.done === true }));
    const active = stepOf(state, 'strategies')?.active ?? [];
    const names = state.recommended.filter((s) => active.includes(s.key)).map((s) => s.name);

    return (
        <StepFrame step="done" title={t('onboarding.wizard.done.title')} description={t('onboarding.wizard.done.description')} onBack={onBack} onNext={onFinish} nextLabel={t('onboarding.wizard.done.finish')} busy={busy}>
            <div className="igr:grid igr:gap-6 igr:md:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]">
                <div className="igr:rounded-2xl igr:border igr:bg-card igr:p-5">
                    <Illustration name="celebration" className="igr:mx-auto igr:w-36" />
                    <ul className="igr:mt-4 igr:space-y-2">
                        {checks.map((c) => (
                            <li key={c.key} className="igr:flex igr:items-center igr:gap-2.5 igr:text-support">
                                <span
                                    aria-hidden
                                    className={cn(
                                        'igr:flex igr:size-5 igr:shrink-0 igr:items-center igr:justify-center igr:rounded-full',
                                        c.done ? 'igr:bg-success/12 igr:text-success' : 'igr:bg-muted igr:text-muted-foreground',
                                    )}
                                >
                                    {c.done ? <Check className="igr:size-3.5" strokeWidth={3} /> : <CircleDashed className="igr:size-3.5" />}
                                </span>
                                <span className={cn('igr:min-w-0', c.done ? 'igr:text-foreground' : 'igr:text-muted-foreground')}>
                                    {t(`onboarding.wizard.done.checks.${c.key}`)}
                                    <span className="igr:sr-only"> · {t(c.done ? 'onboarding.wizard.done.ready' : 'onboarding.wizard.done.pending')}</span>
                                </span>
                            </li>
                        ))}
                    </ul>
                    {names.length > 0 ? <p className="igr:mt-4 igr:text-caption igr:text-muted-foreground">{t('onboarding.wizard.done.on', { names: names.join(', ') })}</p> : null}
                    {checks.some((c) => !c.done) ? <p className="igr:mt-2 igr:text-caption igr:text-muted-foreground">{t('onboarding.wizard.done.later')}</p> : null}
                </div>
                <div>
                    <h2 className="igr:text-body igr:font-semibold igr:text-foreground">{t('onboarding.wizard.done.next')}</h2>
                    <ol className="igr:relative igr:mt-4 igr:space-y-6 igr:border-l igr:border-border igr:pl-6">
                        {state.timeline.map((item) => {
                            const Icon = WHEN_ICON[item.when];
                            return (
                                <li key={item.when} className="igr:relative">
                                    <span aria-hidden className="igr:absolute igr:-left-[37px] igr:flex igr:size-7 igr:items-center igr:justify-center igr:rounded-full igr:border igr:bg-card igr:text-primary">
                                        <Icon className="igr:size-3.5" />
                                    </span>
                                    <p className="igr:text-caption igr:font-semibold igr:tracking-wide igr:text-muted-foreground igr:uppercase">{t(`onboarding.wizard.done.when.${item.when}`)}</p>
                                    <p className="igr:mt-1 igr:text-support igr:text-foreground">{timelineText(item.text)}</p>
                                </li>
                            );
                        })}
                    </ol>
                </div>
            </div>
        </StepFrame>
    );
}
