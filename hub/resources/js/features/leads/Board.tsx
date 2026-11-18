import { Inbox } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useQueryClient } from '@tanstack/react-query';
import { EmptyState, ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatMoney, formatNumber } from '@/lib/format';
import { cn } from '@/lib/cn';
import { toast } from '@/stores/toast';
import { errorMessage } from '@/features/contacts/errors';
import { leadKeys, useBoard, useMoveLead } from './api';
import { LeadCard } from './LeadCard';
import { moveCard, moveNeeds } from './logic';
import { MoveDialog } from './MoveDialog';
import type { BoardResponse, LeadFilters, LeadRow, Stage } from './types';

/**
 * Tablero por etapas con arrastrar y soltar. Ganado pide el valor y perdido el
 * motivo; el cambio se ve al instante y se deshace si el servidor lo rechaza.
 * Sin mouse, la etapa se cambia en el detalle del lead.
 */
export function Board({ filters, onOpen }: { filters: LeadFilters; onOpen: (id: string) => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const qc = useQueryClient();
    const board = useBoard(workspace.id, filters);
    const move = useMoveLead(workspace.id);
    const [dragId, setDragId] = useState<string | null>(null);
    const [over, setOver] = useState<string | null>(null);
    const [pending, setPending] = useState<{ lead: LeadRow; stage: Stage } | null>(null);

    if (board.isError) return <ErrorState error={board.error} onRetry={() => void board.refetch()} />;
    if (board.isPending) {
        return (
            <div className="igr:grid igr:grid-cols-2 igr:gap-3 igr:lg:grid-cols-6">
                {[0, 1, 2, 3, 4, 5].map((i) => (
                    <Skeleton key={i} className="igr:h-64" />
                ))}
            </div>
        );
    }
    const data = board.data;
    const total = data.columns.reduce((s, c) => s + c.count, 0);
    const key = leadKeys.board(workspace.id, filters);

    const commit = (lead: LeadRow, stage: Stage, extra: { value?: string | null; lost_reason?: string | null } = {}) => {
        const before = qc.getQueryData<BoardResponse>(key);
        if (before) qc.setQueryData<BoardResponse>(key, { ...before, columns: moveCard(before.columns, lead.id, stage.key) });
        move.mutate(
            { id: lead.id, stage: stage.key, ...extra },
            {
                onSuccess: () => toast.success(t('leads.move.done', { stage: stage.label })),
                onError: (e) => {
                    if (before) qc.setQueryData(key, before);
                    toast.error(errorMessage(e, t));
                },
            },
        );
    };
    const drop = (stage: Stage) => {
        const lead = data.columns.flatMap((c) => c.leads).find((l) => l.id === dragId);
        setDragId(null);
        setOver(null);
        if (!lead || lead.stage === stage.key) return;
        if (moveNeeds(stage) !== null) setPending({ lead, stage });
        else commit(lead, stage);
    };

    return (
        <>
            {total === 0 ? <EmptyState icon={Inbox} title={t('leads.board.emptyTitle')} description={t('leads.board.emptyDescription')} className="igr:mb-3" /> : null}
            <div className="igr:flex igr:gap-3 igr:overflow-x-auto igr:pb-2" aria-label={t('leads.board.label')}>
                {data.columns.map((col) => (
                    <section
                        key={col.key}
                        aria-label={col.label}
                        onDragOver={(e) => {
                            e.preventDefault();
                            setOver(col.key);
                        }}
                        onDragLeave={() => setOver((o) => (o === col.key ? null : o))}
                        onDrop={(e) => {
                            e.preventDefault();
                            drop(col);
                        }}
                        className={cn(
                            'igr:flex igr:w-64 igr:shrink-0 igr:flex-col igr:rounded-xl igr:border igr:bg-muted/30 igr:p-2',
                            over === col.key && dragId !== null && 'igr:border-foreground/30 igr:bg-muted/60',
                        )}
                    >
                        <header className="igr:mb-2 igr:px-1">
                            <div className="igr:flex igr:items-center igr:justify-between igr:gap-2 igr:text-[13px] igr:font-medium">
                                <span className="igr:truncate">{col.label}</span>
                                <span className="igr:tabular-nums igr:text-muted-foreground">{formatNumber(col.count, workspace.locale)}</span>
                            </div>
                            <p className="igr:text-xs igr:tabular-nums igr:text-muted-foreground">{formatMoney(col.value, data.currency, workspace.locale)}</p>
                        </header>
                        <ul className="igr:flex igr:min-h-24 igr:flex-col igr:gap-2">
                            {col.leads.map((lead) => (
                                <li
                                    key={lead.id}
                                    draggable
                                    onDragStart={(e) => {
                                        e.dataTransfer.setData('text/plain', lead.id);
                                        e.dataTransfer.effectAllowed = 'move';
                                        setDragId(lead.id);
                                    }}
                                    onDragEnd={() => {
                                        setDragId(null);
                                        setOver(null);
                                    }}
                                >
                                    <LeadCard lead={lead} onOpen={() => onOpen(lead.id)} dragging={dragId === lead.id} />
                                </li>
                            ))}
                        </ul>
                        {col.count > col.leads.length ? <p className="igr:mt-2 igr:px-1 igr:text-xs igr:text-muted-foreground">{t('leads.board.more', { count: col.count - col.leads.length })}</p> : null}
                    </section>
                ))}
            </div>
            {pending ? (
                <MoveDialog
                    key={pending.lead.id + pending.stage.key}
                    stage={pending.stage}
                    initialValue={pending.lead.value}
                    onCancel={() => setPending(null)}
                    onConfirm={(extra) => {
                        commit(pending.lead, pending.stage, extra);
                        setPending(null);
                    }}
                />
            ) : null}
        </>
    );
}
