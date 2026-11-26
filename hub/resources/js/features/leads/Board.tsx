import { useQueryClient } from '@tanstack/react-query';
import { ArrowRight } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Chip, ChipGroup } from '@/components/ui/chip';
import { EmptyState, ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { PHONE_QUERY, useMedia } from '@/hooks/useMedia';
import { useWorkspace } from '@/hooks/useWorkspace';
import { cn } from '@/lib/cn';
import { formatMoney, formatNumber } from '@/lib/format';
import { toast } from '@/stores/toast';
import { errorMessage } from '@/features/contacts/errors';
import { leadKeys, useBoard, useMoveLead } from './api';
import { LeadCard } from './LeadCard';
import { BOARD_PAGE, firstBusyStage, moveCard, moveNeeds } from './logic';
import { MoveDialog } from './MoveDialog';
import type { BoardColumn, BoardResponse, LeadFilters, LeadRow, Stage } from './types';

/**
 * Tablero por etapas del embudo del negocio (las etapas vienen del API: la clínica tiene el de salud). En el escritorio,
 * columnas con arrastrar y soltar; en el celular, una etapa a la vista (chips con su conteo) y «Mover a» en cada tarjeta.
 * Ganado pide el valor y perdido el motivo; el cambio se ve al instante y se deshace si el servidor lo rechaza.
 */
export function Board({ filters, onOpen, onSeeAll }: { filters: LeadFilters; onOpen: (id: string) => void; onSeeAll: (stage: string) => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const qc = useQueryClient();
    const phone = useMedia(PHONE_QUERY);
    const board = useBoard(workspace.id, filters);
    const move = useMoveLead(workspace.id);
    const [dragId, setDragId] = useState<string | null>(null);
    const [over, setOver] = useState<string | null>(null);
    const [pending, setPending] = useState<{ lead: LeadRow; stage: Stage } | null>(null);
    const [shown, setShown] = useState<Record<string, number>>({});
    const [picked, setPicked] = useState<string | null>(null);
    const [now] = useState(() => Date.now());

    if (board.isError) return <ErrorState error={board.error} onRetry={() => void board.refetch()} />;
    if (board.isPending) {
        return (
            <div className="igr:flex igr:gap-3 igr:overflow-hidden" aria-busy>
                {[0, 1, 2, 3, 4].map((i) => (
                    <Skeleton key={i} className="igr:h-80 igr:w-72 igr:shrink-0 igr:rounded-xl" />
                ))}
            </div>
        );
    }
    const data = board.data;
    const total = data.columns.reduce((s, c) => s + c.count, 0);
    const key = leadKeys.board(workspace.id, filters);
    const stages: Stage[] = data.columns.map(({ key: k, label, kind }) => ({ key: k, label, kind }));

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
    const request = (lead: LeadRow, stage: Stage) => {
        if (lead.stage === stage.key) return;
        if (moveNeeds(stage) !== null) setPending({ lead, stage });
        else commit(lead, stage);
    };
    const drop = (stage: Stage) => {
        const lead = data.columns.flatMap((c) => c.leads).find((l) => l.id === dragId);
        setDragId(null);
        setOver(null);
        if (lead) request(lead, stage);
    };
    const limit = (col: BoardColumn) => shown[col.key] ?? BOARD_PAGE;
    const more = (col: BoardColumn) => {
        const visible = Math.min(limit(col), col.leads.length);
        if (visible < col.leads.length) {
            return (
                <Button variant="ghost" size="sm" className="igr:mt-2 igr:w-full" onClick={() => setShown({ ...shown, [col.key]: visible + BOARD_PAGE })}>
                    {t('leads.board.showMore', { count: Math.min(BOARD_PAGE, col.leads.length - visible) })}
                </Button>
            );
        }
        if (col.count > col.leads.length) {
            return (
                <Button variant="ghost" size="sm" className="igr:mt-2 igr:w-full" onClick={() => onSeeAll(col.key)}>
                    {t('leads.board.seeAll', { count: col.count })}
                    <ArrowRight />
                </Button>
            );
        }
        return null;
    };
    const header = (col: BoardColumn) => (
        <header className="igr:mb-2 igr:px-1">
            <div className="igr:flex igr:items-center igr:justify-between igr:gap-2 igr:text-[13px] igr:font-semibold">
                <span className="igr:flex igr:min-w-0 igr:items-center igr:gap-1.5">
                    <span
                        aria-hidden
                        className={cn('igr:size-2 igr:shrink-0 igr:rounded-full', col.kind === 'won' ? 'igr:bg-success' : col.kind === 'lost' ? 'igr:bg-muted-foreground/50' : 'igr:bg-primary')}
                    />
                    <span className="igr:truncate">{col.label}</span>
                </span>
                <span className="igr:rounded-full igr:bg-muted igr:px-1.5 igr:text-caption igr:font-medium igr:text-muted-foreground igr:tabular-nums">{formatNumber(col.count, workspace.locale)}</span>
            </div>
            <p className="igr:mt-0.5 igr:text-caption igr:text-muted-foreground igr:tabular-nums">{formatMoney(col.value, data.currency, workspace.locale)}</p>
        </header>
    );

    const dialog = pending ? (
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
    ) : null;
    const empty =
        total === 0 ? (
            <EmptyState
                illustration="customers"
                size="compact"
                title={t('leads.board.emptyTitle')}
                description={t('leads.board.emptyDescription')}
                steps={[t('leads.board.emptyStep1'), t('leads.board.emptyStep2'), t('leads.board.emptyStep3')]}
                className="igr:mb-4"
            />
        ) : null;

    if (phone) {
        const activeKey = picked !== null && data.columns.some((c) => c.key === picked) ? picked : firstBusyStage(data.columns);
        const col = data.columns.find((c) => c.key === activeKey) ?? data.columns[0];
        return (
            <>
                {empty}
                <ChipGroup label={t('leads.board.stages')} scroll className="igr:mb-3">
                    {data.columns.map((c) => (
                        <Chip key={c.key} pressed={c.key === col?.key} count={formatNumber(c.count, workspace.locale)} onClick={() => setPicked(c.key)}>
                            {c.label}
                        </Chip>
                    ))}
                </ChipGroup>
                {col ? (
                    <section aria-label={col.label}>
                        {header(col)}
                        {col.leads.length === 0 ? (
                            <p className="igr:rounded-lg igr:border igr:border-dashed igr:px-3 igr:py-6 igr:text-center igr:text-support igr:text-muted-foreground">{t('leads.board.emptyStage')}</p>
                        ) : (
                            <ul className="igr:space-y-2">
                                {col.leads.slice(0, limit(col)).map((lead) => (
                                    <li key={lead.id}>
                                        <LeadCard lead={lead} onOpen={() => onOpen(lead.id)} stages={stages} onMove={(s) => request(lead, s)} now={now} />
                                    </li>
                                ))}
                            </ul>
                        )}
                        {more(col)}
                    </section>
                ) : null}
                {dialog}
            </>
        );
    }

    return (
        <>
            {empty}
            <div className="igr:flex igr:gap-3 igr:overflow-x-auto igr:pb-3" role="region" aria-label={t('leads.board.label')} tabIndex={0}>
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
                            'igr:flex igr:w-72 igr:shrink-0 igr:flex-col igr:rounded-xl igr:border igr:bg-muted/40 igr:p-2 igr:transition-colors',
                            over === col.key && dragId !== null && 'igr:border-primary/40 igr:bg-brand-soft/50',
                        )}
                    >
                        {header(col)}
                        <ul className="igr:flex igr:min-h-24 igr:flex-col igr:gap-2">
                            {col.leads.slice(0, limit(col)).map((lead) => (
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
                                    className="igr:cursor-grab igr:active:cursor-grabbing"
                                >
                                    <LeadCard lead={lead} onOpen={() => onOpen(lead.id)} dragging={dragId === lead.id} stages={stages} onMove={(s) => request(lead, s)} now={now} />
                                </li>
                            ))}
                        </ul>
                        {more(col)}
                    </section>
                ))}
            </div>
            {dialog}
        </>
    );
}
