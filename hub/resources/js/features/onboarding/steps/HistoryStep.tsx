import { ArrowRight, CalendarCheck, Coins, Inbox, Package, ShoppingBag, Users } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { Callout } from '@/components/ui/callout';
import { Illustration } from '@/components/ui/illustrations';
import { Progress } from '@/components/ui/progress';
import { StatCard } from '@/components/ui/stat-card';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatMoney, formatNumber, formatPercent } from '@/lib/format';
import { speaksServices, stepOf } from '../flow';
import { StepFrame } from '../StepFrame';
import type { LearnedSoFar, Onboarding } from '../types';

/*
 * Paso 3 (docs/diseno/rediseno-v2.md §4.2): el avance de la importación del historial y, mientras tanto, lo que ya se
 * aprendió. Una tienda ve clientes, pedidos, ventas y sus productos más vendidos; un negocio sin tienda, las personas,
 * los contactos que recibió, sus citas y de dónde llegan. Sin web conectada no hay historial que traer: se explica qué
 * va a aprender la plataforma en cuanto lleguen contactos. Se puede seguir mientras termina.
 */

function Learned({ learned, services, currency }: { learned: LearnedSoFar; services: boolean; currency: string }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const n = (v: number) => formatNumber(v, workspace.locale);
    if (services) {
        const total = learned.sources.reduce((s, x) => s + x.count, 0);
        return (
            <div className="igr:space-y-4">
                <div className="igr:grid igr:gap-3 igr:sm:grid-cols-3">
                    <StatCard label={t('onboarding.wizard.history.contacts')} value={n(learned.contacts)} icon={Users} tone="sky" />
                    <StatCard label={t('onboarding.wizard.history.leads')} value={n(learned.leads)} icon={Inbox} tone="rose" />
                    <StatCard label={t('onboarding.wizard.history.appointments')} value={n(learned.appointments)} icon={CalendarCheck} tone="teal" />
                </div>
                {learned.sources.length > 0 ? (
                    <div className="igr:rounded-xl igr:border igr:bg-card igr:p-4">
                        <p className="igr:text-support igr:font-semibold igr:text-foreground">{t('onboarding.wizard.history.sources')}</p>
                        <ul className="igr:mt-2 igr:space-y-1.5">
                            {learned.sources.map((s) => (
                                <li key={s.origin} className="igr:flex igr:justify-between igr:gap-3 igr:text-support">
                                    <span className="igr:min-w-0 igr:truncate igr:text-foreground">{s.label}</span>
                                    <span className="igr:shrink-0 igr:text-muted-foreground igr:tabular-nums">{formatPercent(total > 0 ? s.count / total : 0, workspace.locale, 0)}</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                ) : null}
            </div>
        );
    }
    return (
        <div className="igr:space-y-4">
            <div className="igr:grid igr:gap-3 igr:sm:grid-cols-3">
                <StatCard label={t('onboarding.wizard.history.customers')} value={n(learned.customers)} icon={Users} tone="sky" />
                <StatCard label={t('onboarding.wizard.history.orders')} value={n(learned.orders)} icon={ShoppingBag} tone="indigo" />
                <StatCard label={t('onboarding.wizard.history.revenue')} value={formatMoney(learned.revenue, currency, workspace.locale)} icon={Coins} tone="emerald" />
            </div>
            {learned.top_products.length > 0 ? (
                <div className="igr:rounded-xl igr:border igr:bg-card igr:p-4">
                    <p className="igr:text-support igr:font-semibold igr:text-foreground">{t('onboarding.wizard.history.top')}</p>
                    <ol className="igr:mt-2 igr:space-y-1.5">
                        {learned.top_products.map((p, i) => (
                            <li key={p} className="igr:flex igr:items-center igr:gap-2.5 igr:text-support igr:text-foreground">
                                <Package className="igr:size-4 igr:shrink-0 igr:text-muted-foreground" aria-hidden />
                                <span className="igr:sr-only">{i + 1}.</span>
                                <span className="igr:min-w-0 igr:truncate">{p}</span>
                            </li>
                        ))}
                    </ol>
                </div>
            ) : null}
        </div>
    );
}

export function HistoryStep({ state, base, onBack, onNext, busy }: { state: Onboarding; base: string; onBack: () => void; onNext: () => void; busy: boolean }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const services = speaksServices(state.business_type ?? workspace.business_type);
    const connect = stepOf(state, 'connect');
    const history = stepOf(state, 'history');
    // Recién conectada, la web todavía no empezó a mandar su historial: se espera como si ya corriera.
    const running = history?.status === 'running' || history?.status === 'queued' || (history?.status === null && !history.done);
    const stopped = history?.status === 'failed' || history?.status === 'paused';
    const learned = history?.learned ?? null;
    const progress = history?.progress ?? 0;
    const siteId = history?.site_id ?? null;

    return (
        <StepFrame
            step="history"
            title={t(services ? 'onboarding.wizard.history.titleServices' : 'onboarding.wizard.history.title')}
            description={t(services ? 'onboarding.wizard.history.descriptionServices' : 'onboarding.wizard.history.description')}
            onBack={onBack}
            onNext={onNext}
            busy={busy}
            footerNote={running ? t('onboarding.wizard.history.keepGoing') : undefined}
        >
            {!connect?.done ? (
                <div className="igr:flex igr:flex-col igr:items-center igr:gap-4 igr:rounded-2xl igr:border igr:bg-card igr:p-6 igr:text-center igr:sm:flex-row igr:sm:text-left">
                    <Illustration name="customers" className="igr:w-32 igr:shrink-0" />
                    <div className="igr:min-w-0">
                        <p className="igr:text-body igr:font-semibold igr:text-foreground">{t('onboarding.wizard.history.noSiteTitle')}</p>
                        <p className="igr:mt-1 igr:text-support igr:text-muted-foreground">{t(services ? 'onboarding.wizard.history.noSiteServices' : 'onboarding.wizard.history.noSite')}</p>
                    </div>
                </div>
            ) : (
                <div className="igr:space-y-5">
                    <div className="igr:rounded-2xl igr:border igr:bg-card igr:p-5" aria-live="polite">
                        <div className="igr:flex igr:flex-wrap igr:items-baseline igr:justify-between igr:gap-2">
                            <p className="igr:text-body igr:font-semibold igr:text-foreground">
                                {history?.status === null && running
                                    ? t(services ? 'onboarding.wizard.history.startingServices' : 'onboarding.wizard.history.starting')
                                    : running
                                    ? t('onboarding.wizard.history.running', { pct: formatPercent(progress, workspace.locale, 0) })
                                    : stopped
                                      ? t('onboarding.wizard.history.stopped')
                                      : t('onboarding.wizard.history.done')}
                            </p>
                            {running ? <span className="igr:text-caption igr:text-muted-foreground">{t('onboarding.wizard.history.noMessages')}</span> : null}
                        </div>
                        <Progress value={running || stopped ? progress : 1} tone={stopped ? 'warning' : running ? 'brand' : 'success'} className="igr:mt-3 igr:h-2" label={t('onboarding.wizard.history.progressLabel')} />
                        {stopped && siteId ? (
                            <Callout
                                tone="warning"
                                className="igr:mt-4"
                                action={
                                    <Button asChild size="sm" variant="outline">
                                        <Link to={`${base}/sitios/${siteId}`}>
                                            {t('onboarding.wizard.history.review')}
                                            <ArrowRight aria-hidden />
                                        </Link>
                                    </Button>
                                }
                            >
                                {t('onboarding.wizard.history.stoppedBody')}
                            </Callout>
                        ) : null}
                    </div>
                    {learned && (learned.contacts > 0 || learned.orders > 0 || learned.leads > 0) ? (
                        <div>
                            <p className="igr:mb-3 igr:text-support igr:font-semibold igr:text-foreground">{running ? t('onboarding.wizard.history.learning') : t('onboarding.wizard.history.learned')}</p>
                            <Learned learned={learned} services={services} currency={state.workspace.currency} />
                        </div>
                    ) : null}
                </div>
            )}
        </StepFrame>
    );
}
