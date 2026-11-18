import { Download, FilePlus2, ShieldAlert } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input, Select } from '@/components/ui/input';
import { EmptyState, ErrorState, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import type { BillingStatus } from '@/features/billing/api';
import { MeterStatus, formatMeterValue } from '@/features/billing/UsageMeter';
import { useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { toast } from '@/stores/toast';
import {
    type InvoiceDraft,
    type PlatformAccount,
    type PlatformOverview,
    draftTotal,
    lagState,
    platformInvoicePdfUrl,
    useAssignPlan,
    useCreateInvoice,
    useInvoiceAction,
    useInvoiceDraft,
    usePlanRequestAction,
    usePlatformHealth,
    usePlatformOverview,
    useSetBillingStatus,
    useSetFeature,
} from './api';
import { UpdatesTab } from './UpdatesTab';

const STATUSES: BillingStatus[] = ['trial', 'active', 'grace', 'suspended'];

export function PlatformPage() {
    const { t } = useTranslation();
    const { me } = useWorkspace();
    const isAdmin = me.user.is_platform_admin;
    const overview = usePlatformOverview(isAdmin);

    if (!isAdmin) {
        return <EmptyState icon={ShieldAlert} title={t('platform.forbidden')} description={t('platform.forbiddenHelp')} />;
    }

    return (
        <div>
            <PageHeader title={t('platform.title')} description={t('platform.description')} />
            <Tabs defaultValue="accounts">
                <TabsList>
                    <TabsTrigger value="accounts">{t('platform.tabs.accounts')}</TabsTrigger>
                    <TabsTrigger value="health">{t('platform.tabs.health')}</TabsTrigger>
                    <TabsTrigger value="updates">{t('platform.tabs.updates')}</TabsTrigger>
                    <TabsTrigger value="invoices">{t('platform.tabs.invoices')}</TabsTrigger>
                    <TabsTrigger value="requests">
                        {t('platform.tabs.requests')}
                        {overview.data && overview.data.plan_requests.length > 0 ? <Badge className="igr:ml-1.5">{overview.data.plan_requests.length}</Badge> : null}
                    </TabsTrigger>
                </TabsList>
                <TabsContent value="health" className="igr:mt-4">
                    <HealthTab />
                </TabsContent>
                <TabsContent value="updates" className="igr:mt-4">
                    <UpdatesTab />
                </TabsContent>
                {overview.isError ? (
                    <ErrorState error={overview.error} onRetry={() => void overview.refetch()} />
                ) : overview.isPending ? (
                    <Skeleton className="igr:mt-4 igr:h-64" />
                ) : (
                    <>
                        <TabsContent value="accounts" className="igr:mt-4">
                            <AccountsTab data={overview.data} />
                        </TabsContent>
                        <TabsContent value="invoices" className="igr:mt-4">
                            <InvoicesTab data={overview.data} />
                        </TabsContent>
                        <TabsContent value="requests" className="igr:mt-4">
                            <RequestsTab data={overview.data} />
                        </TabsContent>
                    </>
                )}
            </Tabs>
        </div>
    );
}

function useFail() {
    const { t } = useTranslation();
    return (e: unknown) => toast.error(t('errors.title'), isApiError(e) ? e.message : undefined);
}

function AccountsTab({ data }: { data: PlatformOverview }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const assign = useAssignPlan();
    const setStatus = useSetBillingStatus();
    const fail = useFail();
    const [open, setOpen] = useState<string | null>(null);
    const [invoiceFor, setInvoiceFor] = useState<PlatformAccount | null>(null);

    if (data.accounts.length === 0) return <EmptyState icon={ShieldAlert} title={t('platform.accounts.empty')} />;

    return (
        <Card>
            <CardContent className="igr:overflow-x-auto igr:pt-4">
                <Table>
                    <THead>
                        <TR>
                            <TH>{t('platform.accounts.account')}</TH>
                            <TH>{t('platform.accounts.plan')}</TH>
                            <TH>{t('platform.accounts.status')}</TH>
                            <TH>{t('platform.accounts.usage')}</TH>
                            <TH className="igr:text-right">{t('platform.accounts.invoices')}</TH>
                            <TH>
                                <span className="igr:sr-only">{t('platform.accounts.actions')}</span>
                            </TH>
                        </TR>
                    </THead>
                    <TBody>
                        {data.accounts.map((a) => (
                            <AccountRows
                                key={a.id}
                                account={a}
                                data={data}
                                open={open === a.id}
                                onToggle={() => setOpen(open === a.id ? null : a.id)}
                                onPlan={(plan) =>
                                    assign.mutate(
                                        { account: a.id, plan_id: plan },
                                        {
                                            onSuccess: () => toast.success(t('platform.saved')),
                                            onError: fail,
                                        },
                                    )
                                }
                                onStatus={(status) =>
                                    setStatus.mutate(
                                        { account: a.id, status },
                                        {
                                            onSuccess: () => toast.success(t('platform.saved')),
                                            onError: fail,
                                        },
                                    )
                                }
                                onInvoice={() => setInvoiceFor(a)}
                                locale={workspace.locale}
                            />
                        ))}
                    </TBody>
                </Table>
            </CardContent>
            <CreateInvoiceDialog account={invoiceFor} onClose={() => setInvoiceFor(null)} />
        </Card>
    );
}

function AccountRows(props: {
    account: PlatformAccount;
    data: PlatformOverview;
    open: boolean;
    locale: string;
    onToggle: () => void;
    onPlan: (plan: string | null) => void;
    onStatus: (s: BillingStatus) => void;
    onInvoice: () => void;
}) {
    const { t } = useTranslation();
    const { account: a, data, locale } = props;
    const setFeature = useSetFeature();
    const fail = useFail();
    const worst = a.workspaces.reduce<'ok' | 'warning' | 'critical'>(
        (w, ws) => (ws.usage.state === 'critical' || w === 'critical' ? 'critical' : ws.usage.state === 'warning' || w === 'warning' ? 'warning' : 'ok'),
        'ok',
    );

    return (
        <>
            <TR>
                <TD>
                    <button type="button" className="igr:text-left igr:font-medium igr:hover:underline" aria-expanded={props.open} onClick={props.onToggle}>
                        {a.name}
                    </button>
                    <div className="igr:text-xs igr:text-muted-foreground">
                        {t('platform.accounts.workspaces', {
                            count: a.workspaces.length,
                        })}
                    </div>
                </TD>
                <TD>
                    <Select
                        aria-label={t('platform.accounts.plan')}
                        value={a.plan_id ?? ''}
                        onChange={(e) => props.onPlan(e.target.value === '' ? null : e.target.value)}
                        className="igr:h-7 igr:w-44 igr:text-xs"
                    >
                        <option value="">{t('platform.accounts.noPlan')}</option>
                        {data.plans.map((p) => (
                            <option key={p.id} value={p.id}>
                                {p.name}
                                {p.public ? '' : ` (${t('platform.accounts.internal')})`}
                            </option>
                        ))}
                    </Select>
                </TD>
                <TD>
                    <Select
                        aria-label={t('platform.accounts.status')}
                        value={a.billing_status}
                        onChange={(e) => props.onStatus(e.target.value as BillingStatus)}
                        className="igr:h-7 igr:w-40 igr:text-xs"
                    >
                        {STATUSES.map((s) => (
                            <option key={s} value={s}>
                                {t(`billing.status.${s}`)}
                            </option>
                        ))}
                    </Select>
                    {a.grace_ends_at ? (
                        <div className="igr:mt-0.5 igr:text-xs igr:text-muted-foreground">
                            {t('platform.accounts.graceUntil', {
                                date: formatDate(a.grace_ends_at, locale),
                            })}
                        </div>
                    ) : null}
                </TD>
                <TD>
                    <MeterStatus state={worst} />
                </TD>
                <TD className="igr:text-right igr:tabular-nums">
                    {a.open_invoices}
                    {a.overdue_invoices > 0 ? (
                        <Badge variant="destructive" className="igr:ml-1.5">
                            {t('platform.accounts.overdue', {
                                count: a.overdue_invoices,
                            })}
                        </Badge>
                    ) : null}
                </TD>
                <TD className="igr:text-right">
                    <Button size="sm" variant="outline" onClick={props.onInvoice}>
                        <FilePlus2 />
                        {t('platform.invoices.create')}
                    </Button>
                </TD>
            </TR>
            {props.open
                ? a.workspaces.map((ws) => (
                      <TR key={ws.id} className="igr:bg-muted/30">
                          <TD className="igr:pl-6 igr:text-xs igr:text-muted-foreground">{ws.name}</TD>
                          <TD colSpan={2} className="igr:text-xs">
                              <span className="igr:text-muted-foreground">{t(`billing.metric.${ws.usage.metric}`)}: </span>
                              <span className="igr:tabular-nums">
                                  {formatMeterValue(ws.usage.metric, ws.usage.value, locale)}
                                  {ws.usage.limit !== null ? ` / ${formatMeterValue(ws.usage.metric, ws.usage.limit, locale)}` : ''}
                              </span>{' '}
                              <MeterStatus state={ws.usage.state} className="igr:ml-1" />
                              <div>
                                  <span className="igr:text-muted-foreground">{t('platform.accounts.ai')}: </span>
                                  <span className="igr:tabular-nums">{formatMoney(ws.ai.value, 'USD', locale)}</span>
                              </div>
                          </TD>
                          <TD colSpan={3}>
                              <div className="igr:flex igr:flex-wrap igr:gap-1.5">
                                  {data.modules.map((m) => {
                                      const forced = ws.overrides[m];
                                      return (
                                          <Select
                                              key={m}
                                              aria-label={t(`billing.module.${m}`)}
                                              value={forced === undefined ? 'plan' : forced ? 'on' : 'off'}
                                              onChange={(e) =>
                                                  setFeature.mutate(
                                                      {
                                                          workspace: ws.id,
                                                          feature: m,
                                                          enabled: e.target.value === 'plan' ? null : e.target.value === 'on',
                                                      },
                                                      {
                                                          onSuccess: () => toast.success(t('platform.saved')),
                                                          onError: fail,
                                                      },
                                                  )
                                              }
                                              className="igr:h-7 igr:w-auto igr:text-xs"
                                          >
                                              <option value="plan">
                                                  {t('platform.features.plan', {
                                                      module: t(`billing.module.${m}`),
                                                  })}
                                              </option>
                                              <option value="on">
                                                  {t('platform.features.on', {
                                                      module: t(`billing.module.${m}`),
                                                  })}
                                              </option>
                                              <option value="off">
                                                  {t('platform.features.off', {
                                                      module: t(`billing.module.${m}`),
                                                  })}
                                              </option>
                                          </Select>
                                      );
                                  })}
                              </div>
                          </TD>
                      </TR>
                  ))
                : null}
        </>
    );
}

function CreateInvoiceDialog({ account, onClose }: { account: PlatformAccount | null; onClose: () => void }) {
    const { t } = useTranslation();
    const draft = useInvoiceDraft(account?.id ?? null);

    return (
        <Dialog open={account !== null} onOpenChange={(o) => (!o ? onClose() : undefined)}>
            <DialogContent className="igr:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        {t('platform.invoices.createFor', {
                            account: account?.name ?? '',
                        })}
                    </DialogTitle>
                    <DialogDescription>{t('platform.invoices.createHelp')}</DialogDescription>
                </DialogHeader>
                {draft.isError ? (
                    <ErrorState error={draft.error} onRetry={() => void draft.refetch()} />
                ) : account === null || draft.data === undefined ? (
                    <Skeleton className="igr:h-24" />
                ) : (
                    <InvoiceForm key={account.id} account={account.id} initial={draft.data} onDone={onClose} />
                )}
            </DialogContent>
        </Dialog>
    );
}

function InvoiceForm({ account, initial, onDone }: { account: string; initial: InvoiceDraft; onDone: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const create = useCreateInvoice();
    const fail = useFail();
    const [draft, setDraft] = useState<InvoiceDraft>(
        initial.lines.length > 0
            ? initial
            : {
                  ...initial,
                  lines: [{ concept: '', quantity: 1, unit_price: '0' }],
              },
    );
    const setLine = (i: number, patch: Partial<InvoiceDraft['lines'][number]>) =>
        setDraft((d) => ({
            ...d,
            lines: d.lines.map((l, j) => (j === i ? { ...l, ...patch } : l)),
        }));
    const submit = (send: boolean) =>
        create.mutate(
            {
                account,
                period: draft.period,
                currency: draft.currency,
                lines: draft.lines,
                send,
            },
            {
                onSuccess: () => {
                    toast.success(t(send ? 'platform.invoices.createdSent' : 'platform.invoices.created'));
                    onDone();
                },
                onError: fail,
            },
        );

    return (
        <>
            <div className="igr:space-y-3 igr:text-sm">
                <div className="igr:flex igr:gap-3">
                    <label className="igr:space-y-1">
                        <span className="igr:text-xs igr:text-muted-foreground">{t('platform.invoices.period')}</span>
                        <Input value={draft.period} onChange={(e) => setDraft({ ...draft, period: e.target.value })} className="igr:w-28" />
                    </label>
                    <label className="igr:space-y-1">
                        <span className="igr:text-xs igr:text-muted-foreground">{t('platform.invoices.currency')}</span>
                        <Select value={draft.currency} onChange={(e) => setDraft({ ...draft, currency: e.target.value })} className="igr:w-24">
                            {['COP', 'USD', 'MXN'].map((c) => (
                                <option key={c}>{c}</option>
                            ))}
                        </Select>
                    </label>
                </div>
                {draft.lines.map((l, i) => (
                    <div key={i} className="igr:grid igr:grid-cols-[1fr_5rem_8rem] igr:gap-2">
                        <Input aria-label={t('platform.invoices.concept')} value={l.concept} onChange={(e) => setLine(i, { concept: e.target.value })} />
                        <Input
                            aria-label={t('platform.invoices.quantity')}
                            type="number"
                            min={1}
                            value={l.quantity}
                            onChange={(e) => setLine(i, { quantity: Number(e.target.value) })}
                        />
                        <Input
                            aria-label={t('platform.invoices.unitPrice')}
                            type="number"
                            min={0}
                            value={l.unit_price}
                            onChange={(e) => setLine(i, { unit_price: e.target.value })}
                        />
                    </div>
                ))}
                <div className="igr:flex igr:items-center igr:justify-between">
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() =>
                            setDraft({
                                ...draft,
                                lines: [
                                    ...draft.lines,
                                    {
                                        concept: '',
                                        quantity: 1,
                                        unit_price: '0',
                                    },
                                ],
                            })
                        }
                    >
                        {t('platform.invoices.addLine')}
                    </Button>
                    <span className="igr:font-medium igr:tabular-nums">
                        {t('platform.invoices.totalIs', {
                            total: formatMoney(draftTotal(draft.lines), draft.currency, workspace.locale),
                        })}
                    </span>
                </div>
            </div>
            <DialogFooter>
                <Button variant="outline" loading={create.isPending && !create.variables.send} onClick={() => submit(false)}>
                    {t('platform.invoices.saveDraft')}
                </Button>
                <Button loading={create.isPending && create.variables.send} onClick={() => submit(true)}>
                    {t('platform.invoices.issueAndSend')}
                </Button>
            </DialogFooter>
        </>
    );
}

function HealthTab() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const health = usePlatformHealth(true);
    const n = (v: number) => formatNumber(v, workspace.locale);
    if (health.isError) return <ErrorState error={health.error} onRetry={() => void health.refetch()} />;
    if (health.isPending) return <Skeleton className="igr:h-64" />;
    const h = health.data;

    return (
        <div className="igr:grid igr:gap-4 igr:lg:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle>{t('platform.health.ingest')}</CardTitle>
                    <CardDescription>{t('platform.health.ingestHelp')}</CardDescription>
                </CardHeader>
                <CardContent>
                    {h.ingest === null ? (
                        <p className="igr:text-sm igr:text-muted-foreground">{t('platform.health.unavailable')}</p>
                    ) : (
                        <Table>
                            <THead>
                                <TR>
                                    <TH>{t('platform.health.shard')}</TH>
                                    <TH className="igr:text-right">{t('platform.health.length')}</TH>
                                    <TH className="igr:text-right">{t('platform.health.pending')}</TH>
                                    <TH className="igr:text-right">{t('platform.health.lag')}</TH>
                                    <TH>{t('platform.health.state')}</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {h.ingest.map((s) => (
                                    <TR key={s.shard}>
                                        <TD>{s.shard}</TD>
                                        <TD className="igr:text-right igr:tabular-nums">{n(s.length)}</TD>
                                        <TD className="igr:text-right igr:tabular-nums">{n(s.pending)}</TD>
                                        <TD className="igr:text-right igr:tabular-nums">
                                            {t('platform.health.seconds', {
                                                n: n(s.lag_seconds),
                                            })}
                                        </TD>
                                        <TD>
                                            <MeterStatus state={lagState(s.lag_seconds)} />
                                        </TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                    )}
                </CardContent>
            </Card>
            <CountCard
                title={t('platform.health.rejections')}
                empty={t('platform.health.none')}
                rows={h.rejections_24h.map((r) => ({
                    label: r.reason,
                    count: r.count,
                }))}
                n={n}
            />
            <CountCard
                title={t('platform.health.failedDeliveries')}
                empty={t('platform.health.none')}
                rows={h.failed_deliveries_24h.map((r) => ({
                    label: `${r.channel} · ${r.provider ?? t('platform.health.noProvider')}`,
                    count: r.count,
                }))}
                n={n}
            />
            <CountCard
                title={t('platform.health.conversionFailures')}
                empty={h.conversion_failures_24h === null ? t('platform.health.unavailable') : t('platform.health.none')}
                rows={(h.conversion_failures_24h ?? []).map((r) => ({
                    label: `${r.platform} · ${r.workspace ?? '—'}`,
                    count: r.count,
                }))}
                n={n}
            />
            <Card className="igr:lg:col-span-2">
                <CardHeader>
                    <CardTitle>{t('platform.health.aiCost')}</CardTitle>
                    <CardDescription>{t('platform.health.aiCostHelp')}</CardDescription>
                </CardHeader>
                <CardContent>
                    {h.ai_cost_month.length === 0 ? (
                        <p className="igr:text-sm igr:text-muted-foreground">{t('platform.health.none')}</p>
                    ) : (
                        <Table>
                            <THead>
                                <TR>
                                    <TH>{t('platform.health.workspace')}</TH>
                                    <TH className="igr:text-right">{t('platform.health.cost')}</TH>
                                    <TH className="igr:text-right">{t('platform.health.budget')}</TH>
                                    <TH className="igr:text-right">{t('platform.health.used')}</TH>
                                    <TH>{t('platform.health.state')}</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {h.ai_cost_month.map((r) => (
                                    <TR key={r.workspace_id}>
                                        <TD>{r.workspace}</TD>
                                        <TD className="igr:text-right igr:tabular-nums">{formatMoney(r.cost_usd, 'USD', workspace.locale)}</TD>
                                        <TD className="igr:text-right igr:tabular-nums">{r.budget_usd !== null ? formatMoney(r.budget_usd, 'USD', workspace.locale) : '—'}</TD>
                                        <TD className="igr:text-right igr:tabular-nums">{r.pct !== null ? `${r.pct} %` : '—'}</TD>
                                        <TD>
                                            <MeterStatus state={r.pct === null ? 'ok' : r.pct >= 100 ? 'critical' : r.pct >= 80 ? 'warning' : 'ok'} />
                                        </TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}

function CountCard({ title, rows, empty, n }: { title: string; rows: { label: string; count: number }[]; empty: string; n: (v: number) => string }) {
    const { t } = useTranslation();
    return (
        <Card>
            <CardHeader>
                <CardTitle>{title}</CardTitle>
                <CardDescription>{t('platform.health.last24h')}</CardDescription>
            </CardHeader>
            <CardContent>
                {rows.length === 0 ? (
                    <p className="igr:text-sm igr:text-muted-foreground">{empty}</p>
                ) : (
                    <Table>
                        <TBody>
                            {rows.map((r) => (
                                <TR key={r.label}>
                                    <TD>{r.label}</TD>
                                    <TD className="igr:text-right igr:tabular-nums">{n(r.count)}</TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                )}
            </CardContent>
        </Card>
    );
}

function InvoicesTab({ data }: { data: PlatformOverview }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const action = useInvoiceAction();
    const fail = useFail();
    if (data.invoices.length === 0) return <EmptyState icon={FilePlus2} title={t('platform.invoices.empty')} description={t('platform.invoices.emptyHelp')} />;
    const run = (invoice: string, a: 'send' | 'paid' | 'void') =>
        action.mutate(
            { invoice, action: a },
            {
                onSuccess: () => toast.success(t('platform.saved')),
                onError: fail,
            },
        );

    return (
        <Card>
            <CardContent className="igr:overflow-x-auto igr:pt-4">
                <Table>
                    <THead>
                        <TR>
                            <TH>{t('billing.invoices.number')}</TH>
                            <TH>{t('platform.accounts.account')}</TH>
                            <TH>{t('billing.invoices.period')}</TH>
                            <TH className="igr:text-right">{t('billing.invoices.total')}</TH>
                            <TH>{t('billing.invoices.status')}</TH>
                            <TH>
                                <span className="igr:sr-only">{t('platform.accounts.actions')}</span>
                            </TH>
                        </TR>
                    </THead>
                    <TBody>
                        {data.invoices.map((inv) => (
                            <TR key={inv.id}>
                                <TD className="igr:font-medium">{inv.number}</TD>
                                <TD>{inv.account ?? '—'}</TD>
                                <TD>{inv.period}</TD>
                                <TD className="igr:text-right igr:tabular-nums">{formatMoney(inv.total, inv.currency, workspace.locale)}</TD>
                                <TD>
                                    <Badge variant={inv.status === 'paid' ? 'success' : inv.status === 'overdue' ? 'destructive' : inv.status === 'void' ? 'outline' : 'secondary'}>
                                        {t(`billing.invoiceStatus.${inv.status}`)}
                                    </Badge>
                                </TD>
                                <TD>
                                    <div className="igr:flex igr:justify-end igr:gap-1.5">
                                        {inv.status === 'draft' || inv.status === 'sent' || inv.status === 'overdue' ? (
                                            <Button size="sm" variant="outline" onClick={() => run(inv.id, 'send')}>
                                                {inv.status === 'draft' ? t('platform.invoices.send') : t('platform.invoices.resend')}
                                            </Button>
                                        ) : null}
                                        {inv.status === 'sent' || inv.status === 'overdue' || inv.status === 'draft' ? (
                                            <Button size="sm" onClick={() => run(inv.id, 'paid')}>
                                                {t('platform.invoices.markPaid')}
                                            </Button>
                                        ) : null}
                                        {inv.status !== 'paid' && inv.status !== 'void' ? (
                                            <Button size="sm" variant="ghost" onClick={() => run(inv.id, 'void')}>
                                                {t('platform.invoices.void')}
                                            </Button>
                                        ) : null}
                                        {inv.has_pdf ? (
                                            <Button asChild size="sm" variant="ghost">
                                                <a href={platformInvoicePdfUrl(inv.id)} download aria-label={t('billing.invoices.pdf')}>
                                                    <Download />
                                                </a>
                                            </Button>
                                        ) : null}
                                    </div>
                                </TD>
                            </TR>
                        ))}
                    </TBody>
                </Table>
            </CardContent>
        </Card>
    );
}

function RequestsTab({ data }: { data: PlatformOverview }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const act = usePlanRequestAction();
    const fail = useFail();
    const planName = (id: string | null) => (id === null ? t('platform.accounts.noPlan') : (data.plans.find((p) => p.id === id)?.name ?? id));
    if (data.plan_requests.length === 0) return <EmptyState icon={ShieldAlert} title={t('platform.requests.empty')} />;

    return (
        <Card>
            <CardContent className="igr:pt-4">
                <Table>
                    <THead>
                        <TR>
                            <TH>{t('platform.accounts.account')}</TH>
                            <TH>{t('platform.requests.change')}</TH>
                            <TH>{t('platform.requests.note')}</TH>
                            <TH>{t('platform.requests.date')}</TH>
                            <TH>
                                <span className="igr:sr-only">{t('platform.accounts.actions')}</span>
                            </TH>
                        </TR>
                    </THead>
                    <TBody>
                        {data.plan_requests.map((r) => (
                            <TR key={r.id}>
                                <TD className="igr:font-medium">{r.account ?? '—'}</TD>
                                <TD>
                                    {t('platform.requests.fromTo', {
                                        from: planName(r.from_plan),
                                        to: planName(r.to_plan),
                                    })}
                                </TD>
                                <TD className="igr:max-w-64 igr:truncate igr:text-muted-foreground">{r.note ?? '—'}</TD>
                                <TD>{formatDate(r.created_at, workspace.locale)}</TD>
                                <TD>
                                    <div className="igr:flex igr:justify-end igr:gap-1.5">
                                        <Button
                                            size="sm"
                                            onClick={() =>
                                                act.mutate(
                                                    {
                                                        id: r.id,
                                                        action: 'apply',
                                                    },
                                                    {
                                                        onSuccess: () => toast.success(t('platform.saved')),
                                                        onError: fail,
                                                    },
                                                )
                                            }
                                        >
                                            {t('platform.requests.apply')}
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() =>
                                                act.mutate(
                                                    {
                                                        id: r.id,
                                                        action: 'reject',
                                                    },
                                                    {
                                                        onSuccess: () => toast.success(t('platform.saved')),
                                                        onError: fail,
                                                    },
                                                )
                                            }
                                        >
                                            {t('platform.requests.reject')}
                                        </Button>
                                    </div>
                                </TD>
                            </TR>
                        ))}
                    </TBody>
                </Table>
            </CardContent>
        </Card>
    );
}
