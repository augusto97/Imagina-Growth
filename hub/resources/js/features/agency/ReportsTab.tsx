import { Download, FileText, RefreshCw, Send } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { EmptyState, ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { formatMoney, formatRelative } from '@/lib/format';
import { toast } from '@/stores/toast';
import { agencyReportPdfUrl, useAgencyGenerateReport, useAgencyReports, useAgencySendReport } from './api';
import { formatDelta, previousMonth } from './model';

/* Reportes mensuales de todos los clientes (M22). */
export function ReportsTab() {
    const { t, i18n } = useTranslation();
    const { workspace } = useWorkspace();
    const [month, setMonth] = useState(previousMonth());
    const reports = useAgencyReports(workspace.id, month);
    const generate = useAgencyGenerateReport(workspace.id, month);
    const send = useAgencySendReport(workspace.id, month);
    const locale = i18n.language;
    const onError = (e: unknown) => toast.error(t('errors.title'), isApiError(e) ? e.message : undefined);

    return (
        <div className="igr:space-y-4">
            <div className="igr:flex igr:flex-wrap igr:items-end igr:justify-between igr:gap-3">
                <p className="igr:max-w-2xl igr:text-[13px] igr:text-muted-foreground">{t('agency.reports.intro')}</p>
                <label className="igr:flex igr:items-center igr:gap-2 igr:text-[13px]">
                    {t('agency.reports.month')}
                    <Input type="month" value={month} onChange={(e) => e.target.value && setMonth(e.target.value)} className="igr:w-40" />
                </label>
            </div>
            {reports.isError ? (
                <ErrorState error={reports.error} onRetry={() => void reports.refetch()} />
            ) : reports.isPending ? (
                <Skeleton className="igr:h-40 igr:w-full" />
            ) : reports.data.clients.length === 0 ? (
                <EmptyState icon={FileText} title={t('agency.empty')} />
            ) : (
                <div className="igr:overflow-x-auto igr:rounded-lg igr:border">
                    <Table>
                        <THead>
                            <TR>
                                <TH>{t('agency.columns.client')}</TH>
                                <TH className="igr:text-right">{t('agency.columns.generated')}</TH>
                                <TH>{t('agency.columns.change')}</TH>
                                <TH>{t('agency.reports.status')}</TH>
                                <TH>{t('agency.reports.owners')}</TH>
                                <TH />
                            </TR>
                        </THead>
                        <TBody>
                            {reports.data.clients.map((row) => {
                                const r = row.report;
                                const busy = (generate.isPending && generate.variables?.client === row.workspace_id) || (send.isPending && send.variables?.client === row.workspace_id);
                                return (
                                    <TR key={row.workspace_id}>
                                        <TD className="igr:font-medium">{row.workspace}</TD>
                                        <TD className="igr:text-right igr:tabular-nums">{r ? formatMoney(r.generated, r.currency, locale) : '—'}</TD>
                                        <TD className="igr:tabular-nums">{r ? formatDelta(r.change_pct, locale) : '—'}</TD>
                                        <TD>
                                            {r === null ? (
                                                <Badge variant="outline">{t('agency.reports.not_generated')}</Badge>
                                            ) : (
                                                <div className="igr:flex igr:flex-wrap igr:gap-1">
                                                    <Badge variant="secondary">{t(r.summary_source === 'ai' ? 'agency.reports.generated_ai' : 'agency.reports.generated_fallback')}</Badge>
                                                    <Badge variant={r.sent_at ? 'success' : 'outline'}>{r.sent_at ? t('agency.reports.sent', { when: formatRelative(r.sent_at, locale) }) : t('agency.reports.not_sent')}</Badge>
                                                </div>
                                            )}
                                        </TD>
                                        <TD className="igr:text-muted-foreground">{row.owners > 0 ? row.owners : t('agency.reports.no_owners')}</TD>
                                        <TD>
                                            <div className="igr:flex igr:justify-end igr:gap-1.5">
                                                {r?.has_pdf ? (
                                                    <Button asChild size="sm" variant="ghost">
                                                        <a href={agencyReportPdfUrl(workspace.id, row.workspace_id, r.id)} target="_blank" rel="noreferrer">
                                                            <Download aria-hidden />
                                                            {t('agency.reports.download')}
                                                        </a>
                                                    </Button>
                                                ) : null}
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    loading={busy && generate.isPending}
                                                    onClick={() => generate.mutate({ client: row.workspace_id, send: false }, { onSuccess: () => toast.success(t('agency.reports.done')), onError })}
                                                >
                                                    <RefreshCw aria-hidden />
                                                    {t('agency.reports.generate')}
                                                </Button>
                                                {r ? (
                                                    <Button
                                                        size="sm"
                                                        disabled={row.owners === 0}
                                                        loading={busy && send.isPending}
                                                        onClick={() =>
                                                            send.mutate(
                                                                { client: row.workspace_id, report: r.id },
                                                                { onSuccess: (res) => toast.success(t('agency.reports.sent_ok', { count: res.sent.count })), onError },
                                                            )
                                                        }
                                                    >
                                                        <Send aria-hidden />
                                                        {r.sent_at ? t('agency.reports.resend') : t('agency.reports.send')}
                                                    </Button>
                                                ) : null}
                                            </div>
                                        </TD>
                                    </TR>
                                );
                            })}
                        </TBody>
                    </Table>
                </div>
            )}
        </div>
    );
}
