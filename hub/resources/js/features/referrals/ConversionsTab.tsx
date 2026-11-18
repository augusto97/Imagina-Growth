import { CheckCheck, ChevronLeft, ChevronRight, Download, Receipt, Wallet } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Select } from '@/components/ui/input';
import { EmptyState, ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { formatMoney } from '@/lib/format';
import { toast } from '@/stores/toast';
import { payoutsCsvUrl, useConversions, useMarkPaid, usePayouts } from './api';
import { ConversionsTable } from './ConversionsTable';
import type { ConversionStatus, Payout } from './types';

/** Comisiones de afiliados: pagos por hacer (exportar y marcar pagadas) y el historial. */
export function ConversionsTab() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canPay = useCan('viewPaymentData');
    const [status, setStatus] = useState<ConversionStatus | ''>('');
    const [page, setPage] = useState(1);
    const conversions = useConversions(workspace.id, status, page);

    return (
        <div className="igr:space-y-4">
            {canPay ? <Payouts /> : null}
            <div className="igr:flex igr:items-center igr:justify-between igr:gap-2">
                <h2 className="igr:text-sm igr:font-medium">{t('referrals.conversions.title')}</h2>
                <Select
                    aria-label={t('referrals.conversions.statusLabel')}
                    className="igr:w-auto"
                    value={status}
                    onChange={(e) => {
                        setStatus(e.target.value as ConversionStatus | '');
                        setPage(1);
                    }}
                >
                    <option value="">{t('referrals.list.anyStatus')}</option>
                    {(['pending', 'approved', 'paid', 'void'] as const).map((s) => (
                        <option key={s} value={s}>
                            {t(`referrals.conversions.status.${s}`)}
                        </option>
                    ))}
                </Select>
            </div>
            {conversions.isPending ? (
                <Skeleton className="igr:h-40" />
            ) : conversions.isError ? (
                <ErrorState error={conversions.error} onRetry={() => void conversions.refetch()} />
            ) : conversions.data.data.length === 0 ? (
                <EmptyState icon={Receipt} title={t('referrals.conversions.emptyTitle')} description={t('referrals.conversions.emptyDescription')} />
            ) : (
                <Card className="igr:overflow-hidden">
                    <ConversionsTable rows={conversions.data.data} />
                </Card>
            )}
            {conversions.data && conversions.data.meta.last_page > 1 ? (
                <div className="igr:flex igr:items-center igr:justify-end igr:gap-2 igr:text-xs igr:text-muted-foreground">
                    {t('referrals.list.page', { page: conversions.data.meta.page, last: conversions.data.meta.last_page })}
                    <Button size="icon" variant="outline" aria-label={t('referrals.list.prev')} disabled={page <= 1} onClick={() => setPage(page - 1)}>
                        <ChevronLeft />
                    </Button>
                    <Button size="icon" variant="outline" aria-label={t('referrals.list.next')} disabled={page >= conversions.data.meta.last_page} onClick={() => setPage(page + 1)}>
                        <ChevronRight />
                    </Button>
                </div>
            ) : null}
        </div>
    );
}

function Payouts() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const payouts = usePayouts(workspace.id, true);
    const markPaid = useMarkPaid(workspace.id);
    const [confirm, setConfirm] = useState<Payout[] | null>(null);
    const money = (v: string) => formatMoney(v, workspace.currency, workspace.locale);
    const rows = payouts.data ?? [];
    const total = rows.reduce((sum, r) => sum + Number(r.commission), 0);

    return (
        <Card>
            <CardHeader className="igr:flex igr:flex-row igr:items-start igr:justify-between igr:gap-3 igr:space-y-0">
                <div>
                    <CardTitle className="igr:flex igr:items-center igr:gap-1.5">
                        <Wallet className="igr:size-4" aria-hidden />
                        {t('referrals.payouts.title')}
                    </CardTitle>
                    <CardDescription>{t('referrals.payouts.description')}</CardDescription>
                </div>
                {rows.length > 0 ? (
                    <div className="igr:flex igr:shrink-0 igr:gap-2">
                        <Button variant="outline" size="sm" asChild>
                            <a href={payoutsCsvUrl(workspace.id)} download>
                                <Download />
                                {t('referrals.payouts.export')}
                            </a>
                        </Button>
                        <Button size="sm" onClick={() => setConfirm(rows)}>
                            <CheckCheck />
                            {t('referrals.payouts.markAll')}
                        </Button>
                    </div>
                ) : null}
            </CardHeader>
            <CardContent className={rows.length > 0 ? 'igr:px-0 igr:pb-0' : undefined}>
                {payouts.isPending ? (
                    <Skeleton className="igr:h-20" />
                ) : rows.length === 0 ? (
                    <p className="igr:text-[13px] igr:text-muted-foreground">{t('referrals.payouts.empty')}</p>
                ) : (
                    <Table>
                        <THead>
                            <TR>
                                <TH>{t('referrals.conversions.affiliate')}</TH>
                                <TH>{t('referrals.payouts.payoutInfo')}</TH>
                                <TH className="igr:text-right">{t('referrals.affiliates.orders')}</TH>
                                <TH className="igr:text-right">{t('referrals.payouts.amount')}</TH>
                                <TH className="igr:text-right">{t('referrals.affiliates.actions')}</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {rows.map((r) => (
                                <TR key={r.affiliate_id}>
                                    <TD>
                                        <span className="igr:block igr:font-medium">{r.name}</span>
                                        <span className="igr:text-xs igr:text-muted-foreground">{r.email ?? '—'}</span>
                                    </TD>
                                    <TD className="igr:max-w-64 igr:text-xs igr:whitespace-pre-wrap">{r.payout_info ?? <span className="igr:text-muted-foreground">{t('referrals.payouts.noPayoutInfo')}</span>}</TD>
                                    <TD className="igr:text-right igr:tabular-nums">{r.orders}</TD>
                                    <TD className="igr:text-right igr:font-medium igr:tabular-nums">{money(r.commission)}</TD>
                                    <TD className="igr:text-right">
                                        <Button size="sm" variant="outline" onClick={() => setConfirm([r])}>
                                            {t('referrals.payouts.markPaid')}
                                        </Button>
                                    </TD>
                                </TR>
                            ))}
                            <TR>
                                <TD colSpan={3} className="igr:text-right igr:text-xs igr:text-muted-foreground">
                                    {t('referrals.payouts.total')}
                                </TD>
                                <TD className="igr:text-right igr:font-semibold igr:tabular-nums">{money(String(total))}</TD>
                                <TD />
                            </TR>
                        </TBody>
                    </Table>
                )}
            </CardContent>
            <Dialog open={confirm !== null} onOpenChange={(open) => (!open ? setConfirm(null) : undefined)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('referrals.payouts.confirmTitle')}</DialogTitle>
                        <DialogDescription>
                            {t('referrals.payouts.confirmDescription', {
                                count: confirm?.reduce((n, r) => n + r.conversion_ids.length, 0) ?? 0,
                                amount: money(String(confirm?.reduce((sum, r) => sum + Number(r.commission), 0) ?? 0)),
                            })}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setConfirm(null)}>
                            {t('referrals.cancel')}
                        </Button>
                        <Button
                            loading={markPaid.isPending}
                            onClick={() =>
                                confirm &&
                                markPaid.mutate(
                                    confirm.flatMap((r) => r.conversion_ids),
                                    {
                                        onSuccess: (r) => {
                                            toast.success(t('referrals.payouts.marked', { count: r.updated }));
                                            setConfirm(null);
                                        },
                                    },
                                )
                            }
                        >
                            {t('referrals.payouts.markPaid')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </Card>
    );
}
