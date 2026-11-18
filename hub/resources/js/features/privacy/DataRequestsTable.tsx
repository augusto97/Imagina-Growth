import { Download, FileSearch, Play } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/misc';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatDate, formatNumber, parseApiDate } from '@/lib/format';
import { toast } from '@/stores/toast';
import { errorMessage } from '@/features/contacts/errors';
import { ConfirmDeleteDialog } from './ConfirmDeleteDialog';
import { type DataRequest, downloadUrl, useExecuteDataRequest } from './api';

/** ¿Vence en menos de 3 días o ya venció? (el plazo legal corre en días hábiles, cumplimiento.md §9). */
export function dueTone(request: Pick<DataRequest, 'status' | 'due_at'>, now: Date): 'overdue' | 'soon' | 'ok' | null {
    if (request.status === 'completed' || !request.due_at) {
        return null;
    }
    const days = (parseApiDate(request.due_at).getTime() - now.getTime()) / 86_400_000;
    return days < 0 ? 'overdue' : days < 3 ? 'soon' : 'ok';
}

export function DataRequestsTable({ requests, canExport, canDelete }: { requests: DataRequest[]; canExport: boolean; canDelete: boolean }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const execute = useExecuteDataRequest(workspace.id);
    const [confirming, setConfirming] = useState<DataRequest | null>(null);
    const [now] = useState(() => new Date());

    if (requests.length === 0) {
        return <EmptyState icon={FileSearch} title={t('privacy.requests.emptyTitle')} description={t('privacy.requests.emptyDescription')} />;
    }

    const run = (request: DataRequest, confirm?: string) =>
        execute.mutate(
            { id: request.id, ...(confirm ? { confirm } : {}) },
            {
                onSuccess: (done) => {
                    setConfirming(null);
                    toast.success(done.kind === 'delete' ? t('privacy.requests.deletedToast') : t('privacy.requests.exportedToast'));
                },
                onError: (error) => toast.error(errorMessage(error, t)),
            },
        );

    return (
        <>
            <Table>
                <THead>
                    <TR className="igr:hover:bg-transparent">
                        <TH>{t('privacy.requests.kind')}</TH>
                        <TH>{t('privacy.requests.status')}</TH>
                        <TH className="igr:hidden igr:md:table-cell">{t('privacy.requests.origin')}</TH>
                        <TH>{t('privacy.requests.contact')}</TH>
                        <TH>{t('privacy.requests.created')}</TH>
                        <TH>{t('privacy.requests.due')}</TH>
                        <TH className="igr:hidden igr:lg:table-cell">{t('privacy.requests.result')}</TH>
                        <TH className="igr:text-right">
                            <span className="igr:sr-only">{t('privacy.requests.actions')}</span>
                        </TH>
                    </TR>
                </THead>
                <TBody>
                    {requests.map((r) => {
                        const tone = dueTone(r, now);
                        const allowed = r.kind === 'delete' ? canDelete : canExport;
                        return (
                            <TR key={r.id}>
                                <TD className="igr:font-medium">{r.kind === 'delete' ? t('privacy.kind.delete') : t('privacy.kind.access')}</TD>
                                <TD>
                                    <Badge variant={r.status === 'completed' ? 'success' : 'warning'}>
                                        {r.status === 'completed' ? t('privacy.status.completed') : t('privacy.status.pending')}
                                    </Badge>
                                </TD>
                                <TD className="igr:hidden igr:text-muted-foreground igr:md:table-cell">{r.origin ? t(`privacy.origin.${r.origin}`, { defaultValue: r.origin }) : '—'}</TD>
                                <TD>
                                    {r.contact_id ? (
                                        <Link className="igr:text-primary igr:hover:underline" to={`/w/${workspace.id}/contactos/${r.contact_id}`}>
                                            {t('privacy.requests.viewProfile')}
                                        </Link>
                                    ) : (
                                        <span className="igr:text-muted-foreground">{r.kind === 'delete' && r.status === 'completed' ? t('privacy.requests.erased') : t('privacy.requests.unknownPerson')}</span>
                                    )}
                                </TD>
                                <TD className="igr:text-muted-foreground">{formatDate(r.created_at, workspace.locale)}</TD>
                                <TD>
                                    {r.status === 'completed' ? (
                                        <span className="igr:text-muted-foreground">{t('privacy.requests.doneOn', { date: formatDate(r.completed_at, workspace.locale) })}</span>
                                    ) : (
                                        <span className={tone === 'overdue' ? 'igr:font-medium igr:text-destructive' : tone === 'soon' ? 'igr:font-medium igr:text-warning' : undefined}>
                                            {formatDate(r.due_at, workspace.locale)}
                                            {tone === 'overdue' && ` · ${t('privacy.requests.overdue')}`}
                                        </span>
                                    )}
                                </TD>
                                <TD className="igr:hidden igr:text-muted-foreground igr:lg:table-cell">
                                    {r.result.result ??
                                        (r.result.events !== undefined
                                            ? t('privacy.requests.exportedEvents', { count: r.result.events, formatted: formatNumber(r.result.events, workspace.locale) })
                                            : r.result.deleted
                                              ? t('privacy.requests.deletedRows', {
                                                    formatted: formatNumber(
                                                        Object.values(r.result.deleted).reduce((a, b) => a + b, 0),
                                                        workspace.locale,
                                                    ),
                                                })
                                              : '—')}
                                </TD>
                                <TD className="igr:text-right">
                                    {r.downloadable && canExport ? (
                                        <Button variant="outline" size="sm" asChild>
                                            <a href={downloadUrl(workspace.id, r.id)} download>
                                                <Download />
                                                {t('privacy.requests.download')}
                                            </a>
                                        </Button>
                                    ) : r.status !== 'completed' && allowed ? (
                                        <Button
                                            variant={r.kind === 'delete' ? 'destructive' : 'outline'}
                                            size="sm"
                                            loading={execute.isPending && execute.variables.id === r.id}
                                            onClick={() => (r.kind === 'delete' ? setConfirming(r) : run(r))}
                                        >
                                            <Play />
                                            {r.kind === 'delete' ? t('privacy.requests.executeDelete') : t('privacy.requests.executeExport')}
                                        </Button>
                                    ) : null}
                                </TD>
                            </TR>
                        );
                    })}
                </TBody>
            </Table>
            <ConfirmDeleteDialog
                open={confirming !== null}
                onOpenChange={(open) => !open && setConfirming(null)}
                loading={execute.isPending}
                error={execute.isError ? errorMessage(execute.error, t) : null}
                onConfirm={(confirm) => confirming && run(confirming, confirm)}
            />
        </>
    );
}
