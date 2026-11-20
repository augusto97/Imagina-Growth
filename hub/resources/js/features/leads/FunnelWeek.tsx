import { Sparkles, TrendingDown } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { Select } from '@/components/ui/input';
import { ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatDate } from '@/lib/format';
import { BarList } from '@/features/analytics/components/charts';
import { StatTile, TileGrid } from '@/features/analytics/components/StatTile';
import { useFormatters } from '@/features/analytics/format';
import { useLeadFunnel } from './api';
import { duration, signedPoints } from './logic';

/**
 * El embudo de leads del lunes (M18) en el tablero de leads: cuántos llegaron a cada
 * etapa en la semana frente a la anterior, dónde se cae la venta (90 días de leads) y
 * por qué (motivos de pérdida, leads quietos por etapa y primera respuesta), con la
 * conclusión escrita solo con estas cifras. Cifras en tarjetas y tabla; motivos y
 * quietos como barras de una sola serie con el valor escrito.
 */
export function FunnelWeek({ pipeline }: { pipeline: string }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const [week, setWeek] = useState<'last' | 'current'>('last');
    const funnel = useLeadFunnel(workspace.id, week, pipeline);
    const fmt = useFormatters(funnel.data?.currency);
    const date = (iso: string) => formatDate(iso, workspace.locale);

    const header = (
        <div className="igr:mb-3 igr:flex igr:flex-wrap igr:items-center igr:justify-between igr:gap-2">
            <div>
                <h2 className="igr:text-[15px] igr:font-semibold">{t('leads.funnelWeek.title')}</h2>
                {funnel.data ? (
                    <p className="igr:text-xs igr:text-muted-foreground">{t('leads.funnelWeek.range', { from: date(funnel.data.week.from), to: date(funnel.data.week.to) })}</p>
                ) : null}
            </div>
            <label className="igr:flex igr:items-center igr:gap-2 igr:text-[13px]">
                <span className="igr:sr-only">{t('leads.funnelWeek.week')}</span>
                <Select className="igr:h-8 igr:w-48" value={week} onChange={(e) => setWeek(e.target.value === 'current' ? 'current' : 'last')}>
                    <option value="last">{t('leads.funnelWeek.last')}</option>
                    <option value="current">{t('leads.funnelWeek.current')}</option>
                </Select>
            </label>
        </div>
    );

    if (funnel.isError)
        return (
            <section className="igr:mt-6">
                {header}
                <ErrorState error={funnel.error} onRetry={() => void funnel.refetch()} />
            </section>
        );
    if (funnel.isPending)
        return (
            <section className="igr:mt-6">
                {header}
                <Skeleton className="igr:h-64" />
            </section>
        );
    const f = funnel.data;
    const first = f.stages[0];
    const median = duration(f.first_response.week.median_seconds);
    const medianText = median.unit === 'none' ? '—' : t(`leads.kpis.duration.${median.unit}`, { a: median.a, b: median.b });

    return (
        <section className="igr:mt-6" aria-labelledby="funnel-week">
            {header}
            {f.conclusion.text !== '' ? (
                <Card className="igr:mb-3 igr:p-4">
                    <p className="igr:text-[13.5px] igr:leading-relaxed">{f.conclusion.text}</p>
                    <Badge className="igr:mt-2" variant={f.conclusion.source === 'ai' ? 'default' : 'outline'}>
                        {f.conclusion.source === 'ai' ? <Sparkles className="igr:size-3" aria-hidden /> : null}
                        {t(f.conclusion.source === 'ai' ? 'leads.funnelWeek.byAi' : 'leads.funnelWeek.noAi')}
                    </Badge>
                </Card>
            ) : null}
            <TileGrid>
                <StatTile
                    label={first ? first.label : t('leads.funnelWeek.inquiries')}
                    value={first?.reached ?? 0}
                    previous={first?.previous ?? 0}
                    compareMode="previous"
                    unit="count"
                    fmt={fmt}
                />
                <StatTile
                    label={t('leads.funnelWeek.won')}
                    value={f.won.week.count}
                    previous={f.won.previous.count}
                    compareMode="previous"
                    unit="count"
                    fmt={fmt}
                    hint={fmt.money(f.won.week.value)}
                />
                <StatTile label={t('leads.funnelWeek.lost')} value={f.lost.count} previous={f.lost.previous} compareMode="previous" unit="count" fmt={fmt} better="down" />
                <Card className="igr:flex igr:min-w-0 igr:flex-col igr:p-4">
                    <span className="igr:text-[13px] igr:text-muted-foreground">{t('leads.kpis.firstResponse')}</span>
                    <span className="igr:mt-1.5 igr:text-2xl igr:font-semibold igr:tracking-tight igr:tabular-nums">{medianText}</span>
                    <span className="igr:mt-1 igr:text-xs igr:text-muted-foreground">
                        {t('leads.kpis.within15', {
                            rate: fmt.percent(f.first_response.week.within_15m_rate, 0),
                            count: f.first_response.week.leads - f.first_response.week.answered,
                        })}
                    </span>
                </Card>
            </TileGrid>

            {f.drop ? (
                <Card className="igr:mt-3 igr:flex igr:items-start igr:gap-3 igr:p-4">
                    <TrendingDown className="igr:mt-0.5 igr:size-4 igr:shrink-0 igr:text-muted-foreground" aria-hidden />
                    <p className="igr:text-[13px]">
                        <span className="igr:font-medium">{t('leads.funnelWeek.dropTitle')}</span>{' '}
                        {t('leads.funnelWeek.drop', {
                            from: f.drop.from_label,
                            to: f.drop.to_label,
                            rate: fmt.percent(f.drop.conversion, 0),
                            passed: f.drop.passed,
                            entered: f.drop.entered,
                            days: f.drop.days,
                        })}
                    </p>
                </Card>
            ) : null}

            <Card className="igr:mt-3 igr:overflow-x-auto">
                <Table>
                    <caption className="igr:sr-only">{t('leads.funnelWeek.caption')}</caption>
                    <THead>
                        <TR>
                            <TH>{t('leads.funnelWeek.cols.stage')}</TH>
                            <TH className="igr:text-right">{t('leads.funnelWeek.cols.week')}</TH>
                            <TH className="igr:text-right">{t('leads.funnelWeek.cols.previous')}</TH>
                            <TH className="igr:text-right">{t('leads.funnelWeek.cols.passed')}</TH>
                            <TH className="igr:text-right">{t('leads.funnelWeek.cols.passedPrevious')}</TH>
                            <TH className="igr:text-right">{t('leads.funnelWeek.cols.change')}</TH>
                        </TR>
                    </THead>
                    <TBody>
                        {f.stages.map((s) => (
                            <TR key={s.key}>
                                <TD>{s.label}</TD>
                                <TD className="igr:text-right igr:font-medium igr:tabular-nums">{fmt.number(s.reached)}</TD>
                                <TD className="igr:text-right igr:tabular-nums">{fmt.number(s.previous)}</TD>
                                <TD className="igr:text-right igr:tabular-nums">{s.conversion === null ? '—' : fmt.percent(s.conversion, 0)}</TD>
                                <TD className="igr:text-right igr:tabular-nums">{s.previous_conversion === null ? '—' : fmt.percent(s.previous_conversion, 0)}</TD>
                                <TD className="igr:text-right igr:tabular-nums">
                                    {s.change_points === null ? '—' : t('leads.funnelWeek.points', { value: signedPoints(s.change_points) })}
                                </TD>
                            </TR>
                        ))}
                    </TBody>
                </Table>
            </Card>

            <div className="igr:mt-3 igr:grid igr:gap-3 igr:lg:grid-cols-2">
                <Card className="igr:p-4">
                    <h3 className="igr:mb-3 igr:text-[13px] igr:font-medium">{t('leads.funnelWeek.reasons', { count: f.lost.count })}</h3>
                    <BarList
                        rows={f.lost.reasons}
                        label={(r) => r.reason ?? t('leads.funnelWeek.noReason')}
                        value={(r) => r.count}
                        format={(v) => fmt.number(v)}
                        detail={() =>
                            f.lost.by_stage.length > 0
                                ? t('leads.funnelWeek.lostFrom', { stages: f.lost.by_stage.map((b) => `${b.label} (${fmt.number(b.count)})`).join(', ') })
                                : null
                        }
                    />
                </Card>
                <Card className="igr:p-4">
                    <h3 className="igr:mb-3 igr:text-[13px] igr:font-medium">{t('leads.funnelWeek.stalled', { days: f.stale_days })}</h3>
                    <BarList
                        rows={f.stalled}
                        label={(r) => r.label}
                        value={(r) => r.count}
                        format={(v) => fmt.number(v)}
                        detail={(r) => t('leads.funnelWeek.buckets', { a: r.buckets.up_to_14, b: r.buckets.from_15_to_30, c: r.buckets.over_30, max: r.max_days })}
                    />
                </Card>
            </div>
        </section>
    );
}
