import { Download, FileText, RefreshCw, Send, Sparkles } from 'lucide-react';
import { Fragment, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState, ErrorState, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useWorkspace } from '@/hooks/useWorkspace';
import { api, isApiError } from '@/lib/api';
import { formatMoney, formatRelative } from '@/lib/format';
import { wsPath } from '@/api/queries';
import { toast } from '@/stores/toast';
import { type ReportSummary, clientReportPdfUrl, useClientReports, useGenerateClientReport, useResendClientReport } from './api';
import { formatDelta } from './model';

/* Vista del cliente: sus reportes mensuales con la marca de la agencia (M22). */
export function ClientReportsPage() {
    const { t, i18n } = useTranslation();
    const { workspace } = useWorkspace();
    const reports = useClientReports(workspace.id);
    const generate = useGenerateClientReport(workspace.id);
    const resend = useResendClientReport(workspace.id);
    const [open, setOpen] = useState<string | null>(null);
    const [summary, setSummary] = useState<Record<string, ReportSummary>>({});
    const locale = i18n.language;
    const onError = (e: unknown) => toast.error(t('errors.title'), isApiError(e) ? e.message : undefined);

    const toggle = (id: string) => {
        setOpen(open === id ? null : id);
        if (!summary[id]) {
            void api.get<{ report: ReportSummary }>(`${wsPath(workspace.id)}/reports/${encodeURIComponent(id)}`).then((r) => setSummary((s) => ({ ...s, [id]: r.report })));
        }
    };

    return (
        <div className="igr:mx-auto igr:max-w-5xl igr:p-6">
            <PageHeader
                title={t('client_reports.title')}
                description={t('client_reports.description')}
                actions={
                    reports.data?.can_manage ? (
                        <Button
                            variant="outline"
                            loading={generate.isPending}
                            onClick={() => generate.mutate({ month: reports.data.last_month, send: false }, { onSuccess: () => toast.success(t('agency.reports.done')), onError })}
                        >
                            <RefreshCw aria-hidden />
                            {t('client_reports.generate_last', { month: reports.data.last_month })}
                        </Button>
                    ) : null
                }
            />
            {reports.isError ? (
                <ErrorState error={reports.error} onRetry={() => void reports.refetch()} />
            ) : reports.isPending ? (
                <Skeleton className="igr:h-40 igr:w-full" />
            ) : reports.data.reports.length === 0 ? (
                <EmptyState icon={FileText} title={t('client_reports.empty')} />
            ) : (
                <div className="igr:overflow-x-auto igr:rounded-lg igr:border">
                    <Table>
                        <THead>
                            <TR>
                                <TH>{t('agency.reports.month')}</TH>
                                <TH className="igr:text-right">{t('client_reports.generated')}</TH>
                                <TH>{t('client_reports.change')}</TH>
                                <TH>{t('agency.reports.status')}</TH>
                                <TH />
                            </TR>
                        </THead>
                        <TBody>
                            {reports.data.reports.map((r) => (
                                <Fragment key={r.id}>
                                    <TR>
                                        <TD className="igr:font-medium igr:first-letter:uppercase">
                                            <button type="button" className="igr:hover:underline" onClick={() => toggle(r.id)} aria-expanded={open === r.id}>
                                                {r.month || r.period}
                                            </button>
                                        </TD>
                                        <TD className="igr:text-right igr:tabular-nums">{formatMoney(r.generated, r.currency, locale)}</TD>
                                        <TD className="igr:tabular-nums">{formatDelta(r.change_pct, locale)}</TD>
                                        <TD>
                                            <Badge variant={r.sent_at ? 'success' : 'outline'}>{r.sent_at ? t('agency.reports.sent', { when: formatRelative(r.sent_at, locale) }) : t('agency.reports.not_sent')}</Badge>
                                        </TD>
                                        <TD>
                                            <div className="igr:flex igr:justify-end igr:gap-1.5">
                                                {r.has_pdf ? (
                                                    <Button asChild size="sm" variant="ghost">
                                                        <a href={clientReportPdfUrl(workspace.id, r.id)} target="_blank" rel="noreferrer">
                                                            <Download aria-hidden />
                                                            {t('agency.reports.download')}
                                                        </a>
                                                    </Button>
                                                ) : null}
                                                {reports.data.can_manage ? (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        loading={resend.isPending && resend.variables === r.id}
                                                        onClick={() => resend.mutate(r.id, { onSuccess: (res) => toast.success(t('agency.reports.sent_ok', { count: res.sent.count })), onError })}
                                                    >
                                                        <Send aria-hidden />
                                                        {r.sent_at ? t('agency.reports.resend') : t('agency.reports.send')}
                                                    </Button>
                                                ) : null}
                                            </div>
                                        </TD>
                                    </TR>
                                    {open === r.id ? (
                                        <TR>
                                            <TD colSpan={5} className="igr:bg-muted/30">
                                                <div className="igr:space-y-2 igr:py-1 igr:text-[13px] igr:whitespace-pre-line">
                                                    {summary[r.id]?.summary ?? <Skeleton className="igr:h-12 igr:w-full" />}
                                                    {summary[r.id]?.summary_source === 'ai' ? (
                                                        <p className="igr:flex igr:items-center igr:gap-1 igr:text-xs igr:text-muted-foreground">
                                                            <Sparkles className="igr:size-3" aria-hidden />
                                                            {t('client_reports.ai_note')}
                                                        </p>
                                                    ) : null}
                                                </div>
                                            </TD>
                                        </TR>
                                    ) : null}
                                </Fragment>
                            ))}
                        </TBody>
                    </Table>
                </div>
            )}
        </div>
    );
}
