import { ArrowRight, FileText, Mail, Sparkles } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link, useSearchParams } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState, ErrorState, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { cn } from '@/lib/cn';
import { toast } from '@/stores/toast';
import { useAiReport, useAiReports, useGenerateReport, useSendReport } from './api';
import { Markdown, SourceBadge } from './components';

/* Informes del analista semanal (inteligencia.md §6.2): qué pasó, por qué y 3 acciones con su botón. */
export function ReportsPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const reports = useAiReports(workspace.id);
    const generate = useGenerateReport(workspace.id);
    const [search, setSearch] = useSearchParams();
    const selectedId = search.get('informe') ?? reports.data?.[0]?.id ?? null;
    const canGenerate = workspace.abilities.includes('useAi');

    return (
        <>
            <PageHeader
                title={t('intelligence.reports.title')}
                description={t('intelligence.reports.description')}
                actions={
                    canGenerate ? (
                        <Button
                            size="sm"
                            loading={generate.isPending}
                            onClick={() =>
                                generate.mutate(undefined, {
                                    onSuccess: (r) => {
                                        setSearch({ informe: r.id }, { replace: true });
                                        toast.success(t('intelligence.reports.generated'));
                                    },
                                    onError: (err) => toast.error(isApiError(err) ? err.message : t('intelligence.reports.failed')),
                                })
                            }
                        >
                            <Sparkles className="igr:size-3.5" aria-hidden />
                            {t('intelligence.reports.generate')}
                        </Button>
                    ) : null
                }
            />
            {reports.isError ? (
                <ErrorState error={reports.error} onRetry={() => void reports.refetch()} />
            ) : reports.isPending ? (
                <Skeleton className="igr:h-60" />
            ) : reports.data.length === 0 ? (
                <EmptyState icon={FileText} title={t('intelligence.reports.empty')} description={t('intelligence.reports.emptyHelp')} />
            ) : (
                <div className="igr:grid igr:gap-4 igr:lg:grid-cols-[240px_1fr]">
                    <Card className="igr:h-fit">
                        <ul className="igr:divide-y" aria-label={t('intelligence.reports.weeks')}>
                            {reports.data.map((r) => (
                                <li key={r.id}>
                                    <button
                                        type="button"
                                        onClick={() => setSearch({ informe: r.id }, { replace: true })}
                                        aria-current={r.id === selectedId ? 'true' : undefined}
                                        className={cn('igr:flex igr:w-full igr:items-center igr:justify-between igr:gap-2 igr:px-3 igr:py-2.5 igr:text-left igr:text-[13px] igr:hover:bg-muted/40', r.id === selectedId && 'igr:bg-muted/60 igr:font-medium')}
                                    >
                                        <span>{t('intelligence.reports.week', { from: formatDate(r.period_start, workspace.locale, 'UTC'), to: formatDate(r.period_end, workspace.locale, 'UTC') })}</span>
                                        {r.source === 'ai' ? <Sparkles className="igr:size-3.5 igr:text-muted-foreground" aria-label={t('intelligence.source.ai')} /> : null}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </Card>
                    {selectedId ? <ReportDetail id={selectedId} /> : null}
                </div>
            )}
        </>
    );
}

function ReportDetail({ id }: { id: string }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const report = useAiReport(workspace.id, id);
    const send = useSendReport(workspace.id);
    const canSend = workspace.abilities.includes('manageWorkspace');
    if (report.isError) return <ErrorState error={report.error} onRetry={() => void report.refetch()} />;
    if (report.isPending) return <Skeleton className="igr:h-80" />;
    const r = report.data;

    return (
        <Card>
            <CardHeader className="igr:flex igr:flex-row igr:items-start igr:justify-between igr:gap-2">
                <div>
                    <CardTitle>{t('intelligence.reports.week', { from: formatDate(r.period_start, workspace.locale, 'UTC'), to: formatDate(r.period_end, workspace.locale, 'UTC') })}</CardTitle>
                    <p className="igr:mt-1 igr:text-xs igr:text-muted-foreground">
                        {r.delivered_at ? t('intelligence.reports.delivered', { date: formatDate(r.delivered_at, workspace.locale, workspace.timezone) }) : t('intelligence.reports.notDelivered')}
                    </p>
                </div>
                <div className="igr:flex igr:items-center igr:gap-2">
                    <SourceBadge source={r.source} reason={r.validation?.fallback_reason} />
                    {canSend ? (
                        <Button
                            variant="outline"
                            size="sm"
                            loading={send.isPending}
                            onClick={() =>
                                send.mutate(r.id, {
                                    onSuccess: (res) => toast.success(t('intelligence.reports.sent', { count: res.recipients })),
                                    onError: (err) => toast.error(isApiError(err) ? err.message : t('intelligence.reports.failed')),
                                })
                            }
                        >
                            <Mail className="igr:size-3.5" aria-hidden />
                            {t('intelligence.reports.resend')}
                        </Button>
                    ) : null}
                </div>
            </CardHeader>
            <CardContent className="igr:space-y-5">
                <Markdown body={r.body} />
                <div>
                    <h3 className="igr:mb-2 igr:text-[13px] igr:font-semibold">{t('intelligence.reports.actions')}</h3>
                    <ol className="igr:space-y-2">
                        {r.actions.map((a, i) => (
                            <li key={i} className="igr:flex igr:items-start igr:justify-between igr:gap-3 igr:rounded-md igr:border igr:p-3">
                                <div className="igr:min-w-0">
                                    <p className="igr:text-[13px] igr:font-medium">
                                        {i + 1}. {a.titulo}
                                    </p>
                                    <p className="igr:mt-0.5 igr:text-xs igr:text-muted-foreground">{a.detalle}</p>
                                </div>
                                <Button asChild size="sm" variant="secondary">
                                    <Link to={`/w/${workspace.id}/${a.ruta}`}>
                                        {a.tipo === 'flujo' ? t('intelligence.reports.openFlow') : t('intelligence.reports.open')}
                                        <ArrowRight className="igr:size-3.5" aria-hidden />
                                    </Link>
                                </Button>
                            </li>
                        ))}
                    </ol>
                </div>
                {r.source === 'ai' ? (
                    <p className="igr:text-xs igr:text-muted-foreground">
                        <Badge variant="outline" className="igr:mr-1">
                            {t('intelligence.reports.checked', { count: r.validation?.attempts?.[r.validation.attempts.length - 1]?.checked ?? 0 })}
                        </Badge>
                        {t('intelligence.reports.checkedHelp')}
                    </p>
                ) : null}
            </CardContent>
        </Card>
    );
}
