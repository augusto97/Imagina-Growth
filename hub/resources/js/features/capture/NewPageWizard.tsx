import { ArrowLeft, ArrowRight, Calculator, CalendarCheck, MessageCircle, Sparkles, Tag, Ticket, type LucideIcon } from 'lucide-react';
import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { FieldError } from '@/components/ui/misc';
import { errorMessage } from '@/features/contacts/errors';
import { useWorkspace } from '@/hooks/useWorkspace';
import { cn } from '@/lib/cn';
import { useLandingMutations } from './api';
import { TemplatePicker, useCanWriteWithAi } from './LandingDesignParts';
import { DEFAULT_ACCENT } from './model';
import type { LandingGoal, LandingTemplate } from './types';

export const GOALS: readonly LandingGoal[] = ['contact', 'quote', 'appointment', 'offer', 'event'];

const GOAL_ICON: Record<LandingGoal, LucideIcon> = { contact: MessageCircle, quote: Calculator, appointment: CalendarCheck, offer: Tag, event: Ticket };

/** El diseño que mejor le va a cada objetivo (el mismo de `LandingPresets::TEMPLATE` en el hub). */
export const GOAL_TEMPLATE: Record<LandingGoal, LandingTemplate> = { contact: 'split', quote: 'split', appointment: 'centered', offer: 'hero', event: 'hero' };

type Step = 'goal' | 'template' | 'texts';
const STEPS: readonly Step[] = ['goal', 'template', 'texts'];

/**
 * Asistente de «Nueva página» (modulos.md M12): para qué es → diseño → nombre y título. La página nace con los
 * textos, los beneficios, las preguntas y los campos de ese objetivo, en borrador; con la IA disponible se puede abrir
 * el editor con «Escribir con IA» listo.
 */
export function NewPageWizard({ onClose }: { onClose: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const navigate = useNavigate();
    const { create } = useLandingMutations(workspace.id);
    const canWrite = useCanWriteWithAi();
    const [step, setStep] = useState<Step>('goal');
    const [goal, setGoal] = useState<LandingGoal>('contact');
    const [template, setTemplate] = useState<LandingTemplate>(GOAL_TEMPLATE.contact);
    const [name, setName] = useState('');
    const [title, setTitle] = useState('');
    const [withAi, setWithAi] = useState(true);
    const index = STEPS.indexOf(step);
    const id = useId();
    const chooseGoal = (g: LandingGoal) => {
        setGoal(g);
        setTemplate(GOAL_TEMPLATE[g]);
    };
    const submit = () =>
        create.mutate(
            { name: name.trim(), goal, template, ...(title.trim() !== '' ? { title: title.trim() } : {}) },
            { onSuccess: (page) => void navigate(`/w/${workspace.id}/paginas-de-captura/${page.id}?vista=diseno${canWrite && withAi ? '&ia=1' : ''}`) },
        );
    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="igr:sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{t(`capture.wizard.steps.${step}.title`)}</DialogTitle>
                    <DialogDescription>{t(`capture.wizard.steps.${step}.description`)}</DialogDescription>
                </DialogHeader>
                <ol className="igr:mb-1 igr:flex igr:gap-1.5" aria-label={t('capture.wizard.progress', { current: index + 1, total: STEPS.length })}>
                    {STEPS.map((s, i) => (
                        <li key={s} className={cn('igr:h-1 igr:flex-1 igr:rounded-full', i <= index ? 'igr:bg-primary' : 'igr:bg-muted')} aria-current={s === step ? 'step' : undefined}>
                            <span className="igr:sr-only">{t(`capture.wizard.steps.${s}.title`)}</span>
                        </li>
                    ))}
                </ol>
                <form
                    className="igr:space-y-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        if (step === 'texts') submit();
                        else setStep(STEPS[index + 1] ?? 'texts');
                    }}
                >
                    {step === 'goal' ? (
                        <div className="igr:grid igr:gap-2 igr:sm:grid-cols-2" role="radiogroup" aria-label={t('capture.wizard.steps.goal.title')}>
                            {GOALS.map((g) => {
                                const Icon = GOAL_ICON[g];
                                return (
                                    <label
                                        key={g}
                                        className={cn(
                                            'igr:flex igr:cursor-pointer igr:items-start igr:gap-3 igr:rounded-xl igr:border igr:p-3 igr:transition-colors igr:has-[:focus-visible]:ring-2 igr:has-[:focus-visible]:ring-ring',
                                            goal === g ? 'igr:border-primary igr:bg-brand-soft/50' : 'igr:hover:bg-accent',
                                        )}
                                    >
                                        <input type="radio" name={`${id}-goal`} className="igr:sr-only" checked={goal === g} onChange={() => chooseGoal(g)} />
                                        <span className="igr:flex igr:size-9 igr:shrink-0 igr:items-center igr:justify-center igr:rounded-lg igr:bg-brand-soft igr:text-brand-soft-foreground">
                                            <Icon className="igr:size-4.5" aria-hidden />
                                        </span>
                                        <span className="igr:min-w-0">
                                            <span className="igr:block igr:text-[13px] igr:font-medium">{t(`capture.wizard.goals.${g}.name`)}</span>
                                            <span className="igr:block igr:text-caption igr:text-muted-foreground">{t(`capture.wizard.goals.${g}.help`)}</span>
                                        </span>
                                    </label>
                                );
                            })}
                        </div>
                    ) : null}
                    {step === 'template' ? <TemplatePicker value={template} accent={DEFAULT_ACCENT} onChange={setTemplate} /> : null}
                    {step === 'texts' ? (
                        <>
                            <div className="igr:space-y-1.5">
                                <Label htmlFor={`${id}-name`}>{t('capture.pages.create.name')}</Label>
                                <Input
                                    id={`${id}-name`}
                                    required
                                    minLength={2}
                                    maxLength={160}
                                    value={name}
                                    onChange={(e) => setName(e.target.value)}
                                    placeholder={t(`capture.wizard.goals.${goal}.namePlaceholder`)}
                                    autoFocus
                                />
                                <p className="igr:text-caption igr:text-muted-foreground">{t('capture.pages.create.nameHelp')}</p>
                            </div>
                            <div className="igr:space-y-1.5">
                                <Label htmlFor={`${id}-title`}>{t('capture.pages.create.headline')}</Label>
                                <Input id={`${id}-title`} maxLength={120} value={title} onChange={(e) => setTitle(e.target.value)} placeholder={t(`capture.wizard.goals.${goal}.title`)} />
                                <p className="igr:text-caption igr:text-muted-foreground">{t('capture.wizard.titleHelp')}</p>
                            </div>
                            {canWrite ? (
                                <label className="igr:flex igr:items-start igr:gap-2 igr:rounded-lg igr:border igr:p-3 igr:text-[13px]">
                                    <input type="checkbox" className="igr:mt-0.5 igr:size-4 igr:accent-primary" checked={withAi} onChange={(e) => setWithAi(e.target.checked)} />
                                    <span>
                                        <span className="igr:flex igr:items-center igr:gap-1.5 igr:font-medium">
                                            <Sparkles className="igr:size-4 igr:text-primary" aria-hidden />
                                            {t('capture.wizard.ai')}
                                        </span>
                                        <span className="igr:block igr:text-caption igr:text-muted-foreground">{t('capture.wizard.aiHelp')}</span>
                                    </span>
                                </label>
                            ) : null}
                            {create.isError ? <FieldError message={errorMessage(create.error, t)} /> : null}
                        </>
                    ) : null}
                    <DialogFooter>
                        {index > 0 ? (
                            <Button type="button" variant="ghost" onClick={() => setStep(STEPS[index - 1] ?? 'goal')}>
                                <ArrowLeft aria-hidden />
                                {t('capture.wizard.back')}
                            </Button>
                        ) : (
                            <Button type="button" variant="ghost" onClick={onClose}>
                                {t('common.cancel')}
                            </Button>
                        )}
                        {step === 'texts' ? (
                            <Button type="submit" loading={create.isPending} disabled={name.trim().length < 2}>
                                {t('capture.pages.create.submit')}
                            </Button>
                        ) : (
                            <Button type="submit">
                                {t('capture.wizard.next')}
                                <ArrowRight aria-hidden />
                            </Button>
                        )}
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
