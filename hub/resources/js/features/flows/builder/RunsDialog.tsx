import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Select } from '@/components/ui/input';
import { EmptyState, ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatDateTime } from '@/lib/format';
import type { GraphNode } from '../types';
import { Route } from 'lucide-react';
import { useFlowRuns } from '../api';

/** Quién va por el flujo y dónde está. */
export function RunsDialog({ flowId, open, onOpenChange, nodes }: { flowId: string; open: boolean; onOpenChange: (open: boolean) => void; nodes: GraphNode[] }) {
    const { t } = useTranslation();
    // El paso con su nombre (y el ID entre paréntesis si hay varios del mismo tipo).
    const stepLabel = (id: string | null) => {
        const node = id === null ? undefined : nodes.find((n) => n.id === id);
        if (id === null || node === undefined) {
            return id ?? '—';
        }
        const name = t(`flows.nodes.${node.type}`, { defaultValue: node.type });
        return nodes.filter((n) => n.type === node.type).length > 1 ? `${name} (${id})` : name;
    };
    const { workspace } = useWorkspace();
    const [status, setStatus] = useState('');
    const [page, setPage] = useState(1);
    const runs = useFlowRuns(workspace.id, flowId, status, page, open);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="igr:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>{t('flows.runs.title')}</DialogTitle>
                    <DialogDescription className="igr:sr-only">{t('flows.runs.title')}</DialogDescription>
                </DialogHeader>
                <div className="igr:mb-3 igr:flex igr:justify-end">
                    <Select
                        aria-label={t('flows.runs.status')}
                        value={status}
                        onChange={(e) => {
                            setStatus(e.target.value);
                            setPage(1);
                        }}
                    >
                        <option value="">{t('flows.runs.all')}</option>
                        {(['active', 'waiting', 'completed', 'exited', 'failed'] as const).map((s) => (
                            <option key={s} value={s}>
                                {t(`flows.runs.statuses.${s}`)}
                            </option>
                        ))}
                    </Select>
                </div>
                {runs.isPending ? (
                    <Skeleton className="igr:h-48" />
                ) : runs.isError ? (
                    <ErrorState error={runs.error} onRetry={() => void runs.refetch()} />
                ) : runs.data.data.length === 0 ? (
                    <EmptyState icon={Route} title={t('flows.runs.empty')} />
                ) : (
                    <>
                        <div className="igr:max-h-[55vh] igr:overflow-y-auto">
                            <Table>
                                <THead>
                                    <TR>
                                        <TH>{t('flows.runs.contact')}</TH>
                                        <TH>{t('flows.runs.status')}</TH>
                                        <TH>{t('flows.runs.node')}</TH>
                                        <TH className="igr:text-right">{t('flows.runs.started')}</TH>
                                    </TR>
                                </THead>
                                <TBody>
                                    {runs.data.data.map((r) => (
                                        <TR key={r.id}>
                                            <TD>
                                                {r.contact ? (
                                                    <Link className="igr:hover:underline" to={`/w/${workspace.id}/contactos/${r.contact.id}`}>
                                                        {r.contact.name}
                                                    </Link>
                                                ) : (
                                                    '—'
                                                )}
                                                {r.is_holdout ? (
                                                    <Badge variant="outline" className="igr:ml-1.5">
                                                        {t('flows.runs.holdout')}
                                                    </Badge>
                                                ) : null}
                                            </TD>
                                            <TD>
                                                <span>{t(`flows.runs.statuses.${r.status}`)}</span>
                                                {r.exit_reason && r.exit_reason !== 'completed' ? <span className="igr:ml-1 igr:text-xs igr:text-muted-foreground">· {t(`flows.runs.reasons.${r.exit_reason}`, { defaultValue: r.exit_reason })}</span> : null}
                                            </TD>
                                            <TD className="igr:text-muted-foreground">
                                                {stepLabel(r.current_node_id)}
                                                {r.next_run_at && (r.status === 'waiting' || r.status === 'active') ? <span className="igr:block igr:text-xs">{t('flows.runs.next')}: {formatDateTime(r.next_run_at, workspace.locale, workspace.timezone)}</span> : null}
                                            </TD>
                                            <TD className="igr:whitespace-nowrap igr:text-right igr:text-muted-foreground">{formatDateTime(r.started_at, workspace.locale, workspace.timezone)}</TD>
                                        </TR>
                                    ))}
                                </TBody>
                            </Table>
                        </div>
                        {runs.data.meta.last_page > 1 ? (
                            <div className="igr:mt-3 igr:flex igr:items-center igr:justify-end igr:gap-2 igr:text-xs igr:text-muted-foreground">
                                <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
                                    ‹
                                </Button>
                                {page} / {runs.data.meta.last_page}
                                <Button variant="outline" size="sm" disabled={page >= runs.data.meta.last_page} onClick={() => setPage((p) => p + 1)}>
                                    ›
                                </Button>
                            </div>
                        ) : null}
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}
