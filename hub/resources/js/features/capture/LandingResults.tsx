import { ArrowUpRight, Eye, QrCode, Send, Target, UserPlus } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { StatCard, StatCardSkeleton, StatGrid } from '@/components/ui/stat-card';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { TrendChart } from '@/features/analytics/components/charts';
import { useFormatters } from '@/features/analytics/format';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { formatDateTime } from '@/lib/format';
import { useLandingStats, useLandingSubmissions } from './api';
import { conversion } from './model';
import { ConsentBadges, PeriodControl, type Period } from './shared';
import type { LandingPageDetail, LandingSubmission } from './types';

/** Resultados de una página de captura: visitas, envíos, leads y lo que vino del QR, y los últimos envíos. */
export function LandingResults({ page }: { page: LandingPageDetail }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const fmt = useFormatters();
    const [period, setPeriod] = useState<Period>('30');
    const stats = useLandingStats(workspace.id, page.id, Number(period));
    const leadsModule = workspace.modules?.includes('services') ?? true;
    const rate = (submissions: number, views: number) => {
        const r = conversion(submissions, views);
        return r === null ? '—' : fmt.percent(r, 1);
    };

    return (
        <div className="igr:space-y-6">
            <div className="igr:flex igr:flex-wrap igr:items-center igr:justify-between igr:gap-3">
                <p className="igr:text-support igr:text-muted-foreground">{t('capture.results.intro')}</p>
                <PeriodControl value={period} onChange={setPeriod} />
            </div>
            {stats.isError ? (
                <ErrorState error={stats.error} onRetry={() => void stats.refetch()} />
            ) : (
                <>
                    <StatGrid>
                        {stats.data ? (
                            <>
                                <StatCard
                                    label={t('capture.results.views')}
                                    value={fmt.number(stats.data.totals.views)}
                                    hint={t('capture.results.visitors', { count: stats.data.totals.visitors, formatted: fmt.number(stats.data.totals.visitors) })}
                                    icon={Eye}
                                    tone="sky"
                                />
                                <StatCard
                                    label={t('capture.results.submissions')}
                                    value={fmt.number(stats.data.totals.submissions)}
                                    hint={t('capture.results.newContacts', { count: stats.data.totals.new_contacts, formatted: fmt.number(stats.data.totals.new_contacts) })}
                                    icon={Send}
                                    tone="emerald"
                                />
                                <StatCard
                                    label={t('capture.results.conversion')}
                                    value={rate(stats.data.totals.submissions, stats.data.totals.views)}
                                    help={t('capture.results.conversionHelp')}
                                    icon={ArrowUpRight}
                                    tone="violet"
                                />
                                <StatCard
                                    label={leadsModule ? t('capture.results.leads') : t('capture.results.withConsent')}
                                    value={fmt.number(leadsModule ? stats.data.totals.leads : stats.data.totals.with_consent)}
                                    hint={
                                        leadsModule
                                            ? t('capture.results.withConsentHint', { count: stats.data.totals.with_consent, formatted: fmt.number(stats.data.totals.with_consent) })
                                            : undefined
                                    }
                                    help={leadsModule ? t('capture.results.leadsHelp') : t('capture.results.withConsentHelp')}
                                    icon={leadsModule ? Target : UserPlus}
                                    tone="rose"
                                />
                            </>
                        ) : (
                            [0, 1, 2, 3].map((i) => <StatCardSkeleton key={i} />)
                        )}
                    </StatGrid>
                    <Card>
                        <CardHeader className="igr:flex-row igr:flex-wrap igr:items-start igr:justify-between igr:gap-3">
                            <div className="igr:min-w-0">
                                <CardTitle>{t('capture.results.chartTitle')}</CardTitle>
                                <CardDescription>{t('capture.results.chartHelp')}</CardDescription>
                            </div>
                            {stats.data ? (
                                <Badge variant="outline" className="igr:gap-1.5">
                                    <QrCode className="igr:size-3" aria-hidden />
                                    {t('capture.results.qrViews', { count: stats.data.totals.qr_views, formatted: fmt.number(stats.data.totals.qr_views) })}
                                    {' · '}
                                    {leadsModule
                                        ? t('capture.results.qrLeads', { count: stats.data.totals.qr_leads, formatted: fmt.number(stats.data.totals.qr_leads) })
                                        : t('capture.results.qrSubmissions', { count: stats.data.totals.qr_submissions, formatted: fmt.number(stats.data.totals.qr_submissions) })}
                                </Badge>
                            ) : null}
                        </CardHeader>
                        <CardContent>
                            {stats.data ? (
                                <TrendChart
                                    data={stats.data.daily.map((d) => ({ date: d.day, views: d.views, submissions: d.submissions }))}
                                    metric="views"
                                    label={t('capture.results.views')}
                                    unit="count"
                                    granularity="day"
                                    fmt={fmt}
                                    height={220}
                                />
                            ) : (
                                <Skeleton className="igr:h-[220px] igr:rounded-lg" />
                            )}
                        </CardContent>
                    </Card>
                </>
            )}
            <Submissions page={page} />
        </div>
    );
}

function Submissions({ page }: { page: LandingPageDetail }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const list = useLandingSubmissions(workspace.id, page.id);
    const canSeeContacts = useCan('viewAssignedContacts');
    const canSeeLeads = useCan('useLeads');

    return (
        <Card className="igr:overflow-hidden">
            <CardHeader>
                <CardTitle>{t('capture.submissions.title')}</CardTitle>
                <CardDescription>{t('capture.submissions.help')}</CardDescription>
            </CardHeader>
            {list.isError ? (
                <CardContent>
                    <ErrorState error={list.error} onRetry={() => void list.refetch()} />
                </CardContent>
            ) : list.isPending ? (
                <CardContent className="igr:space-y-2">
                    {[0, 1, 2].map((i) => (
                        <Skeleton key={i} className="igr:h-11" />
                    ))}
                </CardContent>
            ) : list.data.data.length === 0 ? (
                <CardContent>
                    <EmptyState size="compact" illustration="customers" title={t('capture.submissions.emptyTitle')} description={t('capture.submissions.emptyBody')} />
                </CardContent>
            ) : (
                <Table>
                    <THead>
                        <TR>
                            <TH>{t('capture.submissions.when')}</TH>
                            <TH>{t('capture.submissions.who')}</TH>
                            <TH>{t('capture.submissions.via')}</TH>
                            <TH>{t('capture.submissions.consent')}</TH>
                            <TH>{t('capture.submissions.answers')}</TH>
                        </TR>
                    </THead>
                    <TBody>
                        {list.data.data.map((row) => (
                            <SubmissionRow key={row.id} row={row} canSeeContacts={canSeeContacts} canSeeLeads={canSeeLeads} />
                        ))}
                    </TBody>
                </Table>
            )}
        </Card>
    );
}

function SubmissionRow({ row, canSeeContacts, canSeeLeads }: { row: LandingSubmission; canSeeContacts: boolean; canSeeLeads: boolean }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const base = `/w/${workspace.id}`;
    const who = row.contact ? (row.contact.name ?? row.contact.email ?? row.contact.phone ?? t('capture.submissions.unknown')) : t('capture.submissions.unknown');
    const answers = row.answers ? Object.entries(row.answers).filter(([, v]) => v !== '') : [];
    return (
        <TR>
            <TD className="igr:whitespace-nowrap igr:text-muted-foreground igr:tabular-nums">{formatDateTime(row.created_at, workspace.locale, workspace.timezone)}</TD>
            <TD className="igr:min-w-44">
                {row.contact && canSeeContacts ? (
                    <Link to={`${base}/contactos/${row.contact.id}`} className="igr:font-medium igr:hover:underline">
                        {who}
                    </Link>
                ) : (
                    <span className="igr:font-medium">{who}</span>
                )}
                <span className="igr:mt-0.5 igr:flex igr:flex-wrap igr:gap-1">
                    {row.was_known ? <Badge variant="outline">{t('capture.submissions.known')}</Badge> : <Badge variant="info">{t('capture.submissions.new')}</Badge>}
                    {row.lead_id && canSeeLeads ? (
                        <Link to={`${base}/leads?lead=${row.lead_id}`} className="igr:rounded-full igr:focus-visible:ring-2 igr:focus-visible:ring-ring">
                            <Badge variant="brand">{t('capture.submissions.lead')}</Badge>
                        </Link>
                    ) : null}
                </span>
            </TD>
            <TD>
                <Badge variant={row.via === 'qr' ? 'warning' : 'secondary'}>{t(`capture.submissions.viaKinds.${row.via}`)}</Badge>
            </TD>
            <TD>
                <ConsentBadges channels={row.consents} />
            </TD>
            <TD className="igr:max-w-xs igr:text-caption igr:text-muted-foreground">
                {answers.length > 0 ? (
                    <ul className="igr:space-y-0.5">
                        {answers.slice(0, 3).map(([k, v]) => (
                            <li key={k} className="igr:line-clamp-2">
                                {v}
                            </li>
                        ))}
                    </ul>
                ) : row.answers === null ? (
                    t('capture.submissions.hidden')
                ) : (
                    '—'
                )}
            </TD>
        </TR>
    );
}
