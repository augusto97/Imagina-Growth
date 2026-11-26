import {
    Activity,
    CalendarCheck,
    Eye,
    Gift,
    type LucideIcon,
    MessageSquare,
    MousePointerClick,
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
import { Chip, ChipGroup } from '@/components/ui/chip';
import { IconTile, type IconTone } from '@/components/ui/icon-tile';
import { ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatDate, formatDateTime, formatMoney, parseApiDate } from '@/lib/format';
import { type TimelineEvent, useTimeline } from '../api';
import { TIMELINE_GROUPS, type TimelineGroup, eventGroup, eventKey, toggleGroup } from '../labels';

const ICONS: Record<TimelineGroup, { icon: LucideIcon; tone: IconTone }> = {
    navegacion: { icon: Eye, tone: 'slate' },
    carrito: { icon: ShoppingCart, tone: 'orange' },
    pedidos: { icon: Package, tone: 'emerald' },
    fidelizacion: { icon: Gift, tone: 'rose' },
    cuenta: { icon: UserRound, tone: 'sky' },
    sitio: { icon: MousePointerClick, tone: 'teal' },
    mensajes: { icon: MessageSquare, tone: 'indigo' },
    resenas: { icon: Star, tone: 'amber' },
    encuestas: { icon: ClipboardList, tone: 'violet' },
    consentimiento: { icon: ShieldCheck, tone: 'emerald' },
    segmentos: { icon: Users, tone: 'violet' },
    servicios: { icon: CalendarCheck, tone: 'teal' },
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

/**
 * Línea de tiempo unificada del perfil 360: agrupada por día, un ícono de color por tipo, filtros a la vista (solo los
 * que tienen sentido para el negocio, `available`) y carga continua (next_before).
 */
export function Timeline({ contactId, available = TIMELINE_GROUPS }: { contactId: string; available?: readonly TimelineGroup[] }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const [picked, setGroups] = useState<TimelineGroup[]>([]);
    // Sin filtro: todos los grupos que aplican al negocio (en una tienda, nada de servicios; sin tienda, nada de carrito).
    const groups = picked.length > 0 ? picked : available.length < TIMELINE_GROUPS.length ? [...available] : [];
    const timeline = useTimeline(workspace.id, contactId, groups);
    const sentinel = useRef<HTMLLIElement | null>(null);
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
                <ChipGroup label={t('contacts.timeline.filter')} scroll className="igr:mt-2">
                    <Chip pressed={picked.length === 0} onClick={() => setGroups([])}>
                        {t('contacts.timeline.all')}
                    </Chip>
                    {available.map((g) => (
                        <Chip key={g} pressed={picked.includes(g)} icon={ICONS[g].icon} onClick={() => setGroups(toggleGroup(picked, g))}>
                            {t(`contacts.timeline.groups.${g}`)}
                        </Chip>
                    ))}
                </ChipGroup>
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
                        <Activity className="igr:size-5 igr:text-muted-foreground" aria-hidden />
                        <p className="igr:text-[13px] igr:font-medium">{t(picked.length > 0 ? 'contacts.timeline.empty' : 'contacts.timeline.emptyAll')}</p>
                        <p className="igr:max-w-xs igr:text-xs igr:text-muted-foreground">{t('contacts.timeline.emptyHelp')}</p>
                    </div>
                ) : (
                    <ol className="igr:-mx-2 igr:max-h-[70vh] igr:space-y-4 igr:overflow-y-auto igr:px-2 igr:pl-3" tabIndex={0} aria-label={t('contacts.timeline.title')}>
                        {days.map(({ day, events: dayEvents }) => (
                            <li key={day}>
                                <p className="igr:sticky igr:top-0 igr:mb-1.5 igr:bg-card igr:text-[11px] igr:font-medium igr:uppercase igr:tracking-wide igr:text-muted-foreground">
                                    {formatDate(`${day}T12:00:00`, workspace.locale, 'UTC')}
                                </p>
                                <ol className="igr:space-y-3">
                                    {dayEvents.map((e) => {
                                        const group = eventGroup(e.event);
                                        return (
                                            <li key={e.id} className="igr:flex igr:gap-2.5">
                                                <IconTile icon={ICONS[group].icon} tone={ICONS[group].tone} size="sm" />
                                                <div className="igr:min-w-0 igr:flex-1">
                                                    <div className="igr:flex igr:items-baseline igr:justify-between igr:gap-2">
                                                        <p className="igr:text-[13px] igr:font-medium">{t(`contacts.events.${eventKey(e.event)}`, { defaultValue: e.event })}</p>
                                                        <time dateTime={e.occurred_at} className="igr:shrink-0 igr:text-[11px] igr:tabular-nums igr:text-muted-foreground">
                                                            {time.format(parseApiDate(e.occurred_at))}
                                                        </time>
                                                    </div>
                                                    <EventDetail event={e} />
                                                </div>
                                            </li>
                                        );
                                    })}
                                </ol>
                            </li>
                        ))}
                        <li ref={sentinel} aria-hidden="true" />
                    </ol>
                )}
                {hasNextPage && (
                    <Button variant="ghost" size="sm" className="igr:mt-3 igr:w-full" loading={isFetchingNextPage} onClick={() => void fetchNextPage()}>
                        {t('contacts.timeline.loadMore')}
                    </Button>
                )}
            </CardContent>
        </Card>
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
    if (s.points !== undefined) {
        parts.push(t('contacts.timeline.points', { count: Math.abs(Number(s.points)) }));
    }
    if (s.wallet !== undefined) {
        parts.push(t('contacts.timeline.wallet', { amount: money(s.wallet) }));
    }
    if (s.tier !== undefined) {
        const key = s.direction === 'up' ? 'tierUp' : s.direction === 'down' ? 'tierDown' : 'tier';
        parts.push(t(`contacts.timeline.${key}`, { tier: str(s.tier) }));
    }
    if (s.referral_code !== undefined) {
        parts.push(t('contacts.timeline.referral', { code: str(s.referral_code) }));
    }
    if (s.score !== undefined) {
        parts.push(t('contacts.timeline.nps', { score: str(s.score) }));
        if (s.nps_category !== undefined) {
            parts.push(t(`contacts.timeline.npsCategory.${String(s.nps_category)}`, { defaultValue: String(s.nps_category) }));
        }
    }
    if (s.media !== undefined) {
        parts.push(t('contacts.timeline.media', { count: Number(s.media) }));
    }
    if (s.widget_kind !== undefined) {
        parts.push(t(`contacts.timeline.widgetKind.${String(s.widget_kind)}`, { defaultValue: String(s.widget_kind) }));
    }
    if (s.watch_kind !== undefined) {
        parts.push(t(`contacts.timeline.watchKind.${String(s.watch_kind)}`, { defaultValue: String(s.watch_kind) }));
    }
    if (s.message_channel !== undefined) {
        parts.push(t(`contacts.consentChannels.${String(s.message_channel)}`, { defaultValue: String(s.message_channel) }));
    }
    if (s.flow !== undefined) {
        parts.push(t('contacts.timeline.flow', { name: str(s.flow) }));
    }
    if (s.service !== undefined) {
        parts.push(String(s.service));
    }
    if (s.appointment_at !== undefined) {
        parts.push(formatDateTime(String(s.appointment_at), workspace.locale, workspace.timezone));
    }
    if (s.lead_value !== undefined) {
        parts.push(money(s.lead_value) ?? '');
    }
    if (s.calculator !== undefined) {
        parts.push(String(s.calculator));
    }
    if (s.estimate_min !== undefined && s.estimate_max !== undefined) {
        parts.push(`${money(s.estimate_min) ?? ''} – ${money(s.estimate_max) ?? ''}`);
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
