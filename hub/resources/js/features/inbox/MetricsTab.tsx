import { Bot, Clock, Hand, MessagesSquare, Timer, Wallet } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Card } from '@/components/ui/card';
import { Select } from '@/components/ui/input';
import { ErrorState } from '@/components/ui/misc';
import { StatCard, StatCardSkeleton, StatGrid } from '@/components/ui/stat-card';
import { TBody, TD, TH, THead, TR, Table } from '@/components/ui/table';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatMoney, formatNumber, formatPercent } from '@/lib/format';
import { useInboxMetrics } from './api';
import { formatDuration, sellsOnline } from './lib';

/**
 * Métricas de la bandeja: qué tan rápido responde el equipo, cuánto resuelve, qué hace el agente con IA y, si vende en
 * línea, las ventas que salieron del chat; por persona, en la tabla.
 */
export function MetricsTab() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const [days, setDays] = useState(30);
    const m = useInboxMetrics(workspace.id, days);
    const money = (v: string) => formatMoney(v, workspace.currency, workspace.locale);
    const store = sellsOnline(workspace);
    const n = (v: number) => formatNumber(v, workspace.locale);

    return (
        <div className="igr:space-y-6">
            <div className="igr:flex igr:flex-wrap igr:items-end igr:justify-between igr:gap-3">
                <div>
                    <h2 className="igr:text-section igr:font-semibold igr:tracking-tight">{t('inbox.metrics.title')}</h2>
                    <p className="igr:text-support igr:text-muted-foreground">{t('inbox.metrics.subtitle')}</p>
                </div>
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
            ) : (
                <>
                    <StatGrid>
                        {m.isPending ? (
                            Array.from({ length: 4 }, (_, i) => <StatCardSkeleton key={i} />)
                        ) : (
                            <>
                                <StatCard icon={MessagesSquare} tone="emerald" label={t('inbox.metrics.opened')} value={n(m.data.opened)} hint={t('inbox.metrics.resolved', { count: m.data.resolved })} />
                                <StatCard
                                    icon={Clock}
                                    tone="sky"
                                    label={t('inbox.metrics.firstResponse')}
                                    value={formatDuration(m.data.first_response.median)}
                                    hint={m.data.first_response.avg !== null ? t('inbox.metrics.avg', { value: formatDuration(m.data.first_response.avg) }) : t('inbox.metrics.noData')}
                                    help={t('inbox.metrics.firstResponseHelp')}
                                />
                                <StatCard
                                    icon={Timer}
                                    tone="violet"
                                    label={t('inbox.metrics.resolution')}
                                    value={formatDuration(m.data.resolution.median)}
                                    hint={m.data.resolution.avg !== null ? t('inbox.metrics.avg', { value: formatDuration(m.data.resolution.avg) }) : t('inbox.metrics.noData')}
                                    help={t('inbox.metrics.resolutionHelp')}
                                />
                                {store ? (
                                    <StatCard
                                        icon={Wallet}
                                        tone="indigo"
                                        label={t('inbox.metrics.sales')}
                                        value={money(m.data.sales.revenue)}
                                        hint={t('inbox.metrics.orders', { count: m.data.sales.orders })}
                                        help={t('inbox.metrics.salesHelp')}
                                    />
                                ) : (
                                    <StatCard
                                        icon={Hand}
                                        tone="amber"
                                        label={t('inbox.metrics.handoffRate')}
                                        value={m.data.ai.handoff_rate !== null ? formatPercent(m.data.ai.handoff_rate, workspace.locale, 1) : '—'}
                                        hint={t('inbox.metrics.handoffs', { count: m.data.ai.handoffs })}
                                        help={t('inbox.metrics.handoffHelp')}
                                    />
                                )}
                            </>
                        )}
                    </StatGrid>
                    <section className="igr:space-y-3">
                        <h3 className="igr:flex igr:items-center igr:gap-2 igr:text-body igr:font-semibold">
                            <Bot className="igr:size-4 igr:text-primary" aria-hidden />
                            {t('inbox.metrics.aiTitle')}
                        </h3>
                        <div className="igr:grid igr:gap-3 igr:sm:grid-cols-3">
                            {m.isPending ? (
                                Array.from({ length: 3 }, (_, i) => <StatCardSkeleton key={i} />)
                            ) : (
                                <>
                                    <StatCard label={t('inbox.metrics.aiTurns')} value={n(m.data.ai.turns)} hint={t('inbox.metrics.aiReplied', { count: m.data.ai.replied })} help={t('inbox.metrics.aiTurnsHelp')} />
                                    {store ? (
                                        <StatCard
                                            label={t('inbox.metrics.handoffRate')}
                                            value={m.data.ai.handoff_rate !== null ? formatPercent(m.data.ai.handoff_rate, workspace.locale, 1) : '—'}
                                            hint={t('inbox.metrics.handoffs', { count: m.data.ai.handoffs })}
                                            help={t('inbox.metrics.handoffHelp')}
                                        />
                                    ) : (
                                        <StatCard label={t('inbox.metrics.aiMessages')} value={n(m.data.ai.messages)} hint={t('inbox.metrics.aiMessagesHint')} />
                                    )}
                                    {store ? (
                                        <StatCard label={t('inbox.metrics.aiSales')} value={money(m.data.sales.ai.revenue)} hint={t('inbox.metrics.orders', { count: m.data.sales.ai.orders })} />
                                    ) : (
                                        <StatCard label={t('inbox.metrics.aiHandoffs')} value={n(m.data.ai.handoffs)} hint={t('inbox.metrics.aiHandoffsHint')} />
                                    )}
                                </>
                            )}
                        </div>
                    </section>
                    {m.data && m.data.agents.length > 0 ? (
                        <section className="igr:space-y-3">
                            <h3 className="igr:text-body igr:font-semibold">{t('inbox.metrics.byPerson')}</h3>
                            <Card>
                                <Table>
                                    <THead>
                                        <TR>
                                            <TH>{t('inbox.metrics.agent')}</TH>
                                            <TH className="igr:text-right">{t('inbox.metrics.conversations')}</TH>
                                            <TH className="igr:text-right">{t('inbox.metrics.messages')}</TH>
                                            <TH className="igr:text-right">{t('inbox.metrics.firstResponse')}</TH>
                                            <TH className="igr:text-right">{t('inbox.metrics.closed')}</TH>
                                            {store ? <TH className="igr:text-right">{t('inbox.metrics.sales')}</TH> : null}
                                        </TR>
                                    </THead>
                                    <TBody>
                                        {m.data.agents.map((a) => (
                                            <TR key={a.id}>
                                                <TD className="igr:font-medium">{a.name}</TD>
                                                <TD className="igr:text-right igr:tabular-nums">{n(a.conversations)}</TD>
                                                <TD className="igr:text-right igr:tabular-nums">{n(a.messages)}</TD>
                                                <TD className="igr:text-right igr:tabular-nums">{formatDuration(a.first_response.median)}</TD>
                                                <TD className="igr:text-right igr:tabular-nums">{n(a.resolved)}</TD>
                                                {store ? (
                                                    <TD className="igr:text-right igr:tabular-nums">
                                                        {money(a.revenue)} <span className="igr:text-muted-foreground">({a.orders})</span>
                                                    </TD>
                                                ) : null}
                                            </TR>
                                        ))}
                                    </TBody>
                                </Table>
                            </Card>
                        </section>
                    ) : null}
                </>
            )}
        </div>
    );
}
