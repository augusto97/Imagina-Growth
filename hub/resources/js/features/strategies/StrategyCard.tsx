import { ArrowRight, CircleAlert, Lock, TrendingUp } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { IconTile } from '@/components/ui/icon-tile';
import { useWorkspace } from '@/hooks/useWorkspace';
import { cn } from '@/lib/cn';
import { formatMoney, formatNumber } from '@/lib/format';
import { nextFix } from './api';
import { cardAction, statusBadge, strategyStyle } from './presentation';
import type { StrategySummary } from './types';

/** Lo que logró este mes (dinero o conversiones), o null si todavía nada. */
export function ResultLine({ strategy, currency }: { strategy: Pick<StrategySummary, 'result'>; currency: string }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const r = strategy.result;
    if (!r) {
        return null;
    }
    const revenue = Number(r.revenue);
    if (revenue > 0) {
        return <>{t('strategies.card.generated', { amount: formatMoney(revenue, currency, workspace.locale) })}</>;
    }
    if (r.conversions && r.conversions.count > 0) {
        return <>{t(`strategies.conversions.${r.conversions.kind}`, { count: r.conversions.count, formatted: formatNumber(r.conversions.count, workspace.locale) })}</>;
    }
    if (r.messages !== null && r.messages > 0) {
        return <>{t('strategies.card.messages', { count: r.messages, formatted: formatNumber(r.messages, workspace.locale) })}</>;
    }
    return <>{t('strategies.card.waitingResults')}</>;
}

/**
 * Una estrategia en el catálogo, el Inicio o el asistente: su resultado (nombre y promesa), el estado y, abajo, lo que
 * logró este mes, lo que hay en juego o lo que le falta; toda la tarjeta abre el detalle (`to`).
 */
export function StrategyCard({ strategy, currency, to, showRecommended = true, className }: { strategy: StrategySummary; currency: string; to: string; showRecommended?: boolean; className?: string }) {
    const { t } = useTranslation();
    const { icon, tone } = strategyStyle(strategy);
    const action = cardAction(strategy);
    const fix = action === 'fix' ? nextFix(strategy) : null;
    const on = strategy.status === 'active' || strategy.status === 'attention';
    return (
        <Link
            to={to}
            className={cn(
                'igr:group igr:flex igr:min-w-0 igr:flex-col igr:rounded-xl igr:border igr:bg-card igr:p-4 igr:shadow-xs igr:transition-[box-shadow,border-color] igr:duration-150 igr:sm:p-5',
                'igr:hover:border-primary/30 igr:hover:shadow-md igr:focus-visible:outline-none igr:focus-visible:ring-2 igr:focus-visible:ring-ring',
                action === 'soon' && 'igr:bg-card/70',
                className,
            )}
        >
            <div className="igr:flex igr:items-start igr:gap-3">
                <IconTile icon={icon} tone={action === 'soon' ? 'slate' : tone} />
                <div className="igr:min-w-0 igr:flex-1">
                    <h3 className="igr:text-[15px] igr:leading-5 igr:font-semibold igr:tracking-tight igr:text-foreground">{strategy.name}</h3>
                    <div className="igr:mt-1.5 igr:flex igr:flex-wrap igr:gap-1.5">
                        <Badge variant={statusBadge(strategy.status)}>{t(`strategies.status.${strategy.status}`)}</Badge>
                        {showRecommended && strategy.recommended_rank !== null && strategy.recommended_rank <= 3 && !on && action !== 'soon' ? (
                            <Badge variant="outline">{t('strategies.card.forYou')}</Badge>
                        ) : null}
                    </div>
                </div>
            </div>
            <p className="igr:mt-3 igr:line-clamp-3 igr:flex-1 igr:text-support igr:text-muted-foreground">{strategy.promise}</p>
            <div className="igr:mt-4 igr:flex igr:min-h-9 igr:items-end igr:justify-between igr:gap-3 igr:border-t igr:pt-3">
                <p className="igr:min-w-0 igr:flex-1 igr:text-caption igr:text-muted-foreground">
                    {on ? (
                        <span className="igr:inline-flex igr:items-center igr:gap-1 igr:font-medium igr:text-foreground igr:tabular-nums">
                            {strategy.status === 'attention' ? (
                                <CircleAlert className="igr:size-3.5 igr:shrink-0 igr:text-warning" aria-hidden />
                            ) : (
                                <TrendingUp className="igr:size-3.5 igr:shrink-0 igr:text-success" aria-hidden />
                            )}
                            {strategy.status === 'attention' && strategy.problems[0] ? (
                                <span className="igr:line-clamp-2 igr:font-normal">{strategy.problems[0].message}</span>
                            ) : (
                                <ResultLine strategy={strategy} currency={currency} />
                            )}
                        </span>
                    ) : action === 'plan' ? (
                        <span className="igr:inline-flex igr:items-center igr:gap-1">
                            <Lock className="igr:size-3.5 igr:shrink-0" aria-hidden />
                            {strategy.plans[0] ? t('strategies.card.inPlan', { plan: strategy.plans[0].name }) : t('strategies.status.not_in_plan')}
                        </span>
                    ) : fix ? (
                        <span className="igr:line-clamp-2">{t('strategies.card.needs', { what: fix.label })}</span>
                    ) : strategy.potential ? (
                        <span className="igr:line-clamp-2">{strategy.potential.summary}</span>
                    ) : action === 'soon' ? (
                        <span>{strategy.unavailable_reason ?? t('strategies.status.coming_soon')}</span>
                    ) : null}
                </p>
                {action === 'soon' ? null : (
                    <span className="igr:inline-flex igr:shrink-0 igr:items-center igr:gap-1 igr:text-caption igr:font-medium igr:text-primary">
                        {t(`strategies.card.action.${action}`)}
                        <ArrowRight className="igr:size-3.5 igr:transition-transform igr:group-hover:translate-x-0.5" aria-hidden />
                    </span>
                )}
            </div>
        </Link>
    );
}
