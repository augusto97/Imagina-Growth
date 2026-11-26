import { ArrowRight, Sparkles } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link, Navigate } from 'react-router';
import { Button } from '@/components/ui/button';
import { ErrorState } from '@/components/ui/misc';
import { PageHeader } from '@/components/ui/page-header';
import { Skeleton } from '@/components/ui/skeleton';
import { StatCardSkeleton } from '@/components/ui/stat-card';
import { preloadAssistant } from '@/features/assistant/AssistantHost';
import { useAssistant } from '@/features/assistant/model';
import { useConnectionAlerts } from '@/features/connections/api';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { useHome } from './api';
import { BusinessKpis } from './BusinessKpis';
import { ForToday } from './ForToday';
import { greetingKey, hourIn, monthName } from './greeting';
import { HealthNotices } from './HealthNotices';
import { HomeStrategies } from './HomeStrategies';
import { MoneyHero } from './MoneyHero';
import { SetupCard } from './SetupCard';

/*
 * El Inicio, el tablero del dueño (docs/diseno/rediseno-v2.md §5), con una sola llamada (`GET home`): saludo, la
 * tarjeta de progreso mientras la configuración no termine, el dinero que generó la plataforma este mes (o lo que puede
 * recuperar), «Para hoy», tus estrategias, tu negocio (los KPI que aplican a tienda o servicios) y los avisos de salud.
 * La primera vez que una persona entra a un espacio de trabajo la lleva a la bienvenida.
 */

/** El Inicio mientras llegan sus datos: cada bloque en su lugar y con su alto, para que nada salte. */
function HomeSkeleton() {
    return (
        <div aria-busy>
            <Skeleton className="igr:h-8 igr:w-64" />
            <Skeleton className="igr:mt-2 igr:mb-6 igr:h-5 igr:w-80 igr:max-w-full" />
            <div className="igr:grid igr:gap-4 igr:lg:grid-cols-[minmax(0,1.55fr)_minmax(0,1fr)]">
                <Skeleton className="igr:h-72 igr:rounded-2xl" />
                <Skeleton className="igr:h-72 igr:rounded-xl igr:lg:row-span-2" />
                <Skeleton className="igr:h-56 igr:rounded-xl" />
            </div>
            <Skeleton className="igr:mt-8 igr:h-6 igr:w-40" />
            <div className="igr:mt-3 igr:grid igr:gap-3 igr:sm:grid-cols-2 igr:lg:grid-cols-4">
                {Array.from({ length: 4 }, (_, i) => (
                    <StatCardSkeleton key={i} />
                ))}
            </div>
        </div>
    );
}

export function HomePage() {
    const { t } = useTranslation();
    const { workspace, me } = useWorkspace();
    const canAsk = useCan('useAi');
    const askAi = useAssistant((st) => st.ask);
    const home = useHome(workspace.id);
    // Los avisos de conexiones salen abajo, con los de salud; se piden a la vez que el Inicio.
    const alerts = useConnectionAlerts(workspace.id);
    const base = `/w/${workspace.id}`;

    if (home.isError) {
        return <ErrorState error={home.error} onRetry={() => void home.refetch()} />;
    }
    if (!home.data) {
        return <HomeSkeleton />;
    }
    const data = home.data;
    // La primera vez de cada persona en este espacio de trabajo: la bienvenida (una sola pantalla) y después el Inicio.
    if (!data.setup.welcome_seen) {
        return <Navigate to={`${base}/bienvenida`} replace />;
    }

    const name = data.greeting.name ?? me.user.name.split(' ')[0] ?? '';
    const greeting = t(`home.greeting.${greetingKey(hourIn(data.workspace.timezone))}`, { name });
    const showHealth = data.health.length > 0 || (alerts.data?.data.length ?? 0) > 0;

    return (
        <>
            <PageHeader
                title={greeting}
                description={t('home.subtitle', { workspace: data.workspace.name, month: monthName(data.period.from, workspace.locale, true) })}
                actions={
                    <>
                        {canAsk ? (
                            <Button variant="outline" size="sm" onClick={() => askAi()} onMouseEnter={preloadAssistant} onFocus={preloadAssistant}>
                                <Sparkles aria-hidden />
                                {t('nav.askAi')}
                            </Button>
                        ) : null}
                        <Button asChild variant="outline" size="sm">
                            <Link to={`${base}/resultados`}>
                                {t('home.openResults')}
                                <ArrowRight aria-hidden />
                            </Link>
                        </Button>
                    </>
                }
            />

            <SetupCard setup={data.setup} base={base} />

            {/*
             * Escritorio: a la izquierda el dinero y tus estrategias, a la derecha «Para hoy» a lo alto de las dos (no
             * deja huecos aunque la lista sea larga). En el celular, en el orden del plan: dinero, para hoy, estrategias.
             */}
            <div className="igr:grid igr:items-start igr:gap-4 igr:lg:grid-cols-[minmax(0,1.55fr)_minmax(0,1fr)]">
                <div className="igr:lg:col-start-1 igr:lg:row-start-1">
                    <MoneyHero home={data} base={base} />
                </div>
                <div className="igr:lg:col-start-2 igr:lg:row-span-2 igr:lg:row-start-1">
                    <ForToday home={data} base={base} />
                </div>
                <div className="igr:lg:col-start-1 igr:lg:row-start-2">
                    <HomeStrategies home={data} base={base} />
                </div>
            </div>

            <div className="igr:mt-8">
                <BusinessKpis home={data} base={base} />
            </div>

            {showHealth ? (
                <section aria-label={t('home.health.title')} className="igr:mt-8">
                    <HealthNotices health={data.health} base={base} />
                </section>
            ) : null}
        </>
    );
}
