import { useTranslation } from 'react-i18next';
import type { Backfill, SiteStatus } from '@/api/types';
import { Badge, StatusDot } from '@/components/ui/badge';
import { Progress } from '@/components/ui/progress';
import { formatNumber } from '@/lib/format';

export function SiteStatusBadge({ status }: { status: SiteStatus }) {
    const { t } = useTranslation();
    const tone = status === 'connected' ? 'success' : status === 'degraded' ? 'warning' : status === 'disconnected' ? 'destructive' : 'muted';
    const variant = status === 'connected' ? 'success' : status === 'degraded' ? 'warning' : status === 'disconnected' ? 'destructive' : 'outline';
    return (
        <Badge variant={variant}>
            <StatusDot tone={tone} />
            {t(`sites.status.${status}`)}
        </Badge>
    );
}

export function BackfillBadge({ backfill }: { backfill: Backfill | null }) {
    const { t } = useTranslation();
    if (!backfill?.status) {
        return <span className="igr:text-xs igr:text-muted-foreground">{t('sites.backfill.none')}</span>;
    }
    const variant = backfill.status === 'completed' ? 'success' : backfill.status === 'failed' ? 'destructive' : backfill.status === 'paused' ? 'warning' : 'default';
    return <Badge variant={variant}>{t(`sites.backfill.status.${backfill.status}`)}</Badge>;
}

const ENTITY_ORDER = ['products', 'categories', 'customers', 'orders', 'refunds', 'reviews', 'subscriptions'];

/** Progreso de la importación histórica por entidad (api-connector.md §5.3). */
export function BackfillProgress({ backfill, locale }: { backfill: Backfill; locale: string }) {
    const { t } = useTranslation();
    const entries = Object.entries(backfill.entities).sort(
        ([a], [b]) => (ENTITY_ORDER.indexOf(a) === -1 ? 99 : ENTITY_ORDER.indexOf(a)) - (ENTITY_ORDER.indexOf(b) === -1 ? 99 : ENTITY_ORDER.indexOf(b)),
    );
    return (
        <div className="igr:space-y-3">
            <div className="igr:flex igr:items-center igr:justify-between igr:text-[13px]">
                <span className="igr:font-medium">{t('sites.backfill.overall')}</span>
                <span className="igr:tabular-nums igr:text-muted-foreground">{Math.round((backfill.progress ?? 0) * 100)} %</span>
            </div>
            <Progress value={backfill.progress} label={t('sites.backfill.overall')} />
            <ul className="igr:grid igr:gap-x-6 igr:gap-y-3 igr:sm:grid-cols-2">
                {entries.map(([name, e]) => {
                    const total = e.total ?? 0;
                    const sent = e.sent ?? 0;
                    const active = backfill.phase === name && backfill.status === 'running';
                    return (
                        <li key={name}>
                            <div className="igr:mb-1 igr:flex igr:items-center igr:justify-between igr:text-xs">
                                <span className={active ? 'igr:font-medium igr:text-foreground' : 'igr:text-muted-foreground'}>
                                    {t(`sites.entities.${name}`, { defaultValue: name })}
                                    {active ? ` · ${t('sites.backfill.inProgress')}` : ''}
                                </span>
                                <span className="igr:tabular-nums igr:text-muted-foreground">
                                    {formatNumber(sent, locale)} / {formatNumber(total, locale)}
                                </span>
                            </div>
                            <Progress value={total > 0 ? sent / total : sent > 0 ? 1 : 0} className="igr:h-1" label={name} />
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}
