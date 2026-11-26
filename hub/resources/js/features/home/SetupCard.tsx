import { ArrowRight, Sparkles } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { IconTile } from '@/components/ui/icon-tile';
import { Progress } from '@/components/ui/progress';
import { useCan } from '@/hooks/useWorkspace';
import type { HomeSetup } from './types';

/*
 * La tarjeta de progreso de la configuración (docs/diseno/rediseno-v2.md §4.3): se ve arriba del Inicio mientras el
 * asistente no termine, dice cuánto va y cuál es el paso que sigue, y lo retoma donde quedó. Nada bloquea explorar.
 * Solo para quien puede configurar el espacio de trabajo.
 */
export function SetupCard({ setup, base }: { setup: HomeSetup; base: string }) {
    const { t } = useTranslation();
    const canSetup = useCan('manageWorkspace');
    if (!setup.visible || !canSetup) {
        return null;
    }
    const ratio = setup.total > 0 ? setup.completed / setup.total : 0;
    return (
        <section
            aria-labelledby="home-setup"
            className="igr:mb-6 igr:flex igr:flex-col igr:gap-4 igr:rounded-2xl igr:border igr:border-primary/15 igr:bg-card igr:p-4 igr:shadow-xs igr:sm:flex-row igr:sm:items-center igr:sm:p-5"
        >
            <IconTile icon={Sparkles} tone="brand" size="lg" className="igr:max-sm:hidden" />
            <div className="igr:min-w-0 igr:flex-1">
                <div className="igr:flex igr:flex-wrap igr:items-baseline igr:gap-x-3 igr:gap-y-1">
                    <h2 id="home-setup" className="igr:text-body igr:font-semibold igr:text-foreground">
                        {setup.completed === 0 ? t('home.setup.titleNew') : t('home.setup.title')}
                    </h2>
                    <span className="igr:text-caption igr:text-muted-foreground igr:tabular-nums">{t('home.setup.progress', { done: setup.completed, total: setup.total })}</span>
                </div>
                <p className="igr:mt-0.5 igr:text-support igr:text-muted-foreground">
                    {setup.current_step === 'done' ? t('home.setup.almost') : t('home.setup.next', { step: t(`home.setup.steps.${setup.current_step}`) })}
                </p>
                <Progress value={ratio} className="igr:mt-3 igr:max-w-md" label={t('home.setup.progress', { done: setup.completed, total: setup.total })} />
            </div>
            <Button asChild variant="brand" className="igr:shrink-0 igr:max-sm:w-full">
                <Link to={`${base}/configurar`}>
                    {setup.completed === 0 ? t('home.setup.start') : t('home.setup.resume')}
                    <ArrowRight aria-hidden />
                </Link>
            </Button>
        </section>
    );
}
