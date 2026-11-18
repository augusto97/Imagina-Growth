import { useTranslation } from 'react-i18next';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatDate, formatMoney } from '@/lib/format';
import { ConversionStatusBadge } from './labels';
import type { Conversion } from './types';

/** Pedidos atribuidos a afiliados con su comisión y estado. */
export function ConversionsTable({ rows, showAffiliate = true }: { rows: Conversion[]; showAffiliate?: boolean }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const money = (v: string) => formatMoney(v, workspace.currency, workspace.locale);
    return (
        <Table>
            <THead>
                <TR>
                    <TH>{t('referrals.conversions.date')}</TH>
                    {showAffiliate ? <TH>{t('referrals.conversions.affiliate')}</TH> : null}
                    <TH>{t('referrals.conversions.order')}</TH>
                    <TH>{t('referrals.conversions.via')}</TH>
                    <TH>{t('referrals.conversions.campaign')}</TH>
                    <TH className="igr:text-right">{t('referrals.conversions.amount')}</TH>
                    <TH className="igr:text-right">{t('referrals.conversions.commission')}</TH>
                    <TH>{t('referrals.conversions.statusLabel')}</TH>
                </TR>
            </THead>
            <TBody>
                {rows.map((c) => (
                    <TR key={c.id}>
                        <TD className="igr:whitespace-nowrap igr:text-muted-foreground">{formatDate(c.created_at, workspace.locale, workspace.timezone)}</TD>
                        {showAffiliate ? <TD>{c.affiliate?.name ?? '—'}</TD> : null}
                        <TD className="igr:tabular-nums">{c.order ? `#${c.order.number}` : '—'}</TD>
                        <TD>{t(`referrals.via.${c.via}`)}</TD>
                        <TD className="igr:text-muted-foreground">{c.campaign ?? '—'}</TD>
                        <TD className="igr:text-right igr:tabular-nums">{money(c.amount_ws)}</TD>
                        <TD className="igr:text-right igr:tabular-nums">{money(c.commission_ws)}</TD>
                        <TD>
                            <ConversionStatusBadge status={c.status} />
                            {c.status === 'pending' && c.qualifies_at ? <span className="igr:mt-1 igr:block igr:text-[11px] igr:text-muted-foreground">{t('referrals.conversions.approvesOn', { date: formatDate(c.qualifies_at, workspace.locale, workspace.timezone) })}</span> : null}
                            {c.void_reason ? <span className="igr:mt-1 igr:block igr:text-[11px] igr:text-muted-foreground">{t(`referrals.rejectedReason.${c.void_reason}`)}</span> : null}
                        </TD>
                    </TR>
                ))}
            </TBody>
        </Table>
    );
}
