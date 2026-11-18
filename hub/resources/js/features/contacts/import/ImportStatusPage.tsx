import { ArrowLeft, CircleAlert, FileUp, ShieldAlert, ShieldCheck, Users } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link, useParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState, ErrorState, PageHeader } from '@/components/ui/misc';
import { Progress } from '@/components/ui/progress';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatDate, formatDateTime, formatNumber } from '@/lib/format';
import { isApiError } from '@/lib/api';
import { useImport } from '../api';
import { ImportStatusBadge } from './StatusBadge';

/** Avance y resultado de una importación CSV (se consulta cada 1,5 s hasta terminar). */
export function ImportStatusPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const { importId = '' } = useParams();
    const job = useImport(workspace.id, importId);
    const back = (
        <Link
            to={`/w/${workspace.id}/contactos/importar`}
            className="igr:mb-3 igr:inline-flex igr:items-center igr:gap-1 igr:text-[13px] igr:text-muted-foreground igr:hover:text-foreground"
        >
            <ArrowLeft className="igr:size-3.5" />
            {t('contacts.import.title')}
        </Link>
    );

    if (job.isError) {
        return (
            <>
                {back}
                {isApiError(job.error, 'not_found') ? <EmptyState icon={FileUp} title={t('contacts.import.status.notFound')} /> : <ErrorState error={job.error} onRetry={() => void job.refetch()} />}
            </>
        );
    }
    if (job.isPending) {
        return (
            <>
                {back}
                <Skeleton className="igr:h-64" />
            </>
        );
    }
    const imp = job.data;
    const finished = imp.status === 'completed' || imp.status === 'failed';
    const totals = imp.totals;
    const done = totals.created + totals.updated + totals.skipped + totals.invalid;
    const progress = finished ? 1 : totals.rows > 0 ? Math.min(0.99, done / totals.rows) : null;
    const n = (v: number | undefined) => formatNumber(v ?? 0, workspace.locale);

    return (
        <>
            {back}
            <PageHeader
                title={imp.filename}
                description={t('contacts.import.status.started', { date: formatDateTime(imp.created_at, workspace.locale, workspace.timezone) })}
                actions={<ImportStatusBadge status={imp.status} />}
            />
            <Card className="igr:mb-4">
                <CardContent className="igr:space-y-4 igr:p-4">
                    {!finished && (
                        <div className="igr:space-y-1.5">
                            <p className="igr:text-[13px] igr:font-medium">{imp.status === 'queued' ? t('contacts.import.status.queued') : t('contacts.import.status.running')}</p>
                            <Progress value={progress} label={t('contacts.import.status.progress')} />
                        </div>
                    )}
                    {imp.status === 'failed' && (
                        <p className="igr:flex igr:items-center igr:gap-2 igr:text-[13px] igr:text-destructive">
                            <CircleAlert className="igr:size-4" />
                            {t('contacts.import.status.failed')}
                        </p>
                    )}
                    <dl className="igr:grid igr:grid-cols-2 igr:gap-px igr:overflow-hidden igr:rounded-lg igr:border igr:bg-border igr:sm:grid-cols-3 igr:lg:grid-cols-6">
                        {(
                            [
                                ['rows', totals.rows],
                                ['created', totals.created],
                                ['updated', totals.updated],
                                ['skipped', totals.skipped],
                                ['invalid', totals.invalid],
                                ['consents', totals.consents],
                            ] as const
                        ).map(([key, value]) => (
                            <div key={key} className="igr:bg-card igr:px-3 igr:py-2.5">
                                <dt className="igr:text-xs igr:text-muted-foreground">{t(`contacts.import.totals.${key}`)}</dt>
                                <dd className="igr:mt-0.5 igr:text-base igr:font-semibold igr:tabular-nums">{n(value)}</dd>
                            </div>
                        ))}
                    </dl>
                    <p className="igr:flex igr:items-start igr:gap-2 igr:text-[13px] igr:text-muted-foreground">
                        {imp.consent ? (
                            <>
                                <ShieldCheck className="igr:mt-0.5 igr:size-4 igr:shrink-0 igr:text-success" />
                                {t('contacts.import.status.consentDeclared', {
                                    channels: imp.consent.channels.map((c) => t(`contacts.consentChannels.${c}`)).join(', '),
                                    date: formatDate(imp.consent.date, workspace.locale, workspace.timezone),
                                    source: imp.consent.source,
                                })}
                            </>
                        ) : (
                            <>
                                <ShieldAlert className="igr:mt-0.5 igr:size-4 igr:shrink-0 igr:text-warning" />
                                {t('contacts.import.status.consentNone')}
                            </>
                        )}
                    </p>
                    {finished && (
                        <div className="igr:flex igr:flex-wrap igr:gap-2">
                            <Button asChild>
                                <Link to={`/w/${workspace.id}/contactos`}>
                                    <Users />
                                    {t('contacts.import.status.viewContacts')}
                                </Link>
                            </Button>
                            <Button variant="outline" asChild>
                                <Link to={`/w/${workspace.id}/contactos/importar`}>
                                    <FileUp />
                                    {t('contacts.import.status.another')}
                                </Link>
                            </Button>
                        </div>
                    )}
                </CardContent>
            </Card>

            {imp.errors.length > 0 && (
                <Card>
                    <CardHeader>
                        <CardTitle>{t('contacts.import.status.errors')}</CardTitle>
                        <CardDescription>{t('contacts.import.status.errorsHelp')}</CardDescription>
                    </CardHeader>
                    <Table>
                        <THead>
                            <TR className="igr:hover:bg-transparent">
                                <TH className="igr:w-24">{t('contacts.import.status.row')}</TH>
                                <TH>{t('contacts.import.status.problem')}</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {imp.errors.map((e, i) => (
                                <TR key={`${e.row}-${i}`}>
                                    <TD className="igr:tabular-nums igr:text-muted-foreground">{e.row}</TD>
                                    <TD>{e.message}</TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                </Card>
            )}
        </>
    );
}
