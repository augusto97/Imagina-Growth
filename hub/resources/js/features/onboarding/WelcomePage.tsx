import { useQueryClient } from '@tanstack/react-query';
import { ArrowRight, Coins, Rocket, ShieldCheck, Users, type LucideIcon } from 'lucide-react';
import { useEffect, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { IconTile, type IconTone } from '@/components/ui/icon-tile';
import { Illustration, type IllustrationName } from '@/components/ui/illustrations';
import { homeKeys } from '@/features/home/api';
import type { Home } from '@/features/home/types';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatMoney, formatNumber } from '@/lib/format';
import { useOnboarding, useUpdateOnboarding } from './api';
import { audienceOf, type WelcomeAudience } from './flow';
import { OnboardingShell } from './OnboardingShell';

/*
 * La bienvenida (docs/diseno/rediseno-v2.md §4.1): una sola pantalla con la promesa, los tres pilares con un ejemplo
 * concreto cada uno (para tienda, para servicios o neutro si todavía no se sabe) y «Configurar en 10 minutos». Se ve
 * una vez por persona y espacio de trabajo (se marca al abrirla) y se vuelve a ver desde el menú de la persona.
 */

const PILLARS: { key: 'know' | 'sell' | 'results'; icon: LucideIcon; tone: IconTone; illustration: IllustrationName }[] = [
    { key: 'know', icon: Users, tone: 'sky', illustration: 'customers' },
    { key: 'sell', icon: Rocket, tone: 'violet', illustration: 'strategies' },
    { key: 'results', icon: Coins, tone: 'emerald', illustration: 'results' },
];

/** Los montos de los ejemplos, en la moneda del negocio (valores redondos que se leen bien en COP, MXN o USD). */
const EXAMPLE_AMOUNTS: Record<string, { cart: number; month: number; won: number }> = {
    COP: { cart: 180_000, month: 4_250_000, won: 12_400_000 },
    MXN: { cart: 850, month: 24_500, won: 68_000 },
};
const USD_LIKE = { cart: 45, month: 1_250, won: 3_400 };

function Pillar({ pillar, audience, amounts }: { pillar: (typeof PILLARS)[number]; audience: WelcomeAudience; amounts: { cart: string; month: string; won: string } }) {
    const { t } = useTranslation();
    return (
        <article className="igr:flex igr:min-w-0 igr:flex-col igr:rounded-2xl igr:border igr:bg-card igr:p-5 igr:shadow-xs">
            <div className="igr:flex igr:items-center igr:gap-3">
                <IconTile icon={pillar.icon} tone={pillar.tone} />
                <h2 className="igr:text-body igr:font-semibold igr:text-foreground">{t(`onboarding.intro.pillars.${pillar.key}.title`)}</h2>
            </div>
            <p className="igr:mt-2 igr:text-support igr:text-muted-foreground">{t(`onboarding.intro.pillars.${pillar.key}.feel`)}</p>
            <div className="igr:mt-4 igr:flex igr:flex-1 igr:flex-col igr:justify-end">
                <Illustration name={pillar.illustration} className="igr:mx-auto igr:w-32 igr:max-sm:hidden" />
                <p className="igr:mt-3 igr:rounded-xl igr:bg-muted/60 igr:px-3.5 igr:py-3 igr:text-support igr:text-foreground">
                    <span className="igr:mb-1 igr:block igr:text-caption igr:font-semibold igr:tracking-wide igr:text-muted-foreground igr:uppercase">
                        {t('onboarding.intro.example')}
                    </span>
                    {t(`onboarding.intro.pillars.${pillar.key}.${audience}`, amounts)}
                </p>
            </div>
        </article>
    );
}

export function WelcomeContent({ base }: { base: string }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const qc = useQueryClient();
    const onboarding = useOnboarding(workspace.id);
    const update = useUpdateOnboarding(workspace.id);
    const marked = useRef(false);

    // Se marca vista al abrirla (la ve una vez); el Inicio en caché se entera al instante para no volver a traerla.
    useEffect(() => {
        if (marked.current) return;
        marked.current = true;
        qc.setQueryData<Home>(homeKeys.home(workspace.id), (old) => (old ? { ...old, setup: { ...old.setup, welcome_seen: true } } : old));
        update.mutate({ welcome_seen: true });
    }, [qc, update, workspace.id]);

    const audience = audienceOf(workspace.business_type ?? onboarding.data?.business_type ?? null);
    const raw = EXAMPLE_AMOUNTS[workspace.currency] ?? USD_LIKE;
    const amounts = {
        cart: formatMoney(raw.cart, workspace.currency, workspace.locale),
        month: formatMoney(raw.month, workspace.currency, workspace.locale),
        won: formatMoney(raw.won, workspace.currency, workspace.locale),
        days: formatNumber(64, workspace.locale),
    };
    const canSetup = workspace.abilities.includes('manageWorkspace');
    const done = onboarding.data?.status === 'completed';

    return (
        <div className="igr:hero-surface igr:min-h-full">
            <div className="igr:mx-auto igr:w-full igr:max-w-6xl igr:px-4 igr:pt-10 igr:md:px-8 igr:md:py-14">
                <div className="igr:mx-auto igr:max-w-3xl igr:text-center">
                    <p className="igr:text-caption igr:font-semibold igr:tracking-wide igr:text-brand-soft-foreground igr:uppercase">{t('onboarding.intro.eyebrow', { name: workspace.name })}</p>
                    <h1 className="igr:mt-3 igr:text-[28px] igr:leading-9 igr:font-semibold igr:tracking-tight igr:text-foreground igr:sm:text-[36px] igr:sm:leading-[44px]">
                        {t(`onboarding.intro.promise.${audience}`)}
                    </h1>
                    <p className="igr:mx-auto igr:mt-3 igr:max-w-2xl igr:text-body igr:text-muted-foreground igr:sm:text-[15px] igr:sm:leading-6">{t(`onboarding.intro.promiseBody.${audience}`)}</p>
                </div>

                <div className="igr:mt-10 igr:grid igr:gap-4 igr:md:grid-cols-3">
                    {PILLARS.map((p) => (
                        <Pillar key={p.key} pillar={p} audience={audience} amounts={amounts} />
                    ))}
                </div>

                {/* En el celular las acciones quedan fijas abajo, al alcance del pulgar. */}
                <div className="igr:mt-10 igr:flex igr:flex-col igr:items-center igr:gap-3 igr:max-md:sticky igr:max-md:bottom-0 igr:max-md:-mx-4 igr:max-md:border-t igr:max-md:bg-card/95 igr:max-md:px-4 igr:max-md:pt-3 igr:max-md:pb-4 igr:max-md:backdrop-blur">
                    <div className="igr:flex igr:w-full igr:flex-col igr:items-stretch igr:justify-center igr:gap-2 igr:sm:w-auto igr:sm:flex-row igr:sm:items-center">
                        {canSetup && !done ? (
                            <>
                                <Button asChild variant="brand" size="lg">
                                    <Link to={`${base}/configurar`}>
                                        {onboarding.data?.status === 'in_progress' || onboarding.data?.status === 'skipped' ? t('onboarding.intro.resume') : t('onboarding.intro.cta')}
                                        <ArrowRight aria-hidden />
                                    </Link>
                                </Button>
                                <Button asChild variant="ghost" size="lg">
                                    <Link to={base}>{t('onboarding.intro.explore')}</Link>
                                </Button>
                            </>
                        ) : (
                            <Button asChild variant="brand" size="lg">
                                <Link to={base}>
                                    {t('onboarding.intro.goHome')}
                                    <ArrowRight aria-hidden />
                                </Link>
                            </Button>
                        )}
                    </div>
                    <p className="igr:flex igr:items-center igr:gap-1.5 igr:text-caption igr:text-muted-foreground">
                        <ShieldCheck className="igr:size-3.5 igr:shrink-0 igr:text-success" aria-hidden />
                        {canSetup && !done ? t('onboarding.intro.reassure') : t('onboarding.intro.reassureMember')}
                    </p>
                </div>
            </div>
        </div>
    );
}

export function WelcomePage() {
    const { t } = useTranslation();
    return <OnboardingShell exitLabel={t('onboarding.intro.skip')}>{(base) => <WelcomeContent base={base} />}</OnboardingShell>;
}
