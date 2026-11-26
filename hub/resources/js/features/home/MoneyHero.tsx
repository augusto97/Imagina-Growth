import { ArrowDownRight, ArrowRight, ArrowUpRight, Coins, Minus, Plug, Rocket } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { HeroCard } from '@/components/ui/hero-card';
import { HelpTip } from '@/components/ui/help-tip';
import { useWorkspace } from '@/hooks/useWorkspace';
import { cn } from '@/lib/cn';
import { formatMoney, formatPercent } from '@/lib/format';
import { heroMode, moneyTrend } from './api';
import { monthName } from './greeting';
import type { Home } from './types';

/*
 * La tarjeta héroe del Inicio (docs/diseno/rediseno-v2.md §5.2): cuánto generó la plataforma este mes, de dónde salió
 * (por estrategia) y cómo va contra el mes anterior. En cero o muy bajo, el potencial con datos reales y el botón para
 * prender la estrategia que lo recupera; sin nada todavía, el primer paso (conectar la web o prender una estrategia).
 */

const BREAKDOWN_ROWS = 4;

function Trend({ home }: { home: Pick<Home, 'money' | 'period' | 'workspace'> }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const trend = moneyTrend(home.money);
    if (trend === null || home.money.change_pct === null) {
        return null;
    }
    const Arrow = trend === 'up' ? ArrowUpRight : trend === 'down' ? ArrowDownRight : Minus;
    const pct = formatPercent(Math.abs(home.money.change_pct) / 100, workspace.locale, 0);
    return (
        <span
            className={cn(
                'igr:inline-flex igr:items-center igr:gap-1 igr:rounded-full igr:px-2 igr:py-0.5 igr:text-caption igr:font-medium igr:tabular-nums',
                trend === 'up' && 'igr:bg-success/12 igr:text-success',
                trend === 'down' && 'igr:bg-destructive/12 igr:text-destructive',
                trend === 'flat' && 'igr:bg-muted igr:text-muted-foreground',
            )}
        >
            <Arrow className="igr:size-3.5" aria-hidden />
            {trend === 'down' ? '−' : trend === 'up' ? '+' : ''}
            {pct} {t('home.money.vs', { month: monthName(home.money.previous.from, workspace.locale) })}
        </span>
    );
}

function Breakdown({ home }: { home: Pick<Home, 'money' | 'workspace'> }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const rows = home.money.breakdown;
    if (rows.length === 0) {
        return null;
    }
    const top = Number(rows[0]?.revenue ?? 0) || 1;
    return (
        <div className="igr:max-w-xl">
            <p className="igr:mb-2 igr:text-caption igr:font-medium igr:text-muted-foreground">{t('home.money.byStrategy')}</p>
            <ul className="igr:space-y-2">
                {rows.slice(0, BREAKDOWN_ROWS).map((row) => (
                    <li key={row.strategy ?? row.bucket} className="igr:grid igr:grid-cols-[minmax(0,1fr)_auto] igr:items-center igr:gap-x-3 igr:gap-y-1">
                        <span className="igr:truncate igr:text-support igr:text-foreground">{row.name}</span>
                        <span className="igr:text-support igr:font-medium igr:text-foreground igr:tabular-nums">{formatMoney(row.revenue, home.workspace.currency, workspace.locale)}</span>
                        <span aria-hidden className="igr:col-span-2 igr:h-1.5 igr:overflow-hidden igr:rounded-full igr:bg-primary/10">
                            <span className="igr:block igr:h-full igr:rounded-full igr:bg-primary" style={{ width: `${Math.max(4, Math.round((Number(row.revenue) / top) * 100))}%` }} />
                        </span>
                    </li>
                ))}
            </ul>
            {rows.length > BREAKDOWN_ROWS ? <p className="igr:mt-2 igr:text-caption igr:text-muted-foreground">{t('home.money.more', { count: rows.length - BREAKDOWN_ROWS })}</p> : null}
        </div>
    );
}

export function MoneyHero({ home, base }: { home: Home; base: string }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const mode = heroMode(home);
    const currency = home.workspace.currency;
    const money = (v: string | number) => formatMoney(v, currency, workspace.locale);
    const services = home.business_type === 'services';
    const canStrategies = workspace.abilities.includes('manageMarketing');
    const site = workspace.connections?.site;
    const hasSite = site === undefined || site === 'connected' || site === 'disconnected';
    const total = Number(home.money.total);

    if (mode === 'money') {
        return (
            <HeroCard
                icon={Coins}
                eyebrow={
                    <span className="igr:inline-flex igr:items-center igr:gap-1.5">
                        {t('home.money.eyebrow')}
                        <HelpTip content={t('home.money.help')} label={t('home.money.helpLabel')} />
                    </span>
                }
                value={money(home.money.total)}
                description={
                    Number(home.money.store_total) > 0
                        ? t(services ? 'home.money.shareServices' : 'home.money.share', {
                              total: money(home.money.store_total),
                              pct: formatPercent(home.money.share_pct / 100, workspace.locale, home.money.share_pct < 10 ? 1 : 0),
                          })
                        : undefined
                }
                actions={
                    <Button asChild variant="outline" size="sm" className="igr:bg-card/80">
                        <Link to={`${base}/resultados`}>
                            {t('home.money.details')}
                            <ArrowRight aria-hidden />
                        </Link>
                    </Button>
                }
                aside={
                    home.money.breakdown.length > 0 ? (
                        <div className="igr:w-64 igr:xl:w-72">
                            <Breakdown home={home} />
                        </div>
                    ) : undefined
                }
                className="igr:h-full"
            >
                <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-2">
                    <Trend home={home} />
                </div>
                <div className="igr:mt-5 igr:md:hidden">
                    <Breakdown home={home} />
                </div>
            </HeroCard>
        );
    }

    if (mode === 'potential' && home.potential) {
        const p = home.potential;
        return (
            <HeroCard
                icon={Rocket}
                eyebrow={t('home.potential.eyebrow')}
                value={p.estimated !== null && Number(p.estimated) > 0 ? t('home.potential.value', { amount: money(p.estimated) }) : undefined}
                title={p.estimated !== null && Number(p.estimated) > 0 ? undefined : (p.headline ?? p.summary)}
                description={
                    p.estimated !== null && Number(p.estimated) > 0 && p.rate !== null
                        ? `${p.headline ?? ''} ${t('home.potential.assumption', { rate: formatPercent(p.rate, workspace.locale, 0), name: p.name })}`.trim()
                        : t('home.potential.recovers', { name: p.name })
                }
                actions={
                    <>
                        {canStrategies ? (
                            <Button asChild variant="brand">
                                <Link to={`${base}/estrategias/${p.strategy}`}>
                                    {t(p.status === 'paused' ? 'home.potential.resume' : 'home.potential.cta', { name: p.name })}
                                    <ArrowRight aria-hidden />
                                </Link>
                            </Button>
                        ) : null}
                        {total > 0 ? <span className="igr:text-support igr:text-muted-foreground">{t('home.potential.soFar', { amount: money(total) })}</span> : null}
                    </>
                }
                illustration="strategies"
                className="igr:h-full"
            />
        );
    }

    if (hasSite && home.strategies.active.length > 0) {
        // Ya hay estrategias prendidas y todavía no generaron: que se sepa que están trabajando.
        return (
            <HeroCard
                icon={Rocket}
                eyebrow={t('home.money.eyebrow')}
                value={money(home.money.total)}
                title={t('home.working.title', { count: home.strategies.active.length })}
                description={t(services ? 'home.working.bodyServices' : 'home.working.body')}
                actions={
                    <Button asChild variant="outline" size="sm" className="igr:bg-card/80">
                        <Link to={`${base}/estrategias`}>
                            {t('home.working.cta')}
                            <ArrowRight aria-hidden />
                        </Link>
                    </Button>
                }
                illustration="strategies"
                className="igr:h-full"
            />
        );
    }

    return (
        <HeroCard
            icon={hasSite ? Rocket : Plug}
            eyebrow={t('home.money.eyebrow')}
            title={hasSite ? t('home.empty.title') : t(services ? 'home.empty.connectTitleServices' : 'home.empty.connectTitle')}
            description={hasSite ? t(services ? 'home.empty.bodyServices' : 'home.empty.body') : t(services ? 'home.empty.connectBodyServices' : 'home.empty.connectBody')}
            actions={
                <Button asChild variant="brand">
                    <Link to={hasSite ? `${base}/estrategias` : `${base}/configurar`}>
                        {hasSite ? t('home.empty.cta') : t('home.empty.connectCta')}
                        <ArrowRight aria-hidden />
                    </Link>
                </Button>
            }
            illustration={hasSite ? 'strategies' : 'web'}
            className="igr:h-full"
        />
    );
}
