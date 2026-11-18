import { CheckCircle2, CircleAlert, Clock, Globe, ImageUp, Trash2 } from 'lucide-react';
import { type ReactNode, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input, Select } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { toast } from '@/stores/toast';
import {
    type AgencyBrandingAccount,
    type BrandingForm,
    type CustomDomain,
    useAddDomain,
    useAgencyBranding,
    useDeleteDomain,
    useDeleteLogo,
    useUpdateBranding,
    useUploadLogo,
    useVerifyDomain,
} from './api';
import { normalizeHost } from './model';

/* Marca blanca y dominios propios (M22). */
export function BrandTab() {
    const { workspace } = useWorkspace();
    const branding = useAgencyBranding(workspace.id);
    if (branding.isError) {
        return <ErrorState error={branding.error} onRetry={() => void branding.refetch()} />;
    }
    if (branding.isPending) {
        return <Skeleton className="igr:h-64 igr:w-full" />;
    }
    return (
        <div className="igr:space-y-6">
            {branding.data.accounts.map((account) => (
                <AccountBrand key={`${account.id}:${JSON.stringify(account.branding)}`} account={account} />
            ))}
        </div>
    );
}

function AccountBrand({ account }: { account: AgencyBrandingAccount }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const update = useUpdateBranding(workspace.id);
    const upload = useUploadLogo(workspace.id);
    const removeLogo = useDeleteLogo(workspace.id);
    const file = useRef<HTMLInputElement>(null);
    const [form, setForm] = useState<Omit<BrandingForm, 'logo_url'>>(() => strip(account.branding));
    const disabled = !account.can_manage;
    const set = <K extends keyof typeof form>(key: K, value: (typeof form)[K]) => setForm((f) => ({ ...f, [key]: value }));
    const onError = (e: unknown) => toast.error(t('errors.title'), isApiError(e) ? e.message : undefined);
    const color = form.primary_color && /^#[0-9a-f]{6}$/i.test(form.primary_color) ? form.primary_color : '#4F46E5';

    return (
        <Card>
            <CardHeader>
                <CardTitle>{account.name}</CardTitle>
                <CardDescription>{account.can_manage ? t('agency.brand.intro') : t('agency.brand.owner_only')}</CardDescription>
            </CardHeader>
            <CardContent className="igr:space-y-6">
                <div className="igr:grid igr:gap-6 igr:lg:grid-cols-[1fr_280px]">
                    <form
                        className="igr:grid igr:gap-3 igr:sm:grid-cols-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            update.mutate({ account: account.id, body: form }, { onSuccess: () => toast.success(t('agency.brand.saved')), onError });
                        }}
                    >
                        <Field label={t('agency.brand.brand_name')}>
                            <Input value={form.brand_name ?? ''} onChange={(e) => set('brand_name', e.target.value)} disabled={disabled} maxLength={120} />
                        </Field>
                        <Field label={t('agency.brand.color')}>
                            <div className="igr:flex igr:gap-2">
                                <input type="color" value={color} onChange={(e) => set('primary_color', e.target.value.toUpperCase())} disabled={disabled} className="igr:h-8 igr:w-10 igr:rounded igr:border" aria-label={t('agency.brand.color')} />
                                <Input value={form.primary_color ?? ''} onChange={(e) => set('primary_color', e.target.value)} disabled={disabled} placeholder="#4F46E5" maxLength={7} />
                            </div>
                        </Field>
                        <Field label={t('agency.brand.sender_name')}>
                            <Input value={form.sender_name ?? ''} onChange={(e) => set('sender_name', e.target.value)} disabled={disabled} maxLength={120} />
                        </Field>
                        <Field label={t('agency.brand.sender_email')} hint={t('agency.brand.sender_hint')}>
                            <Input type="email" value={form.sender_email ?? ''} onChange={(e) => set('sender_email', e.target.value)} disabled={disabled} />
                            {form.sender_email ? (
                                <p className="igr:text-xs igr:text-muted-foreground">{t('agency.brand.sender_verified', { count: account.sender_verified_in, total: account.workspaces.length })}</p>
                            ) : null}
                        </Field>
                        <Field label={t('agency.brand.support_email')}>
                            <Input type="email" value={form.support_email ?? ''} onChange={(e) => set('support_email', e.target.value)} disabled={disabled} />
                        </Field>
                        <label className="igr:flex igr:items-start igr:gap-2 igr:text-[13px] igr:sm:col-span-2">
                            <input type="checkbox" checked={form.white_label} onChange={(e) => set('white_label', e.target.checked)} disabled={disabled} className="igr:mt-0.5" />
                            {t('agency.brand.white_label')}
                        </label>
                        {account.can_manage ? (
                            <div className="igr:sm:col-span-2">
                                <Button type="submit" loading={update.isPending}>
                                    {t('agency.brand.save')}
                                </Button>
                            </div>
                        ) : null}
                    </form>
                    <div className="igr:space-y-3">
                        <Label>{t('agency.brand.preview')}</Label>
                        <div className="igr:rounded-lg igr:border igr:bg-sidebar igr:p-3">
                            <div className="igr:flex igr:items-center igr:gap-2">
                                {account.branding.logo_url ? (
                                    <img src={account.branding.logo_url} alt="" className="igr:h-6 igr:max-w-32 igr:object-contain" />
                                ) : (
                                    <span className="igr:flex igr:size-6 igr:items-center igr:justify-center igr:rounded-md igr:text-xs igr:font-semibold igr:text-white" style={{ background: color }}>
                                        {(form.brand_name || account.name).slice(0, 1).toUpperCase()}
                                    </span>
                                )}
                                <span className="igr:text-sm igr:font-semibold">{form.brand_name || 'Imagina Growth'}</span>
                            </div>
                            <div className="igr:mt-3 igr:h-1.5 igr:rounded-full" style={{ background: color }} />
                        </div>
                        <div className="igr:space-y-1">
                            <Label>{t('agency.brand.logo')}</Label>
                            <p className="igr:text-xs igr:text-muted-foreground">{t('agency.brand.logo_hint')}</p>
                            <input
                                ref={file}
                                type="file"
                                accept="image/png,image/jpeg"
                                className="igr:hidden"
                                onChange={(e) => {
                                    const f = e.target.files?.[0];
                                    if (f) {
                                        upload.mutate({ account: account.id, file: f }, { onError });
                                    }
                                    e.target.value = '';
                                }}
                            />
                            <div className="igr:flex igr:gap-2">
                                <Button size="sm" variant="outline" disabled={disabled} loading={upload.isPending} onClick={() => file.current?.click()}>
                                    <ImageUp aria-hidden />
                                    {t('agency.brand.upload')}
                                </Button>
                                {account.branding.logo_url ? (
                                    <Button size="sm" variant="ghost" disabled={disabled} onClick={() => removeLogo.mutate(account.id, { onError })}>
                                        {t('agency.brand.remove_logo')}
                                    </Button>
                                ) : null}
                            </div>
                        </div>
                    </div>
                </div>
                <Domains account={account} />
            </CardContent>
        </Card>
    );
}

function Domains({ account }: { account: AgencyBrandingAccount }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const add = useAddDomain(workspace.id);
    const verify = useVerifyDomain(workspace.id);
    const remove = useDeleteDomain(workspace.id);
    const [host, setHost] = useState('');
    const [purpose, setPurpose] = useState<CustomDomain['purpose']>('panel');
    const [client, setClient] = useState('');
    const onError = (e: unknown) => toast.error(t('errors.title'), isApiError(e) ? e.message : undefined);

    return (
        <div className="igr:space-y-3">
            <div>
                <h3 className="igr:flex igr:items-center igr:gap-1.5 igr:text-sm igr:font-semibold">
                    <Globe className="igr:size-4" aria-hidden />
                    {t('agency.brand.domains')}
                </h3>
                <p className="igr:text-[13px] igr:text-muted-foreground">{t('agency.brand.domains_hint')}</p>
            </div>
            {account.can_manage ? (
                <form
                    className="igr:flex igr:flex-wrap igr:items-end igr:gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        add.mutate(
                            { account_id: account.id, host: normalizeHost(host), purpose, workspace_id: purpose === 'panel' ? undefined : client || undefined },
                            { onSuccess: () => setHost(''), onError },
                        );
                    }}
                >
                    <Field label={t('agency.brand.host')}>
                        <Input value={host} onChange={(e) => setHost(e.target.value)} placeholder="panel.tuagencia.com" className="igr:w-64" />
                    </Field>
                    <Field label={t('agency.brand.purpose')}>
                        <Select value={purpose} onChange={(e) => setPurpose(e.target.value as CustomDomain['purpose'])} className="igr:w-48">
                            {(['panel', 'links', 'booking', 'tracking'] as const).map((p) => (
                                <option key={p} value={p}>
                                    {t(`agency.brand.purposes.${p}`)}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    {purpose !== 'panel' ? (
                        <Field label={t('agency.brand.client')}>
                            <Select value={client} onChange={(e) => setClient(e.target.value)} className="igr:w-48">
                                <option value="">—</option>
                                {account.workspaces.map((w) => (
                                    <option key={w.id} value={w.id}>
                                        {w.name}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                    ) : null}
                    <Button type="submit" variant="outline" disabled={host.trim() === ''} loading={add.isPending}>
                        {t('agency.brand.add_domain')}
                    </Button>
                </form>
            ) : null}
            {account.domains.length === 0 ? (
                <p className="igr:text-[13px] igr:text-muted-foreground">{t('agency.brand.no_domains')}</p>
            ) : (
                <div className="igr:space-y-3">
                    {account.domains.map((d) => (
                        <div key={d.id} className="igr:rounded-lg igr:border igr:p-3">
                            <div className="igr:flex igr:flex-wrap igr:items-center igr:justify-between igr:gap-2">
                                <div className="igr:flex igr:items-center igr:gap-2">
                                    <span className="igr:font-mono igr:text-[13px]">{d.host}</span>
                                    <DomainStatus status={d.status} />
                                    <span className="igr:text-xs igr:text-muted-foreground">
                                        {t(`agency.brand.purposes.${d.purpose}`)}
                                        {d.workspace ? ` · ${d.workspace}` : ''}
                                    </span>
                                </div>
                                {account.can_manage ? (
                                    <div className="igr:flex igr:gap-1.5">
                                        <Button size="sm" variant="outline" loading={verify.isPending && verify.variables === d.id} onClick={() => verify.mutate(d.id, { onError })}>
                                            {t('agency.brand.verify')}
                                        </Button>
                                        <Button size="sm" variant="ghost" aria-label={t('agency.brand.remove')} onClick={() => remove.mutate(d.id, { onError })}>
                                            <Trash2 aria-hidden />
                                        </Button>
                                    </div>
                                ) : null}
                            </div>
                            {d.last_error ? <p className="igr:mt-1 igr:text-xs igr:text-destructive">{d.last_error}</p> : null}
                            {d.status !== 'verified' ? (
                                <Table className="igr:mt-2">
                                    <THead>
                                        <TR>
                                            <TH>{t('agency.brand.record_type')}</TH>
                                            <TH>{t('agency.brand.record_name')}</TH>
                                            <TH>{t('agency.brand.record_value')}</TH>
                                        </TR>
                                    </THead>
                                    <TBody>
                                        {d.records.map((r) => (
                                            <TR key={r.type}>
                                                <TD>{r.type}</TD>
                                                <TD className="igr:font-mono igr:text-xs igr:break-all">{r.name}</TD>
                                                <TD className="igr:font-mono igr:text-xs igr:break-all">{r.value}</TD>
                                            </TR>
                                        ))}
                                    </TBody>
                                </Table>
                            ) : null}
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

function DomainStatus({ status }: { status: CustomDomain['status'] }) {
    const { t } = useTranslation();
    const Icon = status === 'verified' ? CheckCircle2 : status === 'failed' ? CircleAlert : Clock;
    return (
        <Badge variant={status === 'verified' ? 'success' : status === 'failed' ? 'warning' : 'outline'}>
            <Icon className="igr:size-3" aria-hidden />
            {t(`agency.brand.status.${status}`)}
        </Badge>
    );
}

function strip(b: BrandingForm): Omit<BrandingForm, 'logo_url'> {
    return {
        brand_name: b.brand_name,
        primary_color: b.primary_color,
        sender_name: b.sender_name,
        sender_email: b.sender_email,
        support_email: b.support_email,
        white_label: b.white_label,
    };
}

function Field({ label, hint, children }: { label: string; hint?: string; children: ReactNode }) {
    return (
        <div className="igr:space-y-1">
            <Label title={hint}>{label}</Label>
            {children}
            {hint ? <p className="igr:text-xs igr:text-muted-foreground">{hint}</p> : null}
        </div>
    );
}
