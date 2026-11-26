import { ArrowRight, Mail, Sparkles } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link, useSearchParams } from 'react-router';
import { Select } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState, ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { toast } from '@/stores/toast';
import { useAiReport, useAiReports, useGenerateReport, useSendReport } from './api';
import { Markdown, SourceBadge } from './components';

/*
 * Informes del analista semanal (inteligencia.md §6.2): qué pasó, por qué y 3 acciones con su botón. Va dentro de
 * Resultados → Informes (pestaña «Semanal», InformesPage), con la lista de semanas y el detalle de la elegida.
 */
export function WeeklyReports() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const reports = useAiReports(workspace.id);
    const generate = useGenerateReport(workspace.id);
    const [search, setSearch] = useSearchParams();
    const selectedId = search.get('informe') ?? reports.data?.[0]?.id ?? null;
    const canGenerate = workspace.abilities.includes('useAi');
    const select = (id: string) => {
        const next = new URLSearchParams(search);
        next.set('informe', id);
        setSearch(next, { replace: true });
    };
    const generateButton = canGenerate ? (
        <Button
            size="sm"
            variant="outline"
            loading={generate.isPending}
            onClick={() =>
                generate.mutate(undefined, {
                    onSuccess: (r) => {
                        select(r.id);
                        toast.success(t('intelligence.reports.generated'));
                    },
                    onError: (err) => toast.error(isApiError(err) ? err.message : t('intelligence.reports.failed')),
                })
            }
        >
            <Sparkles className="igr:size-3.5" aria-hidden />
            {t('intelligence.reports.generate')}
        </Button>
    ) : null;

    if (reports.isError) {
        return <ErrorState error={reports.error} onRetry={() => void reports.refetch()} />;
    }
    if (reports.isPending) {
        return <Skeleton className="igr:h-60" />;
    }
    if (reports.data.length === 0) {
        return <EmptyState illustration="results" title={t('intelligence.reports.empty')} description={t('intelligence.reports.emptyHelp')} action={generateButton ?? undefined} />;
    }
    return (
        <div className="igr:space-y-3">
            <div className="igr:flex igr:flex-wrap igr:items-center igr:justify-between igr:gap-2">
                <label className="igr:flex igr:min-w-0 igr:items-center igr:gap-2 igr:text-support igr:text-muted-foreground">
                    <span className="igr:shrink-0">{t('intelligence.reports.weeks')}</span>
                    <Select value={selectedId ?? ''} onChange={(e) => select(e.target.value)} className="igr:w-auto igr:min-w-0">
                        {reports.data.map((r) => (
                            <option key={r.id} value={r.id}>
                                {t('intelligence.reports.week', { from: formatDate(r.period_start, workspace.locale, 'UTC'), to: formatDate(r.period_end, workspace.locale, 'UTC') })}
                            </option>
                        ))}
                    </Select>
                </label>
                {generateButton}
            </div>
            {selectedId ? <ReportDetail id={selectedId} /> : null}
        </div>
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
            <CardHeader className="igr:flex igr:flex-row igr:flex-wrap igr:items-start igr:justify-between igr:gap-2">
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
                            <li key={i} className="igr:flex igr:flex-wrap igr:items-start igr:justify-between igr:gap-3 igr:rounded-md igr:border igr:p-3">
                                <div className="igr:min-w-0 igr:flex-1 igr:basis-56">
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
