import {
    Calculator,
    CalendarCheck,
    CalendarClock,
    Camera,
    FileText,
    Hourglass,
    Megaphone,
    MessageCircle,
    MessagesSquare,
    MoreHorizontal,
    PanelTop,
    Plug,
    Search,
    UserPlus,
    type LucideIcon,
} from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Avatar } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { useWorkspace } from '@/hooks/useWorkspace';
import { cn } from '@/lib/cn';
import { formatDate, formatMoney, formatRelative } from '@/lib/format';
import { temperatureTone, waitingFor } from './logic';
import type { LeadRow, Origin, Stage } from './types';

export const ORIGIN_ICONS: Record<Origin, LucideIcon> = {
    form: FileText,
    whatsapp: MessageCircle,
    messenger: MessagesSquare,
    instagram: Camera,
    quote: Calculator,
    booking: CalendarCheck,
    manual: UserPlus,
    api: Plug,
    landing: PanelTop,
    meta_lead_ads: Megaphone,
    google_lead_form: Search,
};

/**
 * Tarjeta de un lead (tablero y lista por etapa del celular): quién es y qué pide, qué tan interesado está (en palabras),
 * de dónde llegó, cuánto lleva sin respuesta y el siguiente paso, con su vendedor. En el celular, «Mover a» cambia la
 * etapa sin arrastrar.
 */
export function LeadCard({
    lead,
    onOpen,
    dragging,
    stages,
    onMove,
    now,
}: {
    lead: LeadRow;
    onOpen: () => void;
    dragging?: boolean;
    /** Con etapas, la tarjeta lleva el menú «Mover a» (celular y teclado). */
    stages?: Stage[];
    onMove?: (stage: Stage) => void;
    now: number;
}) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const OriginIcon = ORIGIN_ICONS[lead.origin];
    const waiting = waitingFor(lead, now);
    const overdue = lead.next_step_at !== null && Date.parse(lead.next_step_at) < now;
    const open = lead.won_at === null && lead.lost_at === null;

    return (
        <div
            className={cn(
                'igr:relative igr:rounded-lg igr:border igr:bg-card igr:p-3 igr:shadow-xs igr:transition-[border-color,box-shadow] igr:hover:border-foreground/20 igr:hover:shadow-sm',
                dragging && 'igr:opacity-50',
            )}
        >
            <div className="igr:flex igr:items-start igr:justify-between igr:gap-2">
                <button
                    type="button"
                    onClick={onOpen}
                    className="igr:min-w-0 igr:text-left igr:text-[13px] igr:font-semibold igr:outline-none igr:after:absolute igr:after:inset-0 igr:after:rounded-lg igr:focus-visible:after:ring-3 igr:focus-visible:after:ring-ring/30"
                >
                    <span className="igr:block igr:truncate">{lead.contact?.name ?? t('leads.noName')}</span>
                </button>
                <div className="igr:relative igr:z-10 igr:flex igr:shrink-0 igr:items-center igr:gap-1">
                    {open ? (
                        <Badge variant={temperatureTone(lead.temperature)} title={t('leads.card.score', { score: lead.score })}>
                            {t(`leads.temperature.${lead.temperature}`)}
                        </Badge>
                    ) : null}
                    {stages && onMove ? (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button variant="ghost" size="icon" className="igr:-mr-1.5 igr:-mt-1 igr:size-7" aria-label={t('leads.card.moveLabel', { name: lead.contact?.name ?? t('leads.noName') })}>
                                    <MoreHorizontal />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuLabel>{t('leads.card.moveTo')}</DropdownMenuLabel>
                                {stages
                                    .filter((s) => s.key !== lead.stage)
                                    .map((s) => (
                                        <DropdownMenuItem key={s.key} onSelect={() => onMove(s)}>
                                            {s.label}
                                        </DropdownMenuItem>
                                    ))}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    ) : null}
                </div>
            </div>
            <p className="igr:mt-0.5 igr:truncate igr:text-caption igr:text-muted-foreground">{[lead.service, lead.contact?.city].filter(Boolean).join(' · ') || t('leads.noService')}</p>

            <div className="igr:mt-2.5 igr:flex igr:flex-wrap igr:items-center igr:gap-x-2.5 igr:gap-y-1 igr:text-caption igr:text-muted-foreground">
                <span className="igr:inline-flex igr:min-w-0 igr:items-center igr:gap-1" title={t('leads.card.origin')}>
                    <OriginIcon className="igr:size-3.5 igr:shrink-0" aria-hidden />
                    <span className="igr:truncate">{t(`leads.originsShort.${lead.origin}`, { defaultValue: t(`leads.origins.${lead.origin}`) })}</span>
                </span>
                {lead.value !== null ? <span className="igr:font-medium igr:text-foreground igr:tabular-nums">{formatMoney(lead.value, lead.currency, workspace.locale)}</span> : null}
            </div>

            {waiting !== null ? (
                <p className={cn('igr:mt-2 igr:flex igr:items-center igr:gap-1 igr:text-caption igr:font-medium', waiting.urgent ? 'igr:text-destructive' : 'igr:text-warning')}>
                    <Hourglass className="igr:size-3.5 igr:shrink-0" aria-hidden />
                    {t('leads.card.waiting', { since: formatRelative(waiting.since, workspace.locale) })}
                </p>
            ) : lead.next_step ? (
                <p className={cn('igr:mt-2 igr:flex igr:items-center igr:gap-1 igr:text-caption', overdue ? 'igr:font-medium igr:text-destructive' : 'igr:text-muted-foreground')}>
                    <CalendarClock className="igr:size-3.5 igr:shrink-0" aria-hidden />
                    <span className="igr:truncate">
                        {lead.next_step_at
                            ? t('leads.card.nextOn', { step: lead.next_step, date: formatDate(lead.next_step_at, workspace.locale, workspace.timezone) })
                            : t('leads.card.next', { step: lead.next_step })}
                    </span>
                </p>
            ) : null}

            <div className="igr:mt-2.5 igr:flex igr:items-center igr:justify-between igr:gap-2 igr:border-t igr:pt-2 igr:text-caption igr:text-muted-foreground">
                <span className="igr:flex igr:min-w-0 igr:items-center igr:gap-1.5">
                    {lead.owner ? <Avatar name={lead.owner.name} seed={lead.owner.id} size="sm" className="igr:size-5 igr:text-[9px]" /> : null}
                    <span className="igr:truncate">{lead.owner?.name ?? t('leads.unassigned')}</span>
                </span>
                <span className="igr:shrink-0">{formatRelative(lead.last_activity_at ?? lead.created_at, workspace.locale)}</span>
            </div>
        </div>
    );
}
