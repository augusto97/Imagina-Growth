import { ArrowLeft, Bot, Check, CheckCheck, Clock, Hand, PanelRight, SlidersHorizontal, Smartphone, Sparkles, StickyNote, UserRound } from 'lucide-react';
import { Fragment, useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { Callout } from '@/components/ui/callout';
import { HelpTip } from '@/components/ui/help-tip';
import { Input, Select } from '@/components/ui/input';
import { ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { cn } from '@/lib/cn';
import { formatDate } from '@/lib/format';
import { toast } from '@/stores/toast';
import { errorMessage } from '@/features/contacts/errors';
import { useConversation, useConversationActions, useInboxAgents } from './api';
import { Composer } from './Composer';
import { ChannelIcon } from './ChannelIcon';
import { composerMode, dayKey, formatLeft, initials, parseLabels, windowLeft } from './lib';
import type { AiMode, ChatMessage, ConversationDetail, ConversationStatus } from './types';

/** El chat de una conversación: cabecera con la ventana en palabras, asignación, IA y etiquetas; mensajes; notas internas; y el editor. */
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
            <div className="igr:flex igr:h-full igr:flex-col">
                <div className="igr:flex igr:items-center igr:gap-3 igr:border-b igr:px-4 igr:py-3">
                    <Skeleton className="igr:size-9 igr:rounded-full" />
                    <div className="igr:flex-1 igr:space-y-2">
                        <Skeleton className="igr:h-3.5 igr:w-40" />
                        <Skeleton className="igr:h-3 igr:w-56" />
                    </div>
                </div>
                <div className="igr:flex-1 igr:space-y-3 igr:p-4">
                    <Skeleton className="igr:h-14 igr:w-2/3" />
                    <Skeleton className="igr:ml-auto igr:h-14 igr:w-1/2" />
                </div>
            </div>
        );
    }
    const c = query.data;

    return (
        <div className="igr:flex igr:h-full igr:min-h-0 igr:flex-col">
            <ChatHeader c={c} onBack={onBack} onTogglePanel={onTogglePanel} showNotes={showNotes} onToggleNotes={() => setShowNotes((v) => !v)} />
            <AiBanner c={c} />
            <div className="igr:relative igr:flex igr:min-h-0 igr:flex-1">
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
    // Celular: la asignación, la IA y las etiquetas van plegadas para dejarle la pantalla a los mensajes.
    const [tools, setTools] = useState(false);
    const fail = (e: unknown) => toast.error(errorMessage(e, t));

    return (
        <div className="igr:space-y-2.5 igr:border-b igr:px-3 igr:py-2.5 igr:md:px-4">
            <div className="igr:flex igr:items-center igr:gap-2.5">
                <Button size="icon" variant="ghost" className="igr:-ml-1 igr:md:hidden" onClick={onBack} aria-label={t('inbox.back')}>
                    <ArrowLeft aria-hidden />
                </Button>
                <span aria-hidden className="igr:flex igr:size-9 igr:shrink-0 igr:items-center igr:justify-center igr:rounded-full igr:bg-muted igr:text-xs igr:font-semibold igr:text-muted-foreground igr:max-lg:hidden">
                    {initials(c.contact?.name)}
                </span>
                <div className="igr:min-w-0 igr:flex-1">
                    <h2 className="igr:flex igr:items-center igr:gap-1.5 igr:truncate igr:text-[15px] igr:font-semibold igr:tracking-tight">
                        <span className="igr:truncate">{c.contact?.name ?? t('inbox.unknownContact')}</span>
                        <ChannelIcon channel={c.channel} />
                    </h2>
                    <WindowLine c={c} />
                </div>
                <Select
                    aria-label={t('inbox.chat.status')}
                    className="igr:h-8 igr:w-auto igr:text-xs igr:max-sm:max-w-28"
                    value={c.status}
                    onChange={(e) => actions.update.mutate({ status: e.target.value as ConversationStatus }, { onError: fail })}
                >
                    {(['open', 'pending', 'closed'] as const).map((s) => (
                        <option key={s} value={s}>
                            {t(`inbox.chat.statusValue.${s}`)}
                        </option>
                    ))}
                </Select>
                <Button
                    size="icon"
                    variant={tools ? 'secondary' : 'ghost'}
                    className="igr:relative igr:md:hidden"
                    onClick={() => setTools((v) => !v)}
                    aria-expanded={tools}
                    aria-controls={`chat-tools-${c.id}`}
                    aria-label={t('inbox.chat.tools')}
                >
                    <SlidersHorizontal aria-hidden />
                    {c.handoff_reason ? <span className="igr:absolute igr:top-1 igr:right-1 igr:size-1.5 igr:rounded-full igr:bg-warning" aria-hidden /> : null}
                </Button>
                <Button size="icon" variant={showNotes ? 'secondary' : 'ghost'} className="igr:max-md:hidden" onClick={onToggleNotes} aria-pressed={showNotes} aria-label={t('inbox.notes.toggle')}>
                    <StickyNote aria-hidden />
                </Button>
                <Button size="icon" variant="ghost" className="igr:xl:hidden" onClick={onTogglePanel} aria-label={t('inbox.panel.toggle')} aria-haspopup="dialog">
                    <PanelRight aria-hidden />
                </Button>
            </div>
            <div id={`chat-tools-${c.id}`} className={cn('igr:flex igr:flex-wrap igr:items-center igr:gap-2 igr:text-xs', !tools && 'igr:max-md:hidden')}>
                <label className="igr:flex igr:items-center igr:gap-1.5">
                    <UserRound className="igr:size-3.5 igr:text-muted-foreground" aria-hidden />
                    <span className="igr:sr-only">{t('inbox.chat.assignee')}</span>
                    <Select
                        className="igr:h-8 igr:w-auto igr:max-w-44 igr:text-xs"
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
                <Input
                    aria-label={t('inbox.chat.labels')}
                    placeholder={t('inbox.chat.labelsPlaceholder')}
                    className="igr:h-8 igr:max-w-60 igr:min-w-40 igr:flex-1 igr:text-xs"
                    value={labels}
                    onChange={(e) => setLabels(e.target.value)}
                    onBlur={() => {
                        const next = parseLabels(labels);
                        if (next.join(',') !== c.labels.join(',')) actions.update.mutate({ labels: next }, { onError: fail });
                    }}
                />
                <Button size="sm" variant={showNotes ? 'secondary' : 'ghost'} className="igr:md:hidden" onClick={onToggleNotes} aria-pressed={showNotes}>
                    <StickyNote aria-hidden />
                    {t('inbox.notes.toggle')}
                </Button>
            </div>
        </div>
    );
}

/** Qué hace la IA en esta conversación: la apagas, te sugiere respuestas o responde sola (y pasa a una persona cuando hace falta). */
function AiModeSwitch({ value, onChange }: { value: AiMode; onChange: (mode: AiMode) => void }) {
    const { t } = useTranslation();
    return (
        <div className="igr:flex igr:items-center igr:gap-1">
            <div role="radiogroup" aria-label={t('inbox.ai.label')} className="igr:inline-flex igr:rounded-md igr:border igr:bg-card igr:p-0.5">
                {(['off', 'assist', 'auto'] as const).map((mode) => (
                    <button
                        key={mode}
                        type="button"
                        role="radio"
                        aria-checked={value === mode}
                        onClick={() => onChange(mode)}
                        className={cn(
                            'igr:flex igr:h-6 igr:items-center igr:gap-1 igr:rounded igr:px-2 igr:outline-none igr:transition-colors igr:focus-visible:ring-2 igr:focus-visible:ring-ring',
                            value === mode ? (mode === 'off' ? 'igr:bg-muted igr:font-medium igr:text-foreground' : 'igr:bg-brand-soft igr:font-medium igr:text-brand-soft-foreground') : 'igr:text-muted-foreground igr:hover:text-foreground',
                        )}
                    >
                        {mode === 'auto' ? <Bot className="igr:size-3" aria-hidden /> : mode === 'assist' ? <Sparkles className="igr:size-3" aria-hidden /> : null}
                        {t(`inbox.ai.${mode}`)}
                    </button>
                ))}
            </div>
            <HelpTip content={t('inbox.ai.help')} label={t('inbox.ai.helpLabel')} />
        </div>
    );
}

/** Lo que está haciendo el agente con IA en esta conversación, dicho en palabras, y cómo tomarla. */
function AiBanner({ c }: { c: ConversationDetail }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const actions = useConversationActions(workspace.id, c.id);
    const fail = (e: unknown) => toast.error(errorMessage(e, t));
    if (c.handoff_reason) {
        return (
            <div className="igr:border-b igr:px-3 igr:py-2 igr:md:px-4">
                <Callout tone="warning" icon={Hand} title={t('inbox.handoff.title')}>
                    {t('inbox.handoff.reason', { reason: t(`inbox.handoff.reasons.${c.handoff_reason}`, { defaultValue: t('inbox.handoff.reasons.other') }) })}
                </Callout>
            </div>
        );
    }
    if (c.ai_mode === 'off') return null;
    return (
        <div className="igr:border-b igr:px-3 igr:py-2 igr:md:px-4">
            <Callout
                tone="brand"
                icon={c.ai_mode === 'auto' ? Bot : Sparkles}
                title={t(c.ai_mode === 'auto' ? 'inbox.ai.autoBanner' : 'inbox.ai.assistBanner')}
                action={
                    c.ai_mode === 'auto' ? (
                        <Button size="sm" variant="outline" loading={actions.handoff.isPending} onClick={() => actions.handoff.mutate('manual', { onError: fail })}>
                            <Hand aria-hidden />
                            {t('inbox.handoff.takeOver')}
                        </Button>
                    ) : undefined
                }
            >
                {t(c.ai_mode === 'auto' ? 'inbox.ai.autoBannerHelp' : 'inbox.ai.assistBannerHelp')}{' '}
                <Link to={`/w/${workspace.id}/bandeja/agente`} className="igr:font-medium igr:text-primary igr:underline-offset-4 igr:hover:underline">
                    {t('inbox.ai.configure')}
                </Link>
            </Callout>
        </div>
    );
}

/**
 * La ventana de 24 h en palabras: cuánto tiempo queda para responder libremente y, fuera de ella, qué se puede hacer
 * (plantilla aprobada en WhatsApp, solo una persona hasta 7 días en Messenger, esperar en Instagram).
 */
function WindowLine({ c }: { c: ConversationDetail }) {
    const expiresAt = c.window_expires_at;
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const [now, setNow] = useState(() => new Date());
    useEffect(() => {
        const timer = window.setInterval(() => setNow(new Date()), 30_000);
        return () => window.clearInterval(timer);
    }, []);
    const left = windowLeft(expiresAt, now);
    const help = <HelpTip content={t(`inbox.window.explain_${c.channel}`)} label={t('inbox.window.explainLabel')} />;
    if (left <= 0) {
        const mode = composerMode(c, now);
        return (
            <p className="igr:flex igr:min-w-0 igr:items-center igr:gap-1 igr:text-xs igr:text-warning">
                <Clock className="igr:size-3 igr:shrink-0" aria-hidden />
                <span className="igr:truncate">
                    {mode === 'human'
                        ? t('inbox.window.humanAgent', { until: c.human_agent_until ? formatDate(c.human_agent_until, workspace.locale, workspace.timezone) : '' })
                        : t(mode === 'none' ? 'inbox.window.closedSocial' : 'inbox.window.closed')}
                </span>
                {help}
            </p>
        );
    }
    const { hours, minutes } = formatLeft(left);
    return (
        <p className={cn('igr:flex igr:min-w-0 igr:items-center igr:gap-1 igr:text-xs', hours < 2 ? 'igr:text-warning' : 'igr:text-muted-foreground')}>
            <Clock className="igr:size-3 igr:shrink-0" aria-hidden />
            <span className="igr:truncate">{hours > 0 ? t('inbox.window.open', { hours, minutes }) : t('inbox.window.openMinutes', { minutes })}</span>
            {help}
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
    const time = (iso: string | null) => (iso ? new Date(iso).toLocaleTimeString(workspace.locale, { hour: 'numeric', minute: '2-digit', timeZone: workspace.timezone }) : '');

    return (
        <div
            className="igr:min-h-0 igr:flex-1 igr:space-y-2 igr:overflow-y-auto igr:bg-muted/30 igr:px-3 igr:py-4 igr:md:px-5"
            role="log"
            aria-live="polite"
            aria-label={t('inbox.chat.messages')}
        >
            {messages.length === 0 ? <p className="igr:py-8 igr:text-center igr:text-xs igr:text-muted-foreground">{t('inbox.chat.noMessages')}</p> : null}
            {messages.map((m, i) => {
                const day = dayKey(m.at, workspace.timezone);
                const newDay = day !== '' && day !== dayKey(messages[i - 1]?.at ?? null, workspace.timezone);
                return (
                    <Fragment key={m.id}>
                        {newDay ? (
                            <div className="igr:flex igr:justify-center igr:py-1.5">
                                <span className="igr:rounded-full igr:border igr:bg-card igr:px-2.5 igr:py-0.5 igr:text-[11px] igr:font-medium igr:text-muted-foreground igr:shadow-xs">
                                    {formatDate(m.at, workspace.locale, workspace.timezone)}
                                </span>
                            </div>
                        ) : null}
                        <div className={cn('igr:flex', m.direction === 'out' ? 'igr:justify-end' : 'igr:justify-start')}>
                            <div
                                className={cn(
                                    'igr:max-w-[85%] igr:rounded-2xl igr:px-3.5 igr:py-2 igr:text-[13px] igr:leading-5 igr:shadow-xs igr:sm:max-w-[75%]',
                                    m.direction === 'out' ? 'igr:rounded-br-md igr:bg-brand-soft igr:text-foreground' : 'igr:rounded-bl-md igr:border igr:bg-card',
                                    m.author?.kind === 'ai' && 'igr:ring-1 igr:ring-primary/20',
                                    m.status === 'failed' && 'igr:border igr:border-destructive/40',
                                )}
                            >
                                {m.author ? (
                                    <p className="igr:mb-0.5 igr:flex igr:items-center igr:gap-1 igr:text-[11px] igr:font-medium igr:text-muted-foreground">
                                        {m.author.kind === 'ai' ? <Bot className="igr:size-3 igr:text-primary" aria-hidden /> : null}
                                        {m.author.kind === 'phone' ? <Smartphone className="igr:size-3" aria-hidden /> : null}
                                        {m.author.kind === 'phone' ? t('inbox.chat.fromPhone') : m.author.kind === 'ai' ? t('inbox.chat.fromAi') : m.author.name}
                                    </p>
                                ) : null}
                                {m.type === 'revoked' ? <p className="igr:text-[11px] igr:italic igr:text-muted-foreground">{t('inbox.chat.revoked')}</p> : null}
                                {m.type === 'media_placeholder' ? <p className="igr:text-[11px] igr:italic igr:text-muted-foreground">{t('inbox.chat.historyMedia')}</p> : null}
                                {m.template ? <p className="igr:text-[11px] igr:text-muted-foreground">{t('inbox.chat.template', { name: m.template })}</p> : null}
                                {m.attachments.map((a, k) => (
                                    <p key={k} className="igr:text-[11px] igr:italic igr:text-muted-foreground">
                                        {t(`inbox.attachment.${a.kind}`, { defaultValue: a.kind })}
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
                                <p className="igr:mt-0.5 igr:flex igr:items-center igr:justify-end igr:gap-1 igr:text-[10px] igr:text-muted-foreground igr:tabular-nums">
                                    {m.edited ? <span>{t('inbox.chat.edited')}</span> : null}
                                    {time(m.at)}
                                    {m.direction === 'out' ? <DeliveryIcon m={m} /> : null}
                                </p>
                                {m.status === 'failed' && m.failed_reason ? <p className="igr:text-[11px] igr:text-destructive">{m.failed_reason}</p> : null}
                            </div>
                        </div>
                    </Fragment>
                );
            })}
            <div ref={bottom} />
        </div>
    );
}

function DeliveryIcon({ m }: { m: ChatMessage }) {
    const { t } = useTranslation();
    if (m.read_at) return <CheckCheck className="igr:size-3 igr:text-primary" role="img" aria-label={t('inbox.delivery.read')} />;
    if (m.delivered_at) return <CheckCheck className="igr:size-3" role="img" aria-label={t('inbox.delivery.delivered')} />;
    if (m.status === 'sent') return <Check className="igr:size-3" role="img" aria-label={t('inbox.delivery.sent')} />;
    return <Clock className="igr:size-3" role="img" aria-label={t('inbox.delivery.queued')} />;
}

function NotesPane({ c }: { c: ConversationDetail }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const actions = useConversationActions(workspace.id, c.id);
    const [body, setBody] = useState('');
    return (
        <div className="igr:flex igr:w-full igr:shrink-0 igr:flex-col igr:border-l igr:bg-warning/5 igr:max-md:absolute igr:max-md:inset-x-0 igr:max-md:bottom-0 igr:max-md:z-10 igr:max-md:h-1/2 igr:max-md:border-t igr:max-md:bg-card igr:md:w-64">
            <p className="igr:border-b igr:px-3 igr:py-2 igr:text-xs igr:font-semibold">{t('inbox.notes.title')}</p>
            <div className="igr:min-h-0 igr:flex-1 igr:space-y-2 igr:overflow-y-auto igr:p-3">
                {c.notes.length === 0 ? <p className="igr:text-xs igr:text-muted-foreground">{t('inbox.notes.empty')}</p> : null}
                {c.notes.map((n) => (
                    <div key={n.id} className="igr:rounded-md igr:border igr:bg-background igr:p-2 igr:text-xs">
                        <p className="igr:whitespace-pre-wrap">{n.body}</p>
                        <p className="igr:mt-1 igr:text-[10px] igr:text-muted-foreground">
                            {n.author ?? '—'} · {formatDate(n.at, workspace.locale, workspace.timezone)}
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
                    className="igr:h-16 igr:w-full igr:resize-none igr:rounded-md igr:border igr:border-input igr:bg-background igr:p-2 igr:text-xs"
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
