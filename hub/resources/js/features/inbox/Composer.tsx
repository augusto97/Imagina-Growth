import { FileText, Package, Send, Sparkles, TicketPercent } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { toast } from '@/stores/toast';
import { errorMessage } from '@/features/contacts/errors';
import { useConversationActions, useQuickReplies } from './api';
import { applyQuickReply, composerMode, matchQuickReplies, newClientId, quickReplyQuery } from './lib';
import { CouponDialog, ProductDialog, TemplateDialog } from './SellDialogs';
import type { ConversationDetail, SendBody, SendResult } from './types';

type Dialog = 'template' | 'product' | 'coupon' | null;

/**
 * Editor: texto libre dentro de la ventana, `/atajo` para respuestas rápidas, plantilla (solo WhatsApp), producto,
 * carrito o enlace de pago, cupón y sugerencia de la IA. En Messenger, pasadas 24 h, solo una persona escribe (HUMAN_AGENT).
 */
export function Composer({ c }: { c: ConversationDetail }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const actions = useConversationActions(workspace.id, c.id);
    const replies = useQuickReplies(workspace.id);
    const [text, setText] = useState('');
    const [dialog, setDialog] = useState<Dialog>(null);
    const [highlight, setHighlight] = useState(0);
    const mode = composerMode(c);
    const open = mode === 'free';
    const canType = mode === 'free' || mode === 'human';
    const help = mode === 'human' ? 'humanAgentHelp' : mode === 'none' ? `closedHelp_${c.channel}` : mode === 'template' ? 'closedHelp' : null;
    const query = quickReplyQuery(text);
    const matches = query !== null ? matchQuickReplies(replies.data?.data ?? [], query) : [];

    const send = (body: Omit<SendBody, 'client_id'>, onDone?: () => void) => {
        actions.send.mutate(
            { ...body, client_id: newClientId() },
            {
                onSuccess: () => onDone?.(),
                onError: (e) => {
                    const body = isApiError(e) ? (e.body as SendResult | null) : null;
                    toast.error(body?.message ?? errorMessage(e, t));
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

    return (
        <div className="igr:border-t igr:bg-background igr:p-2.5">
            {help ? (
                <p className="igr:mb-2 igr:rounded-md igr:bg-warning/10 igr:px-2.5 igr:py-1.5 igr:text-xs igr:text-warning">
                    {t(`inbox.window.${help}`, { until: c.human_agent_until ? new Date(c.human_agent_until).toLocaleDateString(workspace.locale) : '' })}
                </p>
            ) : null}
            <div className="igr:relative">
                {matches.length > 0 ? (
                    <ul
                        role="listbox"
                        aria-label={t('inbox.composer.quickReplies')}
                        className="igr:absolute igr:bottom-full igr:left-0 igr:z-10 igr:mb-1 igr:w-72 igr:overflow-hidden igr:rounded-md igr:border igr:bg-popover igr:shadow-lg"
                    >
                        {matches.map((r, i) => (
                            <li key={r.id} role="option" aria-selected={i === highlight}>
                                <button
                                    type="button"
                                    className={`igr:block igr:w-full igr:px-3 igr:py-1.5 igr:text-left igr:text-xs ${i === highlight ? 'igr:bg-accent' : ''}`}
                                    onMouseDown={(e) => {
                                        e.preventDefault();
                                        setText(applyQuickReply(text, r));
                                    }}
                                >
                                    <span className="igr:font-medium">/{r.shortcut}</span> · {r.title}
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
                    className="igr:w-full igr:resize-none igr:rounded-md igr:border igr:bg-background igr:px-3 igr:py-2 igr:text-[13px] igr:outline-none igr:focus-visible:ring-2 igr:focus-visible:ring-ring igr:disabled:opacity-60"
                    placeholder={canType ? t('inbox.composer.placeholder') : t(mode === 'none' ? 'inbox.composer.placeholderNone' : 'inbox.composer.placeholderClosed')}
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
            </div>
            <div className="igr:mt-1.5 igr:flex igr:flex-wrap igr:items-center igr:gap-1">
                {c.channel === 'whatsapp' ? (
                    <Button size="sm" variant="ghost" onClick={() => setDialog('template')}>
                        <FileText aria-hidden />
                        {t('inbox.composer.template')}
                    </Button>
                ) : null}
                <Button size="sm" variant="ghost" disabled={!canType} onClick={() => setDialog('product')}>
                    <Package aria-hidden />
                    {t('inbox.composer.product')}
                </Button>
                <Button size="sm" variant="ghost" disabled={!canType} onClick={() => setDialog('coupon')}>
                    <TicketPercent aria-hidden />
                    {t('inbox.composer.coupon')}
                </Button>
                <Button size="sm" variant="ghost" disabled={!open} loading={actions.suggest.isPending} onClick={suggest}>
                    <Sparkles aria-hidden />
                    {t('inbox.composer.suggest')}
                </Button>
                <span className="igr:flex-1" />
                <span className="igr:hidden igr:text-[11px] igr:text-muted-foreground igr:2xl:inline">{t('inbox.composer.hint')}</span>
                <Button size="sm" disabled={!canType || text.trim() === ''} loading={actions.send.isPending} onClick={sendText}>
                    <Send aria-hidden />
                    {t('inbox.composer.send')}
                </Button>
            </div>
            <TemplateDialog
                open={dialog === 'template'}
                onOpenChange={(v) => setDialog(v ? 'template' : null)}
                onSend={(b) => send(b, () => setDialog(null))}
                busy={actions.send.isPending}
            />
            <ProductDialog
                open={dialog === 'product'}
                onOpenChange={(v) => setDialog(v ? 'product' : null)}
                onSend={(b) => send(b, () => setDialog(null))}
                busy={actions.send.isPending}
            />
            <CouponDialog
                open={dialog === 'coupon'}
                onOpenChange={(v) => setDialog(v ? 'coupon' : null)}
                onSend={(b) => send(b, () => setDialog(null))}
                busy={actions.send.isPending}
            />
        </div>
    );
}
