import { ArrowLeft, Bot, Check, CheckCheck, Clock, PanelRight, Smartphone, Sparkles, StickyNote, UserRound } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input, Select } from '@/components/ui/input';
import { ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { cn } from '@/lib/cn';
import { formatDateTime } from '@/lib/format';
import { toast } from '@/stores/toast';
import { errorMessage } from '@/features/contacts/errors';
import { useConversation, useConversationActions, useInboxAgents } from './api';
import { Composer } from './Composer';
import { ChannelIcon } from './ChannelIcon';
import { composerMode, formatLeft, parseLabels, windowLeft } from './lib';
import type { AiMode, ChatMessage, ConversationDetail, ConversationStatus } from './types';

/** El chat de una conversación: cabecera con estado, asignación, IA y etiquetas; mensajes; notas internas; y el editor. */
export function ChatPane({ id, onBack, onTogglePanel }: { id: string; onBack: () => void; onTogglePanel: () => void }) {
    const { workspace } = useWorkspace();
    const query = useConversation(workspace.id, id);
    const actions = useConversationActions(workspace.id, id);
    const [showNotes, setShowNotes] = useState(false);
    const { mutate: markRead } = actions.read;
    const unread = query.data?.unread_count ?? 0;

    useEffect(() => {
        if (unread > 0) markRead();
    }, [unread, markRead]);

    if (query.isError) {
        return (
            <div className="igr:p-4">
                <ErrorState error={query.error} onRetry={() => void query.refetch()} />
            </div>
        );
    }
    if (query.isPending) {
        return (
            <div className="igr:space-y-3 igr:p-4">
                <Skeleton className="igr:h-10" />
                <Skeleton className="igr:h-24" />
                <Skeleton className="igr:h-24" />
            </div>
        );
    }
    const c = query.data;

    return (
        <div className="igr:flex igr:h-full igr:min-h-0 igr:flex-col">
            <ChatHeader c={c} onBack={onBack} onTogglePanel={onTogglePanel} showNotes={showNotes} onToggleNotes={() => setShowNotes((v) => !v)} />
            <div className="igr:flex igr:min-h-0 igr:flex-1">
                <Messages messages={c.messages} />
                {showNotes ? <NotesPane c={c} /> : null}
            </div>
            <Composer c={c} />
        </div>
    );
}

function ChatHeader({
    c,
    onBack,
    onTogglePanel,
    showNotes,
    onToggleNotes,
}: {
    c: ConversationDetail;
    onBack: () => void;
    onTogglePanel: () => void;
    showNotes: boolean;
    onToggleNotes: () => void;
}) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const seesAll = useCan('viewAllContacts');
    const actions = useConversationActions(workspace.id, c.id);
    const agents = useInboxAgents(workspace.id);
    const [labels, setLabels] = useState(c.labels.join(', '));
    const fail = (e: unknown) => toast.error(errorMessage(e, t));

    return (
        <div className="igr:space-y-2 igr:border-b igr:px-3 igr:py-2">
            <div className="igr:flex igr:items-center igr:gap-2">
                <Button size="icon" variant="ghost" className="igr:md:hidden" onClick={onBack} aria-label={t('inbox.back')}>
                    <ArrowLeft aria-hidden />
                </Button>
                <div className="igr:min-w-0 igr:flex-1">
                    <h2 className="igr:flex igr:items-center igr:gap-1.5 igr:truncate igr:text-sm igr:font-semibold">
                        <ChannelIcon channel={c.channel} />
                        <span className="igr:truncate">{c.contact?.name ?? t('inbox.unknownContact')}</span>
                    </h2>
                    <WindowBadge c={c} />
                </div>
                <Select
                    aria-label={t('inbox.chat.status')}
                    className="igr:h-7 igr:w-auto igr:text-xs"
                    value={c.status}
                    onChange={(e) => actions.update.mutate({ status: e.target.value as ConversationStatus }, { onError: fail })}
                >
                    {(['open', 'pending', 'closed'] as const).map((s) => (
                        <option key={s} value={s}>
                            {t(`inbox.chat.statusValue.${s}`)}
                        </option>
                    ))}
                </Select>
                <Button size="icon" variant={showNotes ? 'secondary' : 'ghost'} onClick={onToggleNotes} aria-pressed={showNotes} aria-label={t('inbox.notes.toggle')}>
                    <StickyNote aria-hidden />
                </Button>
                <Button size="icon" variant="ghost" className="igr:xl:hidden" onClick={onTogglePanel} aria-label={t('inbox.panel.toggle')}>
                    <PanelRight aria-hidden />
                </Button>
            </div>
            <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-2 igr:text-xs">
                <label className="igr:flex igr:items-center igr:gap-1">
                    <UserRound className="igr:size-3.5 igr:text-muted-foreground" aria-hidden />
                    <span className="igr:sr-only">{t('inbox.chat.assignee')}</span>
                    <Select
                        className="igr:h-7 igr:w-auto igr:text-xs"
                        value={c.assignee?.id ?? ''}
                        disabled={!seesAll && c.assignee !== null && agents.data === undefined}
                        onChange={(e) => actions.assign.mutate(e.target.value === '' ? null : e.target.value, { onError: fail })}
                    >
                        <option value="">{t('inbox.chat.unassigned')}</option>
                        {(agents.data?.data ?? []).map((a) => (
                            <option key={a.id} value={a.id}>
                                {a.name}
                                {a.online ? ` · ${t('inbox.available')}` : ''}
                            </option>
                        ))}
                    </Select>
                </label>
                <AiModeSwitch value={c.ai_mode} onChange={(mode) => actions.update.mutate({ ai_mode: mode }, { onError: fail })} />
                {c.handoff_reason ? (
                    <Badge variant="warning">
                        {t('inbox.handoff.reason', {
                            reason: t(`inbox.handoff.reasons.${c.handoff_reason}`, { defaultValue: c.handoff_reason }),
                        })}
                    </Badge>
                ) : null}
                {c.ai_mode !== 'off' ? (
                    <Button size="sm" variant="ghost" onClick={() => actions.handoff.mutate('manual', { onError: fail })}>
                        {t('inbox.handoff.takeOver')}
                    </Button>
                ) : null}
                <Input
                    aria-label={t('inbox.chat.labels')}
                    placeholder={t('inbox.chat.labelsPlaceholder')}
                    className="igr:h-7 igr:max-w-48 igr:text-xs"
                    value={labels}
                    onChange={(e) => setLabels(e.target.value)}
                    onBlur={() => {
                        const next = parseLabels(labels);
                        if (next.join(',') !== c.labels.join(',')) actions.update.mutate({ labels: next }, { onError: fail });
                    }}
                />
            </div>
        </div>
    );
}

function AiModeSwitch({ value, onChange }: { value: AiMode; onChange: (mode: AiMode) => void }) {
    const { t } = useTranslation();
    return (
        <div role="radiogroup" aria-label={t('inbox.ai.label')} className="igr:inline-flex igr:rounded-md igr:border igr:p-0.5">
            {(['off', 'assist', 'auto'] as const).map((mode) => (
                <button
                    key={mode}
                    type="button"
                    role="radio"
                    aria-checked={value === mode}
                    onClick={() => onChange(mode)}
                    className={cn(
                        'igr:flex igr:items-center igr:gap-1 igr:rounded igr:px-2 igr:py-0.5 igr:outline-none igr:focus-visible:ring-2 igr:focus-visible:ring-ring',
                        value === mode ? 'igr:bg-primary/10 igr:font-medium igr:text-primary' : 'igr:text-muted-foreground',
                    )}
                >
                    {mode === 'auto' ? <Bot className="igr:size-3" aria-hidden /> : mode === 'assist' ? <Sparkles className="igr:size-3" aria-hidden /> : null}
                    {t(`inbox.ai.${mode}`)}
                </button>
            ))}
        </div>
    );
}

/** Contador de la ventana de 24 h. Fuera de ella: plantillas (WhatsApp), solo una persona hasta 7 días (Messenger) o nada (Instagram). */
function WindowBadge({ c }: { c: ConversationDetail }) {
    const expiresAt = c.window_expires_at;
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const [now, setNow] = useState(() => new Date());
    useEffect(() => {
        const timer = window.setInterval(() => setNow(new Date()), 30_000);
        return () => window.clearInterval(timer);
    }, []);
    const left = windowLeft(expiresAt, now);
    if (left <= 0) {
        const mode = composerMode(c, now);
        return (
            <p className="igr:text-[11px] igr:text-warning">
                {mode === 'human'
                    ? t('inbox.window.humanAgent', {
                          until: c.human_agent_until
                              ? new Date(c.human_agent_until).toLocaleDateString(workspace.locale, { day: 'numeric', month: 'short', timeZone: workspace.timezone })
                              : '',
                      })
                    : t(mode === 'none' ? 'inbox.window.closedSocial' : 'inbox.window.closed')}
            </p>
        );
    }
    const { hours, minutes } = formatLeft(left);
    return (
        <p className={cn('igr:flex igr:items-center igr:gap-1 igr:text-[11px]', hours < 2 ? 'igr:text-warning' : 'igr:text-muted-foreground')}>
            <Clock className="igr:size-3" aria-hidden />
            {t('inbox.window.open', {
                hours,
                minutes: String(minutes).padStart(2, '0'),
            })}
        </p>
    );
}

function Messages({ messages }: { messages: ChatMessage[] }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const bottom = useRef<HTMLDivElement>(null);
    const last = messages[messages.length - 1]?.id;
    useEffect(() => {
        bottom.current?.scrollIntoView({ block: 'end' });
    }, [last]);

    return (
        <div
            className="igr:min-h-0 igr:flex-1 igr:space-y-2 igr:overflow-y-auto igr:bg-muted/30 igr:px-3 igr:py-3"
            role="log"
            aria-live="polite"
            aria-label={t('inbox.chat.messages')}
        >
            {messages.length === 0 ? <p className="igr:py-8 igr:text-center igr:text-xs igr:text-muted-foreground">{t('inbox.chat.noMessages')}</p> : null}
            {messages.map((m) => (
                <div key={m.id} className={cn('igr:flex', m.direction === 'out' ? 'igr:justify-end' : 'igr:justify-start')}>
                    <div
                        className={cn(
                            'igr:max-w-[80%] igr:rounded-lg igr:px-3 igr:py-2 igr:text-[13px] igr:shadow-xs',
                            m.direction === 'out' ? 'igr:bg-primary/10' : 'igr:border igr:bg-background',
                            m.status === 'failed' && 'igr:border igr:border-destructive/40',
                        )}
                    >
                        {m.author ? (
                            <p className="igr:mb-0.5 igr:flex igr:items-center igr:gap-1 igr:text-[10px] igr:font-medium igr:text-muted-foreground">
                                {m.author.kind === 'ai' ? <Bot className="igr:size-3" aria-hidden /> : null}
                                {m.author.kind === 'phone' ? <Smartphone className="igr:size-3" aria-hidden /> : null}
                                {m.author.kind === 'phone' ? t('inbox.chat.fromPhone') : m.author.name}
                            </p>
                        ) : null}
                        {m.type === 'revoked' ? <p className="igr:text-[11px] igr:italic igr:text-muted-foreground">{t('inbox.chat.revoked')}</p> : null}
                        {m.type === 'media_placeholder' ? <p className="igr:text-[11px] igr:italic igr:text-muted-foreground">{t('inbox.chat.historyMedia')}</p> : null}
                        {m.template ? <p className="igr:text-[11px] igr:text-muted-foreground">{t('inbox.chat.template', { name: m.template })}</p> : null}
                        {m.attachments.map((a, i) => (
                            <p key={i} className="igr:text-[11px] igr:italic igr:text-muted-foreground">
                                {t(`inbox.attachment.${a.kind}`, {
                                    defaultValue: a.kind,
                                })}
                            </p>
                        ))}
                        {m.type === 'story_reply' || m.type === 'story_mention' || m.type === 'share' ? (
                            <p className="igr:text-[11px] igr:italic igr:text-muted-foreground">{t(`inbox.attachment.${m.type}`)}</p>
                        ) : null}
                        {m.media_url ? (
                            <a href={m.media_url} target="_blank" rel="noopener noreferrer nofollow" className="igr:text-[11px] igr:text-primary igr:underline">
                                {t('inbox.chat.openAttachment')}
                            </a>
                        ) : null}
                        {m.text ? <p className="igr:whitespace-pre-wrap igr:break-words">{m.text}</p> : null}
                        <p className="igr:mt-1 igr:flex igr:items-center igr:justify-end igr:gap-1 igr:text-[10px] igr:text-muted-foreground">
                            {m.edited ? <span>{t('inbox.chat.edited')}</span> : null}
                            {formatDateTime(m.at, workspace.locale, workspace.timezone)}
                            {m.direction === 'out' ? <DeliveryIcon m={m} /> : null}
                        </p>
                        {m.status === 'failed' && m.failed_reason ? <p className="igr:text-[11px] igr:text-destructive">{m.failed_reason}</p> : null}
                    </div>
                </div>
            ))}
            <div ref={bottom} />
        </div>
    );
}

function DeliveryIcon({ m }: { m: ChatMessage }) {
    const { t } = useTranslation();
    if (m.read_at) return <CheckCheck className="igr:size-3 igr:text-primary" aria-label={t('inbox.delivery.read')} />;
    if (m.delivered_at) return <CheckCheck className="igr:size-3" aria-label={t('inbox.delivery.delivered')} />;
    if (m.status === 'sent') return <Check className="igr:size-3" aria-label={t('inbox.delivery.sent')} />;
    return <Clock className="igr:size-3" aria-label={t('inbox.delivery.queued')} />;
}

function NotesPane({ c }: { c: ConversationDetail }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const actions = useConversationActions(workspace.id, c.id);
    const [body, setBody] = useState('');
    return (
        <div className="igr:hidden igr:w-64 igr:shrink-0 igr:flex-col igr:border-l igr:bg-warning/5 igr:md:flex">
            <p className="igr:border-b igr:px-3 igr:py-2 igr:text-xs igr:font-semibold">{t('inbox.notes.title')}</p>
            <div className="igr:min-h-0 igr:flex-1 igr:space-y-2 igr:overflow-y-auto igr:p-3">
                {c.notes.length === 0 ? <p className="igr:text-xs igr:text-muted-foreground">{t('inbox.notes.empty')}</p> : null}
                {c.notes.map((n) => (
                    <div key={n.id} className="igr:rounded-md igr:border igr:bg-background igr:p-2 igr:text-xs">
                        <p className="igr:whitespace-pre-wrap">{n.body}</p>
                        <p className="igr:mt-1 igr:text-[10px] igr:text-muted-foreground">
                            {n.author ?? '—'} · {formatDateTime(n.at, workspace.locale, workspace.timezone)}
                        </p>
                    </div>
                ))}
            </div>
            <form
                className="igr:space-y-1.5 igr:border-t igr:p-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    if (body.trim() === '') return;
                    actions.note.mutate(body, {
                        onSuccess: () => setBody(''),
                        onError: (err) => toast.error(errorMessage(err, t)),
                    });
                }}
            >
                <label className="igr:sr-only" htmlFor="inbox-note">
                    {t('inbox.notes.add')}
                </label>
                <textarea
                    id="inbox-note"
                    className="igr:h-16 igr:w-full igr:resize-none igr:rounded-md igr:border igr:bg-background igr:p-2 igr:text-xs"
                    placeholder={t('inbox.notes.placeholder')}
                    value={body}
                    onChange={(e) => setBody(e.target.value)}
                />
                <Button type="submit" size="sm" className="igr:w-full" loading={actions.note.isPending}>
                    {t('inbox.notes.add')}
                </Button>
            </form>
        </div>
    );
}
