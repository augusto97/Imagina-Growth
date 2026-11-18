import { Check, ChevronLeft, ChevronRight, ShieldAlert, Users, X } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Select } from '@/components/ui/input';
import { EmptyState, ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { formatDate, formatMoney } from '@/lib/format';
import { toast } from '@/stores/toast';
import { type ReferralFilter, useReferrals, useReviewReferral } from './api';
import { ReferralStatusBadge, SignalBadges } from './labels';
import type { Referral, ReferralStatus } from './types';

const STATUSES: ReferralStatus[] = ['signed_up', 'ordered', 'qualified', 'rewarded', 'rejected'];

/** Referidos con su estado, señales de fraude y la cola de revisión manual. */
export function ReferralsTab() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageMarketing');
    const [filter, setFilter] = useState<ReferralFilter>({ status: '', review: false, page: 1 });
    const list = useReferrals(workspace.id, filter);
    const review = useReviewReferral(workspace.id);

    const decide = (r: Referral, decision: 'approve' | 'reject') =>
        review.mutate(
            { id: r.id, decision },
            {
                onSuccess: (updated) => toast.success(decision === 'approve' ? (updated.status === 'rewarded' ? t('referrals.review.approvedRewarded') : t('referrals.review.approved')) : t('referrals.review.rejected')),
                onError: (error) => toast.error(t('referrals.review.failed'), isApiError(error) ? error.message : undefined),
            },
        );

    return (
        <div className="igr:space-y-3">
            <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-2">
                <Button variant={filter.review ? 'outline' : 'secondary'} size="sm" onClick={() => setFilter({ status: '', review: false, page: 1 })}>
                    {t('referrals.list.all')}
                </Button>
                <Button variant={filter.review ? 'secondary' : 'outline'} size="sm" onClick={() => setFilter({ status: '', review: true, page: 1 })}>
                    <ShieldAlert />
                    {t('referrals.list.review', { count: list.data?.pending_review ?? 0 })}
                </Button>
                {!filter.review ? (
                    <Select
                        aria-label={t('referrals.list.statusFilter')}
                        className="igr:w-auto"
                        value={filter.status}
                        onChange={(e) => setFilter({ status: e.target.value as ReferralStatus | '', review: false, page: 1 })}
                    >
                        <option value="">{t('referrals.list.anyStatus')}</option>
                        {STATUSES.map((s) => (
                            <option key={s} value={s}>
                                {t(`referrals.status.${s}`)} ({list.data?.counts[s] ?? 0})
                            </option>
                        ))}
                    </Select>
                ) : null}
            </div>
            {filter.review ? <p className="igr:text-xs igr:text-muted-foreground">{t('referrals.review.explainer')}</p> : null}
            {list.isPending ? (
                <Skeleton className="igr:h-48" />
            ) : list.isError ? (
                <ErrorState error={list.error} onRetry={() => void list.refetch()} />
            ) : list.data.data.length === 0 ? (
                <EmptyState icon={Users} title={filter.review ? t('referrals.list.emptyReview') : t('referrals.list.emptyTitle')} description={filter.review ? undefined : t('referrals.list.emptyDescription')} />
            ) : (
                <Card className="igr:overflow-hidden">
                    <Table>
                        <THead>
                            <TR>
                                <TH>{t('referrals.list.date')}</TH>
                                <TH>{t('referrals.list.referrer')}</TH>
                                <TH>{t('referrals.list.friend')}</TH>
                                <TH>{t('referrals.list.status')}</TH>
                                <TH>{t('referrals.list.signals')}</TH>
                                <TH className="igr:text-right">{t('referrals.list.order')}</TH>
                                <TH>{t('referrals.list.reward')}</TH>
                                {canManage && filter.review ? <TH className="igr:text-right">{t('referrals.list.decision')}</TH> : null}
                            </TR>
                        </THead>
                        <TBody>
                            {list.data.data.map((r) => (
                                <TR key={r.id}>
                                    <TD className="igr:whitespace-nowrap igr:text-muted-foreground">{formatDate(r.created_at, workspace.locale, workspace.timezone)}</TD>
                                    <TD>
                                        <span className="igr:block">{r.referrer?.name ?? t('referrals.hiddenPerson')}</span>
                                        <span className="igr:font-mono igr:text-[11px] igr:text-muted-foreground">{r.code}</span>
                                    </TD>
                                    <TD>{r.friend?.name ?? t('referrals.hiddenPerson')}</TD>
                                    <TD>
                                        <ReferralStatusBadge referral={r} />
                                        {r.rejected_reason ? <span className="igr:mt-1 igr:block igr:text-[11px] igr:text-muted-foreground">{t(`referrals.rejectedReason.${r.rejected_reason}`)}</span> : null}
                                        {r.status === 'ordered' && r.qualifies_at ? <span className="igr:mt-1 igr:block igr:text-[11px] igr:text-muted-foreground">{t('referrals.list.qualifiesOn', { date: formatDate(r.qualifies_at, workspace.locale, workspace.timezone) })}</span> : null}
                                    </TD>
                                    <TD>
                                        <SignalBadges signals={r.signals} />
                                    </TD>
                                    <TD className="igr:text-right igr:tabular-nums">
                                        {r.order ? (
                                            <>
                                                <span className="igr:block">#{r.order.number}</span>
                                                <span className="igr:text-[11px] igr:text-muted-foreground">{formatMoney(r.order_amount_ws ?? 0, workspace.currency, workspace.locale)}</span>
                                            </>
                                        ) : (
                                            <span className="igr:text-muted-foreground">{r.friend_coupon ? t('referrals.list.couponSent') : '—'}</span>
                                        )}
                                    </TD>
                                    <TD className="igr:text-xs">
                                        {r.reward?.state === 'done' ? r.reward.label : r.reward?.state === 'failed' ? <span className="igr:text-destructive">{t(`referrals.rewardError.${r.reward.error ?? 'unknown'}`)}</span> : <span className="igr:text-muted-foreground">—</span>}
                                    </TD>
                                    {canManage && filter.review ? (
                                        <TD className="igr:text-right">
                                            <div className="igr:inline-flex igr:gap-1.5">
                                                <Button size="sm" variant="outline" onClick={() => decide(r, 'reject')} disabled={review.isPending} aria-label={t('referrals.review.reject')}>
                                                    <X />
                                                    {t('referrals.review.reject')}
                                                </Button>
                                                <Button size="sm" onClick={() => decide(r, 'approve')} disabled={review.isPending} aria-label={t('referrals.review.approve')}>
                                                    <Check />
                                                    {t('referrals.review.approve')}
                                                </Button>
                                            </div>
                                        </TD>
                                    ) : null}
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                </Card>
            )}
            {list.data && list.data.meta.last_page > 1 ? (
                <div className="igr:flex igr:items-center igr:justify-end igr:gap-2 igr:text-xs igr:text-muted-foreground">
                    {t('referrals.list.page', { page: list.data.meta.page, last: list.data.meta.last_page })}
                    <Button size="icon" variant="outline" aria-label={t('referrals.list.prev')} disabled={filter.page <= 1} onClick={() => setFilter({ ...filter, page: filter.page - 1 })}>
                        <ChevronLeft />
                    </Button>
                    <Button size="icon" variant="outline" aria-label={t('referrals.list.next')} disabled={filter.page >= list.data.meta.last_page} onClick={() => setFilter({ ...filter, page: filter.page + 1 })}>
                        <ChevronRight />
                    </Button>
                </div>
            ) : null}
        </div>
    );
}
