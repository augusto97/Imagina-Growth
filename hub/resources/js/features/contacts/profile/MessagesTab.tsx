import { ArrowDownLeft, ArrowUpRight, Camera, Eye, Mail, MessageCircle, MessageSquare, MessagesSquare, MousePointerClick, Phone, Reply } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState, ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatDateTime } from '@/lib/format';
import { type ContactMessage, useContactMessages } from '@/features/channels/api';

const CHANNEL_ICONS = { email: Mail, whatsapp: MessageCircle, sms: Phone, messenger: MessagesSquare, instagram: Camera } as const;

function tone(status: string): 'success' | 'warning' | 'destructive' | 'secondary' {
    if (status === 'read' || status === 'delivered' || status === 'received') return 'success';
    if (status === 'failed' || status === 'bounced') return 'destructive';
    if (status === 'deferred' || status === 'queued' || status === 'sending') return 'warning';
    return 'secondary';
}

/** Pestaña «Mensajes» del perfil 360: cada correo, WhatsApp y SMS con su estado (M07). */
export function MessagesTab({ contactId }: { contactId: string }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const [page, setPage] = useState(1);
    const messages = useContactMessages(workspace.id, contactId, page);
    const when = (iso: string | null) => formatDateTime(iso, workspace.locale, workspace.timezone);

    if (messages.isError) return <ErrorState error={messages.error} onRetry={() => void messages.refetch()} />;
    if (messages.isPending) return <Skeleton className="igr:h-40" />;
    if (messages.data.data.length === 0) return <EmptyState icon={MessageSquare} title={t('channels.messagesTab.empty')} description={t('channels.messagesTab.emptyHelp')} />;

    return (
        <div className="igr:space-y-2">
            <ul className="igr:space-y-2">
                {messages.data.data.map((m: ContactMessage) => {
                    const Icon = CHANNEL_ICONS[m.channel];
                    const Dir = m.direction === 'in' ? ArrowDownLeft : ArrowUpRight;
                    return (
                        <li key={m.id}>
                            <Card className="igr:flex igr:gap-3 igr:p-3">
                                <span className="igr:flex igr:size-8 igr:shrink-0 igr:items-center igr:justify-center igr:rounded-lg igr:bg-muted">
                                    <Icon className="igr:size-4 igr:text-muted-foreground" aria-hidden="true" />
                                </span>
                                <div className="igr:min-w-0 igr:flex-1">
                                    <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-1.5">
                                        <span className="igr:inline-flex igr:items-center igr:gap-1 igr:text-[13px] igr:font-medium">
                                            <Dir className="igr:size-3.5" aria-hidden="true" />
                                            {m.direction === 'in' ? t('channels.messagesTab.in') : (m.source_name ?? t(`channels.messagesTab.source.${m.source_type}`, { defaultValue: m.source_type }))}
                                        </span>
                                        <Badge variant={tone(m.status)}>{t(`channels.messagesTab.status.${m.status}`, { defaultValue: m.status })}</Badge>
                                        <span className="igr:text-xs igr:text-muted-foreground">{when(m.sent_at ?? m.created_at)}</span>
                                    </div>
                                    {m.preview && <p className="igr:mt-0.5 igr:truncate igr:text-xs igr:text-muted-foreground">{m.preview}</p>}
                                    {m.button_payload && <p className="igr:mt-0.5 igr:text-xs">{t('channels.messagesTab.button', { button: m.button_payload })}</p>}
                                    {m.failed_reason && <p className="igr:mt-0.5 igr:text-xs igr:text-destructive">{m.failed_reason}</p>}
                                    <div className="igr:mt-1 igr:flex igr:flex-wrap igr:gap-3 igr:text-[11px] igr:text-muted-foreground">
                                        {m.opened_at && (
                                            <span className="igr:inline-flex igr:items-center igr:gap-1">
                                                <Eye className="igr:size-3" aria-hidden="true" />
                                                {t('channels.messagesTab.opened')} {when(m.opened_at)}
                                            </span>
                                        )}
                                        {m.clicked_at && (
                                            <span className="igr:inline-flex igr:items-center igr:gap-1">
                                                <MousePointerClick className="igr:size-3" aria-hidden="true" />
                                                {t('channels.messagesTab.clicked')} {when(m.clicked_at)}
                                            </span>
                                        )}
                                        {m.replied_at && (
                                            <span className="igr:inline-flex igr:items-center igr:gap-1">
                                                <Reply className="igr:size-3" aria-hidden="true" />
                                                {t('channels.messagesTab.replied')} {when(m.replied_at)}
                                            </span>
                                        )}
                                        {m.cost_estimated && Number(m.cost_estimated) > 0 && <span>{t('channels.messagesTab.cost', { cost: new Intl.NumberFormat(workspace.locale, { style: 'currency', currency: 'USD', maximumFractionDigits: 4 }).format(Number(m.cost_estimated)) })}</span>}
                                    </div>
                                </div>
                            </Card>
                        </li>
                    );
                })}
            </ul>
            {messages.data.meta.last_page > 1 && (
                <div className="igr:flex igr:justify-end igr:gap-2">
                    <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>
                        {t('common.previous')}
                    </Button>
                    <Button variant="outline" size="sm" disabled={page >= messages.data.meta.last_page} onClick={() => setPage(page + 1)}>
                        {t('common.next')}
                    </Button>
                </div>
            )}
        </div>
    );
}
