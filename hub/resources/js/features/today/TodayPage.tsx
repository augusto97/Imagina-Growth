import { AlarmClock, CalendarClock, Check, CheckCircle2, Clock, ExternalLink, Flame, ListTodo, MessageCircleReply, Phone, Send, Snail, Sparkles } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Select, Textarea } from '@/components/ui/input';
import { EmptyState, ErrorState, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { formatDate, formatDateTime } from '@/lib/format';
import { toast } from '@/stores/toast';
import { errorMessage } from '@/features/contacts/errors';
import { useTeam } from '@/features/leads/api';
import { temperatureTone } from '@/features/leads/logic';
import { useDoneToday, useSendToday, useSnoozeToday, useSuggestion, useToday } from './api';
import { AUTO_SUGGEST, canSend, groupByKind, hoursSince, totalItems } from './logic';
import type { TodayItem, TodayKind } from './types';

const ICONS: Record<TodayKind, typeof Flame> = { reply: MessageCircleReply, first_contact: Flame, task: ListTodo, next_step: CalendarClock, stale: Snail };

/**
 * «Hoy», el asistente del vendedor (M18): a quién escribirle hoy y qué decirle. Cada
 * ítem trae el mensaje que sugiere la IA (con los datos del lead) y se envía con un
 * clic por el despachador (ventana de 24 h, plantillas, consentimiento y horario legal);
 * también se marca hecho o se pospone. La dueña ve el de todo el equipo.
 */
export function TodayPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const seeAll = useCan('viewAllContacts');
    const canUse = useCan('useLeads');
    const [owner, setOwner] = useState(seeAll ? 'all' : 'me');
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
                            <Select className="igr:h-8 igr:w-48" value={owner} onChange={(e) => setOwner(e.target.value)}>
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
                <div className="igr:space-y-3">
                    {[0, 1, 2].map((i) => (
                        <Skeleton key={i} className="igr:h-36" />
                    ))}
                </div>
            ) : total === 0 ? (
                <EmptyState icon={CheckCircle2} title={t('today.emptyTitle')} description={t('today.emptyHelp', { days: today.data.stale_days })} />
            ) : (
                <div className="igr:space-y-6">
                    <p className="igr:text-[13px] igr:text-muted-foreground">{t('today.summary', { count: total })}</p>
                    {groupByKind(today.data.items).map((group) => (
                        <section key={group.kind} aria-labelledby={`today-${group.kind}`} className="igr:space-y-2">
                            <h2 id={`today-${group.kind}`} className="igr:flex igr:items-center igr:gap-2 igr:text-[13px] igr:font-semibold">
                                {t(`today.kinds.${group.kind}`)}
                                <Badge variant="secondary">{today.data.counts[group.kind]}</Badge>
                            </h2>
                            {group.items.map((item) => (
                                <ItemCard
                                    key={item.key}
                                    item={item}
                                    autoSuggest={today.data.items.indexOf(item) < AUTO_SUGGEST}
                                    staleDays={today.data.stale_days}
                                    showOwner={owner !== 'me'}
                                />
                            ))}
                        </section>
                    ))}
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
    const Icon = item.task?.kind === 'call' ? Phone : ICONS[item.kind];
    const text = draft ?? suggestion.data?.text ?? '';
    const mode = suggestion.data?.reach.mode;
    const fail = (e: unknown) => toast.error(errorMessage(e, t));

    return (
        <Card className="igr:p-3">
            <div className="igr:flex igr:items-start igr:gap-3">
                <Icon className="igr:mt-0.5 igr:size-4 igr:shrink-0 igr:text-muted-foreground" aria-hidden />
                <div className="igr:min-w-0 igr:flex-1 igr:space-y-2">
                    <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-x-2 igr:gap-y-1">
                        <span className="igr:font-medium">{item.lead?.name ?? t('today.noName')}</span>
                        {item.lead ? (
                            <>
                                <span className="igr:text-[13px] igr:text-muted-foreground">{[item.lead.service, item.lead.city].filter(Boolean).join(' · ')}</span>
                                <Badge variant="outline">{item.lead.stage_label}</Badge>
                                <Badge variant={temperatureTone(item.lead.brief_temperature === 'hot' ? 'hot' : item.lead.temperature)}>
                                    {t(`leads.temperature.${item.lead.brief_temperature === 'hot' ? 'hot' : item.lead.temperature}`)}
                                </Badge>
                            </>
                        ) : null}
                        {showOwner ? <span className="igr:text-xs igr:text-muted-foreground">{item.owner ? item.owner.name : t('today.unassigned')}</span> : null}
                    </div>
                    <p className="igr:text-[13px]">
                        <Reason item={item} staleDays={staleDays} />
                    </p>
                    {item.lead ? (
                        <div className="igr:space-y-1.5">
                            {!wanted ? (
                                <Button size="sm" variant="outline" onClick={() => setWanted(true)}>
                                    <Sparkles aria-hidden />
                                    {t('today.suggest')}
                                </Button>
                            ) : suggestion.isPending ? (
                                <Skeleton className="igr:h-16" />
                            ) : (
                                <>
                                    <Textarea aria-label={t('today.message')} rows={3} maxLength={1000} value={text} onChange={(e) => setDraft(e.target.value)} />
                                    <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-2 igr:text-xs igr:text-muted-foreground">
                                        {suggestion.data ? (
                                            <Badge variant={suggestion.data.source === 'ai' && draft === null ? 'default' : 'outline'}>
                                                {suggestion.data.source === 'ai' && draft === null ? <Sparkles className="igr:size-3" aria-hidden /> : null}
                                                {t(draft !== null ? 'today.edited' : suggestion.data.source === 'ai' ? 'today.byAi' : 'today.noAi')}
                                            </Badge>
                                        ) : null}
                                        <span>
                                            {suggestion.isError
                                                ? errorMessage(suggestion.error, t)
                                                : t(`today.reach.${mode ?? 'none'}`, { channel: t(`today.channels.${suggestion.data.reach.channel ?? 'none'}`) })}
                                        </span>
                                        {mode === 'none' && suggestion.data?.reach.reason ? (
                                            <span>{t(`today.noReach.${suggestion.data.reach.reason}`, { defaultValue: '' })}</span>
                                        ) : null}
                                    </div>
                                </>
                            )}
                        </div>
                    ) : null}
                    <div className="igr:flex igr:flex-wrap igr:gap-2">
                        {item.lead ? (
                            <Button
                                size="sm"
                                disabled={!canSend(text, mode) || send.isPending}
                                onClick={() =>
                                    send.mutate(
                                        { key: item.key, text, client_id: clientId },
                                        {
                                            onSuccess: (r) =>
                                                toast.success(
                                                    t(r.status === 'duplicate' ? 'today.sentAlready' : 'today.sent', { channel: t(`today.channels.${r.channel ?? 'none'}`) }),
                                                ),
                                            onError: fail,
                                        },
                                    )
                                }
                            >
                                <Send aria-hidden />
                                {t('today.send')}
                            </Button>
                        ) : null}
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={done.isPending}
                            onClick={() => done.mutate(item.key, { onSuccess: () => toast.success(t('today.doneToast')), onError: fail })}
                        >
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
                            <Button asChild size="sm" variant="ghost">
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
                    {item.overdue ? (
                        <span className="igr:text-destructive">{t('today.reasons.overdue', { at: when(item.due_at) })}</span>
                    ) : (
                        t('today.reasons.due', { at: when(item.due_at) })
                    )}
                    {item.task?.source === 'flow' ? <span className="igr:text-muted-foreground"> · {t('today.reasons.fromFlow')}</span> : null}
                </>
            );
        case 'next_step':
            return (
                <>
                    {t('today.reasons.nextStep', { step: item.lead?.next_step ?? t('today.reasons.noStep') })} ·{' '}
                    <span className={item.overdue ? 'igr:text-destructive' : undefined}>
                        <Clock className="igr:inline igr:size-3" aria-hidden /> {when(item.due_at)}
                    </span>
                </>
            );
        case 'stale':
            return t('today.reasons.stale', { count: item.days ?? staleDays, stage: item.lead?.stage_label ?? '' });
    }
}
