import { CircleCheck, CircleMinus, Copy, Pause, Pencil, Play, Trophy, Wallet, XCircle } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useParams } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { HeroCard } from '@/components/ui/hero-card';
import { ErrorState, FieldError, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { Tooltip } from '@/components/ui/tooltip';
import { CostNote, formatCost, formatCostPerSale, formatRoi } from '@/features/analytics/cost';
import { errorMessage } from '@/features/contacts/errors';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { formatDateTime, formatMoney, formatNumber, formatPercent } from '@/lib/format';
import { useCampaignActions, useCampaignReport } from './api';
import { ChannelLabel, StatusBadge } from './labels';
import type { CampaignDetail, CampaignReport } from './types';
import { AutoBreadcrumbs } from '@/components/ui/page-header';

/** Embudo de entrega: cada paso como proporción de los enviados. */
export function funnelSteps(report: CampaignReport, channel: CampaignDetail['channel']) {
    const d = report.delivery;
    const base = d.sent;
    const steps = [
        { key: 'sent', value: d.sent },
        { key: 'delivered', value: d.delivered },
        { key: channel === 'email' ? 'opened' : 'read', value: channel === 'email' ? d.opened : d.read },
        { key: 'clicked', value: d.clicked },
    ];
    return steps.map((s) => ({ ...s, ratio: base > 0 ? s.value / base : 0 }));
}

export function CampaignDetailPage() {
    const { workspace } = useWorkspace();
    const { campaignId = '' } = useParams();
    const data = useCampaignReport(workspace.id, campaignId);
    const back = <AutoBreadcrumbs />;
    if (data.isError) {
        return (
            <>
                {back}
                <ErrorState error={data.error} onRetry={() => void data.refetch()} />
            </>
        );
    }
    if (data.isPending) {
        return (
            <>
                {back}
                <Skeleton className="igr:mb-4 igr:h-10 igr:w-80" />
                <div className="igr:grid igr:gap-3 igr:sm:grid-cols-4">
                    {Array.from({ length: 4 }, (_, i) => (
                        <Skeleton key={i} className="igr:h-20" />
                    ))}
                </div>
            </>
        );
    }
    const { campaign, report } = data.data;

    return (
        <>
            {back}
            <Header campaign={campaign} />
            <Overview campaign={campaign} report={report} />
        </>
    );
}

function Header({ campaign }: { campaign: CampaignDetail }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageMarketing');
    const navigate = useNavigate();
    const actions = useCampaignActions(workspace.id, campaign.id);
    const [confirmCancel, setConfirmCancel] = useState(false);
    const base = `/w/${workspace.id}/campanas`;
    const when = campaign.started_at ?? campaign.send_at;
    const error = [actions.pause, actions.resume, actions.cancel, actions.duplicate].find((m) => m.isError)?.error;

    return (
        <>
            <PageHeader
                breadcrumb={false}
                title={campaign.name}
                description={
                    <span className="igr:inline-flex igr:flex-wrap igr:items-center igr:gap-2">
                        <StatusBadge status={campaign.status} />
                        <ChannelLabel channel={campaign.channel} />
                        {when ? <span>· {formatDateTime(when, workspace.locale, workspace.timezone)}</span> : null}
                        {campaign.finished_at ? (
                            <span>· {t('campaigns.detail.finished', { when: formatDateTime(campaign.finished_at, workspace.locale, workspace.timezone) })}</span>
                        ) : null}
                    </span>
                }
                actions={
                    canManage ? (
                        <div className="igr:flex igr:flex-wrap igr:gap-2">
                            {campaign.editable ? (
                                <Button variant="outline" asChild>
                                    <Link to={`${base}/${campaign.id}/editar`}>
                                        <Pencil />
                                        {campaign.status === 'draft' ? t('campaigns.actions.continue') : t('campaigns.actions.edit')}
                                    </Link>
                                </Button>
                            ) : null}
                            {['scheduled', 'sending'].includes(campaign.status) ? (
                                <Button variant="outline" loading={actions.pause.isPending} onClick={() => actions.pause.mutate()}>
                                    <Pause />
                                    {t('campaigns.actions.pause')}
                                </Button>
                            ) : null}
                            {campaign.status === 'paused' ? (
                                <Button variant="outline" loading={actions.resume.isPending} onClick={() => actions.resume.mutate()}>
                                    <Play />
                                    {t('campaigns.actions.resume')}
                                </Button>
                            ) : null}
                            <Button
                                variant="outline"
                                loading={actions.duplicate.isPending}
                                onClick={() => actions.duplicate.mutate(undefined, { onSuccess: (c) => void navigate(`${base}/${c.id}/editar`) })}
                            >
                                <Copy />
                                {t('campaigns.actions.duplicate')}
                            </Button>
                            {['scheduled', 'sending', 'paused'].includes(campaign.status) ? (
                                <Button variant="destructive" onClick={() => setConfirmCancel(true)}>
                                    <XCircle />
                                    {t('campaigns.actions.cancel')}
                                </Button>
                            ) : null}
                        </div>
                    ) : null
                }
            />
            <FieldError message={error ? errorMessage(error, t) : undefined} />
            <Dialog open={confirmCancel} onOpenChange={setConfirmCancel}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('campaigns.cancel.title')}</DialogTitle>
                        <DialogDescription>{t('campaigns.cancel.description')}</DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setConfirmCancel(false)}>
                            {t('common.back')}
                        </Button>
                        <Button
                            variant="destructive"
                            loading={actions.cancel.isPending}
                            onClick={() => actions.cancel.mutate(undefined, { onSuccess: () => setConfirmCancel(false) })}
                        >
                            {t('campaigns.cancel.confirm')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

function Stat({ label, value, hint }: { label: string; value: string; hint?: string }) {
    return (
        <Card className="igr:p-3">
            <p className="igr:text-xs igr:text-muted-foreground">{label}</p>
            <p className="igr:mt-1 igr:text-xl igr:font-semibold igr:tabular-nums">{value}</p>
            {hint ? <p className="igr:mt-0.5 igr:text-xs igr:text-muted-foreground">{hint}</p> : null}
        </Card>
    );
}

function Overview({ campaign, report }: { campaign: CampaignDetail; report: CampaignReport }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const locale = workspace.locale;
    const d = report.delivery;
    const recipients = report.recipients.total - report.recipients.holdout;
    const notStarted = campaign.status === 'draft' || (campaign.status === 'scheduled' && report.recipients.total === 0);

    if (notStarted) {
        return (
            <Card>
                <CardHeader>
                    <CardTitle>{t('campaigns.detail.notStarted')}</CardTitle>
                    <CardDescription>
                        {campaign.status === 'draft'
                            ? t('campaigns.detail.draftHelp')
                            : t('campaigns.detail.scheduledHelp', {
                                  count: campaign.compliance_check?.will_send ?? 0,
                                  when: formatDateTime(campaign.send_at, locale, workspace.timezone),
                              })}
                    </CardDescription>
                </CardHeader>
            </Card>
        );
    }

    const revenue = report.revenue.total;
    return (
        <div className="igr:space-y-4">
            <HeroCard
                icon={Wallet}
                eyebrow={t('campaigns.report.heroEyebrow')}
                value={formatMoney(report.revenue.total, report.currency, locale)}
                description={
                    revenue > 0
                        ? t('campaigns.report.heroHelp', { count: report.revenue.orders, n: formatNumber(report.revenue.orders, locale) })
                        : campaign.status === 'sending'
                          ? t('campaigns.report.heroSending')
                          : t('campaigns.report.heroEmpty')
                }
            >
                {report.uplift && report.uplift.incremental_revenue > 0 ? (
                    <p className="igr:text-support igr:text-foreground">{t('campaigns.report.incremental', { amount: formatMoney(report.uplift.incremental_revenue, report.currency, locale) })}</p>
                ) : null}
            </HeroCard>
            <div className="igr:grid igr:gap-3 igr:sm:grid-cols-2 igr:lg:grid-cols-4">
                <Stat
                    label={t('campaigns.report.recipients')}
                    value={formatNumber(recipients, locale)}
                    hint={report.recipients.holdout > 0 ? t('campaigns.report.holdoutHint', { n: formatNumber(report.recipients.holdout, locale) }) : undefined}
                />
                <Stat label={t('campaigns.report.sent')} value={formatNumber(d.sent, locale)} hint={t('campaigns.report.deliveredHint', { n: formatNumber(d.delivered, locale) })} />
                <Stat
                    label={t('campaigns.report.clicks')}
                    value={formatNumber(d.clicked, locale)}
                    hint={d.click_rate !== null ? t('campaigns.report.clickRate', { pct: formatPercent(d.click_rate, locale, 1) }) : undefined}
                />
                <Stat
                    label={t('analytics.cost.cost')}
                    value={formatCost(report.cost, report.cost.meta.currency, locale)}
                    hint={report.cost.roi !== null ? t('campaigns.report.roiHint', { roi: formatRoi(report.cost.roi, locale), perSale: formatCostPerSale(report.cost, report.cost.meta.currency, locale) }) : t('analytics.cost.estimated')}
                />
            </div>
            <CostNote meta={report.cost.meta} locale={locale} />
            <div className="igr:grid igr:gap-4 igr:lg:grid-cols-2">
                <Funnel campaign={campaign} report={report} />
                <Uplift report={report} />
            </div>
            {campaign.is_ab ? <Variants report={report} /> : null}
            <RecipientsBreakdown report={report} />
        </div>
    );
}

/** Barras de una sola serie (sin paleta categórica): proporción de los enviados, valor en texto. */
function Funnel({ campaign, report }: { campaign: CampaignDetail; report: CampaignReport }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const steps = funnelSteps(report, campaign.channel);
    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('campaigns.report.funnel')}</CardTitle>
                <CardDescription>
                    {campaign.channel === 'email'
                        ? report.delivery.opened_excludes_mpp
                            ? t('campaigns.report.opensNoMpp')
                            : t('campaigns.report.opensMaybeMpp')
                        : t('campaigns.report.funnelHelp')}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <ul className="igr:space-y-2.5">
                    {steps.map((s) => (
                        <li key={s.key}>
                            <Tooltip
                                content={`${t(`campaigns.report.step.${s.key}`)}: ${formatNumber(s.value, workspace.locale)} (${formatPercent(s.ratio, workspace.locale, 1)})`}
                            >
                                <div className="igr:group">
                                    <div className="igr:mb-1 igr:flex igr:justify-between igr:text-xs">
                                        <span>{t(`campaigns.report.step.${s.key}`)}</span>
                                        <span className="igr:tabular-nums igr:text-muted-foreground">
                                            {formatNumber(s.value, workspace.locale)} · {formatPercent(s.ratio, workspace.locale, 1)}
                                        </span>
                                    </div>
                                    <div className="igr:h-2 igr:w-full igr:rounded-full igr:bg-muted">
                                        <div
                                            className="igr:h-2 igr:rounded-full igr:bg-primary igr:transition-[width] igr:group-hover:bg-primary/80"
                                            style={{ width: `${Math.max(s.value > 0 ? 2 : 0, Math.round(s.ratio * 100))}%` }}
                                        />
                                    </div>
                                </div>
                            </Tooltip>
                        </li>
                    ))}
                </ul>
                <p className="igr:mt-3 igr:text-xs igr:text-muted-foreground">
                    {t('campaigns.report.unsubscribed', { n: formatNumber(report.delivery.unsubscribed, workspace.locale) })} ·{' '}
                    {t('campaigns.report.failed', { n: formatNumber(report.delivery.failed, workspace.locale) })}
                </p>
            </CardContent>
        </Card>
    );
}

function Uplift({ report }: { report: CampaignReport }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const u = report.uplift;
    const pp = (v: number) => `${v >= 0 ? '+' : ''}${(v * 100).toLocaleString(workspace.locale, { maximumFractionDigits: 1 })} pp`;
    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('campaigns.report.uplift')}</CardTitle>
                <CardDescription>{u ? t('campaigns.report.upliftHelp', { days: u.window_days }) : t('campaigns.report.noHoldout')}</CardDescription>
            </CardHeader>
            {u ? (
                <CardContent className="igr:space-y-3">
                    <div className="igr:grid igr:grid-cols-2 igr:gap-3">
                        <div>
                            <p className="igr:text-xs igr:text-muted-foreground">{t('campaigns.report.treatment')}</p>
                            <p className="igr:text-lg igr:font-semibold igr:tabular-nums">{formatPercent(u.treatment.rate, workspace.locale, 1)}</p>
                            <p className="igr:text-xs igr:text-muted-foreground">
                                {t('campaigns.report.converted', {
                                    n: formatNumber(u.treatment.converted, workspace.locale),
                                    of: formatNumber(u.treatment.recipients, workspace.locale),
                                })}
                            </p>
                        </div>
                        <div>
                            <p className="igr:text-xs igr:text-muted-foreground">{t('campaigns.report.control')}</p>
                            <p className="igr:text-lg igr:font-semibold igr:tabular-nums">{formatPercent(u.control.rate, workspace.locale, 1)}</p>
                            <p className="igr:text-xs igr:text-muted-foreground">
                                {t('campaigns.report.converted', {
                                    n: formatNumber(u.control.converted, workspace.locale),
                                    of: formatNumber(u.control.recipients, workspace.locale),
                                })}
                            </p>
                        </div>
                    </div>
                    <p className="igr:flex igr:items-center igr:gap-1.5 igr:text-[13px]">
                        {u.significant ? (
                            <CircleCheck className="igr:size-4 igr:text-success" aria-hidden="true" />
                        ) : (
                            <CircleMinus className="igr:size-4 igr:text-muted-foreground" aria-hidden="true" />
                        )}
                        <span>
                            <strong>{pp(u.lift)}</strong> {t('campaigns.report.ci', { low: pp(u.ci_low), high: pp(u.ci_high) })} ·{' '}
                            {u.significant ? t('campaigns.report.significant') : t('campaigns.report.notSignificant')}
                        </span>
                    </p>
                    <p className="igr:text-[13px]">{t('campaigns.report.incremental', { amount: formatMoney(u.incremental_revenue, report.currency, workspace.locale) })}</p>
                    {!u.complete ? <p className="igr:text-xs igr:text-muted-foreground">{t('campaigns.report.windowOpen')}</p> : null}
                </CardContent>
            ) : null}
        </Card>
    );
}

function Variants({ report }: { report: CampaignReport }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const rows = Object.entries(report.variants).sort(([a], [b]) => a.localeCompare(b));
    const maxRate = Math.max(0.0001, ...rows.map(([, v]) => v.click_rate));
    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('campaigns.report.variants')}</CardTitle>
                <CardDescription>
                    {report.winner_variant ? t('campaigns.report.winnerChosen', { variant: report.winner_variant }) : t('campaigns.report.winnerPending')}
                </CardDescription>
            </CardHeader>
            <Table>
                <THead>
                    <TR>
                        <TH>{t('campaigns.report.variant')}</TH>
                        <TH className="igr:text-right">{t('campaigns.report.sent')}</TH>
                        <TH className="igr:text-right">{t('campaigns.report.clicks')}</TH>
                        <TH>{t('campaigns.report.clickRateCol')}</TH>
                        <TH className="igr:text-right">{t('campaigns.report.revenue')}</TH>
                        <TH className="igr:text-right">{t('campaigns.report.revenuePerSent')}</TH>
                    </TR>
                </THead>
                <TBody>
                    {rows.map(([key, v]) => (
                        <TR key={key}>
                            <TD className="igr:font-medium">
                                {key}
                                {report.winner_variant === key ? (
                                    <Badge variant="success" className="igr:ml-2">
                                        <Trophy className="igr:size-3" aria-hidden="true" />
                                        {t('campaigns.report.winner')}
                                    </Badge>
                                ) : null}
                            </TD>
                            <TD className="igr:text-right igr:tabular-nums">{formatNumber(v.sent, workspace.locale)}</TD>
                            <TD className="igr:text-right igr:tabular-nums">{formatNumber(v.clicks, workspace.locale)}</TD>
                            <TD>
                                <div className="igr:flex igr:items-center igr:gap-2">
                                    <div className="igr:h-1.5 igr:w-24 igr:rounded-full igr:bg-muted">
                                        <div className="igr:h-1.5 igr:rounded-full igr:bg-primary" style={{ width: `${Math.round((v.click_rate / maxRate) * 100)}%` }} />
                                    </div>
                                    <span className="igr:text-xs igr:tabular-nums">{formatPercent(v.click_rate, workspace.locale, 1)}</span>
                                </div>
                            </TD>
                            <TD className="igr:text-right igr:tabular-nums">{formatMoney(v.revenue, report.currency, workspace.locale)}</TD>
                            <TD className="igr:text-right igr:tabular-nums">{formatMoney(v.revenue_per_sent, report.currency, workspace.locale)}</TD>
                        </TR>
                    ))}
                </TBody>
            </Table>
        </Card>
    );
}

function RecipientsBreakdown({ report }: { report: CampaignReport }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const statuses = Object.entries(report.recipients.by_status).sort(([, a], [, b]) => b - a);
    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('campaigns.report.recipientsTitle')}</CardTitle>
                <CardDescription>{t('campaigns.report.recipientsHelp')}</CardDescription>
            </CardHeader>
            <CardContent className="igr:grid igr:gap-4 igr:md:grid-cols-2">
                <ul className="igr:space-y-1">
                    {statuses.map(([status, n]) => (
                        <li key={status} className="igr:flex igr:justify-between igr:text-[13px]">
                            <span>{t(`campaigns.recipientStatus.${status}`, { defaultValue: status })}</span>
                            <span className="igr:tabular-nums">{formatNumber(n, workspace.locale)}</span>
                        </li>
                    ))}
                </ul>
                <ul className="igr:space-y-1">
                    {report.recipients.reasons.length === 0 ? <li className="igr:text-[13px] igr:text-muted-foreground">{t('campaigns.report.noReasons')}</li> : null}
                    {report.recipients.reasons.map((r) => (
                        <li key={`${r.status}-${r.reason}`} className="igr:flex igr:justify-between igr:gap-2 igr:text-[13px]">
                            <span>
                                {t(`campaigns.reasons.${r.reason}`, { defaultValue: r.reason })}{' '}
                                <span className="igr:text-xs igr:text-muted-foreground">({t(`campaigns.recipientStatus.${r.status}`, { defaultValue: r.status })})</span>
                            </span>
                            <span className="igr:tabular-nums">{formatNumber(r.count, workspace.locale)}</span>
                        </li>
                    ))}
                </ul>
            </CardContent>
        </Card>
    );
}
