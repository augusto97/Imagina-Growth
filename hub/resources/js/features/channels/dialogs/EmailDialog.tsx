import { CheckCircle2, CircleDashed, XCircle } from 'lucide-react';
import { type SyntheticEvent, useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input, Select } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { FieldError } from '@/components/ui/misc';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useWorkspace } from '@/hooks/useWorkspace';
import { cn } from '@/lib/cn';
import { toast } from '@/stores/toast';
import { type ChannelAccount, type DnsRecord, type EmailForm, useChannels, useCheckDns, useCreateEmail } from '../api';
import { CopyButton, fieldError } from '../shared';

/** ¿El plan incluye correo por la cuenta de SES de la plataforma? (`meta.platform_email`; si no llega, sí). */
export function platformEmailAllowed(meta: unknown): boolean {
    return !(typeof meta === 'object' && meta !== null && Reflect.get(meta, 'platform_email') === false);
}

/** Registros DNS del dominio con su verificación en vivo. */
export function DnsRecords({ records }: { records: DnsRecord[] }) {
    const { t } = useTranslation();
    return (
        <div className="igr:overflow-x-auto igr:rounded-lg igr:border">
            <Table>
                <THead>
                    <TR>
                        <TH>{t('channels.email.type')}</TH>
                        <TH>{t('channels.email.name')}</TH>
                        <TH>{t('channels.email.value')}</TH>
                        <TH>{t('channels.email.check')}</TH>
                    </TR>
                </THead>
                <TBody>
                    {records.map((r) => (
                        <TR key={`${r.type}${r.name}`}>
                            <TD className="igr:align-top">
                                <span className="igr:font-mono igr:text-xs">{r.type}</span>
                                <div className="igr:mt-1 igr:flex igr:flex-wrap igr:gap-1">
                                    <Badge variant="outline">{t(`channels.email.purpose.${r.purpose}`)}</Badge>
                                    {!r.required && <Badge variant="secondary">{t('channels.email.recommended')}</Badge>}
                                </div>
                            </TD>
                            <TD className="igr:align-top">
                                <div className="igr:flex igr:items-center igr:gap-1">
                                    <code className="igr:break-all igr:text-xs">{r.name}</code>
                                    <CopyButton value={r.name} />
                                </div>
                            </TD>
                            <TD className="igr:align-top">
                                <div className="igr:flex igr:items-center igr:gap-1">
                                    <code className="igr:break-all igr:text-xs">{r.value}</code>
                                    <CopyButton value={r.value} />
                                </div>
                            </TD>
                            <TD className="igr:align-top igr:whitespace-nowrap">
                                {r.ok === null ? (
                                    <span className="igr:inline-flex igr:items-center igr:gap-1 igr:text-xs igr:text-muted-foreground">
                                        <CircleDashed className="igr:size-3.5" aria-hidden="true" />
                                        {t('channels.email.unchecked')}
                                    </span>
                                ) : r.ok ? (
                                    <span className="igr:inline-flex igr:items-center igr:gap-1 igr:text-xs igr:text-success">
                                        <CheckCircle2 className="igr:size-3.5" aria-hidden="true" />
                                        {t('channels.email.ok')}
                                    </span>
                                ) : (
                                    <span className={cn('igr:inline-flex igr:items-center igr:gap-1 igr:text-xs', r.required ? 'igr:text-destructive' : 'igr:text-warning')}>
                                        <XCircle className="igr:size-3.5" aria-hidden="true" />
                                        {t('channels.email.missing')}
                                    </span>
                                )}
                            </TD>
                        </TR>
                    ))}
                </TBody>
            </Table>
        </div>
    );
}

/** Paso de DNS de una cuenta existente (también desde «Ver y verificar DNS»). */
export function DnsStep({ account, initial }: { account: ChannelAccount; initial: DnsRecord[] }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const check = useCheckDns(workspace.id);
    const records = check.data?.records ?? initial;
    // Abierto desde una cuenta existente: verifica de una vez para mostrar el estado real.
    const started = useRef(false);
    useEffect(() => {
        if (initial.length === 0 && !started.current) {
            started.current = true;
            check.mutate(account.id);
        }
    }, [account.id, check, initial.length]);
    return (
        <div className="igr:space-y-3">
            <div>
                <h3 className="igr:text-[13px] igr:font-semibold">{t('channels.email.dnsTitle')}</h3>
                <p className="igr:text-xs igr:text-muted-foreground">{t('channels.email.dnsDescription')}</p>
            </div>
            <DnsRecords records={records} />
            {check.data && (
                <p role="status" className={cn('igr:text-xs', check.data.verified ? 'igr:text-success' : 'igr:text-warning')}>
                    {check.data.verified ? t('channels.email.verified') : t('channels.email.notYet')}
                </p>
            )}
            <Button
                variant="outline"
                loading={check.isPending}
                onClick={() => check.mutate(account.id, { onSuccess: (r) => r.verified && toast.success(t('channels.email.verified')) })}
            >
                {t('channels.email.verify')}
            </Button>
        </div>
    );
}

export function EmailDialog({ open, onClose, dnsFor }: { open: boolean; onClose: () => void; dnsFor: ChannelAccount | null }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const create = useCreateEmail(workspace.id);
    const sesAllowed = platformEmailAllowed(useChannels(workspace.id).data?.meta);
    const [form, setForm] = useState<EmailForm>({ provider: 'ses', from_email: '', from_name: workspace.name, encryption: 'tls', port: 587 });
    const provider = sesAllowed ? form.provider : 'smtp';
    const [created, setCreated] = useState<{ account: ChannelAccount; dns: DnsRecord[] } | null>(null);
    const set = (patch: Partial<EmailForm>) => setForm((f) => ({ ...f, ...patch }));
    const account = dnsFor ?? created?.account ?? null;

    const submit = (e: SyntheticEvent<HTMLFormElement>) => {
        e.preventDefault();
        create.mutate({ ...form, provider }, {
            onSuccess: (r) => {
                toast.success(t('channels.toast.connected'));
                if (r.data.provider === 'smtp') {
                    onClose();
                } else {
                    setCreated({ account: r.data, dns: r.dns });
                }
            },
        });
    };
    const close = () => {
        setCreated(null);
        create.reset();
        onClose();
    };

    return (
        <Dialog open={open} onOpenChange={(next) => !next && close()}>
            <DialogContent className="igr:max-w-4xl">
                <DialogHeader>
                    <DialogTitle>{t('channels.email.title')}</DialogTitle>
                    {!account && <DialogDescription>{t('channels.empty.email.description')}</DialogDescription>}
                </DialogHeader>
                {account ? (
                    <DnsStep account={account} initial={created?.dns ?? []} />
                ) : (
                    <form id="igr-email-form" onSubmit={submit} className="igr:space-y-4">
                        <fieldset className="igr:grid igr:gap-2 igr:sm:grid-cols-2">
                            <legend className="igr:sr-only">{t('channels.email.title')}</legend>
                            {(['ses', 'smtp'] as const).map((p) => (
                                <label
                                    key={p}
                                    className={cn(
                                        'igr:flex igr:cursor-pointer igr:gap-2 igr:rounded-lg igr:border igr:p-3',
                                        provider === p && 'igr:border-primary igr:bg-primary/5',
                                        p === 'ses' && !sesAllowed && 'igr:cursor-not-allowed igr:opacity-60',
                                    )}
                                >
                                    <input
                                        type="radio"
                                        name="provider"
                                        value={p}
                                        checked={provider === p}
                                        disabled={p === 'ses' && !sesAllowed}
                                        onChange={() => set({ provider: p })}
                                        className="igr:mt-0.5"
                                    />
                                    <span>
                                        <span className="igr:block igr:text-[13px] igr:font-medium">{t(p === 'ses' ? 'channels.email.providerSes' : 'channels.email.providerSmtp')}</span>
                                        <span className="igr:block igr:text-xs igr:text-muted-foreground">
                                            {t(p === 'ses' ? (sesAllowed ? 'channels.email.providerSesHelp' : 'channels.email.providerSesNotInPlan') : 'channels.email.providerSmtpHelp')}
                                        </span>
                                    </span>
                                </label>
                            ))}
                        </fieldset>
                        <FieldError message={fieldError(create.error, 'provider')} />
                        <div className="igr:grid igr:gap-3 igr:sm:grid-cols-2">
                            <div className="igr:space-y-1">
                                <Label htmlFor="em-from">{t('channels.email.fromEmail')}</Label>
                                <Input id="em-from" type="email" required value={form.from_email} onChange={(e) => set({ from_email: e.target.value })} placeholder="hola@tutienda.co" />
                                <FieldError message={fieldError(create.error, 'from_email')} />
                            </div>
                            <div className="igr:space-y-1">
                                <Label htmlFor="em-name">{t('channels.email.fromName')}</Label>
                                <Input id="em-name" required value={form.from_name} onChange={(e) => set({ from_name: e.target.value })} />
                            </div>
                            <div className="igr:space-y-1">
                                <Label htmlFor="em-reply">{t('channels.email.replyTo')}</Label>
                                <Input id="em-reply" type="email" value={form.reply_to ?? ''} onChange={(e) => set({ reply_to: e.target.value || undefined })} />
                                <FieldError message={fieldError(create.error, 'reply_to')} />
                            </div>
                        </div>
                        {provider === 'smtp' && (
                            <div className="igr:grid igr:gap-3 igr:sm:grid-cols-4">
                                <div className="igr:space-y-1 igr:sm:col-span-2">
                                    <Label htmlFor="em-host">{t('channels.email.host')}</Label>
                                    <Input id="em-host" required value={form.host ?? ''} onChange={(e) => set({ host: e.target.value })} placeholder="smtp.tuproveedor.com" />
                                    <FieldError message={fieldError(create.error, 'host')} />
                                </div>
                                <div className="igr:space-y-1">
                                    <Label htmlFor="em-port">{t('channels.email.port')}</Label>
                                    <Input id="em-port" type="number" required value={form.port ?? 587} onChange={(e) => set({ port: Number(e.target.value) })} />
                                </div>
                                <div className="igr:space-y-1">
                                    <Label htmlFor="em-enc">{t('channels.email.encryption')}</Label>
                                    <Select id="em-enc" value={form.encryption ?? 'tls'} onChange={(e) => set({ encryption: e.target.value as EmailForm['encryption'] })}>
                                        <option value="tls">TLS</option>
                                        <option value="ssl">SSL</option>
                                        <option value="none">{t('channels.email.none')}</option>
                                    </Select>
                                </div>
                                <div className="igr:space-y-1 igr:sm:col-span-2">
                                    <Label htmlFor="em-user">{t('channels.email.username')}</Label>
                                    <Input id="em-user" autoComplete="off" value={form.username ?? ''} onChange={(e) => set({ username: e.target.value })} />
                                </div>
                                <div className="igr:space-y-1 igr:sm:col-span-2">
                                    <Label htmlFor="em-pass">{t('channels.email.password')}</Label>
                                    <Input id="em-pass" type="password" autoComplete="new-password" value={form.password ?? ''} onChange={(e) => set({ password: e.target.value })} />
                                </div>
                            </div>
                        )}
                    </form>
                )}
                <DialogFooter>
                    {account ? (
                        <Button onClick={close}>{t('common.close')}</Button>
                    ) : (
                        <>
                            <Button variant="outline" onClick={close}>
                                {t('common.cancel')}
                            </Button>
                            <Button type="submit" form="igr-email-form" loading={create.isPending}>
                                {t('channels.email.create')}
                            </Button>
                        </>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
