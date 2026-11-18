import { Download, FileDown, ShieldCheck, ShieldOff, Trash2 } from 'lucide-react';
import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ErrorState, FieldError } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatDate, formatDateTime } from '@/lib/format';
import { toast } from '@/stores/toast';
import { downloadUrl } from '@/features/privacy/api';
import { useContactAbilities } from '../abilities';
import { type ContactConsentRow, useContactPrivacy, useRecordConsent } from '../api';
import { errorMessage } from '../errors';
import { CONSENT_CHANNELS, consentSource } from '../labels';

type Pending = { channel: string; action: 'granted' | 'revoked' };

export function PrivacyTab({ contactId, onExport, onDelete }: { contactId: string; onExport: () => void; onDelete: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const can = useContactAbilities();
    const privacy = useContactPrivacy(workspace.id, contactId);
    const [pending, setPending] = useState<Pending | null>(null);
    const sourceText = (source: string | null | undefined) => {
        const label = consentSource(source);
        return label ? t(label.key, label.values) : '—';
    };

    if (privacy.isError) {
        return <ErrorState error={privacy.error} onRetry={() => void privacy.refetch()} />;
    }
    if (privacy.isPending) {
        return <Skeleton className="igr:h-60" />;
    }
    const { consents, evidence, requests } = privacy.data;
    const marketing = new Map(consents.filter((c) => c.purpose === 'marketing').map((c) => [c.channel, c]));
    const channels = [...CONSENT_CHANNELS, ...[...marketing.keys()].filter((c) => !(CONSENT_CHANNELS as readonly string[]).includes(c))];

    return (
        <div className="igr:space-y-4">
            <Card>
                <CardHeader>
                    <CardTitle>{t('contacts.privacy.consents')}</CardTitle>
                    <CardDescription>{t('contacts.privacy.consentsHelp')}</CardDescription>
                </CardHeader>
                <Table>
                    <THead>
                        <TR className="igr:hover:bg-transparent">
                            <TH>{t('contacts.privacy.channel')}</TH>
                            <TH>{t('contacts.privacy.status')}</TH>
                            <TH className="igr:hidden igr:md:table-cell">{t('contacts.privacy.source')}</TH>
                            <TH className="igr:hidden igr:md:table-cell">{t('contacts.privacy.version')}</TH>
                            <TH>{t('contacts.privacy.date')}</TH>
                            <TH className="igr:text-right">
                                <span className="igr:sr-only">{t('contacts.privacy.actions')}</span>
                            </TH>
                        </TR>
                    </THead>
                    <TBody>
                        {channels.map((channel) => {
                            const c: ContactConsentRow | undefined = marketing.get(channel);
                            const granted = c?.status === 'granted';
                            return (
                                <TR key={channel}>
                                    <TD className="igr:font-medium">{t(`contacts.consentChannels.${channel}`, { defaultValue: channel })}</TD>
                                    <TD>
                                        {granted ? (
                                            <Badge variant="success">
                                                <ShieldCheck className="igr:size-3" />
                                                {t('contacts.consent.status.granted')}
                                            </Badge>
                                        ) : c ? (
                                            <Badge variant="outline">
                                                <ShieldOff className="igr:size-3" />
                                                {t('contacts.consent.status.revoked')}
                                            </Badge>
                                        ) : (
                                            <span className="igr:text-muted-foreground">{t('contacts.consent.status.none')}</span>
                                        )}
                                    </TD>
                                    <TD className="igr:hidden igr:text-muted-foreground igr:md:table-cell">{sourceText(c?.source)}</TD>
                                    <TD className="igr:hidden igr:font-mono igr:text-xs igr:text-muted-foreground igr:md:table-cell">{c?.version ?? '—'}</TD>
                                    <TD className="igr:text-muted-foreground">{formatDate(granted ? c.granted_at : (c?.revoked_at ?? null), workspace.locale)}</TD>
                                    <TD className="igr:text-right">
                                        {granted && can.edit && (
                                            <Button variant="outline" size="sm" onClick={() => setPending({ channel, action: 'revoked' })}>
                                                {t('contacts.privacy.revoke')}
                                            </Button>
                                        )}
                                        {!granted && can.manage && (
                                            <Button variant="ghost" size="sm" onClick={() => setPending({ channel, action: 'granted' })}>
                                                {t('contacts.privacy.grant')}
                                            </Button>
                                        )}
                                    </TD>
                                </TR>
                            );
                        })}
                    </TBody>
                </Table>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{t('contacts.privacy.evidence')}</CardTitle>
                    <CardDescription>{t('contacts.privacy.evidenceHelp')}</CardDescription>
                </CardHeader>
                <CardContent>
                    {evidence.length === 0 ? (
                        <p className="igr:text-xs igr:text-muted-foreground">{t('contacts.privacy.noEvidence')}</p>
                    ) : (
                        <ol className="igr:space-y-2.5">
                            {evidence.map((e, i) => (
                                <li key={i} className="igr:flex igr:gap-3 igr:text-[13px]">
                                    <Badge variant={e.action === 'granted' ? 'success' : 'outline'} className="igr:mt-0.5 igr:shrink-0">
                                        {e.action === 'granted' ? t('contacts.consent.status.granted') : t('contacts.consent.status.revoked')}
                                    </Badge>
                                    <div className="igr:min-w-0">
                                        <p>
                                            <span className="igr:font-medium">{t(`contacts.consentChannels.${e.channel}`, { defaultValue: e.channel })}</span>
                                            <span className="igr:text-muted-foreground">
                                                {' · '}
                                                {sourceText(e.source)}
                                                {e.version && ` · ${t('contacts.privacy.textVersion', { version: e.version })}`}
                                            </span>
                                        </p>
                                        <p className="igr:truncate igr:text-xs igr:text-muted-foreground">
                                            {formatDateTime(e.occurred_at, workspace.locale, workspace.timezone)}
                                            {e.evidence.page_url && ` · ${e.evidence.page_url}`}
                                            {e.evidence.order_id && ` · ${t('contacts.timeline.order', { number: e.evidence.order_id })}`}
                                            {e.evidence.event && ` · ${e.evidence.event}`}
                                        </p>
                                        {e.evidence.note && <p className="igr:mt-0.5 igr:text-xs igr:italic igr:text-muted-foreground">«{e.evidence.note}»</p>}
                                    </div>
                                </li>
                            ))}
                        </ol>
                    )}
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{t('contacts.privacy.requests')}</CardTitle>
                    <CardDescription>{t('contacts.privacy.requestsHelp')}</CardDescription>
                </CardHeader>
                <CardContent className="igr:space-y-3">
                    {requests.length > 0 && (
                        <ul className="igr:divide-y igr:rounded-md igr:border">
                            {requests.map((r) => (
                                <li key={r.id} className="igr:flex igr:items-center igr:justify-between igr:gap-2 igr:px-3 igr:py-2 igr:text-[13px]">
                                    <span>
                                        {r.kind === 'delete' ? t('privacy.kind.delete') : t('privacy.kind.access')}{' '}
                                        <Badge variant={r.status === 'completed' ? 'success' : 'warning'}>
                                            {r.status === 'completed' ? t('privacy.status.completed') : t('privacy.status.pending')}
                                        </Badge>
                                    </span>
                                    {r.kind === 'access' && r.status === 'completed' && can.export ? (
                                        <Button variant="ghost" size="sm" asChild>
                                            <a href={downloadUrl(workspace.id, r.id)} download>
                                                <Download />
                                                {t('privacy.requests.download')}
                                            </a>
                                        </Button>
                                    ) : (
                                        <span className="igr:text-xs igr:text-muted-foreground">
                                            {r.status === 'completed' ? '' : t('privacy.requests.dueOn', { date: formatDate(r.due_at, workspace.locale) })}
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                    <div className="igr:flex igr:flex-wrap igr:gap-2">
                        {can.export && (
                            <Button variant="outline" onClick={onExport}>
                                <FileDown />
                                {t('contacts.actions.export')}
                            </Button>
                        )}
                        {can.remove && (
                            <Button variant="outline" className="igr:text-destructive" onClick={onDelete}>
                                <Trash2 />
                                {t('contacts.actions.delete')}
                            </Button>
                        )}
                        {!can.export && !can.remove && <p className="igr:text-xs igr:text-muted-foreground">{t('contacts.privacy.askAdmin')}</p>}
                    </div>
                </CardContent>
            </Card>

            <ConsentDialog contactId={contactId} pending={pending} onClose={() => setPending(null)} />
        </div>
    );
}

/** Revocar u otorgar a mano exige una nota: queda como evidencia (cumplimiento.md §3). */
function ConsentDialog({ contactId, pending, onClose }: { contactId: string; pending: Pending | null; onClose: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const record = useRecordConsent(workspace.id, contactId);
    const [note, setNote] = useState('');
    const noteId = useId();
    const channel = pending ? t(`contacts.consentChannels.${pending.channel}`, { defaultValue: pending.channel }) : '';
    const granting = pending?.action === 'granted';

    const close = () => {
        setNote('');
        record.reset();
        onClose();
    };

    return (
        <Dialog open={pending !== null} onOpenChange={(open) => !open && close()}>
            <DialogContent>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        if (!pending || note.trim().length < 5) {
                            return;
                        }
                        record.mutate(
                            { channel: pending.channel, action: pending.action, note: note.trim() },
                            {
                                onSuccess: () => {
                                    toast.success(granting ? t('contacts.privacy.grantedToast', { channel }) : t('contacts.privacy.revokedToast', { channel }));
                                    close();
                                },
                            },
                        );
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>{granting ? t('contacts.privacy.grantTitle', { channel }) : t('contacts.privacy.revokeTitle', { channel })}</DialogTitle>
                        <DialogDescription>{granting ? t('contacts.privacy.grantDescription') : t('contacts.privacy.revokeDescription')}</DialogDescription>
                    </DialogHeader>
                    <div className="igr:space-y-1.5">
                        <Label htmlFor={noteId}>{t('contacts.privacy.note')}</Label>
                        <Textarea
                            id={noteId}
                            required
                            minLength={5}
                            maxLength={500}
                            placeholder={granting ? t('contacts.privacy.grantPlaceholder') : t('contacts.privacy.revokePlaceholder')}
                            value={note}
                            onChange={(e) => setNote(e.target.value)}
                        />
                        <p className="igr:text-xs igr:text-muted-foreground">{t('contacts.privacy.noteHelp')}</p>
                        <FieldError message={record.isError ? errorMessage(record.error, t) : undefined} />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={close}>
                            {t('common.cancel')}
                        </Button>
                        <Button type="submit" variant={granting ? 'default' : 'destructive'} disabled={note.trim().length < 5} loading={record.isPending}>
                            {granting ? t('contacts.privacy.grant') : t('contacts.privacy.revoke')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
