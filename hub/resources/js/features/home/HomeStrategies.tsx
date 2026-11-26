import { ArrowRight } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { IconTile } from '@/components/ui/icon-tile';
import { nextFix } from '@/features/strategies/api';
import { statusBadge, strategyStyle } from '@/features/strategies/presentation';
import { ResultLine } from '@/features/strategies/StrategyCard';
import type { Home } from './types';

/*
 * «Tus estrategias» (docs/diseno/rediseno-v2.md §5.4): las prendidas con lo que lograron este mes y las 2 o 3 que
 * conviene prender después, cada una a un clic de su detalle.
 */

const ACTIVE_ROWS = 5;

export function HomeStrategies({ home, base }: { home: Pick<Home, 'strategies' | 'workspace'>; base: string }) {
    const { t } = useTranslation();
    const { active, suggested } = home.strategies;
    const currency = home.workspace.currency;
    return (
        <Card className="igr:p-5">
            <div className="igr:mb-4 igr:flex igr:items-center igr:justify-between igr:gap-3">
                <div className="igr:min-w-0">
                    <h2 className="igr:text-body igr:font-semibold igr:text-foreground">{t('home.strategies.title')}</h2>
                    <p className="igr:text-caption igr:text-muted-foreground">
                        {active.length > 0 ? t('home.strategies.activeCount', { count: active.length }) : t('home.strategies.noneActive')}
                    </p>
                </div>
                <Button asChild variant="ghost" size="sm" className="igr:shrink-0">
                    <Link to={`${base}/estrategias`}>
                        {t('home.strategies.all')}
                        <ArrowRight aria-hidden />
                    </Link>
                </Button>
            </div>

            {active.length > 0 ? (
                <ul className="igr:divide-y igr:rounded-xl igr:border">
                    {active.slice(0, ACTIVE_ROWS).map((s) => {
                        const style = strategyStyle(s);
                        return (
                            <li key={s.key}>
                                <Link
                                    to={`${base}/estrategias/${s.key}`}
                                    className="igr:flex igr:items-center igr:gap-3 igr:px-3.5 igr:py-3 igr:transition-colors igr:hover:bg-accent/60 igr:focus-visible:outline-none igr:focus-visible:ring-2 igr:focus-visible:ring-ring igr:first:rounded-t-xl igr:last:rounded-b-xl"
                                >
                                    <IconTile icon={style.icon} tone={style.tone} size="sm" />
                                    <span className="igr:min-w-0 igr:flex-1">
                                        <span className="igr:block igr:text-support igr:font-medium igr:text-foreground">{s.name}</span>
                                        <span className="igr:line-clamp-2 igr:block igr:text-caption igr:text-muted-foreground igr:tabular-nums">
                                            {s.status === 'attention' && s.problems[0] ? s.problems[0].message : <ResultLine strategy={s} currency={currency} />}
                                        </span>
                                    </span>
                                    <Badge variant={statusBadge(s.status)} className={s.status === 'attention' ? 'igr:shrink-0' : 'igr:shrink-0 igr:max-sm:hidden'}>
                                        {t(`strategies.status.${s.status}`)}
                                    </Badge>
                                </Link>
                            </li>
                        );
                    })}
                </ul>
            ) : null}
            {active.length > ACTIVE_ROWS ? (
                <p className="igr:mt-2 igr:text-caption igr:text-muted-foreground">{t('home.strategies.moreActive', { count: active.length - ACTIVE_ROWS })}</p>
            ) : null}

            {suggested.length > 0 ? (
                <>
                    <p className="igr:mt-5 igr:mb-2.5 igr:text-caption igr:font-semibold igr:tracking-wide igr:text-muted-foreground igr:uppercase">{t('home.strategies.suggested')}</p>
                    <ul className="igr:divide-y igr:rounded-xl igr:border">
                        {suggested.map((s) => {
                            const style = strategyStyle(s);
                            const fix = nextFix(s);
                            return (
                                <li key={s.key}>
                                    <Link
                                        to={`${base}/estrategias/${s.key}`}
                                        className="igr:group igr:flex igr:items-start igr:gap-3 igr:px-3.5 igr:py-3 igr:transition-colors igr:hover:bg-accent/60 igr:focus-visible:outline-none igr:focus-visible:ring-2 igr:focus-visible:ring-ring igr:first:rounded-t-xl igr:last:rounded-b-xl"
                                    >
                                        <IconTile icon={style.icon} tone={style.tone} size="sm" />
                                        <span className="igr:min-w-0 igr:flex-1">
                                            <span className="igr:block igr:text-support igr:font-medium igr:text-foreground">{s.name}</span>
                                            <span className="igr:line-clamp-2 igr:block igr:text-caption igr:text-muted-foreground">{s.potential?.headline ?? s.potential?.summary ?? s.promise}</span>
                                        </span>
                                        <span className="igr:inline-flex igr:shrink-0 igr:items-center igr:gap-1 igr:self-center igr:text-caption igr:font-medium igr:text-primary">
                                            <span className="igr:max-sm:sr-only">{fix ? t('home.strategies.fix') : t('home.strategies.activate')}</span>
                                            <ArrowRight className="igr:size-3.5 igr:transition-transform igr:group-hover:translate-x-0.5" aria-hidden />
                                        </span>
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                </>
            ) : null}
        </Card>
    );
}
