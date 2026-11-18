import { CircleCheck, CircleX, Info } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import type { Reconciliation } from '@/api/types';
import { Badge } from '@/components/ui/badge';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { Tooltip } from '@/components/ui/tooltip';
import { cn } from '@/lib/cn';
import { formatDateTime, formatMoney, formatNumber } from '@/lib/format';

function Mark({ ok }: { ok: boolean }) {
    const { t } = useTranslation();
    return ok ? (
        <CircleCheck className="igr:size-4 igr:text-success" aria-label={t('reconciliation.match')} />
    ) : (
        <CircleX className="igr:size-4 igr:text-destructive" aria-label={t('reconciliation.mismatch')} />
    );
}

/**
 * Conciliación por estado WooCommerce ↔ hub: número de pedidos y suma del
 * total (api-connector.md §1.2 y §5.3). Es la prueba del criterio de la Fase 0.
 */
export function ReconciliationTable({ reconciliation, currency, locale, timezone }: { reconciliation: Reconciliation; currency: string; locale: string; timezone?: string }) {
    const { t } = useTranslation();
    if (!reconciliation.available) {
        return <p className="igr:text-[13px] igr:text-muted-foreground">{t('reconciliation.unavailable')}</p>;
    }
    const cur = reconciliation.currency ?? currency;
    const money = (v: string) => formatMoney(v, cur, locale);
    const { totals } = reconciliation;
    const countsOk = totals.wc_count === totals.hub_count;

    return (
        <div className="igr:space-y-3">
            <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-2">
                {reconciliation.ok ? (
                    <Badge variant="success">
                        <CircleCheck className="igr:size-3" />
                        {t('reconciliation.allMatch')}
                    </Badge>
                ) : (
                    <Badge variant="destructive">
                        <CircleX className="igr:size-3" />
                        {t('reconciliation.someMismatch')}
                    </Badge>
                )}
                <Tooltip content={t('reconciliation.help', { from: formatDateTime(reconciliation.from, locale, timezone), until: formatDateTime(reconciliation.until, locale, timezone) })}>
                    <button type="button" className="igr:inline-flex igr:items-center igr:gap-1 igr:text-xs igr:text-muted-foreground igr:hover:text-foreground">
                        <Info className="igr:size-3.5" />
                        {t('reconciliation.whatIsCompared')}
                    </button>
                </Tooltip>
            </div>
            <Table>
                <THead>
                    <TR className="igr:hover:bg-transparent">
                        <TH>{t('reconciliation.status')}</TH>
                        <TH className="igr:text-right">{t('reconciliation.wcOrders')}</TH>
                        <TH className="igr:text-right">{t('reconciliation.hubOrders')}</TH>
                        <TH className="igr:text-right">{t('reconciliation.wcTotal')}</TH>
                        <TH className="igr:text-right">{t('reconciliation.hubTotal')}</TH>
                        <TH className="igr:w-10 igr:text-center">
                            <span className="igr:sr-only">{t('reconciliation.result')}</span>
                        </TH>
                    </TR>
                </THead>
                <TBody>
                    {reconciliation.rows.map((row) => (
                        <TR key={row.status} className={cn(!row.ok && 'igr:bg-destructive/5')} data-testid={`rec-${row.status}`}>
                            <TD>{t(`orderStatus.${row.status}`, { defaultValue: row.status })}</TD>
                            <TD className="igr:text-right igr:tabular-nums">{formatNumber(row.wc_count, locale)}</TD>
                            <TD className={cn('igr:text-right igr:tabular-nums', row.wc_count !== row.hub_count && 'igr:font-medium igr:text-destructive')}>{formatNumber(row.hub_count, locale)}</TD>
                            <TD className="igr:text-right igr:tabular-nums">{money(row.wc_total)}</TD>
                            <TD className={cn('igr:text-right igr:tabular-nums', row.wc_total !== row.hub_total && 'igr:font-medium igr:text-destructive')}>{money(row.hub_total)}</TD>
                            <TD className="igr:text-center">
                                <span className="igr:inline-flex">
                                    <Mark ok={row.ok} />
                                </span>
                            </TD>
                        </TR>
                    ))}
                    <TR className="igr:bg-muted/40 igr:font-medium igr:hover:bg-muted/40">
                        <TD>{t('reconciliation.total')}</TD>
                        <TD className="igr:text-right igr:tabular-nums">{formatNumber(totals.wc_count, locale)}</TD>
                        <TD className="igr:text-right igr:tabular-nums">{formatNumber(totals.hub_count, locale)}</TD>
                        <TD className="igr:text-right igr:tabular-nums">{money(totals.wc_total)}</TD>
                        <TD className="igr:text-right igr:tabular-nums">{money(totals.hub_total)}</TD>
                        <TD className="igr:text-center">
                            <span className="igr:inline-flex">
                                <Mark ok={countsOk && totals.wc_total === totals.hub_total} />
                            </span>
                        </TD>
                    </TR>
                    {totals.wc_refunded !== undefined && totals.hub_refunded !== undefined ? (
                        <TR className="igr:hover:bg-transparent">
                            <TD className="igr:text-muted-foreground">{t('reconciliation.refunds')}</TD>
                            <TD />
                            <TD />
                            <TD className="igr:text-right igr:tabular-nums igr:text-muted-foreground">{money(totals.wc_refunded)}</TD>
                            <TD className="igr:text-right igr:tabular-nums igr:text-muted-foreground">{money(totals.hub_refunded)}</TD>
                            <TD className="igr:text-center">
                                <span className="igr:inline-flex">
                                    <Mark ok={totals.refunds_ok === true} />
                                </span>
                            </TD>
                        </TR>
                    ) : null}
                </TBody>
            </Table>
        </div>
    );
}
