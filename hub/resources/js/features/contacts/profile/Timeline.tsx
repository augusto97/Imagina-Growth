import {
    Activity,
    Eye,
    type LucideIcon,
    MessageSquare,
    Package,
    ShieldCheck,
    ShoppingCart,
    Star,
    UserRound,
    Users,
    ClipboardList,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatDate, formatMoney, parseApiDate } from '@/lib/format';
import { cn } from '@/lib/cn';
import { type TimelineEvent, useTimeline } from '../api';
import { TIMELINE_GROUPS, type TimelineGroup, eventGroup, eventKey, toggleGroup } from '../labels';

const ICONS: Record<TimelineGroup, LucideIcon> = {
    navegacion: Eye,
    carrito: ShoppingCart,
    pedidos: Package,
    cuenta: UserRound,
    mensajes: MessageSquare,
    resenas: Star,
    encuestas: ClipboardList,
    consentimiento: ShieldCheck,
    segmentos: Users,
};

/** Agrupa por día local (los eventos llegan del más nuevo al más viejo). */
export function groupByDay(events: readonly TimelineEvent[], timeZone: string): { day: string; events: TimelineEvent[] }[] {
    const fmt = new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' });
    const out: { day: string; events: TimelineEvent[] }[] = [];
    for (const e of events) {
        const day = fmt.format(parseApiDate(e.occurred_at));
        const last = out[out.length - 1];
        if (last && last.day === day) {
            last.events.push(e);
        } else {
            out.push({ day, events: [e] });
        }
    }
    return out;
}

/** Línea de tiempo unificada del perfil 360 con filtros por grupo y carga continua (next_before). */
export function Timeline({ contactId }: { contactId: string }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const [groups, setGroups] = useState<TimelineGroup[]>([]);
    const timeline = useTimeline(workspace.id, contactId, groups);
    const sentinel = useRef<HTMLDivElement | null>(null);
    const { hasNextPage, isFetchingNextPage, fetchNextPage } = timeline;

    useEffect(() => {
        const node = sentinel.current;
        if (!node || !hasNextPage) {
            return;
        }
        const observer = new IntersectionObserver((entries) => {
            if (entries.some((e) => e.isIntersecting) && !isFetchingNextPage) {
                void fetchNextPage();
            }
        });
        observer.observe(node);
        return () => observer.disconnect();
    }, [hasNextPage, isFetchingNextPage, fetchNextPage]);

    const events = timeline.data?.pages.flatMap((p) => p.data) ?? [];
    const days = groupByDay(events, workspace.timezone);
    const time = new Intl.DateTimeFormat(workspace.locale, { hour: 'numeric', minute: '2-digit', timeZone: workspace.timezone });

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('contacts.timeline.title')}</CardTitle>
                <div role="group" aria-label={t('contacts.timeline.filter')} className="igr:mt-1 igr:flex igr:flex-wrap igr:gap-1">
                    <Chip active={groups.length === 0} onClick={() => setGroups([])}>
                        {t('contacts.timeline.all')}
                    </Chip>
                    {TIMELINE_GROUPS.map((g) => (
                        <Chip key={g} active={groups.includes(g)} onClick={() => setGroups(toggleGroup(groups, g))}>
                            {t(`contacts.timeline.groups.${g}`)}
                        </Chip>
                    ))}
                </div>
            </CardHeader>
            <CardContent>
                {timeline.isError ? (
                    <ErrorState error={timeline.error} onRetry={() => void timeline.refetch()} />
                ) : timeline.isPending ? (
                    <div className="igr:space-y-3">
                        {Array.from({ length: 6 }, (_, i) => (
                            <Skeleton key={i} className="igr:h-8" />
                        ))}
                    </div>
                ) : events.length === 0 ? (
                    <div className="igr:flex igr:flex-col igr:items-center igr:gap-1.5 igr:py-8 igr:text-center">
                        <Activity className="igr:size-5 igr:text-muted-foreground" />
                        <p className="igr:text-[13px] igr:font-medium">{groups.includes('mensajes') && groups.length === 1 ? t('contacts.timeline.messagesSoon') : t('contacts.timeline.empty')}</p>
                        <p className="igr:max-w-xs igr:text-xs igr:text-muted-foreground">{t('contacts.timeline.emptyHelp')}</p>
                    </div>
                ) : (
                    <ol className="igr:space-y-4">
                        {days.map(({ day, events: dayEvents }) => (
                            <li key={day}>
                                <p className="igr:sticky igr:top-0 igr:mb-1.5 igr:bg-card igr:text-[11px] igr:font-medium igr:uppercase igr:tracking-wide igr:text-muted-foreground">
                                    {formatDate(`${day}T12:00:00`, workspace.locale, 'UTC')}
                                </p>
                                <ol className="igr:relative igr:space-y-2.5 igr:border-l igr:pl-4">
                                    {dayEvents.map((e) => {
                                        const group = eventGroup(e.event);
                                        const Icon = ICONS[group];
                                        return (
                                            <li key={e.id} className="igr:relative">
                                                <span className="igr:absolute igr:-left-[25px] igr:top-0 igr:flex igr:size-[18px] igr:items-center igr:justify-center igr:rounded-full igr:border igr:bg-card">
                                                    <Icon className="igr:size-2.5 igr:text-muted-foreground" aria-hidden="true" />
                                                </span>
                                                <div className="igr:flex igr:items-baseline igr:justify-between igr:gap-2">
                                                    <p className="igr:text-[13px] igr:font-medium">{t(`contacts.events.${eventKey(e.event)}`, { defaultValue: e.event })}</p>
                                                    <time dateTime={e.occurred_at} className="igr:shrink-0 igr:text-[11px] igr:tabular-nums igr:text-muted-foreground">
                                                        {time.format(parseApiDate(e.occurred_at))}
                                                    </time>
                                                </div>
                                                <EventDetail event={e} />
                                            </li>
                                        );
                                    })}
                                </ol>
                            </li>
                        ))}
                    </ol>
                )}
                <div ref={sentinel} />
                {hasNextPage && (
                    <Button variant="ghost" size="sm" className="igr:mt-3 igr:w-full" loading={isFetchingNextPage} onClick={() => void fetchNextPage()}>
                        {t('contacts.timeline.loadMore')}
                    </Button>
                )}
            </CardContent>
        </Card>
    );
}

function Chip({ active, onClick, children }: { active: boolean; onClick: () => void; children: string }) {
    return (
        <button
            type="button"
            aria-pressed={active}
            onClick={onClick}
            className={cn(
                'igr:rounded-full igr:border igr:px-2 igr:py-0.5 igr:text-[11px] igr:font-medium igr:outline-none igr:transition-colors igr:focus-visible:ring-2 igr:focus-visible:ring-ring',
                active ? 'igr:border-primary/40 igr:bg-primary/10 igr:text-primary' : 'igr:text-muted-foreground igr:hover:text-foreground',
            )}
        >
            {children}
        </button>
    );
}

function EventDetail({ event }: { event: TimelineEvent }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const s = event.summary;
    const str = (v: string | number | undefined) => (v === undefined ? undefined : String(v));
    const money = (v: string | number | undefined) => (v === undefined ? undefined : formatMoney(v, str(s.currency) ?? workspace.currency, workspace.locale));
    const parts: string[] = [];

    if (s.order_number !== undefined) {
        parts.push(t('contacts.timeline.order', { number: str(s.order_number) }));
    }
    if (s.status !== undefined && event.event.startsWith('order.')) {
        parts.push(t(`orderStatus.${String(s.status)}`, { defaultValue: String(s.status) }));
    }
    if (s.total !== undefined) {
        parts.push(money(s.total) ?? '');
    }
    if (s.items !== undefined) {
        parts.push(t('contacts.timeline.items', { count: Number(s.items) }));
    }
    if (s.payment !== undefined) {
        parts.push(String(s.payment));
    }
    if (s.product !== undefined && s.category === undefined) {
        parts.push(String(s.product));
    }
    if (s.category !== undefined) {
        parts.push(String(s.category));
    }
    if (s.query !== undefined) {
        parts.push(`«${String(s.query)}»`);
        if (s.results !== undefined) {
            parts.push(t('contacts.timeline.results', { count: Number(s.results) }));
        }
    }
    if (s.cta !== undefined) {
        parts.push(t(`contacts.timeline.cta.${String(s.cta)}`, { defaultValue: String(s.cta) }));
    }
    if (s.cart_total !== undefined && s.total === undefined) {
        parts.push(money(s.cart_total) ?? '');
    }
    if (s.form !== undefined) {
        parts.push(String(s.form));
    }
    if (s.rating !== undefined) {
        parts.push(`★ ${String(s.rating)}`);
    }
    if (s.answer !== undefined) {
        parts.push(String(s.answer));
    }
    if (s.channel !== undefined) {
        parts.push(t(`contacts.consentChannels.${String(s.channel)}`, { defaultValue: String(s.channel) }));
    }
    if (s.segment !== undefined) {
        parts.push(String(s.segment));
    }
    if (parts.length === 0 && event.path) {
        parts.push(event.path);
    }
    const channel = event.channel ? t(`contacts.channels.${event.channel}`, { defaultValue: event.channel }) : null;

    if (parts.length === 0 && !channel) {
        return null;
    }
    return (
        <p className="igr:mt-0.5 igr:truncate igr:text-xs igr:text-muted-foreground" title={parts.join(' · ')}>
            {parts.filter(Boolean).join(' · ')}
            {channel && (
                <span className="igr:ml-1.5 igr:rounded igr:bg-muted igr:px-1 igr:py-px igr:text-[10px]">{channel}</span>
            )}
        </p>
    );
}
