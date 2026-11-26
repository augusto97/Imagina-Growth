import { Archive, ArrowRight, MessageCircle, MoreHorizontal, Pencil, Plus, QrCode, ScanLine, Target } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Callout } from '@/components/ui/callout';
import { Card } from '@/components/ui/card';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { CopyButton } from '@/components/ui/copy-field';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { EmptyState } from '@/components/ui/empty-state';
import { Input, Select, Textarea } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ErrorState, FieldError } from '@/components/ui/misc';
import { PageHeader } from '@/components/ui/page-header';
import { Sheet, SheetBody, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { StatCard, StatCardSkeleton, StatGrid } from '@/components/ui/stat-card';
import { errorMessage } from '@/features/contacts/errors';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { formatNumber } from '@/lib/format';
import { toast } from '@/stores/toast';
import { useCaptureOptions, useWhatsAppLinkMutations, useWhatsAppLinks } from './api';
import { waMessage } from './model';
import { QrShare } from './QrShare';
import { Warnings } from './shared';
import type { WhatsAppLink, WhatsAppLinkInput } from './types';

/**
 * Chats de WhatsApp con QR (modulos.md M12 «Chats de WhatsApp con atribución»): un QR para el mostrador o un enlace con
 * el mensaje de alta y su código. Cada escaneo cuenta, el chat llega a la bandeja con su origen, se crea el lead y se le
 * pregunta a la persona si quiere recibir promociones.
 */
export function WhatsAppLinksPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageMarketing');
    const links = useWhatsAppLinks(workspace.id, 'qr');
    const options = useCaptureOptions(workspace.id);
    const [editing, setEditing] = useState<WhatsAppLink | 'new' | null>(null);
    const [sharing, setSharing] = useState<WhatsAppLink | null>(null);
    const rows = links.data?.data ?? [];
    const noNumber = options.data !== undefined && options.data.whatsapp_number === null && options.data.whatsapp_accounts.length === 0;

    return (
        <>
            <PageHeader
                title={t('capture.wa.title')}
                description={t('capture.wa.description')}
                actions={
                    canManage && rows.length > 0 ? (
                        <Button onClick={() => setEditing('new')}>
                            <Plus aria-hidden />
                            {t('capture.wa.new')}
                        </Button>
                    ) : null
                }
            />
            {noNumber ? (
                <Callout
                    tone="warning"
                    className="igr:mb-6"
                    title={t('capture.wa.noNumberTitle')}
                    action={
                        <Button asChild size="sm" variant="outline">
                            <Link to={`/w/${workspace.id}/conexiones`}>
                                {t('capture.wa.connect')}
                                <ArrowRight aria-hidden />
                            </Link>
                        </Button>
                    }
                >
                    {t('capture.wa.noNumberBody')}
                </Callout>
            ) : null}
            {links.isError ? (
                <ErrorState error={links.error} onRetry={() => void links.refetch()} />
            ) : links.isPending ? (
                <>
                    <StatGrid className="igr:mb-6 igr:lg:grid-cols-3">
                        {[0, 1, 2].map((i) => (
                            <StatCardSkeleton key={i} />
                        ))}
                    </StatGrid>
                    <div className="igr:grid igr:gap-4 igr:md:grid-cols-2">
                        {[0, 1].map((i) => (
                            <Skeleton key={i} className="igr:h-56 igr:rounded-xl" />
                        ))}
                    </div>
                </>
            ) : rows.length === 0 ? (
                <EmptyState
                    illustration="conversations"
                    title={t('capture.wa.empty.title')}
                    description={t('capture.wa.empty.description')}
                    action={
                        canManage ? (
                            <Button size="lg" onClick={() => setEditing('new')}>
                                <QrCode aria-hidden />
                                {t('capture.wa.empty.action')}
                            </Button>
                        ) : undefined
                    }
                    steps={[t('capture.wa.empty.step1'), t('capture.wa.empty.step2'), t('capture.wa.empty.step3')]}
                />
            ) : (
                <>
                    <Totals links={rows} />
                    <div className="igr:grid igr:gap-4 igr:md:grid-cols-2">
                        {rows.map((link) => (
                            <LinkCard key={link.id} link={link} canManage={canManage} onEdit={() => setEditing(link)} onShare={() => setSharing(link)} />
                        ))}
                    </div>
                </>
            )}
            {editing !== null ? <LinkSheet link={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onCreated={(link) => setSharing(link)} /> : null}
            {sharing !== null ? (
                <Dialog open onOpenChange={(open) => !open && setSharing(null)}>
                    <DialogContent className="igr:max-w-2xl">
                        <DialogHeader>
                            <DialogTitle>{t('capture.wa.shareTitle', { name: sharing.name })}</DialogTitle>
                            <DialogDescription>{t('capture.wa.shareBody')}</DialogDescription>
                        </DialogHeader>
                        <QrShare url={sharing.url} qr={sharing.qr} name={sharing.name} linkLabel={t('capture.wa.link')} />
                    </DialogContent>
                </Dialog>
            ) : null}
        </>
    );
}

function Totals({ links }: { links: WhatsAppLink[] }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const sum = links.reduce((acc, l) => ({ scans: acc.scans + (l.stats?.scans ?? 0), chats: acc.chats + (l.stats?.chats ?? 0), leads: acc.leads + (l.stats?.leads ?? 0) }), { scans: 0, chats: 0, leads: 0 });
    const n = (v: number) => formatNumber(v, workspace.locale);
    return (
        <StatGrid className="igr:mb-6 igr:lg:grid-cols-3">
            <StatCard label={t('capture.wa.totals.scans')} value={n(sum.scans)} hint={t('capture.wa.totals.window')} help={t('capture.wa.totals.scansHelp')} icon={ScanLine} tone="sky" />
            <StatCard label={t('capture.wa.totals.chats')} value={n(sum.chats)} hint={t('capture.wa.totals.window')} help={t('capture.wa.totals.chatsHelp')} icon={MessageCircle} tone="emerald" />
            <StatCard label={t('capture.wa.totals.leads')} value={n(sum.leads)} hint={t('capture.wa.totals.window')} icon={Target} tone="rose" />
        </StatGrid>
    );
}

function LinkCard({ link, canManage, onEdit, onShare }: { link: WhatsAppLink; canManage: boolean; onEdit: () => void; onShare: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const { archive } = useWhatsAppLinkMutations(workspace.id);
    const [confirm, setConfirm] = useState(false);
    const n = (v: number | undefined) => formatNumber(v ?? 0, workspace.locale);
    return (
        <Card className="igr:flex igr:min-w-0 igr:flex-col">
            <div className="igr:flex igr:items-start igr:gap-3 igr:p-5 igr:pb-3">
                <div className="igr:flex igr:size-10 igr:shrink-0 igr:items-center igr:justify-center igr:rounded-lg igr:bg-success/12 igr:text-success">
                    <QrCode className="igr:size-5" aria-hidden />
                </div>
                <div className="igr:min-w-0 igr:flex-1">
                    <h2 className="igr:truncate igr:text-[15px] igr:font-semibold igr:tracking-tight">{link.name}</h2>
                    <p className="igr:mt-0.5 igr:text-caption igr:text-muted-foreground">
                        {link.number ? t('capture.wa.opens', { number: link.number }) : t('capture.wa.noNumberShort')}
                    </p>
                </div>
                <Badge variant="outline" className="igr:font-mono" title={t('capture.wa.codeHelp')}>
                    {link.code}
                </Badge>
                {canManage ? (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="ghost" size="icon" className="igr:-mt-1 igr:-mr-2" aria-label={t('capture.wa.more', { name: link.name })}>
                                <MoreHorizontal aria-hidden />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem onSelect={onEdit}>
                                <Pencil aria-hidden />
                                {t('capture.wa.edit')}
                            </DropdownMenuItem>
                            <DropdownMenuItem className="igr:text-destructive" onSelect={() => setConfirm(true)}>
                                <Archive aria-hidden />
                                {t('capture.wa.archive')}
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                ) : null}
            </div>
            <div className="igr:px-5">
                <ChatBubble text={waMessage(link.message, link.code)} />
            </div>
            <dl className="igr:mt-4 igr:grid igr:grid-cols-3 igr:gap-2 igr:px-5 igr:pb-4">
                {(
                    [
                        ['scans', link.stats?.scans],
                        ['chats', link.stats?.chats],
                        ['leads', link.stats?.leads],
                    ] as const
                ).map(([key, value]) => (
                    <div key={key} className="igr:min-w-0 igr:rounded-lg igr:bg-muted/50 igr:px-3 igr:py-2">
                        <dt className="igr:truncate igr:text-caption igr:text-muted-foreground">{t(`capture.wa.card.${key}`)}</dt>
                        <dd className="igr:text-[15px] igr:font-semibold igr:tabular-nums">{n(value)}</dd>
                    </div>
                ))}
            </dl>
            {link.warnings.length > 0 ? <Warnings warnings={link.warnings} title={t('capture.wa.warningsTitle')} className="igr:mx-5 igr:mb-4" /> : null}
            <div className="igr:mt-auto igr:flex igr:flex-wrap igr:items-center igr:gap-2 igr:border-t igr:px-5 igr:py-3">
                <Button size="sm" onClick={onShare}>
                    <QrCode aria-hidden />
                    {t('capture.wa.showQr')}
                </Button>
                <CopyButton value={link.url} size="sm" variant="ghost">
                    {t('capture.wa.copyLink')}
                </CopyButton>
            </div>
            <ConfirmDialog
                open={confirm}
                onOpenChange={setConfirm}
                title={t('capture.wa.archiveTitle', { name: link.name })}
                description={t('capture.wa.archiveBody')}
                confirmLabel={t('capture.wa.archive')}
                tone="danger"
                loading={archive.isPending}
                onConfirm={() =>
                    archive.mutate(link.id, {
                        onSuccess: () => {
                            setConfirm(false);
                            toast.info(t('capture.wa.archived'));
                        },
                        onError: (e) => toast.error(errorMessage(e, t)),
                    })
                }
            />
        </Card>
    );
}

/** El mensaje como lo verá la persona en su WhatsApp al escanear (con el código de atribución al final). */
export function ChatBubble({ text }: { text: string }) {
    return (
        <div className="igr:rounded-lg igr:bg-muted/60 igr:p-3">
            <p className="igr:ml-auto igr:w-fit igr:max-w-[90%] igr:rounded-lg igr:rounded-tr-sm igr:bg-success/15 igr:px-3 igr:py-2 igr:text-[13px] igr:break-words igr:text-foreground">{text}</p>
        </div>
    );
}

function LinkSheet({ link, onClose, onCreated }: { link: WhatsAppLink | null; onClose: () => void; onCreated: (link: WhatsAppLink) => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const options = useCaptureOptions(workspace.id);
    const { create, update } = useWhatsAppLinkMutations(workspace.id);
    const leadsModule = workspace.modules?.includes('services') ?? true;
    const [form, setForm] = useState<WhatsAppLinkInput>(() => ({
        name: link?.name ?? '',
        message: link?.message ?? t('capture.wa.form.messageDefault'),
        channel_account_id: link?.channel_account_id ?? null,
        settings: { create_lead: link?.settings.create_lead ?? true, ask_consent: link?.settings.ask_consent ?? true, service: link?.settings.service ?? null },
    }));
    const accounts = options.data?.whatsapp_accounts ?? [];
    const mutation = link ? update : create;
    const settings = form.settings ?? {};
    const submit = () => {
        const data: WhatsAppLinkInput = { ...form, name: form.name.trim(), message: form.message.trim(), settings: { ...settings, service: settings.service?.trim() || null } };
        if (link) {
            update.mutate(
                { id: link.id, data },
                {
                    onSuccess: () => {
                        toast.success(t('capture.wa.saved'));
                        onClose();
                    },
                },
            );
        } else {
            create.mutate(data, {
                onSuccess: (created) => {
                    toast.success(t('capture.wa.created'));
                    onClose();
                    onCreated(created);
                },
            });
        }
    };
    return (
        <Sheet open onOpenChange={(open) => !open && onClose()}>
            <SheetContent>
                <form
                    className="igr:flex igr:min-h-0 igr:flex-1 igr:flex-col"
                    onSubmit={(e) => {
                        e.preventDefault();
                        submit();
                    }}
                >
                    <SheetHeader>
                        <SheetTitle>{link ? t('capture.wa.form.editTitle') : t('capture.wa.form.newTitle')}</SheetTitle>
                        <SheetDescription>{t('capture.wa.form.description')}</SheetDescription>
                    </SheetHeader>
                    <SheetBody className="igr:space-y-5">
                        <div className="igr:space-y-1.5">
                            <Label htmlFor="wa-name">{t('capture.wa.form.name')}</Label>
                            <Input id="wa-name" required maxLength={160} value={form.name} placeholder={t('capture.wa.form.namePlaceholder')} onChange={(e) => setForm({ ...form, name: e.target.value })} />
                            <p className="igr:text-caption igr:text-muted-foreground">{t('capture.wa.form.nameHelp')}</p>
                        </div>
                        <div className="igr:space-y-1.5">
                            <Label htmlFor="wa-message">{t('capture.wa.form.message')}</Label>
                            <Textarea id="wa-message" required rows={3} maxLength={300} value={form.message} onChange={(e) => setForm({ ...form, message: e.target.value })} />
                            <p className="igr:text-caption igr:text-muted-foreground">{t('capture.wa.form.messageHelp')}</p>
                        </div>
                        <div>
                            <p className="igr:mb-1.5 igr:text-caption igr:font-medium igr:text-muted-foreground">{t('capture.wa.form.preview')}</p>
                            <ChatBubble text={waMessage(form.message, link?.code ?? t('capture.wa.form.codeSample'))} />
                        </div>
                        {accounts.length > 1 ? (
                            <div className="igr:space-y-1.5">
                                <Label htmlFor="wa-account">{t('capture.wa.form.account')}</Label>
                                <Select id="wa-account" value={form.channel_account_id ?? ''} onChange={(e) => setForm({ ...form, channel_account_id: e.target.value || null })}>
                                    <option value="">{t('capture.wa.form.defaultAccount')}</option>
                                    {accounts.map((a) => (
                                        <option key={a.id} value={a.id}>
                                            {a.number ? `${a.name} · ${a.number}` : a.name}
                                        </option>
                                    ))}
                                </Select>
                            </div>
                        ) : null}
                        <fieldset className="igr:space-y-3 igr:rounded-lg igr:border igr:p-4">
                            <legend className="igr:px-1 igr:text-[13px] igr:font-semibold">{t('capture.wa.form.whenArrives')}</legend>
                            {leadsModule ? (
                                <Check label={t('capture.wa.form.createLead')} help={t('capture.wa.form.createLeadHelp')} checked={settings.create_lead ?? true} onChange={(v) => setForm({ ...form, settings: { ...settings, create_lead: v } })} />
                            ) : null}
                            <Check label={t('capture.wa.form.askConsent')} help={t('capture.wa.form.askConsentHelp')} checked={settings.ask_consent ?? true} onChange={(v) => setForm({ ...form, settings: { ...settings, ask_consent: v } })} />
                            {leadsModule && (settings.create_lead ?? true) ? (
                                <div className="igr:space-y-1.5 igr:pl-6">
                                    <Label htmlFor="wa-service">{t('capture.wa.form.service')}</Label>
                                    <Input id="wa-service" maxLength={120} value={settings.service ?? ''} placeholder={t('capture.wa.form.servicePlaceholder')} onChange={(e) => setForm({ ...form, settings: { ...settings, service: e.target.value } })} />
                                </div>
                            ) : null}
                        </fieldset>
                        {mutation.isError ? <FieldError message={errorMessage(mutation.error, t)} /> : null}
                    </SheetBody>
                    <SheetFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>
                            {t('common.cancel')}
                        </Button>
                        <Button type="submit" loading={mutation.isPending} disabled={form.name.trim() === '' || form.message.trim() === ''}>
                            {link ? t('capture.wa.form.save') : t('capture.wa.form.create')}
                        </Button>
                    </SheetFooter>
                </form>
            </SheetContent>
        </Sheet>
    );
}

function Check({ label, help, checked, onChange }: { label: string; help: string; checked: boolean; onChange: (value: boolean) => void }) {
    return (
        <label className="igr:flex igr:items-start igr:gap-2.5">
            <input type="checkbox" className="igr:mt-0.5 igr:size-4 igr:accent-primary" checked={checked} onChange={(e) => onChange(e.target.checked)} />
            <span>
                <span className="igr:block igr:text-[13px] igr:font-medium">{label}</span>
                <span className="igr:block igr:text-caption igr:text-muted-foreground">{help}</span>
            </span>
        </label>
    );
}
