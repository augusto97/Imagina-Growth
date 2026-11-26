import { AlarmClock, CalendarClock, Check, ChevronDown, ExternalLink, Flame, ListTodo, MessageCircleReply, Phone, Send, Snail, Sparkles } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Avatar } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Chip, ChipGroup } from '@/components/ui/chip';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { IconTile, type IconTone } from '@/components/ui/icon-tile';
import { Select, Textarea } from '@/components/ui/input';
import { EmptyState, ErrorState, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { cn } from '@/lib/cn';
import { formatDate, formatDateTime, formatNumber } from '@/lib/format';
import { toast } from '@/stores/toast';
import { errorMessage } from '@/features/contacts/errors';
import { useTeam } from '@/features/leads/api';
import { temperatureTone } from '@/features/leads/logic';
import { useDoneToday, useSendToday, useSnoozeToday, useSuggestion, useToday } from './api';
import { AUTO_SUGGEST, GROUP_PAGE, canSend, groupByKind, hoursSince, totalItems } from './logic';
import { TODAY_KINDS, type TodayItem, type TodayKind } from './types';

const KIND: Record<TodayKind, { icon: typeof Flame; tone: IconTone }> = {
    reply: { icon: MessageCircleReply, tone: 'emerald' },
    first_contact: { icon: Flame, tone: 'rose' },
    task: { icon: ListTodo, tone: 'sky' },
    next_step: { icon: CalendarClock, tone: 'violet' },
    stale: { icon: Snail, tone: 'amber' },
};

/**
 * «Hoy», el asistente del vendedor (M18): a quién escribirle hoy y qué decirle, en orden de urgencia. Cada ítem trae el
 * mensaje que sugiere la IA (con los datos del lead) y se envía con un clic por el despachador (ventana de 24 h,
 * plantillas, consentimiento y horario legal); también se marca hecho o se pospone. Los chips filtran por motivo y cada
 * grupo muestra los primeros (el resto con «Ver más»), así la lista se recorre rápido en el celular.
 */
export function TodayPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const seeAll = useCan('viewAllContacts');
    const canUse = useCan('useLeads');
    const [owner, setOwner] = useState(seeAll ? 'all' : 'me');
    const [kind, setKind] = useState<TodayKind | 'all'>('all');
    const [shown, setShown] = useState<Partial<Record<TodayKind, number>>>({});
    const today = useToday(workspace.id, canUse ? owner : '');
    const team = useTeam(workspace.id);
    const total = totalItems(today.data?.counts);

    if (!canUse) return <EmptyState icon={ListTodo} title={t('today.forbidden')} />;

    return (
        <>
            <PageHeader
                title={t('today.title')}
                description={t('today.description')}
                actions={
                    seeAll ? (
                        <label className="igr:flex igr:items-center igr:gap-2 igr:text-[13px]">
                            <span className="igr:text-muted-foreground">{t('today.owner')}</span>
                            <Select className="igr:w-48" value={owner} onChange={(e) => setOwner(e.target.value)}>
                                <option value="all">{t('today.owners.all')}</option>
                                <option value="me">{t('today.owners.me')}</option>
                                <option value="none">{t('today.owners.none')}</option>
                                {(team.data?.data ?? []).map((m) => (
                                    <option key={m.id} value={m.id}>
                                        {m.name}
                                    </option>
                                ))}
                            </Select>
                        </label>
                    ) : null
                }
            />
            {today.isError ? (
                <ErrorState error={today.error} onRetry={() => void today.refetch()} />
            ) : today.isPending ? (
                <div className="igr:space-y-3" aria-busy>
                    <Skeleton className="igr:h-8 igr:w-96 igr:max-w-full" />
                    {[0, 1, 2].map((i) => (
                        <Skeleton key={i} className="igr:h-44 igr:rounded-xl" />
                    ))}
                </div>
            ) : total === 0 ? (
                <EmptyState
                    illustration="celebration"
                    title={t('today.emptyTitle')}
                    description={t('today.emptyHelp', { days: today.data.stale_days })}
                    action={
                        <Button variant="outline" asChild>
                            <Link to={`/w/${workspace.id}/leads`}>{t('today.seeLeads')}</Link>
                        </Button>
                    }
                />
            ) : (
                <div className="igr:space-y-6">
                    <div className="igr:space-y-2">
                        <p className="igr:text-body igr:font-medium">{t('today.summary', { count: total, formatted: formatNumber(total, workspace.locale) })}</p>
                        <ChipGroup label={t('today.filter')} scroll>
                            <Chip pressed={kind === 'all'} onClick={() => setKind('all')} count={formatNumber(total, workspace.locale)}>
                                {t('today.all')}
                            </Chip>
                            {TODAY_KINDS.filter((k) => today.data.counts[k] > 0).map((k) => (
                                <Chip key={k} pressed={kind === k} icon={KIND[k].icon} onClick={() => setKind(k)} count={formatNumber(today.data.counts[k], workspace.locale)}>
                                    {t(`today.kinds.${k}`)}
                                </Chip>
                            ))}
                        </ChipGroup>
                    </div>
                    {groupByKind(today.data.items)
                        .filter((group) => kind === 'all' || group.kind === kind)
                        .map((group) => {
                            const limit = shown[group.kind] ?? (kind === group.kind ? GROUP_PAGE * 2 : GROUP_PAGE);
                            const rest = group.items.length - limit;
                            return (
                                <section key={group.kind} aria-labelledby={`today-${group.kind}`} className="igr:space-y-3">
                                    <h2 id={`today-${group.kind}`} className="igr:flex igr:items-center igr:gap-2 igr:text-section igr:font-semibold igr:tracking-tight">
                                        {t(`today.kinds.${group.kind}`)}
                                        <span className="igr:text-support igr:font-normal igr:text-muted-foreground igr:tabular-nums">{formatNumber(today.data.counts[group.kind], workspace.locale)}</span>
                                    </h2>
                                    {group.items.slice(0, limit).map((item) => (
                                        <ItemCard
                                            key={item.key}
                                            item={item}
                                            autoSuggest={today.data.items.indexOf(item) < AUTO_SUGGEST}
                                            staleDays={today.data.stale_days}
                                            showOwner={owner !== 'me'}
                                        />
                                    ))}
                                    {rest > 0 ? (
                                        <Button variant="outline" className="igr:w-full" onClick={() => setShown({ ...shown, [group.kind]: limit + GROUP_PAGE * 2 })}>
                                            <ChevronDown />
                                            {t('today.showMore', { count: Math.min(rest, GROUP_PAGE * 2), rest })}
                                        </Button>
                                    ) : null}
                                </section>
                            );
                        })}
                </div>
            )}
        </>
    );
}

function ItemCard({ item, autoSuggest, staleDays, showOwner }: { item: TodayItem; autoSuggest: boolean; staleDays: number; showOwner: boolean }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const [wanted, setWanted] = useState(autoSuggest);
    const suggestion = useSuggestion(workspace.id, item.key, wanted && item.lead !== null);
    const [draft, setDraft] = useState<string | null>(null);
    const [clientId] = useState(() => crypto.randomUUID());
    const send = useSendToday(workspace.id);
    const done = useDoneToday(workspace.id);
    const snooze = useSnoozeToday(workspace.id);
    const look = KIND[item.kind];
    const text = draft ?? suggestion.data?.text ?? '';
    const mode = suggestion.data?.reach.mode;
    const channel = suggestion.data?.reach.channel ?? null;
    const fail = (e: unknown) => toast.error(errorMessage(e, t));
    const temperature = item.lead ? (item.lead.brief_temperature === 'hot' ? 'hot' : item.lead.temperature) : null;
    const messageId = `today-msg-${item.key}`;

    return (
        <Card className="igr:p-4">
            <div className="igr:flex igr:items-start igr:gap-3">
                <IconTile icon={item.task?.kind === 'call' ? Phone : look.icon} tone={look.tone} className="igr:max-sm:hidden" />
                <div className="igr:min-w-0 igr:flex-1 igr:space-y-3">
                    <div>
                        <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-x-2 igr:gap-y-1">
                            <span className="igr:font-semibold">{item.lead?.name ?? t('today.noName')}</span>
                            {item.lead ? <Badge variant="outline">{item.lead.stage_label}</Badge> : null}
                            {temperature ? <Badge variant={temperatureTone(temperature)}>{t(`leads.temperature.${temperature}`)}</Badge> : null}
                        </div>
                        {(item.lead && [item.lead.service, item.lead.city].some(Boolean)) || showOwner ? (
                            <p className="igr:mt-0.5 igr:flex igr:flex-wrap igr:items-center igr:gap-x-2 igr:text-support igr:text-muted-foreground">
                                {item.lead && [item.lead.service, item.lead.city].some(Boolean) ? <span>{[item.lead.service, item.lead.city].filter(Boolean).join(' · ')}</span> : null}
                                {showOwner ? (
                                    <span className="igr:inline-flex igr:items-center igr:gap-1.5">
                                        {item.owner ? <Avatar name={item.owner.name} seed={item.owner.id} size="sm" className="igr:size-5 igr:text-[9px]" /> : null}
                                        {item.owner ? item.owner.name : t('today.unassigned')}
                                    </span>
                                ) : null}
                            </p>
                        ) : null}
                        <p className="igr:mt-1.5 igr:text-body">
                            <Reason item={item} staleDays={staleDays} />
                        </p>
                    </div>

                    {item.lead ? (
                        !wanted ? (
                            <Button size="sm" variant="outline" onClick={() => setWanted(true)}>
                                <Sparkles aria-hidden />
                                {t('today.suggest')}
                            </Button>
                        ) : suggestion.isPending ? (
                            <Skeleton className="igr:h-24 igr:rounded-lg" />
                        ) : (
                            <div className="igr:space-y-2 igr:rounded-lg igr:bg-muted/50 igr:p-3">
                                <div className="igr:flex igr:flex-wrap igr:items-center igr:justify-between igr:gap-2">
                                    <label htmlFor={messageId} className="igr:text-caption igr:font-medium igr:text-muted-foreground">
                                        {t('today.message')}
                                    </label>
                                    {suggestion.data ? (
                                        <Badge variant={suggestion.data.source === 'ai' && draft === null ? 'brand' : 'outline'}>
                                            {suggestion.data.source === 'ai' && draft === null ? <Sparkles className="igr:size-3" aria-hidden /> : null}
                                            {t(draft !== null ? 'today.edited' : suggestion.data.source === 'ai' ? 'today.byAi' : 'today.noAi')}
                                        </Badge>
                                    ) : null}
                                </div>
                                <Textarea id={messageId} rows={3} maxLength={1000} value={text} onChange={(e) => setDraft(e.target.value)} className="igr:bg-card" />
                                <p className={cn('igr:text-caption', mode === 'none' || suggestion.isError ? 'igr:text-warning' : 'igr:text-muted-foreground')}>
                                    {suggestion.isError ? errorMessage(suggestion.error, t) : t(`today.reach.${mode ?? 'none'}`, { channel: t(`today.channels.${channel ?? 'none'}`) })}
                                    {mode === 'none' && suggestion.data?.reach.reason ? ` ${t(`today.noReach.${suggestion.data.reach.reason}`, { defaultValue: '' })}` : ''}
                                </p>
                            </div>
                        )
                    ) : null}

                    <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-2">
                        {item.lead ? (
                            <Button
                                className="igr:max-sm:w-full"
                                disabled={!canSend(text, mode) || send.isPending}
                                loading={send.isPending}
                                onClick={() =>
                                    send.mutate(
                                        { key: item.key, text, client_id: clientId },
                                        {
                                            onSuccess: (r) =>
                                                toast.success(t(r.status === 'duplicate' ? 'today.sentAlready' : 'today.sent', { channel: t(`today.channels.${r.channel ?? 'none'}`) })),
                                            onError: fail,
                                        },
                                    )
                                }
                            >
                                <Send aria-hidden />
                                {channel ? t('today.sendBy', { channel: t(`today.channels.${channel}`) }) : t('today.send')}
                            </Button>
                        ) : null}
                        <Button variant="outline" size="sm" disabled={done.isPending} onClick={() => done.mutate(item.key, { onSuccess: () => toast.success(t('today.doneToast')), onError: fail })}>
                            <Check aria-hidden />
                            {t('today.done')}
                        </Button>
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button size="sm" variant="ghost">
                                    <AlarmClock aria-hidden />
                                    {t('today.snooze')}
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="start">
                                {[1, 3, 7].map((days) => (
                                    <DropdownMenuItem
                                        key={days}
                                        onSelect={() =>
                                            snooze.mutate(
                                                { key: item.key, days },
                                                {
                                                    onSuccess: (r) => toast.success(t('today.snoozed', { date: formatDate(r.until, workspace.locale, workspace.timezone) })),
                                                    onError: fail,
                                                },
                                            )
                                        }
                                    >
                                        {t('today.snoozeDays', { count: days })}
                                    </DropdownMenuItem>
                                ))}
                            </DropdownMenuContent>
                        </DropdownMenu>
                        {item.lead ? (
                            <Button asChild size="sm" variant="ghost" className="igr:sm:ml-auto">
                                <Link to={`/w/${workspace.id}/leads?lead=${item.lead.id}`}>
                                    <ExternalLink aria-hidden />
                                    {t('today.openLead')}
                                </Link>
                            </Button>
                        ) : null}
                    </div>
                </div>
            </div>
        </Card>
    );
}

function Reason({ item, staleDays }: { item: TodayItem; staleDays: number }): ReactNode {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const when = (iso: string | null) => formatDateTime(iso, workspace.locale, workspace.timezone);
    switch (item.kind) {
        case 'reply': {
            const hours = hoursSince(item.since);
            return hours !== null && hours >= 1 ? t('today.reasons.replyHours', { count: hours }) : t('today.reasons.replyNow');
        }
        case 'first_contact':
            return t('today.reasons.firstContact', { since: when(item.since) });
        case 'task':
            return (
                <>
                    <span className="igr:font-medium">{item.task?.title}</span> ·{' '}
                    {item.overdue ? <span className="igr:text-destructive">{t('today.reasons.overdue', { at: when(item.due_at) })}</span> : t('today.reasons.due', { at: when(item.due_at) })}
                    {item.task?.source === 'flow' ? <span className="igr:text-muted-foreground"> · {t('today.reasons.fromFlow')}</span> : null}
                </>
            );
        case 'next_step':
            return (
                <>
                    {t('today.reasons.nextStep', { step: item.lead?.next_step ?? t('today.reasons.noStep') })} ·{' '}
                    <span className={item.overdue ? 'igr:text-destructive' : undefined}>{when(item.due_at)}</span>
                </>
            );
        case 'stale':
            return t('today.reasons.stale', { count: item.days ?? staleDays, stage: item.lead?.stage_label ?? '' });
    }
}
