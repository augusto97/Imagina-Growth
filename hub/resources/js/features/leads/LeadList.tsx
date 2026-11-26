import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Select } from '@/components/ui/input';
import { EmptyState, ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatMoney, formatRelative } from '@/lib/format';
import { useLeadList } from './api';
import { temperatureTone } from './logic';
import type { LeadFilters } from './types';

/** Lista de leads con estado, orden y páginas. */
export function LeadList({ filters, onOpen, onChange }: { filters: LeadFilters; onOpen: (id: string) => void; onChange: (key: string, value: string | null) => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const list = useLeadList(workspace.id, filters);
    const stageLabel = (key: string) => list.data?.pipeline.stages.find((s) => s.key === key)?.label ?? key;

    return (
        <div className="igr:space-y-3">
            <div className="igr:flex igr:flex-wrap igr:gap-2">
                <label>
                    <span className="igr:sr-only">{t('leads.list.stage')}</span>
                    <Select className="igr:h-8 igr:w-44" value={filters.stage ?? ''} onChange={(e) => onChange('stage', e.target.value)}>
                        <option value="">{t('leads.list.anyStage')}</option>
                        {(list.data?.pipeline.stages ?? []).map((s) => (
                            <option key={s.key} value={s.key}>
                                {s.label}
                            </option>
                        ))}
                    </Select>
                </label>
                <label>
                    <span className="igr:sr-only">{t('leads.list.status')}</span>
                    <Select className="igr:h-8 igr:w-40" value={filters.status ?? ''} onChange={(e) => onChange('status', e.target.value)}>
                        {(['', 'open', 'won', 'lost'] as const).map((s) => (
                            <option key={s} value={s}>
                                {t(`leads.list.statuses.${s || 'all'}`)}
                            </option>
                        ))}
                    </Select>
                </label>
                <label>
                    <span className="igr:sr-only">{t('leads.list.sort')}</span>
                    <Select className="igr:h-8 igr:w-48" value={filters.sort ?? 'recent'} onChange={(e) => onChange('sort', e.target.value === 'recent' ? null : e.target.value)}>
                        {(['recent', 'score', 'value', 'activity'] as const).map((s) => (
                            <option key={s} value={s}>
                                {t(`leads.list.sorts.${s}`)}
                            </option>
                        ))}
                    </Select>
                </label>
            </div>
            {list.isError ? (
                <ErrorState error={list.error} onRetry={() => void list.refetch()} />
            ) : list.isPending ? (
                <Skeleton className="igr:h-72" />
            ) : list.data.data.length === 0 ? (
                <EmptyState illustration="search" size="compact" title={t('leads.list.emptyTitle')} description={t('leads.list.emptyDescription')} />
            ) : (
                <Card className="igr:overflow-x-auto">
                    <Table>
                        <THead>
                            <TR>
                                <TH>{t('leads.list.cols.lead')}</TH>
                                <TH>{t('leads.list.cols.stage')}</TH>
                                <TH>{t('leads.list.cols.score')}</TH>
                                <TH>{t('leads.list.cols.source')}</TH>
                                <TH>{t('leads.list.cols.owner')}</TH>
                                <TH className="igr:text-right">{t('leads.list.cols.value')}</TH>
                                <TH>{t('leads.list.cols.activity')}</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {list.data.data.map((l) => (
                                <TR key={l.id} className="igr:cursor-pointer igr:hover:bg-muted/40" onClick={() => onOpen(l.id)}>
                                    <TD>
                                        <button type="button" className="igr:text-left igr:font-medium igr:hover:underline" onClick={() => onOpen(l.id)}>
                                            {l.contact?.name ?? t('leads.noName')}
                                        </button>
                                        <p className="igr:text-xs igr:text-muted-foreground">{l.service ?? t('leads.noService')}</p>
                                    </TD>
                                    <TD>{stageLabel(l.stage)}</TD>
                                    <TD>
                                        <Badge variant={temperatureTone(l.temperature)} title={t('leads.card.score', { score: l.score })}>
                                            {t(`leads.temperature.${l.temperature}`)}
                                        </Badge>
                                    </TD>
                                    <TD className="igr:text-[13px]">{t(`leads.originsShort.${l.origin}`, { defaultValue: t(`leads.origins.${l.origin}`) })}</TD>
                                    <TD className="igr:text-[13px]">{l.owner?.name ?? t('leads.unassigned')}</TD>
                                    <TD className="igr:text-right igr:tabular-nums">{l.value !== null ? formatMoney(l.value, l.currency, workspace.locale) : '—'}</TD>
                                    <TD className="igr:text-[13px] igr:text-muted-foreground">{formatRelative(l.last_activity_at ?? l.created_at, workspace.locale)}</TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                </Card>
            )}
            {list.data && list.data.meta.last_page > 1 ? (
                <div className="igr:flex igr:items-center igr:justify-end igr:gap-2 igr:text-[13px]">
                    <Button variant="outline" size="sm" disabled={list.data.meta.page <= 1} onClick={() => onChange('page', String(list.data.meta.page - 1))}>
                        {t('leads.list.prev')}
                    </Button>
                    <span className="igr:text-muted-foreground">{t('leads.list.page', { page: list.data.meta.page, last: list.data.meta.last_page })}</span>
                    <Button variant="outline" size="sm" disabled={list.data.meta.page >= list.data.meta.last_page} onClick={() => onChange('page', String(list.data.meta.page + 1))}>
                        {t('leads.list.next')}
                    </Button>
                </div>
            ) : null}
        </div>
    );
}
