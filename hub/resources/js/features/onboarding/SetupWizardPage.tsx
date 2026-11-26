import { useQueryClient } from '@tanstack/react-query';
import { Pause } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useSearchParams } from 'react-router';
import { keys } from '@/api/queries';
import { Button } from '@/components/ui/button';
import { Stepper } from '@/components/ui/checklist';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/misc';
import { Skeleton, SkeletonText } from '@/components/ui/skeleton';
import { useWorkspace } from '@/hooks/useWorkspace';
import { nextStep, STEP_ORDER, useOnboarding, useUpdateOnboarding } from './api';
import { initialStep, previousStep, stepperStatuses } from './flow';
import { OnboardingShell } from './OnboardingShell';
import { BusinessStep } from './steps/BusinessStep';
import { ChannelsStep } from './steps/ChannelsStep';
import { ConnectStep } from './steps/ConnectStep';
import { DoneStep } from './steps/DoneStep';
import { HistoryStep } from './steps/HistoryStep';
import { StrategiesStep } from './steps/StrategiesStep';
import type { OnboardingStepKey, OnboardingUpdate } from './types';

/*
 * El asistente de configuración a pantalla completa (docs/diseno/rediseno-v2.md §4.2): 6 pasos con su barra de
 * progreso; el estado vive en el servidor (`GET/PUT onboarding`), así se pausa («Seguir después») y se retoma donde
 * quedó desde cualquier equipo. `?paso=connect` abre un paso directo (lo usan «Para hoy» y el Inicio). Para quien no
 * puede configurar el espacio de trabajo, el asistente dice a quién pedírselo.
 */

function WizardSkeleton() {
    return (
        <div className="igr:mx-auto igr:max-w-3xl igr:px-4 igr:py-10 igr:md:px-8" aria-busy>
            <Skeleton className="igr:h-4 igr:w-24" />
            <Skeleton className="igr:mt-3 igr:h-8 igr:w-80 igr:max-w-full" />
            <SkeletonText lines={2} className="igr:mt-3" />
            <div className="igr:mt-8 igr:grid igr:gap-3 igr:sm:grid-cols-3">
                {Array.from({ length: 3 }, (_, i) => (
                    <Skeleton key={i} className="igr:h-28 igr:rounded-xl" />
                ))}
            </div>
        </div>
    );
}

function Wizard({ base }: { base: string }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const navigate = useNavigate();
    const qc = useQueryClient();
    const [params] = useSearchParams();
    const onboarding = useOnboarding(workspace.id);
    const update = useUpdateOnboarding(workspace.id);
    const [viewing, setViewing] = useState<OnboardingStepKey | null>(null);
    const canSetup = workspace.abilities.includes('manageWorkspace');

    if (onboarding.isError) {
        return (
            <div className="igr:mx-auto igr:max-w-md igr:p-8">
                <ErrorState error={onboarding.error} onRetry={() => void onboarding.refetch()} />
            </div>
        );
    }
    const state = onboarding.data;
    if (!state) {
        return <WizardSkeleton />;
    }
    if (!canSetup) {
        return (
            <div className="igr:mx-auto igr:max-w-xl igr:px-4 igr:py-12">
                <EmptyState
                    illustration="locked"
                    title={t('onboarding.wizard.askTitle')}
                    description={t('onboarding.wizard.askBody')}
                    action={
                        <Button asChild>
                            <Link to={base}>{t('onboarding.shell.exit')}</Link>
                        </Button>
                    }
                />
            </div>
        );
    }

    const step = viewing ?? initialStep(state, params.get('paso'));
    const save = async (changes: OnboardingUpdate) => {
        await update.mutateAsync(changes);
        if (changes.business_type !== undefined || changes.vertical !== undefined) {
            // El tipo de negocio y la vertical cambian el menú y las palabras de toda la app.
            void qc.invalidateQueries({ queryKey: keys.me });
        }
        if (changes.current_step) {
            setViewing(changes.current_step);
        }
    };
    const go = (to: OnboardingStepKey) => {
        setViewing(to);
        update.mutate({ current_step: to });
    };
    const back = () => {
        const prev = previousStep(step);
        if (prev) go(prev);
    };
    const forward = () => go(nextStep(step));
    const finish = async () => {
        await update.mutateAsync({ action: 'complete' });
        void qc.invalidateQueries({ queryKey: keys.me });
        await navigate(base);
    };
    const busy = update.isPending;
    const titles = STEP_ORDER.map((key) => t(`onboarding.wizard.steps.${key}`));

    return (
        <div className="igr:flex igr:min-h-full igr:flex-col">
            <div className="igr:border-b igr:bg-card/60">
                <div className="igr:mx-auto igr:w-full igr:max-w-3xl igr:px-4 igr:py-3 igr:md:px-8 igr:md:py-4">
                    <Stepper steps={stepperStatuses(step).map((s, i) => ({ ...s, title: titles[i] ?? s.key }))} />
                </div>
            </div>
            <div className="igr:flex igr:flex-1 igr:flex-col">
                {step === 'business' ? (
                    <BusinessStep state={state} save={save} busy={busy} />
                ) : step === 'connect' ? (
                    <ConnectStep state={state} base={base} onBack={back} onNext={forward} busy={busy} />
                ) : step === 'history' ? (
                    <HistoryStep state={state} base={base} onBack={back} onNext={forward} busy={busy} />
                ) : step === 'channels' ? (
                    <ChannelsStep state={state} base={base} onBack={back} onNext={forward} busy={busy} />
                ) : step === 'strategies' ? (
                    <StrategiesStep state={state} base={base} onBack={back} onNext={forward} onGoTo={go} busy={busy} />
                ) : (
                    <DoneStep state={state} onBack={back} onFinish={() => void finish()} busy={busy} />
                )}
            </div>
        </div>
    );
}

export function SetupWizardPage() {
    const { t } = useTranslation();
    return (
        <OnboardingShell
            exitLabel={t('onboarding.shell.pause')}
            aside={
                <span className="igr:hidden igr:items-center igr:gap-1.5 igr:text-caption igr:text-muted-foreground igr:md:inline-flex">
                    <Pause className="igr:size-3.5" aria-hidden />
                    {t('onboarding.shell.saved')}
                </span>
            }
        >
            {(base) => <Wizard base={base} />}
        </OnboardingShell>
    );
}
