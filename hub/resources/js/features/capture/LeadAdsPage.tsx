import { ArrowRight, CircleCheck, KeyRound, ListChecks, Plus, Power, RefreshCw, RotateCw, Unplug } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Callout, type CalloutTone } from '@/components/ui/callout';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { CopyField } from '@/components/ui/copy-field';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Select } from '@/components/ui/input';
import { ErrorState, FieldError } from '@/components/ui/misc';
import { PageHeader } from '@/components/ui/page-header';
import { SegmentedControl } from '@/components/ui/segmented';
import { Sheet, SheetBody, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { errorMessage } from '@/features/contacts/errors';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { formatDateTime, formatNumber, formatRelative } from '@/lib/format';
import { toast } from '@/stores/toast';
import { useAdLeads, useLeadAdForms, useLeadAds, useLeadAdsMutations, useMetaPages } from './api';
import { ConsentBadges } from './shared';
import type { ConsentChannel, GoogleWebhookCreated, LeadAdForm, LeadAdLeadRow, LeadAdSource, LeadAdsState } from './types';

/**
 * Leads de anuncios (modulos.md M18 «Leads de anuncios»): los formularios de Facebook e Instagram (Meta Lead Ads) y de
 * Google Ads llegan como leads con su campaña. Aquí se ve el estado de cada plataforma en palabras, qué falta y el botón
 * para resolverlo, a qué canal da permiso cada casilla y los últimos leads, con el reintento de los que fallaron.
 */
export function LeadAdsPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const state = useLeadAds(workspace.id);
    const canManage = useCan('manageServices');
    const [forms, setForms] = useState<LeadAdSource | null>(null);

    return (
        <>
            <PageHeader title={t('capture.ads.title')} description={t('capture.ads.description')} />
            {state.isError ? (
                <ErrorState error={state.error} onRetry={() => void state.refetch()} />
            ) : state.isPending ? (
                <div className="igr:grid igr:gap-4 igr:lg:grid-cols-2">
                    <Skeleton className="igr:h-72 igr:rounded-xl" />
                    <Skeleton className="igr:h-72 igr:rounded-xl" />
                </div>
            ) : (
                <div className="igr:grid igr:items-start igr:gap-4 igr:lg:grid-cols-2">
                    <MetaCard meta={state.data.meta} canManage={canManage} onForms={setForms} />
                    <GoogleCard google={state.data.google} canManage={canManage} onForms={setForms} />
                </div>
            )}
            <RecentLeads />
            {forms !== null ? <FormsSheet source={forms} canManage={canManage} onClose={() => setForms(null)} /> : null}
        </>
    );
}

const META_TONE: Record<LeadAdsState['meta']['status'], CalloutTone> = {
    not_configured: 'neutral',
    not_connected: 'info',
    reconnect: 'warning',
    missing_permissions: 'warning',
    error: 'danger',
    ready: 'brand',
    active: 'success',
};

function PlatformHeader({ name, help, status, tone }: { name: string; help: string; status: string; tone: 'success' | 'warning' | 'secondary' | 'destructive' | 'info' }) {
    return (
        <CardHeader className="igr:flex-row igr:items-start igr:justify-between igr:gap-3">
            <div className="igr:min-w-0">
                <CardTitle>{name}</CardTitle>
                <CardDescription>{help}</CardDescription>
            </div>
            <Badge variant={tone} className="igr:shrink-0">
                {status}
            </Badge>
        </CardHeader>
    );
}

function MetaCard({ meta, canManage, onForms }: { meta: LeadAdsState['meta']; canManage: boolean; onForms: (s: LeadAdSource) => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const [adding, setAdding] = useState(false);
    const needsConnection = meta.status === 'not_connected' || meta.status === 'reconnect' || meta.status === 'missing_permissions';
    const tone = meta.status === 'active' ? 'success' : meta.status === 'error' ? 'destructive' : meta.status === 'ready' ? 'info' : needsConnection ? 'warning' : 'secondary';
    return (
        <Card className="igr:min-w-0">
            <PlatformHeader name={t('capture.ads.meta.name')} help={t('capture.ads.meta.help')} status={t(`capture.ads.meta.status.${meta.status}`)} tone={tone} />
            <CardContent className="igr:space-y-4">
                <Callout tone={META_TONE[meta.status]}>{meta.message}</Callout>
                {meta.missing_permissions.length > 0 ? (
                    <div>
                        <p className="igr:text-[13px] igr:font-medium">{t('capture.ads.meta.missingTitle')}</p>
                        <ul className="igr:mt-1.5 igr:space-y-1 igr:text-support igr:text-muted-foreground">
                            {meta.missing_permissions.map((p) => (
                                <li key={p.scope} className="igr:flex igr:gap-2">
                                    <KeyRound className="igr:mt-0.5 igr:size-3.5 igr:shrink-0 igr:text-warning" aria-hidden />
                                    {p.label}
                                </li>
                            ))}
                        </ul>
                    </div>
                ) : null}
                {meta.pages.length > 0 ? (
                    <ul className="igr:divide-y igr:rounded-lg igr:border">
                        {meta.pages.map((page) => (
                            <SourceRow key={page.id} source={page} canManage={canManage} onForms={() => onForms(page)} />
                        ))}
                    </ul>
                ) : null}
            </CardContent>
            {canManage && (needsConnection || meta.status === 'ready' || meta.status === 'active' || meta.status === 'error') ? (
                <CardFooter>
                    {needsConnection ? (
                        <Button asChild>
                            <Link to={`/w/${workspace.id}/conexiones`}>
                                {meta.status === 'not_connected' ? t('capture.ads.meta.connect') : t('capture.ads.meta.reconnect')}
                                <ArrowRight aria-hidden />
                            </Link>
                        </Button>
                    ) : (
                        <Button variant={meta.pages.length === 0 ? 'default' : 'outline'} onClick={() => setAdding(true)}>
                            <Plus aria-hidden />
                            {t('capture.ads.meta.addPage')}
                        </Button>
                    )}
                </CardFooter>
            ) : null}
            {adding ? <MetaPagesDialog onClose={() => setAdding(false)} /> : null}
        </Card>
    );
}

function SourceRow({ source, canManage, onForms }: { source: LeadAdSource; canManage: boolean; onForms: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const { unsubscribeMetaPage } = useLeadAdsMutations(workspace.id);
    const [confirm, setConfirm] = useState(false);
    return (
        <li className="igr:space-y-2 igr:p-3">
            <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-2">
                <p className="igr:min-w-0 igr:flex-1 igr:truncate igr:text-[13px] igr:font-medium">{source.name ?? source.page_id}</p>
                <Badge variant={source.status === 'active' ? 'success' : source.status === 'error' ? 'destructive' : 'secondary'}>
                    {t(`capture.ads.sourceStatus.${source.status}`)}
                </Badge>
            </div>
            <p className="igr:text-caption igr:text-muted-foreground igr:tabular-nums">
                {t('capture.ads.sourceStats', {
                    leads: formatNumber(source.leads_30d, workspace.locale),
                    consent: formatNumber(source.with_consent_30d, workspace.locale),
                })}
                {' · '}
                {source.last_lead_at ? t('capture.ads.lastLead', { when: formatRelative(source.last_lead_at, workspace.locale) }) : t('capture.ads.noLeadsYet')}
            </p>
            {source.last_error ? <p className="igr:text-caption igr:text-destructive">{source.last_error}</p> : null}
            {canManage ? (
                <div className="igr:flex igr:flex-wrap igr:gap-2">
                    <Button size="sm" variant="outline" onClick={onForms}>
                        <ListChecks aria-hidden />
                        {t('capture.ads.formsButton', { count: source.forms_mapped })}
                    </Button>
                    {source.platform === 'meta' ? (
                        <Button size="sm" variant="ghost" onClick={() => setConfirm(true)}>
                            <Unplug aria-hidden />
                            {t('capture.ads.meta.stop')}
                        </Button>
                    ) : null}
                </div>
            ) : null}
            <ConfirmDialog
                open={confirm}
                onOpenChange={setConfirm}
                title={t('capture.ads.meta.stopTitle', { name: source.name ?? '' })}
                description={t('capture.ads.meta.stopBody')}
                confirmLabel={t('capture.ads.meta.stop')}
                tone="danger"
                loading={unsubscribeMetaPage.isPending}
                onConfirm={() =>
                    unsubscribeMetaPage.mutate(source.id, {
                        onSuccess: () => {
                            setConfirm(false);
                            toast.info(t('capture.ads.meta.stopped'));
                        },
                        onError: (e) => toast.error(errorMessage(e, t)),
                    })
                }
            />
        </li>
    );
}

function MetaPagesDialog({ onClose }: { onClose: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const pages = useMetaPages(workspace.id, true);
    const { subscribeMetaPage } = useLeadAdsMutations(workspace.id);
    const [chosen, setChosen] = useState('');
    const list = pages.data?.data ?? [];
    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('capture.ads.meta.pagesTitle')}</DialogTitle>
                    <DialogDescription>{t('capture.ads.meta.pagesBody')}</DialogDescription>
                </DialogHeader>
                {pages.isError ? (
                    <ErrorState error={pages.error} onRetry={() => void pages.refetch()} />
                ) : pages.isPending ? (
                    <div className="igr:space-y-2">
                        <Skeleton className="igr:h-10" />
                        <Skeleton className="igr:h-10" />
                    </div>
                ) : list.length === 0 ? (
                    <p className="igr:text-support igr:text-muted-foreground">{t('capture.ads.meta.noPages')}</p>
                ) : (
                    <fieldset className="igr:space-y-2">
                        <legend className="igr:sr-only">{t('capture.ads.meta.pagesTitle')}</legend>
                        {list.map((p) => (
                            <label
                                key={p.page_id}
                                className="igr:flex igr:cursor-pointer igr:items-center igr:gap-3 igr:rounded-lg igr:border igr:p-3 igr:has-[:checked]:border-primary igr:has-[:checked]:bg-brand-soft/60"
                            >
                                <input
                                    type="radio"
                                    name="meta-page"
                                    className="igr:size-4 igr:accent-primary"
                                    value={p.page_id}
                                    disabled={p.source_id !== null}
                                    checked={chosen === p.page_id}
                                    onChange={() => setChosen(p.page_id)}
                                />
                                <span className="igr:min-w-0 igr:flex-1 igr:truncate igr:text-[13px] igr:font-medium">{p.name}</span>
                                {p.source_id !== null ? <Badge variant="success">{t('capture.ads.meta.already')}</Badge> : null}
                            </label>
                        ))}
                    </fieldset>
                )}
                {subscribeMetaPage.isError ? <FieldError message={errorMessage(subscribeMetaPage.error, t)} /> : null}
                <DialogFooter>
                    <Button variant="ghost" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    <Button
                        disabled={chosen === ''}
                        loading={subscribeMetaPage.isPending}
                        onClick={() =>
                            subscribeMetaPage.mutate(chosen, {
                                onSuccess: () => {
                                    toast.success(t('capture.ads.meta.subscribed'));
                                    onClose();
                                },
                            })
                        }
                    >
                        {t('capture.ads.meta.subscribe')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function GoogleCard({ google, canManage, onForms }: { google: LeadAdsState['google']; canManage: boolean; onForms: (s: LeadAdSource) => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const { enableGoogle, disableGoogle } = useLeadAdsMutations(workspace.id);
    const [created, setCreated] = useState<GoogleWebhookCreated | null>(null);
    const [confirm, setConfirm] = useState<'rotate' | 'disable' | null>(null);
    const active = google.status === 'active';
    const generate = () =>
        enableGoogle.mutate(undefined, {
            onSuccess: (r) => {
                setConfirm(null);
                setCreated(r);
            },
            onError: (e) => toast.error(errorMessage(e, t)),
        });
    return (
        <Card className="igr:min-w-0">
            <PlatformHeader
                name={t('capture.ads.google.name')}
                help={t('capture.ads.google.help')}
                status={t(`capture.ads.google.status.${google.status}`)}
                tone={active ? 'success' : 'secondary'}
            />
            <CardContent className="igr:space-y-4">
                <Callout tone={active ? 'success' : 'info'}>{google.message}</Callout>
                {active && google.webhook_url ? (
                    <>
                        <CopyField label={t('capture.ads.google.url')} value={google.webhook_url} mono />
                        <div>
                            <p className="igr:text-[13px] igr:font-medium">{t('capture.ads.google.key')}</p>
                            <p className="igr:mt-1 igr:font-mono igr:text-[13px] igr:text-muted-foreground">{google.key_hint}</p>
                            <p className="igr:mt-1 igr:text-caption igr:text-muted-foreground">{t('capture.ads.google.keyHelp')}</p>
                        </div>
                        <ol className="igr:list-decimal igr:space-y-1 igr:pl-5 igr:text-support igr:text-muted-foreground">
                            <li>{t('capture.ads.google.step1')}</li>
                            <li>{t('capture.ads.google.step2')}</li>
                            <li>{t('capture.ads.google.step3')}</li>
                        </ol>
                        {google.source ? (
                            <ul className="igr:rounded-lg igr:border">
                                <SourceRow source={google.source} canManage={canManage} onForms={() => google.source && onForms(google.source)} />
                            </ul>
                        ) : null}
                        {google.source?.test_received_at ? (
                            <p className="igr:flex igr:items-center igr:gap-1.5 igr:text-caption igr:text-success">
                                <CircleCheck className="igr:size-3.5" aria-hidden />
                                {t('capture.ads.google.testReceived', { when: formatRelative(google.source.test_received_at, workspace.locale) })}
                            </p>
                        ) : null}
                    </>
                ) : null}
            </CardContent>
            {canManage ? (
                <CardFooter>
                    {active ? (
                        <>
                            <Button variant="outline" onClick={() => setConfirm('rotate')}>
                                <RotateCw aria-hidden />
                                {t('capture.ads.google.rotate')}
                            </Button>
                            <Button variant="ghost" onClick={() => setConfirm('disable')}>
                                <Power aria-hidden />
                                {t('capture.ads.google.disable')}
                            </Button>
                        </>
                    ) : (
                        <Button loading={enableGoogle.isPending} onClick={generate}>
                            <KeyRound aria-hidden />
                            {t('capture.ads.google.generate')}
                        </Button>
                    )}
                </CardFooter>
            ) : null}
            <ConfirmDialog
                open={confirm === 'rotate'}
                onOpenChange={(open) => !open && setConfirm(null)}
                title={t('capture.ads.google.rotateTitle')}
                description={t('capture.ads.google.rotateBody')}
                confirmLabel={t('capture.ads.google.rotate')}
                loading={enableGoogle.isPending}
                onConfirm={generate}
            />
            <ConfirmDialog
                open={confirm === 'disable'}
                onOpenChange={(open) => !open && setConfirm(null)}
                title={t('capture.ads.google.disableTitle')}
                description={t('capture.ads.google.disableBody')}
                confirmLabel={t('capture.ads.google.disable')}
                tone="danger"
                loading={disableGoogle.isPending}
                onConfirm={() =>
                    disableGoogle.mutate(undefined, {
                        onSuccess: () => {
                            setConfirm(null);
                            toast.info(t('capture.ads.google.disabled'));
                        },
                        onError: (e) => toast.error(errorMessage(e, t)),
                    })
                }
            />
            {created ? (
                <Dialog open onOpenChange={(open) => !open && setCreated(null)}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>{t('capture.ads.google.createdTitle')}</DialogTitle>
                            <DialogDescription>{t('capture.ads.google.createdBody')}</DialogDescription>
                        </DialogHeader>
                        <div className="igr:space-y-4">
                            {created.webhook_url ? <CopyField label={t('capture.ads.google.url')} value={created.webhook_url} mono /> : null}
                            <CopyField label={t('capture.ads.google.key')} value={created.key} mono />
                            <Callout tone="warning">{t('capture.ads.google.onlyOnce')}</Callout>
                        </div>
                        <DialogFooter>
                            <Button onClick={() => setCreated(null)}>{t('capture.ads.google.done')}</Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            ) : null}
        </Card>
    );
}

const PLATFORM_FILTERS = ['all', 'meta', 'google'] as const;
type PlatformFilter = (typeof PLATFORM_FILTERS)[number];

function RecentLeads() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const [platform, setPlatform] = useState<PlatformFilter>('all');
    const [status, setStatus] = useState('');
    const leads = useAdLeads(workspace.id, platform === 'all' ? '' : platform, status);
    return (
        <Card className="igr:mt-6 igr:overflow-hidden">
            <CardHeader className="igr:flex-row igr:flex-wrap igr:items-start igr:justify-between igr:gap-3">
                <div className="igr:min-w-0">
                    <CardTitle>{t('capture.ads.leads.title')}</CardTitle>
                    <CardDescription>{t('capture.ads.leads.help')}</CardDescription>
                </div>
                <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-2">
                    <SegmentedControl
                        size="sm"
                        label={t('capture.ads.leads.platform')}
                        value={platform}
                        onChange={setPlatform}
                        options={PLATFORM_FILTERS.map((p) => ({ value: p, label: t(`capture.ads.leads.platforms.${p}`) }))}
                    />
                    <Select aria-label={t('capture.ads.leads.status')} className="igr:h-8 igr:w-auto" value={status} onChange={(e) => setStatus(e.target.value)}>
                        <option value="">{t('capture.ads.leads.anyStatus')}</option>
                        {(['processed', 'failed', 'received', 'test'] as const).map((s) => (
                            <option key={s} value={s}>
                                {t(`capture.ads.leads.statuses.${s}`)}
                            </option>
                        ))}
                    </Select>
                </div>
            </CardHeader>
            {leads.isError ? (
                <CardContent>
                    <ErrorState error={leads.error} onRetry={() => void leads.refetch()} />
                </CardContent>
            ) : leads.isPending ? (
                <CardContent className="igr:space-y-2">
                    {[0, 1, 2].map((i) => (
                        <Skeleton key={i} className="igr:h-11" />
                    ))}
                </CardContent>
            ) : leads.data.data.length === 0 ? (
                <CardContent>
                    <EmptyState size="compact" illustration="customers" title={t('capture.ads.leads.emptyTitle')} description={t('capture.ads.leads.emptyBody')} />
                </CardContent>
            ) : (
                <Table>
                    <THead>
                        <TR>
                            <TH>{t('capture.ads.leads.when')}</TH>
                            <TH>{t('capture.ads.leads.who')}</TH>
                            <TH>{t('capture.ads.leads.from')}</TH>
                            <TH>{t('capture.ads.leads.consent')}</TH>
                            <TH>{t('capture.ads.leads.state')}</TH>
                        </TR>
                    </THead>
                    <TBody>
                        {leads.data.data.map((row) => (
                            <LeadRow key={row.id} row={row} />
                        ))}
                    </TBody>
                </Table>
            )}
        </Card>
    );
}

function LeadRow({ row }: { row: LeadAdLeadRow }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageServices');
    const canSeeLeads = useCan('useLeads');
    const { retryLead } = useLeadAdsMutations(workspace.id);
    const who = row.contact ? (row.contact.name ?? row.contact.email ?? row.contact.phone) : null;
    const campaign = [row.campaign.name, row.ad.name].filter((v): v is string => v !== null && v !== '').join(' · ');
    const tone = row.status === 'processed' ? 'success' : row.status === 'failed' ? 'destructive' : row.status === 'test' ? 'info' : 'secondary';
    return (
        <TR>
            <TD className="igr:whitespace-nowrap igr:text-muted-foreground igr:tabular-nums">{formatDateTime(row.received_at, workspace.locale, workspace.timezone)}</TD>
            <TD className="igr:min-w-40">
                {who ? <span className="igr:font-medium">{who}</span> : <span className="igr:text-muted-foreground">{row.is_test ? t('capture.ads.leads.testPerson') : '—'}</span>}
                {row.lead_id && canSeeLeads ? (
                    <Link to={`/w/${workspace.id}/leads?lead=${row.lead_id}`} className="igr:ml-2 igr:text-caption igr:font-medium igr:text-primary igr:hover:underline">
                        {t('capture.ads.leads.openLead')}
                    </Link>
                ) : null}
            </TD>
            <TD className="igr:min-w-48">
                <span className="igr:flex igr:items-center igr:gap-1.5">
                    <Badge variant="outline">{t(`capture.ads.leads.platforms.${row.platform}`)}</Badge>
                    <span className="igr:truncate igr:text-[13px]">{row.form_name ?? row.form_id ?? '—'}</span>
                </span>
                {campaign ? <span className="igr:mt-0.5 igr:block igr:truncate igr:text-caption igr:text-muted-foreground">{campaign}</span> : null}
            </TD>
            <TD>
                <ConsentBadges channels={row.consents} empty={t('capture.ads.leads.noConsent')} />
            </TD>
            <TD className="igr:min-w-40">
                <Badge variant={tone}>{t(`capture.ads.leads.statuses.${row.status}`)}</Badge>
                {row.error ? <p className="igr:mt-1 igr:max-w-xs igr:text-caption igr:text-destructive">{row.error}</p> : null}
                {row.status === 'failed' && canManage ? (
                    <Button
                        size="sm"
                        variant="outline"
                        className="igr:mt-1.5"
                        loading={retryLead.isPending && retryLead.variables === row.id}
                        onClick={() =>
                            retryLead.mutate(row.id, { onSuccess: () => toast.success(t('capture.ads.leads.retried')), onError: (e) => toast.error(errorMessage(e, t)) })
                        }
                    >
                        <RefreshCw aria-hidden />
                        {t('capture.ads.leads.retry')}
                    </Button>
                ) : null}
            </TD>
        </TR>
    );
}

function FormsSheet({ source, canManage, onClose }: { source: LeadAdSource; canManage: boolean; onClose: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const forms = useLeadAdForms(workspace.id, source.id);
    return (
        <Sheet open onOpenChange={(open) => !open && onClose()}>
            <SheetContent className="igr:max-w-xl">
                <SheetHeader>
                    <SheetTitle>{t('capture.ads.forms.title', { name: source.name ?? '' })}</SheetTitle>
                    <SheetDescription>{t('capture.ads.forms.description')}</SheetDescription>
                </SheetHeader>
                <SheetBody className="igr:space-y-4">
                    {forms.isError ? (
                        <ErrorState error={forms.error} onRetry={() => void forms.refetch()} />
                    ) : forms.isPending ? (
                        <>
                            <Skeleton className="igr:h-40" />
                            <Skeleton className="igr:h-40" />
                        </>
                    ) : forms.data.data.length === 0 ? (
                        <p className="igr:text-support igr:text-muted-foreground">
                            {source.platform === 'google' ? t('capture.ads.forms.emptyGoogle') : t('capture.ads.forms.empty')}
                        </p>
                    ) : (
                        forms.data.data.map((form) => <FormMapping key={form.id} source={source} form={form} canManage={canManage} />)
                    )}
                </SheetBody>
            </SheetContent>
        </Sheet>
    );
}

function FormMapping({ source, form, canManage }: { source: LeadAdSource; form: LeadAdForm; canManage: boolean }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const { saveFormMapping } = useLeadAdsMutations(workspace.id);
    const initial = Object.fromEntries(form.mapping.consent.map((c) => [c.key, c.channel])) as Record<string, ConsentChannel>;
    const [consent, setConsent] = useState<Record<string, ConsentChannel | ''>>(initial);
    const [service, setService] = useState(form.mapping.service_key ?? '');
    // En Meta, las casillas personalizadas; en Google, las preguntas (una pregunta de sí o no puede dar el permiso).
    const boxes =
        form.checkboxes.length > 0
            ? form.checkboxes.map((c) => ({ key: c.key, text: c.text, preChecked: c.checked_by_default }))
            : form.questions.map((q) => ({ key: q.key, text: q.label, preChecked: false }));
    const save = () =>
        saveFormMapping.mutate(
            {
                source: source.id,
                form: form.id,
                data: {
                    consent: Object.entries(consent)
                        .filter((e): e is [string, ConsentChannel] => e[1] !== '')
                        .map(([key, channel]) => ({ key, channel })),
                    service_key: service === '' ? null : service,
                },
            },
            { onSuccess: () => toast.success(t('capture.ads.forms.saved')) },
        );
    return (
        <Card>
            <CardHeader>
                <CardTitle className="igr:flex igr:flex-wrap igr:items-center igr:gap-2">
                    {form.name}
                    {form.status ? <Badge variant="outline">{form.status}</Badge> : null}
                </CardTitle>
            </CardHeader>
            <CardContent className="igr:space-y-4">
                <fieldset disabled={!canManage} className="igr:space-y-3">
                    <legend className="igr:text-[13px] igr:font-medium">{t('capture.ads.forms.consentTitle')}</legend>
                    <p className="igr:text-caption igr:text-muted-foreground">{t('capture.ads.forms.consentHelp')}</p>
                    {boxes.length === 0 ? <p className="igr:text-support igr:text-muted-foreground">{t('capture.ads.forms.noBoxes')}</p> : null}
                    {boxes.map((box) => (
                        <div key={box.key} className="igr:grid igr:gap-2 igr:sm:grid-cols-[minmax(0,1fr)_160px] igr:sm:items-center">
                            <p className="igr:text-[13px]">
                                {box.text}
                                {box.preChecked ? <span className="igr:mt-0.5 igr:block igr:text-caption igr:text-warning">{t('capture.ads.forms.preChecked')}</span> : null}
                            </p>
                            <Select
                                aria-label={t('capture.ads.forms.channelFor', { text: box.text })}
                                value={consent[box.key] ?? ''}
                                disabled={box.preChecked}
                                onChange={(e) => setConsent({ ...consent, [box.key]: e.target.value as ConsentChannel | '' })}
                            >
                                <option value="">{t('capture.ads.forms.noChannel')}</option>
                                {(['whatsapp', 'email', 'sms'] as const).map((c) => (
                                    <option key={c} value={c}>
                                        {t(`capture.channels.${c}`)}
                                    </option>
                                ))}
                            </Select>
                        </div>
                    ))}
                    {form.questions.length > 0 ? (
                        <div className="igr:space-y-1.5">
                            <label htmlFor={`svc-${form.id}`} className="igr:text-[13px] igr:font-medium">
                                {t('capture.ads.forms.service')}
                            </label>
                            <Select id={`svc-${form.id}`} value={service} onChange={(e) => setService(e.target.value)}>
                                <option value="">{t('capture.ads.forms.noService')}</option>
                                {form.questions.map((q) => (
                                    <option key={q.key} value={q.key}>
                                        {q.label}
                                    </option>
                                ))}
                            </Select>
                            <p className="igr:text-caption igr:text-muted-foreground">{t('capture.ads.forms.serviceHelp')}</p>
                        </div>
                    ) : null}
                </fieldset>
                {saveFormMapping.isError && saveFormMapping.variables.form === form.id ? <FieldError message={errorMessage(saveFormMapping.error, t)} /> : null}
            </CardContent>
            {canManage ? (
                <CardFooter>
                    <Button size="sm" loading={saveFormMapping.isPending && saveFormMapping.variables.form === form.id} onClick={save}>
                        {t('capture.ads.forms.save')}
                    </Button>
                </CardFooter>
            ) : null}
        </Card>
    );
}
