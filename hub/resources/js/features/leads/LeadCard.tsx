import { Clock, UserRound } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatMoney, formatRelative } from '@/lib/format';
import { cn } from '@/lib/cn';
import { temperatureTone } from './logic';
import type { LeadRow } from './types';

/** Tarjeta de un lead en el tablero: nombre, servicio, puntaje, fuente, vendedor y última actividad. */
export function LeadCard({ lead, onOpen, dragging }: { lead: LeadRow; onOpen: () => void; dragging?: boolean }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    return (
        <button
            type="button"
            onClick={onOpen}
            className={cn(
                'igr:w-full igr:rounded-lg igr:border igr:bg-card igr:p-2.5 igr:text-left igr:shadow-xs igr:transition-colors igr:hover:border-foreground/20 igr:focus-visible:outline-none igr:focus-visible:ring-2 igr:focus-visible:ring-ring',
                dragging && 'igr:opacity-50',
            )}
        >
            <div className="igr:flex igr:items-start igr:justify-between igr:gap-2">
                <span className="igr:truncate igr:text-[13px] igr:font-medium">{lead.contact?.name ?? t('leads.noName')}</span>
                <Badge variant={temperatureTone(lead.temperature)} title={t(`leads.temperature.${lead.temperature}`)}>
                    <span className="igr:tabular-nums">{lead.score}</span>
                    <span className="igr:sr-only">{t(`leads.temperature.${lead.temperature}`)}</span>
                </Badge>
            </div>
            <p className="igr:mt-0.5 igr:truncate igr:text-xs igr:text-muted-foreground">{[lead.service, lead.contact?.city].filter(Boolean).join(' · ') || t('leads.noService')}</p>
            <div className="igr:mt-2 igr:flex igr:flex-wrap igr:items-center igr:gap-x-2 igr:gap-y-1 igr:text-[11px] igr:text-muted-foreground">
                {lead.channel ? <span className="igr:truncate">{t(`leads.channels.${lead.channel}`, { defaultValue: lead.channel_label ?? lead.channel })}</span> : null}
                {lead.value !== null ? <span className="igr:font-medium igr:tabular-nums igr:text-foreground">{formatMoney(lead.value, lead.currency, workspace.locale)}</span> : null}
                <span className="igr:ml-auto igr:inline-flex igr:items-center igr:gap-1">
                    <Clock className="igr:size-3" aria-hidden />
                    {formatRelative(lead.last_activity_at ?? lead.created_at, workspace.locale)}
                </span>
            </div>
            <div className="igr:mt-1.5 igr:flex igr:items-center igr:gap-1 igr:text-[11px] igr:text-muted-foreground">
                <UserRound className="igr:size-3" aria-hidden />
                <span className="igr:truncate">{lead.owner?.name ?? t('leads.unassigned')}</span>
                {lead.first_response_at === null && lead.won_at === null && lead.lost_at === null ? (
                    <Badge variant="outline" className="igr:ml-auto">
                        {t('leads.noResponse')}
                    </Badge>
                ) : null}
            </div>
        </button>
    );
}
