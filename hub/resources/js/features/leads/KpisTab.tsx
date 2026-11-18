import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Card } from '@/components/ui/card';
import { Select } from '@/components/ui/input';
import { ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useWorkspace } from '@/hooks/useWorkspace';
import { BarList } from '@/features/analytics/components/charts';
import { StatTile, TileGrid } from '@/features/analytics/components/StatTile';
import { useFormatters } from '@/features/analytics/format';
import { useLeadKpis } from './api';
import { duration } from './logic';

const PERIODS = [7, 30, 90, 365] as const;

/**
 * Indicadores de leads (skill dataviz): cifras en tarjetas, leads por fuente y
 * embudo por etapa como barras de una sola serie con el valor escrito y el
 * detalle al pasar el mouse, y la tabla con todas las cifras.
 */
export function KpisTab({ pipeline }: { pipeline: string }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const [days, setDays] = useState<number>(30);
    const kpis = useLeadKpis(workspace.id, days, pipeline);
    const fmt = useFormatters(kpis.data?.currency);

    const header = (
        <div className="igr:mb-3 igr:flex igr:items-center igr:gap-2">
            <label className="igr:text-[13px] igr:text-muted-foreground" htmlFor="leads-period">
                {t('leads.kpis.period')}
            </label>
            <Select id="leads-period" className="igr:h-8 igr:w-40" value={String(days)} onChange={(e) => setDays(Number(e.target.value))}>
                {PERIODS.map((d) => (
                    <option key={d} value={d}>
                        {t('leads.kpis.lastDays', { count: d })}
                    </option>
                ))}
            </Select>
        </div>
    );
    if (kpis.isError) return <ErrorState error={kpis.error} onRetry={() => void kpis.refetch()} />;
    if (kpis.isPending) {
        return (
            <>
                {header}
                <TileGrid>
                    {[0, 1, 2, 3].map((i) => (
                        <Skeleton key={i} className="igr:h-24" />
                    ))}
                </TileGrid>
            </>
        );
    }
    const k = kpis.data;
    const d = duration(k.first_response.median_seconds);
    const median = d.unit === 'none' ? '—' : t(`leads.kpis.duration.${d.unit}`, { a: d.a, b: d.b });

    return (
        <>
            {header}
            <TileGrid>
                <StatTile label={t('leads.kpis.leads')} value={k.leads} unit="count" fmt={fmt} hint={t('leads.kpis.open', { count: k.open.count, hot: k.open.hot })} />
                <StatTile label={t('leads.kpis.closeRate')} value={k.conversion_rate} unit="ratio" fmt={fmt} hint={t('leads.kpis.wonInCohort', { count: k.won_in_cohort })} help={t('leads.kpis.closeRateHelp')} />
                <StatTile label={t('leads.kpis.wonValue')} value={Number(k.won.value)} unit="money" fmt={fmt} hint={t('leads.kpis.wonCount', { count: k.won.count })} />
                <Card className="igr:flex igr:min-w-0 igr:flex-col igr:p-4">
                    <span className="igr:text-[13px] igr:text-muted-foreground">{t('leads.kpis.firstResponse')}</span>
                    <span className="igr:mt-1.5 igr:text-2xl igr:font-semibold igr:tracking-tight igr:tabular-nums">{median}</span>
                    <span className="igr:mt-1 igr:text-xs igr:text-muted-foreground">
                        {t('leads.kpis.within15', { rate: fmt.percent(k.first_response.within_15m_rate, 0), count: k.first_response.unanswered })}
                    </span>
                </Card>
            </TileGrid>
            <div className="igr:mt-3 igr:grid igr:gap-3 igr:lg:grid-cols-2">
                <Card className="igr:p-4">
                    <h3 className="igr:mb-3 igr:text-[13px] igr:font-medium">{t('leads.kpis.bySource')}</h3>
                    <BarList
                        rows={k.by_source}
                        label={(r) => t(`leads.channels.${r.channel}`, { defaultValue: r.label })}
                        value={(r) => r.leads}
                        format={(v) => fmt.number(v)}
                        detail={(r) => t('leads.kpis.sourceDetail', { won: fmt.number(r.won), rate: fmt.percent(r.conversion_rate, 0), value: fmt.money(r.won_value) })}
                    />
                </Card>
                <Card className="igr:p-4">
                    <h3 className="igr:mb-3 igr:text-[13px] igr:font-medium">{t('leads.kpis.funnel')}</h3>
                    <BarList
                        rows={k.funnel}
                        max={12}
                        label={(r) => r.label}
                        value={(r) => r.leads}
                        format={(v) => fmt.number(v)}
                        detail={(r) => t('leads.kpis.funnelDetail', { rate: fmt.percent(r.rate, 0) })}
                    />
                </Card>
            </div>
            <Card className="igr:mt-3 igr:overflow-x-auto">
                <Table>
                    <caption className="igr:sr-only">{t('leads.kpis.tableCaption')}</caption>
                    <THead>
                        <TR>
                            <TH>{t('leads.kpis.cols.source')}</TH>
                            <TH className="igr:text-right">{t('leads.kpis.cols.leads')}</TH>
                            <TH className="igr:text-right">{t('leads.kpis.cols.won')}</TH>
                            <TH className="igr:text-right">{t('leads.kpis.cols.rate')}</TH>
                            <TH className="igr:text-right">{t('leads.kpis.cols.value')}</TH>
                        </TR>
                    </THead>
                    <TBody>
                        {k.by_source.map((r) => (
                            <TR key={r.channel}>
                                <TD>{t(`leads.channels.${r.channel}`, { defaultValue: r.label })}</TD>
                                <TD className="igr:text-right igr:tabular-nums">{fmt.number(r.leads)}</TD>
                                <TD className="igr:text-right igr:tabular-nums">{fmt.number(r.won)}</TD>
                                <TD className="igr:text-right igr:tabular-nums">{fmt.percent(r.conversion_rate, 0)}</TD>
                                <TD className="igr:text-right igr:tabular-nums">{fmt.money(r.won_value)}</TD>
                            </TR>
                        ))}
                    </TBody>
                </Table>
            </Card>
        </>
    );
}
