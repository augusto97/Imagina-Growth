import { FileText, Package, Send, Sparkles, TicketPercent, Zap } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { toast } from '@/stores/toast';
import { errorMessage } from '@/features/contacts/errors';
import { useConversationActions, useInboxTemplates, useQuickReplies } from './api';
import { applyQuickReply, composerMode, draftFor, keepDraft, matchQuickReplies, newClientId, quickReplyQuery, sellsOnline } from './lib';
import { CouponDialog, ProductDialog, TemplateDialog } from './SellDialogs';
import type { ConversationDetail, SendBody, SendResult } from './types';

type Dialog = 'template' | 'product' | 'coupon' | null;

/**
 * Editor: texto libre dentro de la ventana, respuestas rápidas (botón o `/atajo`), plantilla aprobada (WhatsApp: fuera de
 * la ventana es lo único que se puede enviar, y se ofrecen a la vista), sugerencia de la IA y, si vende en línea,
 * producto, carrito o enlace de pago y cupón. En Messenger, pasadas 24 h, solo una persona escribe (HUMAN_AGENT).
 */
export function Composer({ c }: { c: ConversationDetail }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageInbox');
    const actions = useConversationActions(workspace.id, c.id);
    const replies = useQuickReplies(workspace.id);
    const [text, setTextState] = useState(() => draftFor(c.id));
    const setText = (value: string) => {
        setTextState(value);
        keepDraft(c.id, value);
    };
    const [dialog, setDialog] = useState<Dialog>(null);
    const [templateId, setTemplateId] = useState<string | null>(null);
    const [highlight, setHighlight] = useState(0);
    const mode = composerMode(c);
    const open = mode === 'free';
    const canType = mode === 'free' || mode === 'human';
    const store = sellsOnline(workspace);
    // Fuera de la ventana de WhatsApp lo único que se puede enviar es una plantilla: se muestran sin abrir nada.
    const templates = useInboxTemplates(workspace.id, mode === 'template');
    const help = mode === 'human' ? 'humanAgentHelp' : mode === 'none' ? `closedHelp_${c.channel}` : null;
    const query = quickReplyQuery(text);
    const matches = query !== null ? matchQuickReplies(replies.data?.data ?? [], query) : [];
    const name = c.contact?.name ?? t('inbox.unknownContact');

    const send = (body: Omit<SendBody, 'client_id'>, onDone?: () => void) => {
        actions.send.mutate(
            { ...body, client_id: newClientId() },
            {
                onSuccess: () => onDone?.(),
                onError: (e) => {
                    const result = isApiError(e) ? (e.body as SendResult | null) : null;
                    toast.error(result?.message ?? errorMessage(e, t));
                },
            },
        );
    };
    const sendText = () => {
        if (text.trim() === '') return;
        send({ type: 'text', text: text.trim() }, () => setText(''));
    };
    const suggest = () =>
        actions.suggest.mutate(undefined, {
            onSuccess: (r) => (r.suggestion ? setText(r.suggestion) : toast.info(t('inbox.composer.noSuggestion'))),
            onError: (e) => toast.error(errorMessage(e, t)),
        });
    const pickTemplate = (id: string | null) => {
        setTemplateId(id);
        setDialog('template');
    };

    if (mode === 'template') {
        const list = templates.data?.data ?? [];
        return (
            <div className="igr:border-t igr:bg-card igr:p-3 igr:md:px-4">
                <div className="igr:rounded-xl igr:border igr:border-warning/30 igr:bg-warning/6 igr:p-3">
                    <p className="igr:text-support igr:font-medium igr:text-foreground">{t('inbox.window.closedTitle', { name })}</p>
                    <p className="igr:mt-0.5 igr:text-support igr:text-muted-foreground">{t('inbox.window.closedHelp')}</p>
                    <div className="igr:mt-3 igr:flex igr:flex-wrap igr:items-center igr:gap-2">
                        {templates.isPending ? <span className="igr:text-xs igr:text-muted-foreground">{t('inbox.template.loading')}</span> : null}
                        {list.slice(0, 3).map((tpl) => (
                            <Button key={tpl.id} size="sm" variant="outline" onClick={() => pickTemplate(tpl.id)}>
                                <FileText aria-hidden />
                                {tpl.name}
                            </Button>
                        ))}
                        {list.length > 3 ? (
                            <Button size="sm" variant="ghost" onClick={() => pickTemplate(null)}>
                                {t('inbox.template.seeAll', { count: list.length })}
                            </Button>
                        ) : null}
                        {!templates.isPending && list.length === 0 ? (
                            <p className="igr:text-xs igr:text-muted-foreground">
                                {t('inbox.template.empty')}{' '}
                                {canCreateTemplates(workspace) ? (
                                    <Link to={`/w/${workspace.id}/canales/plantillas-whatsapp`} className="igr:font-medium igr:text-primary igr:hover:underline">
                                        {t('inbox.template.create')}
                                    </Link>
                                ) : null}
                            </p>
                        ) : null}
                    </div>
                </div>
                <TemplateDialog
                    key={templateId ?? 'todas'}
                    initialId={templateId}
                    open={dialog === 'template'}
                    onOpenChange={(v) => setDialog(v ? 'template' : null)}
                    onSend={(b) => send(b, () => setDialog(null))}
                    busy={actions.send.isPending}
                />
            </div>
        );
    }

    return (
        <div className="igr:border-t igr:bg-card igr:p-3 igr:md:px-4">
            {help ? (
                <p className="igr:mb-2 igr:rounded-lg igr:bg-warning/10 igr:px-3 igr:py-2 igr:text-xs igr:text-warning">
                    {t(`inbox.window.${help}`, { until: c.human_agent_until ? formatDate(c.human_agent_until, workspace.locale, workspace.timezone) : '' })}
                </p>
            ) : null}
            <div className="igr:relative igr:rounded-xl igr:border igr:border-input igr:bg-background igr:transition-shadow igr:focus-within:ring-2 igr:focus-within:ring-ring/60">
                {matches.length > 0 ? (
                    <ul
                        role="listbox"
                        aria-label={t('inbox.composer.quickReplies')}
                        className="igr:absolute igr:bottom-full igr:left-0 igr:z-10 igr:mb-1.5 igr:w-80 igr:max-w-full igr:overflow-hidden igr:rounded-lg igr:border igr:bg-popover igr:shadow-lg"
                    >
                        {matches.map((r, i) => (
                            <li key={r.id} role="option" aria-selected={i === highlight}>
                                <button
                                    type="button"
                                    className={`igr:block igr:w-full igr:px-3 igr:py-2 igr:text-left igr:text-xs ${i === highlight ? 'igr:bg-accent' : ''}`}
                                    onMouseDown={(e) => {
                                        e.preventDefault();
                                        setText(applyQuickReply(text, r));
                                    }}
                                >
                                    <span className="igr:font-medium">/{r.shortcut}</span> · {r.title}
                                    <span className="igr:mt-0.5 igr:block igr:truncate igr:text-muted-foreground">{r.body}</span>
                                </button>
                            </li>
                        ))}
                    </ul>
                ) : null}
                <label htmlFor="inbox-composer" className="igr:sr-only">
                    {t('inbox.composer.label')}
                </label>
                <textarea
                    id="inbox-composer"
                    rows={2}
                    disabled={!canType}
                    className="igr:block igr:w-full igr:resize-none igr:rounded-t-xl igr:bg-transparent igr:px-3 igr:pt-2.5 igr:pb-1 igr:text-[13px] igr:outline-none igr:disabled:opacity-60"
                    placeholder={canType ? t('inbox.composer.placeholder') : t('inbox.composer.placeholderNone')}
                    value={text}
                    onChange={(e) => {
                        setText(e.target.value);
                        setHighlight(0);
                    }}
                    onKeyDown={(e) => {
                        if (matches.length > 0 && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
                            e.preventDefault();
                            setHighlight((h) => (h + (e.key === 'ArrowDown' ? 1 : matches.length - 1)) % matches.length);
                        } else if (matches.length > 0 && (e.key === 'Enter' || e.key === 'Tab')) {
                            e.preventDefault();
                            const pick = matches[highlight];
                            if (pick) setText(applyQuickReply(text, pick));
                        } else if (e.key === 'Enter' && !e.shiftKey) {
                            e.preventDefault();
                            sendText();
                        }
                    }}
                />
                <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-0.5 igr:px-1.5 igr:pb-1.5">
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button size="sm" variant="ghost" disabled={!canType}>
                                <Zap aria-hidden />
                                <span className="igr:max-sm:sr-only">{t('inbox.composer.quickReplies')}</span>
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="start" className="igr:w-80 igr:max-w-[calc(100vw-2rem)]">
                            <DropdownMenuLabel>{t('inbox.composer.quickRepliesHint')}</DropdownMenuLabel>
                            {(replies.data?.data ?? []).length === 0 ? (
                                <p className="igr:px-2 igr:py-1.5 igr:text-xs igr:text-muted-foreground">{t('inbox.composer.noQuickReplies')}</p>
                            ) : (
                                (replies.data?.data ?? []).slice(0, 12).map((r) => (
                                    <DropdownMenuItem key={r.id} onSelect={() => setText(text.trim() === '' ? r.body : `${text.trimEnd()} ${r.body}`)} className="igr:flex-col igr:items-start igr:gap-0.5">
                                        <span className="igr:text-xs igr:font-medium">
                                            /{r.shortcut} · {r.title}
                                        </span>
                                        <span className="igr:line-clamp-2 igr:text-xs igr:text-muted-foreground">{r.body}</span>
                                    </DropdownMenuItem>
                                ))
                            )}
                            {canManage ? (
                                <>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem asChild>
                                        <Link to={`/w/${workspace.id}/bandeja?tab=ajustes`}>{t('inbox.composer.manageQuickReplies')}</Link>
                                    </DropdownMenuItem>
                                </>
                            ) : null}
                        </DropdownMenuContent>
                    </DropdownMenu>
                    {c.channel === 'whatsapp' ? (
                        <Button size="sm" variant="ghost" onClick={() => pickTemplate(null)}>
                            <FileText aria-hidden />
                            <span className="igr:max-sm:sr-only">{t('inbox.composer.template')}</span>
                        </Button>
                    ) : null}
                    {store ? (
                        <>
                            <Button size="sm" variant="ghost" disabled={!canType} onClick={() => setDialog('product')}>
                                <Package aria-hidden />
                                <span className="igr:max-sm:sr-only">{t('inbox.composer.product')}</span>
                            </Button>
                            <Button size="sm" variant="ghost" disabled={!canType} onClick={() => setDialog('coupon')}>
                                <TicketPercent aria-hidden />
                                <span className="igr:max-sm:sr-only">{t('inbox.composer.coupon')}</span>
                            </Button>
                        </>
                    ) : null}
                    <Button size="sm" variant="ghost" disabled={!open} loading={actions.suggest.isPending} onClick={suggest}>
                        <Sparkles aria-hidden className="igr:text-primary" />
                        <span className="igr:max-sm:sr-only">{t('inbox.composer.suggest')}</span>
                    </Button>
                    <span className="igr:flex-1" />
                    <span className="igr:hidden igr:text-[11px] igr:text-muted-foreground igr:2xl:inline">{t('inbox.composer.hint')}</span>
                    <Button size="sm" disabled={!canType || text.trim() === ''} loading={actions.send.isPending} onClick={sendText}>
                        <Send aria-hidden />
                        {t('inbox.composer.send')}
                    </Button>
                </div>
            </div>
            <TemplateDialog
                key={templateId ?? 'todas'}
                initialId={templateId}
                open={dialog === 'template'}
                onOpenChange={(v) => setDialog(v ? 'template' : null)}
                onSend={(b) => send(b, () => setDialog(null))}
                busy={actions.send.isPending}
            />
            {store ? (
                <>
                    <ProductDialog open={dialog === 'product'} onOpenChange={(v) => setDialog(v ? 'product' : null)} onSend={(b) => send(b, () => setDialog(null))} busy={actions.send.isPending} />
                    <CouponDialog open={dialog === 'coupon'} onOpenChange={(v) => setDialog(v ? 'coupon' : null)} onSend={(b) => send(b, () => setDialog(null))} busy={actions.send.isPending} />
                </>
            ) : null}
        </div>
    );
}

/** Quien hace mensajes puede crear plantillas de WhatsApp (Mensajes › Plantillas). */
function canCreateTemplates(workspace: { abilities: readonly string[] }): boolean {
    return workspace.abilities.includes('manageMarketing');
}
