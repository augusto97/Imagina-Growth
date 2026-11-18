import { Check, Download, ExternalLink, Lock, OctagonAlert, RefreshCw, Sparkles, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/input';
import { DescriptionList, EmptyState, ErrorState, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { cn } from '@/lib/cn';
import { formatDate, formatDateTime, formatMoney, formatNumber } from '@/lib/format';
import { toast } from '@/stores/toast';
import { type BillingOverview, type Invoice, type PlanInfo, invoicePdfUrl, useBilling, useRefreshUsage, useRequestPlan } from './api';
import { billingBanner, comparablePlans, planPrice, splitMeters } from './model';
import { formatMeterValue, UsageMeter } from './UsageMeter';

export function BillingPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const query = useBilling(workspace.id);
    const refresh = useRefreshUsage(workspace.id);

    return (
        <div>
            <PageHeader
                title={t('billing.title')}
                description={t('billing.description')}
                actions={
                    <Button
                        variant="outline"
                        size="sm"
                        loading={refresh.isPending}
                        onClick={() =>
                            refresh.mutate(undefined, {
                                onSuccess: () => toast.success(t('billing.refreshed')),
                                onError: (e) => toast.error(t('errors.title'), isApiError(e) ? e.message : undefined),
                            })
                        }
                    >
                        <RefreshCw />
                        {t('billing.refresh')}
                    </Button>
                }
            />
            {query.isError ? (
                <ErrorState error={query.error} onRetry={() => void query.refetch()} />
            ) : query.isPending ? (
                <div className="igr:grid igr:gap-3 igr:md:grid-cols-2">
                    <Skeleton className="igr:h-40" />
                    <Skeleton className="igr:h-40" />
                </div>
            ) : (
                <Overview data={query.data} />
            )}
        </div>
    );
}

function Overview({ data }: { data: BillingOverview }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const locale = workspace.locale;
    const banner = billingBanner(data.account.billing_status, data.account.grace_ends_at, data.account.trial_ends_at);
    const { limited, counts } = splitMeters(data.usage.meters);

    return (
        <div className="igr:space-y-4">
            {banner ? (
                <div
                    role="status"
                    className={cn(
                        'igr:flex igr:items-start igr:gap-2 igr:rounded-md igr:border igr:px-3 igr:py-2.5 igr:text-sm',
                        banner.tone === 'critical' ? 'igr:border-destructive/40 igr:bg-destructive/5' : 'igr:border-warning/50 igr:bg-warning/10',
                    )}
                >
                    {banner.tone === 'critical' ? (
                        <OctagonAlert aria-hidden className="igr:mt-0.5 igr:size-4 igr:shrink-0 igr:text-destructive" />
                    ) : (
                        <TriangleAlert aria-hidden className="igr:mt-0.5 igr:size-4 igr:shrink-0 igr:text-warning" />
                    )}
                    <div>
                        <p className="igr:font-medium">{t(`billing.banner.${banner.kind}.title`)}</p>
                        <p className="igr:text-muted-foreground">
                            {t(`billing.banner.${banner.kind}.body`, {
                                date: formatDate(banner.until, locale, workspace.timezone),
                            })}
                        </p>
                    </div>
                </div>
            ) : null}

            <div className="igr:grid igr:gap-4 igr:lg:grid-cols-3">
                <PlanCard data={data} />
                <Card className="igr:lg:col-span-2">
                    <CardHeader>
                        <CardTitle>{t('billing.usage.title')}</CardTitle>
                        <CardDescription>
                            {t('billing.usage.description', {
                                period: data.usage.period,
                            })}
                            {data.usage.computed_at ? ` · ${t('billing.usage.computedAt', { at: formatDateTime(data.usage.computed_at, locale, workspace.timezone) })}` : ''}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="igr:space-y-5">
                        {limited.length > 0 ? (
                            limited.map((m) => <UsageMeter key={m.metric} meter={m} locale={locale} />)
                        ) : (
                            <p className="igr:text-sm igr:text-muted-foreground">{t('billing.usage.noLimits')}</p>
                        )}
                        <div className="igr:border-t igr:pt-4">
                            <p className="igr:mb-2 igr:text-xs igr:font-medium igr:uppercase igr:tracking-wide igr:text-muted-foreground">{t('billing.usage.counts')}</p>
                            <DescriptionList
                                items={counts.map((m) => ({
                                    label: t(`billing.metric.${m.metric}`),
                                    value: <span className="igr:tabular-nums">{formatMeterValue(m.metric, m.value, locale)}</span>,
                                }))}
                            />
                        </div>
                    </CardContent>
                </Card>
            </div>

            <ModulesCard data={data} />
            {data.invoices !== null ? <InvoicesCard invoices={data.invoices} /> : null}
            <ComparePlans data={data} />
        </div>
    );
}

function PlanCard({ data }: { data: BillingOverview }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const plan = data.plan;
    const status = data.account.billing_status;
    const price = plan ? planPrice(plan, workspace.currency) : null;

    return (
        <Card>
            <CardHeader>
                <CardDescription>{t('billing.plan.current')}</CardDescription>
                <CardTitle className="igr:text-lg">{plan ? plan.name : t('billing.plan.none')}</CardTitle>
            </CardHeader>
            <CardContent className="igr:space-y-3 igr:text-sm">
                {plan && price ? (
                    <>
                        <p>
                            <span className="igr:text-2xl igr:font-semibold igr:tabular-nums">{formatMoney(price.amount, price.currency, workspace.locale)}</span>
                            <span className="igr:text-muted-foreground"> {t('billing.plan.perMonth')}</span>
                        </p>
                        <p className="igr:text-muted-foreground">
                            {t(`billing.plan.included_${plan.metric}`, {
                                count: plan.included,
                                n: formatNumber(plan.included, workspace.locale),
                            })}
                        </p>
                    </>
                ) : (
                    <p className="igr:text-muted-foreground">{t('billing.plan.noneHelp')}</p>
                )}
                <div className="igr:flex igr:items-center igr:gap-2">
                    <span className="igr:text-muted-foreground">{t('billing.statusLabel')}</span>
                    <Badge variant={status === 'suspended' ? 'destructive' : status === 'grace' ? 'warning' : status === 'trial' ? 'secondary' : 'success'}>
                        {t(`billing.status.${status}`)}
                    </Badge>
                </div>
                {data.pending_request ? (
                    <p className="igr:rounded-md igr:bg-muted/50 igr:px-2.5 igr:py-2 igr:text-xs">
                        {t('billing.compare.pending', {
                            plan: data.plans.find((p) => p.id === data.pending_request?.to_plan)?.name ?? data.pending_request.to_plan,
                        })}
                    </p>
                ) : null}
            </CardContent>
        </Card>
    );
}

function ModulesCard({ data }: { data: BillingOverview }) {
    const { t } = useTranslation();
    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('billing.modules.title')}</CardTitle>
                <CardDescription>{t('billing.modules.description')}</CardDescription>
            </CardHeader>
            <CardContent>
                <ul className="igr:grid igr:gap-x-6 igr:gap-y-2 igr:sm:grid-cols-2 igr:lg:grid-cols-3">
                    {data.modules.map((m) => (
                        <li key={m.key} className="igr:flex igr:items-center igr:gap-2 igr:text-sm">
                            {m.enabled ? <Check aria-hidden className="igr:size-4 igr:text-success" /> : <Lock aria-hidden className="igr:size-4 igr:text-muted-foreground" />}
                            <span className={m.enabled ? '' : 'igr:text-muted-foreground'}>{t(`billing.module.${m.key}`)}</span>
                            <span className="igr:sr-only">{m.enabled ? t('billing.modules.active') : t('billing.modules.inactive')}</span>
                            {m.enabled && !m.in_plan ? <Badge variant="outline">{t('billing.modules.forced')}</Badge> : null}
                        </li>
                    ))}
                </ul>
            </CardContent>
        </Card>
    );
}

function InvoicesCard({ invoices }: { invoices: Invoice[] }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const tone = (s: Invoice['status']) => (s === 'paid' ? 'success' : s === 'overdue' ? 'destructive' : s === 'void' ? 'outline' : 'secondary');

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('billing.invoices.title')}</CardTitle>
            </CardHeader>
            <CardContent>
                {invoices.length === 0 ? (
                    <EmptyState icon={Sparkles} title={t('billing.invoices.empty')} />
                ) : (
                    <Table>
                        <THead>
                            <TR>
                                <TH>{t('billing.invoices.number')}</TH>
                                <TH>{t('billing.invoices.period')}</TH>
                                <TH className="igr:text-right">{t('billing.invoices.total')}</TH>
                                <TH>{t('billing.invoices.status')}</TH>
                                <TH>{t('billing.invoices.due')}</TH>
                                <TH className="igr:text-right">
                                    <span className="igr:sr-only">{t('billing.invoices.actions')}</span>
                                </TH>
                            </TR>
                        </THead>
                        <TBody>
                            {invoices.map((inv) => (
                                <TR key={inv.id}>
                                    <TD className="igr:font-medium">{inv.number}</TD>
                                    <TD>{inv.period}</TD>
                                    <TD className="igr:text-right igr:tabular-nums">{formatMoney(inv.total, inv.currency, workspace.locale)}</TD>
                                    <TD>
                                        <Badge variant={tone(inv.status)}>{t(`billing.invoiceStatus.${inv.status}`)}</Badge>
                                    </TD>
                                    <TD>{formatDate(inv.due_at, workspace.locale, workspace.timezone)}</TD>
                                    <TD className="igr:text-right">
                                        <div className="igr:flex igr:justify-end igr:gap-1.5">
                                            {inv.payment_url && (inv.status === 'sent' || inv.status === 'overdue') ? (
                                                <Button asChild size="sm">
                                                    <a href={inv.payment_url} target="_blank" rel="noopener noreferrer">
                                                        <ExternalLink />
                                                        {t('billing.invoices.pay')}
                                                    </a>
                                                </Button>
                                            ) : null}
                                            {inv.has_pdf ? (
                                                <Button asChild size="sm" variant="outline">
                                                    <a href={invoicePdfUrl(workspace.id, inv.id)} download>
                                                        <Download />
                                                        {t('billing.invoices.pdf')}
                                                    </a>
                                                </Button>
                                            ) : null}
                                        </div>
                                    </TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                )}
            </CardContent>
        </Card>
    );
}

const LIMIT_ROWS = ['emails', 'ai_budget_usd', 'users', 'api_requests_per_minute'] as const;

function ComparePlans({ data }: { data: BillingOverview }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const [target, setTarget] = useState<PlanInfo | null>(null);
    const plans = comparablePlans(data.plans, data.plan);
    if (plans.length === 0) return null;
    const n = (v: number) => formatNumber(v, workspace.locale);

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('billing.compare.title')}</CardTitle>
                <CardDescription>{t('billing.compare.description')}</CardDescription>
            </CardHeader>
            <CardContent className="igr:overflow-x-auto">
                <Table>
                    <THead>
                        <TR>
                            <TH>
                                <span className="igr:sr-only">{t('billing.compare.feature')}</span>
                            </TH>
                            {plans.map((p) => (
                                <TH key={p.id} className="igr:min-w-36">
                                    <div className="igr:flex igr:flex-col igr:gap-1">
                                        <span className="igr:text-foreground">{p.name}</span>
                                        {data.plan?.id === p.id ? <Badge className="igr:w-fit">{t('billing.compare.current')}</Badge> : null}
                                    </div>
                                </TH>
                            ))}
                        </TR>
                    </THead>
                    <TBody>
                        <TR>
                            <TD className="igr:text-muted-foreground">{t('billing.compare.price')}</TD>
                            {plans.map((p) => {
                                const price = planPrice(p, workspace.currency);
                                return (
                                    <TD key={p.id} className="igr:tabular-nums igr:font-medium">
                                        {formatMoney(price.amount, price.currency, workspace.locale)}
                                    </TD>
                                );
                            })}
                        </TR>
                        <TR>
                            <TD className="igr:text-muted-foreground">{t(`billing.metric.${plans[0]?.metric ?? 'orders'}`)}</TD>
                            {plans.map((p) => (
                                <TD key={p.id} className="igr:tabular-nums">
                                    {n(p.included)}
                                </TD>
                            ))}
                        </TR>
                        {LIMIT_ROWS.map((row) => (
                            <TR key={row}>
                                <TD className="igr:text-muted-foreground">{t(`billing.limit.${row}`)}</TD>
                                {plans.map((p) => (
                                    <TD key={p.id} className="igr:tabular-nums">
                                        {p.limits[row] !== undefined ? (row === 'ai_budget_usd' ? formatMoney(p.limits[row], 'USD', workspace.locale) : n(p.limits[row])) : '—'}
                                    </TD>
                                ))}
                            </TR>
                        ))}
                        {data.modules.map((m) => (
                            <TR key={m.key}>
                                <TD className="igr:text-muted-foreground">{t(`billing.module.${m.key}`)}</TD>
                                {plans.map((p) => (
                                    <TD key={p.id}>
                                        {p.modules.includes(m.key) ? (
                                            <>
                                                <Check aria-hidden className="igr:size-4 igr:text-success" />
                                                <span className="igr:sr-only">{t('billing.compare.included')}</span>
                                            </>
                                        ) : (
                                            <span className="igr:text-muted-foreground" aria-label={t('billing.compare.notIncluded')}>
                                                —
                                            </span>
                                        )}
                                    </TD>
                                ))}
                            </TR>
                        ))}
                        {data.can_manage_billing ? (
                            <TR>
                                <TD />
                                {plans.map((p) => (
                                    <TD key={p.id}>
                                        {data.plan?.id === p.id ? null : (
                                            <Button
                                                size="sm"
                                                variant={data.pending_request?.to_plan === p.id ? 'secondary' : 'outline'}
                                                disabled={data.pending_request?.to_plan === p.id}
                                                onClick={() => setTarget(p)}
                                            >
                                                {data.pending_request?.to_plan === p.id ? t('billing.compare.requested') : t('billing.compare.request')}
                                            </Button>
                                        )}
                                    </TD>
                                ))}
                            </TR>
                        ) : null}
                    </TBody>
                </Table>
            </CardContent>
            <RequestPlanDialog plan={target} onClose={() => setTarget(null)} />
        </Card>
    );
}

function RequestPlanDialog({ plan, onClose }: { plan: PlanInfo | null; onClose: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const request = useRequestPlan(workspace.id);
    const [note, setNote] = useState('');

    return (
        <Dialog open={plan !== null} onOpenChange={(open) => (!open ? onClose() : undefined)}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {t('billing.compare.dialogTitle', {
                            plan: plan?.name ?? '',
                        })}
                    </DialogTitle>
                    <DialogDescription>{t('billing.compare.dialogBody')}</DialogDescription>
                </DialogHeader>
                <label className="igr:space-y-1.5 igr:text-sm">
                    <span className="igr:font-medium">{t('billing.compare.note')}</span>
                    <Textarea value={note} maxLength={500} rows={3} onChange={(e) => setNote(e.target.value)} />
                </label>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    <Button
                        loading={request.isPending}
                        onClick={() =>
                            plan &&
                            request.mutate(
                                {
                                    plan_id: plan.id,
                                    ...(note.trim() !== '' ? { note: note.trim() } : {}),
                                },
                                {
                                    onSuccess: () => {
                                        toast.success(t('billing.compare.sent'));
                                        setNote('');
                                        onClose();
                                    },
                                    onError: (e) => toast.error(t('errors.title'), isApiError(e) ? e.message : undefined),
                                },
                            )
                        }
                    >
                        {t('billing.compare.send')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
