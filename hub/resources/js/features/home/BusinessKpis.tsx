import { ArrowRight } from 'lucide-react';
import type { TFunction } from 'i18next';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { Callout } from '@/components/ui/callout';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatCard } from '@/components/ui/stat-card';
import { useWorkspace } from '@/hooks/useWorkspace';
import { cn } from '@/lib/cn';
import { formatMoney, formatNumber, formatPercent } from '@/lib/format';
import { kpiBetter, kpiSeries } from './api';
import type { Home, KpiKey, KpiUnit } from './types';

/*
 * «Tu negocio» (docs/diseno/rediseno-v2.md §5.5 y §11.2): los KPI que aplican según el tipo de negocio (el servidor los
 * dice en `kpis.applicable`, la pantalla no adivina), con su ayuda «?», la variación contra el mes anterior y la
 * minigráfica de 30 días. Una tienda ve ventas, pedidos y clientes; un negocio sin tienda, contactos, respuesta,
 * citas, cierre y valor ganado, y de dónde llegan sus contactos.
 */

export function formatKpi(value: number | null, unit: KpiUnit, currency: string, locale: string, t: TFunction): string {
    if (value === null) {
        return '—';
    }
    switch (unit) {
        case 'money':
            return formatMoney(value, currency, locale);
        case 'percent':
            return formatPercent(value / 100, locale, 0);
        case 'minutes':
            return value >= 120 ? t('home.kpis.hours', { count: Math.round(value / 60) }) : t('home.kpis.minutes', { count: Math.round(value) });
        default:
            return formatNumber(value, locale);
    }
}

function Sources({ home }: { home: Pick<Home, 'kpis'> }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const sources = home.kpis.lead_sources;
    const total = sources.reduce((sum, s) => sum + s.count, 0);
    if (total === 0) {
        return null;
    }
    return (
        <Card className="igr:p-5">
            <h3 className="igr:text-support igr:font-semibold igr:text-foreground">{t('home.kpis.sources')}</h3>
            <ul className="igr:mt-3 igr:space-y-2.5">
                {sources.map((s) => (
                    <li key={s.origin} className="igr:grid igr:grid-cols-[minmax(0,1fr)_auto] igr:items-center igr:gap-x-3 igr:gap-y-1">
                        <span className="igr:truncate igr:text-support igr:text-foreground">{s.label}</span>
                        <span className="igr:text-support igr:font-medium igr:tabular-nums">
                            {formatNumber(s.count, workspace.locale)} <span className="igr:font-normal igr:text-muted-foreground">· {formatPercent(s.count / total, workspace.locale, 0)}</span>
                        </span>
                        <span aria-hidden className="igr:col-span-2 igr:h-1.5 igr:overflow-hidden igr:rounded-full igr:bg-muted">
                            <span className="igr:block igr:h-full igr:rounded-full igr:bg-chart-1" style={{ width: `${Math.max(3, Math.round((s.count / total) * 100))}%` }} />
                        </span>
                    </li>
                ))}
            </ul>
        </Card>
    );
}

export function BusinessKpis({ home, base }: { home: Pick<Home, 'kpis' | 'workspace' | 'business_type'>; base: string }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const k = home.kpis;
    const services = home.business_type === 'services';
    const site = workspace.connections?.site;
    const noSite = site === 'none' || site === 'pending';
    const many = k.applicable.length > 4;

    return (
        <section aria-labelledby="home-business">
            <div className="igr:mb-3 igr:flex igr:items-end igr:justify-between igr:gap-3">
                <div className="igr:min-w-0">
                    <h2 id="home-business" className="igr:text-section igr:font-semibold igr:text-foreground">
                        {t('home.kpis.title')}
                    </h2>
                    <p className="igr:text-support igr:text-muted-foreground">{t(services ? 'home.kpis.subtitleServices' : 'home.kpis.subtitle')}</p>
                </div>
                <Button asChild variant="ghost" size="sm" className="igr:shrink-0">
                    <Link to={`${base}/resultados`}>
                        {t('home.kpis.more')}
                        <ArrowRight aria-hidden />
                    </Link>
                </Button>
            </div>
            {noSite && !services ? (
                // El Inicio ya invita a conectar la web arriba: aquí solo se dice qué se va a ver.
                <EmptyState size="compact" illustration="results" title={t('home.kpis.noSiteTitle')} description={t('home.kpis.noSiteBody')} />
            ) : (
                <>
                    {!k.available ? (
                        <Callout tone="info" className="igr:mb-3">
                            {t('home.kpis.unavailable')}
                        </Callout>
                    ) : null}
                    <div className={cn('igr:grid igr:gap-3 igr:sm:grid-cols-2', many ? 'igr:lg:grid-cols-3' : 'igr:lg:grid-cols-4')}>
                        {k.applicable.map((key: KpiKey) => {
                            const def = k.definitions[key];
                            const metric = k.metrics[key];
                            if (!def) return null;
                            const change = metric?.change_pct ?? null;
                            return (
                                <StatCard
                                    key={key}
                                    label={def.label}
                                    help={def.help}
                                    value={formatKpi(metric?.value ?? null, def.unit, home.workspace.currency, workspace.locale, t)}
                                    change={change === null ? null : change / 100}
                                    changeLabel={change === null ? undefined : `${change > 0 ? '+' : change < 0 ? '−' : ''}${formatPercent(Math.abs(change) / 100, workspace.locale, 0)}`}
                                    compareLabel={t('home.kpis.vsPrevious')}
                                    better={kpiBetter(key)}
                                    trend={kpiSeries(k, key)}
                                />
                            );
                        })}
                    </div>
                    {services || home.business_type === 'both' ? (
                        <div className="igr:mt-3">
                            <Sources home={home} />
                        </div>
                    ) : null}
                </>
            )}
        </section>
    );
}
