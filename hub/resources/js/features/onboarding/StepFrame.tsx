import { ArrowLeft, ArrowRight } from 'lucide-react';
import { useEffect, useRef, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { STEP_ORDER } from './api';
import type { OnboardingStepKey } from './types';

/*
 * El marco de cada paso del asistente: «Paso 2 de 6», el título (recibe el foco al cambiar de paso, para el lector de
 * pantalla y el teclado), para qué sirve, el contenido y abajo las acciones: volver, hacerlo después y continuar. En el
 * celular la barra de acciones queda fija abajo, al alcance del pulgar.
 */
export function StepFrame({
    step,
    title,
    description,
    children,
    onBack,
    onLater,
    laterLabel,
    onNext,
    nextLabel,
    nextDisabled = false,
    busy = false,
    footerNote,
}: {
    step: OnboardingStepKey;
    title: ReactNode;
    description?: ReactNode;
    children: ReactNode;
    onBack?: () => void;
    onLater?: () => void;
    laterLabel?: string;
    onNext?: () => void;
    nextLabel?: string;
    nextDisabled?: boolean;
    busy?: boolean;
    footerNote?: ReactNode;
}) {
    const { t } = useTranslation();
    const heading = useRef<HTMLHeadingElement>(null);
    const index = STEP_ORDER.indexOf(step);

    // Al cambiar de paso: arriba del todo y el foco en el título.
    useEffect(() => {
        document.getElementById('igr-main')?.scrollTo({ top: 0 });
        heading.current?.focus({ preventScroll: true });
    }, [step]);

    return (
        <section aria-labelledby="wizard-title" className="igr:flex igr:flex-1 igr:flex-col">
            <div className="igr:mx-auto igr:w-full igr:max-w-3xl igr:flex-1 igr:px-4 igr:pt-6 igr:pb-8 igr:md:px-8 igr:md:pt-10">
                {/* En el celular la barra de pasos ya dice «Paso 2 de 6». */}
                <p className="igr:text-caption igr:font-semibold igr:tracking-wide igr:text-brand-soft-foreground igr:uppercase igr:max-md:hidden">
                    {t('onboarding.wizard.stepOf', { current: index + 1, total: STEP_ORDER.length })}
                </p>
                <h1 id="wizard-title" ref={heading} tabIndex={-1} className="igr:mt-1.5 igr:text-[22px] igr:leading-7 igr:font-semibold igr:tracking-tight igr:text-foreground igr:outline-none igr:sm:text-title">
                    {title}
                </h1>
                {description ? <p className="igr:mt-2 igr:max-w-2xl igr:text-body igr:text-muted-foreground">{description}</p> : null}
                <div className="igr:mt-7">{children}</div>
            </div>
            {onBack || onLater || onNext ? (
                <footer className="igr:sticky igr:bottom-0 igr:z-10 igr:border-t igr:bg-card/95 igr:backdrop-blur">
                    <div className="igr:mx-auto igr:flex igr:w-full igr:max-w-3xl igr:flex-wrap igr:items-center igr:gap-2 igr:px-4 igr:py-3 igr:md:px-8">
                        {onBack ? (
                            <Button variant="ghost" onClick={onBack} disabled={busy}>
                                <ArrowLeft aria-hidden />
                                <span className="igr:max-sm:sr-only">{t('onboarding.wizard.back')}</span>
                            </Button>
                        ) : null}
                        <span className="igr:min-w-0 igr:flex-1">
                            {footerNote ? <span className="igr:text-caption igr:text-muted-foreground igr:max-sm:hidden">{footerNote}</span> : null}
                        </span>
                        {onLater ? (
                            <Button variant="ghost" onClick={onLater} disabled={busy}>
                                {laterLabel ?? t('onboarding.wizard.later')}
                            </Button>
                        ) : null}
                        {onNext ? (
                            <Button onClick={onNext} disabled={nextDisabled} loading={busy} size="lg">
                                {nextLabel ?? t('onboarding.wizard.next')}
                                <ArrowRight aria-hidden />
                            </Button>
                        ) : null}
                    </div>
                </footer>
            ) : null}
        </section>
    );
}
