import type { TFunction } from 'i18next';
import { AlertTriangle, ArrowRight, CircleCheck, CircleX } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import type { Site, SiteHealthCause, SiteHealthState, SiteHealthStatus } from '@/api/types';
import { Badge, StatusDot } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/cn';
import { PluginDownloadButton } from './PluginDownload';
import { SiteStatusBadge } from './SiteBits';

/* Semáforo de salud del sitio (docs/auditoria-2026-11.md U4 y F6): al día, con atraso o sin datos, con qué hacer. */

const TONE: Record<SiteHealthState, { variant: 'success' | 'warning' | 'destructive'; dot: 'success' | 'warning' | 'destructive' }> = {
    ok: { variant: 'success', dot: 'success' },
    lagging: { variant: 'warning', dot: 'warning' },
    no_data: { variant: 'destructive', dot: 'destructive' },
};

/** Sitios activos: el semáforo; pendientes o desconectados: su estado de conexión. */
export function SiteHealthBadge({ site }: { site: Pick<Site, 'status' | 'health_status'> }) {
    const { t } = useTranslation();
    const health = site.health_status;
    if (!health || site.status === 'pending' || site.status === 'disconnected') {
        return <SiteStatusBadge status={site.status} />;
    }
    const tone = TONE[health.state];
    return (
        <Badge variant={tone.variant}>
            <StatusDot tone={tone.dot} />
            {t(`sites.health.state.${health.state}`)}
        </Badge>
    );
}

/** Texto de una causa con sus datos (horas, versión, cantidad…). */
export function causeTitle(t: TFunction, cause: SiteHealthCause): string {
    return t(`sites.health.causes.${cause.key}.title`, { ...cause.params, count: typeof cause.params.count === 'number' ? cause.params.count : undefined });
}

export function causeAction(t: TFunction, cause: SiteHealthCause): string {
    return t(`sites.health.causes.${cause.key}.action`, cause.params);
}

/** Nombre de un tipo de dato para el dueño: el del perfil 360, el del sitio o, si no hay, el código. */
export function eventLabel(t: TFunction, event: string): string {
    const key = event.replace(/[^a-z0-9]+/gi, '_');
    return t(`contacts.events.${key}`, { defaultValue: t(`sites.eventLabels.${key}`, { defaultValue: event }) });
}

/** Las causas que importan para los tableros: sin visitas del navegador, las visitas y la conversión salen en cero. */
export function trackerMissing(health: SiteHealthStatus | null | undefined): boolean {
    return (health?.causes ?? []).some((c) => c.key === 'browser_missing' || c.key === 'browser_late');
}

/** El orden para mostrar varios sitios: primero los que no tienen datos, luego los atrasados. */
export function worstFirst<T extends { health_status?: SiteHealthStatus | null }>(sites: readonly T[]): T[] {
    const rank = (s: T) => (s.health_status?.state === 'no_data' ? 0 : s.health_status?.state === 'lagging' ? 1 : 2);
    return [...sites].sort((a, b) => rank(a) - rank(b));
}

/** Detalle del semáforo con cada causa y qué hacer (detalle del sitio). */
export function SiteHealthPanel({ site }: { site: Site }) {
    const { t } = useTranslation();
    const health = site.health_status;
    if (!health) {
        return null;
    }
    if (health.state === 'ok') {
        return (
            <div className="igr:mb-4 igr:flex igr:items-center igr:gap-2 igr:rounded-lg igr:border igr:bg-card igr:px-4 igr:py-3 igr:text-[13px]">
                <CircleCheck className="igr:size-4 igr:text-success" aria-hidden />
                <span>{t('sites.health.allGood')}</span>
            </div>
        );
    }
    return (
        <section
            aria-label={t('sites.health.title')}
            className={cn('igr:mb-4 igr:rounded-lg igr:border igr:bg-card igr:px-4 igr:py-3', health.state === 'no_data' ? 'igr:border-destructive/40' : 'igr:border-warning/50')}
        >
            <div className="igr:mb-2 igr:flex igr:items-center igr:gap-2">
                <SiteHealthBadge site={site} />
                <p className="igr:text-[13px] igr:font-medium">{t(`sites.health.headline.${health.state}`)}</p>
            </div>
            <ul className="igr:space-y-2.5">
                {health.causes.map((cause) => (
                    <li key={cause.key} className="igr:flex igr:items-start igr:gap-2.5">
                        {cause.severity === 'error' ? (
                            <CircleX className="igr:mt-0.5 igr:size-4 igr:shrink-0 igr:text-destructive" aria-hidden />
                        ) : (
                            <AlertTriangle className="igr:mt-0.5 igr:size-4 igr:shrink-0 igr:text-warning" aria-hidden />
                        )}
                        <div className="igr:min-w-0 igr:flex-1">
                            <p className="igr:text-[13px] igr:font-medium">{causeTitle(t, cause)}</p>
                            <p className="igr:text-xs igr:text-muted-foreground">{causeAction(t, cause)}</p>
                            {cause.key === 'plugin_outdated' ? (
                                <div className="igr:mt-2">
                                    <PluginDownloadButton size="sm" />
                                </div>
                            ) : null}
                        </div>
                    </li>
                ))}
            </ul>
        </section>
    );
}

/** Aviso del Inicio y de Analítica: los sitios que no están al día, con el primer motivo y el camino para resolverlo. */
export function SitesHealthNotice({ sites, base, className }: { sites: readonly Site[]; base: string; className?: string }) {
    const { t } = useTranslation();
    const troubled = worstFirst(sites.filter((s) => s.status !== 'disconnected' && s.health_status && s.health_status.state !== 'ok'));
    if (troubled.length === 0) {
        return null;
    }
    const zeroKpis = troubled.find((s) => trackerMissing(s.health_status));
    return (
        <div className={cn('igr:space-y-2', className)}>
            {zeroKpis ? (
                <div
                    role="status"
                    className="igr:flex igr:flex-col igr:gap-2 igr:rounded-lg igr:border igr:border-warning/50 igr:bg-warning/10 igr:px-4 igr:py-3 igr:text-[13px] igr:sm:flex-row igr:sm:items-center"
                >
                    <AlertTriangle className="igr:size-4 igr:shrink-0 igr:text-warning" aria-hidden />
                    <span className="igr:flex-1">{t('sites.health.zeroKpis', { host: zeroKpis.host })}</span>
                    <Button asChild size="sm" variant="outline">
                        <Link to={`${base}/sitios/${zeroKpis.id}`}>
                            {t('sites.health.whatToDo')}
                            <ArrowRight />
                        </Link>
                    </Button>
                </div>
            ) : null}
            {troubled
                .filter((s) => s !== zeroKpis)
                .slice(0, 2)
                .map((site) => {
                    const cause = site.health_status?.causes[0];
                    return (
                        <div
                            key={site.id}
                            role="status"
                            className="igr:flex igr:flex-col igr:gap-2 igr:rounded-lg igr:border igr:bg-card igr:px-4 igr:py-3 igr:text-[13px] igr:sm:flex-row igr:sm:items-center"
                        >
                            <SiteHealthBadge site={site} />
                            <span className="igr:min-w-0 igr:flex-1">
                                <span className="igr:font-medium">{site.name ?? site.host}</span>
                                {cause ? <span className="igr:text-muted-foreground"> · {causeTitle(t, cause)}</span> : null}
                            </span>
                            <Button asChild size="sm" variant="outline">
                                <Link to={`${base}/sitios/${site.id}`}>
                                    {t('sites.health.whatToDo')}
                                    <ArrowRight />
                                </Link>
                            </Button>
                        </div>
                    );
                })}
        </div>
    );
}
