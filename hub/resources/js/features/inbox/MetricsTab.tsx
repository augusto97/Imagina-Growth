import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Select } from '@/components/ui/input';
import { ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { TBody, TD, TH, THead, TR, Table } from '@/components/ui/table';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatMoney, formatNumber, formatPercent } from '@/lib/format';
import { Kpi } from '@/features/reviews/Kpi';
import { useInboxMetrics } from './api';
import { formatDuration } from './lib';

/** Métricas de la bandeja: primera respuesta, resolución, ventas por agente y traspasos de la IA. */
export function MetricsTab() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const [days, setDays] = useState(30);
    const m = useInboxMetrics(workspace.id, days);
    const money = (v: string) => formatMoney(v, workspace.currency, workspace.locale);

    return (
        <div className="igr:space-y-4">
            <div className="igr:flex igr:items-center igr:justify-end">
                <Select aria-label={t('inbox.metrics.period')} className="igr:w-auto" value={String(days)} onChange={(e) => setDays(Number(e.target.value))}>
                    {[7, 30, 90].map((d) => (
                        <option key={d} value={d}>
                            {t('inbox.metrics.days', { count: d })}
                        </option>
                    ))}
                </Select>
            </div>
            {m.isError ? (
                <ErrorState error={m.error} onRetry={() => void m.refetch()} />
            ) : m.isPending ? (
                <Skeleton className="igr:h-40" />
            ) : (
                <>
                    <div className="igr:grid igr:gap-3 igr:sm:grid-cols-2 igr:lg:grid-cols-4">
                        <Kpi
                            label={t('inbox.metrics.opened')}
                            value={formatNumber(m.data.opened, workspace.locale)}
                            hint={t('inbox.metrics.resolved', {
                                count: m.data.resolved,
                            })}
                        />
                        <Kpi
                            label={t('inbox.metrics.firstResponse')}
                            value={formatDuration(m.data.first_response.median)}
                            hint={
                                m.data.first_response.avg !== null
                                    ? t('inbox.metrics.avg', {
                                          value: formatDuration(m.data.first_response.avg),
                                      })
                                    : t('inbox.metrics.noData')
                            }
                            help={t('inbox.metrics.firstResponseHelp')}
                        />
                        <Kpi
                            label={t('inbox.metrics.resolution')}
                            value={formatDuration(m.data.resolution.median)}
                            hint={
                                m.data.resolution.avg !== null
                                    ? t('inbox.metrics.avg', {
                                          value: formatDuration(m.data.resolution.avg),
                                      })
                                    : t('inbox.metrics.noData')
                            }
                            help={t('inbox.metrics.resolutionHelp')}
                        />
                        <Kpi
                            label={t('inbox.metrics.sales')}
                            value={money(m.data.sales.revenue)}
                            hint={t('inbox.metrics.orders', {
                                count: m.data.sales.orders,
                            })}
                            help={t('inbox.metrics.salesHelp')}
                        />
                    </div>
                    <div className="igr:grid igr:gap-3 igr:sm:grid-cols-3">
                        <Kpi
                            label={t('inbox.metrics.aiTurns')}
                            value={formatNumber(m.data.ai.turns, workspace.locale)}
                            hint={t('inbox.metrics.aiReplied', {
                                count: m.data.ai.replied,
                            })}
                        />
                        <Kpi
                            label={t('inbox.metrics.handoffRate')}
                            value={m.data.ai.handoff_rate !== null ? formatPercent(m.data.ai.handoff_rate, workspace.locale, 1) : '—'}
                            hint={t('inbox.metrics.handoffs', {
                                count: m.data.ai.handoffs,
                            })}
                            help={t('inbox.metrics.handoffHelp')}
                        />
                        <Kpi
                            label={t('inbox.metrics.aiSales')}
                            value={money(m.data.sales.ai.revenue)}
                            hint={t('inbox.metrics.orders', {
                                count: m.data.sales.ai.orders,
                            })}
                        />
                    </div>
                    <Table>
                        <THead>
                            <TR>
                                <TH>{t('inbox.metrics.agent')}</TH>
                                <TH className="igr:text-right">{t('inbox.metrics.conversations')}</TH>
                                <TH className="igr:text-right">{t('inbox.metrics.messages')}</TH>
                                <TH className="igr:text-right">{t('inbox.metrics.firstResponse')}</TH>
                                <TH className="igr:text-right">{t('inbox.metrics.closed')}</TH>
                                <TH className="igr:text-right">{t('inbox.metrics.sales')}</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {m.data.agents.map((a) => (
                                <TR key={a.id}>
                                    <TD className="igr:font-medium">{a.name}</TD>
                                    <TD className="igr:text-right igr:tabular-nums">{a.conversations}</TD>
                                    <TD className="igr:text-right igr:tabular-nums">{a.messages}</TD>
                                    <TD className="igr:text-right igr:tabular-nums">{formatDuration(a.first_response.median)}</TD>
                                    <TD className="igr:text-right igr:tabular-nums">{a.resolved}</TD>
                                    <TD className="igr:text-right igr:tabular-nums">
                                        {money(a.revenue)} <span className="igr:text-muted-foreground">({a.orders})</span>
                                    </TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                </>
            )}
        </div>
    );
}
