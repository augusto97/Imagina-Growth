import { Sparkles, Table2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { TBody, TD, TH, THead, TR, Table } from '@/components/ui/table';
import { Tooltip } from '@/components/ui/tooltip';
import { BarList, TrendChart } from '@/features/analytics/components/charts';
import { StatTile } from '@/features/analytics/components/StatTile';
import { useFormatters } from '@/features/analytics/format';
import type { Formatters } from '@/features/analytics/format';
import type { AiSource, AskColumn, AskResult } from './api';
import { chartUnit, parseMarkdown } from './logic';

/** «Escrito con IA» o «Plantilla sin IA»: todo texto generado se marca (inteligencia.md §6.1). */
export function SourceBadge({ source, reason }: { source: AiSource | null | undefined; reason?: string | undefined }) {
    const { t } = useTranslation();
    if (source === 'ai') {
        return (
            <Tooltip content={t('intelligence.source.aiHelp')}>
                <Badge variant="secondary" className="igr:gap-1">
                    <Sparkles className="igr:size-3" aria-hidden />
                    {t('intelligence.source.ai')}
                </Badge>
            </Tooltip>
        );
    }
    return (
        <Tooltip content={reason ? t(`intelligence.fallback.${reason}`, { defaultValue: t('intelligence.source.fallbackHelp') }) : t('intelligence.source.fallbackHelp')}>
            <Badge variant="outline">{t('intelligence.source.fallback')}</Badge>
        </Tooltip>
    );
}

/** Markdown mínimo de los informes (párrafos, listas y negritas), siempre como texto. */
export function Markdown({ body }: { body: string }) {
    return (
        <div className="igr:space-y-3 igr:text-[13px] igr:leading-relaxed">
            {parseMarkdown(body).map((block, i) =>
                block.kind === 'list' ? (
                    <ul key={i} className="igr:list-disc igr:space-y-1 igr:pl-5">
                        {block.lines.map((line, j) => (
                            <li key={j}>
                                {line.map((part, k) => (part.bold ? <strong key={k}>{part.text}</strong> : <span key={k}>{part.text}</span>))}
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p key={i}>
                        {block.lines.map((line, j) => (
                            <span key={j} className="igr:block">
                                {line.map((part, k) => (part.bold ? <strong key={k}>{part.text}</strong> : <span key={k}>{part.text}</span>))}
                            </span>
                        ))}
                    </p>
                ),
            )}
        </div>
    );
}

function cell(fmt: Formatters, column: AskColumn, value: string | number | null | undefined): string {
    if (column.kind === 'dimension') {
        if (column.key === 'date' && typeof value === 'string') return fmt.date(value);
        return value === null || value === undefined || value === '' ? '—' : String(value);
    }
    return fmt.byUnit(chartUnit(column.unit), value === null || value === undefined ? null : Number(value));
}

/** La tabla y el gráfico de una respuesta: salen de los datos, nunca del modelo. */
export function AnswerResult({ result, granularity = 'day' }: { result: AskResult; granularity?: string | undefined }) {
    const { t } = useTranslation();
    const fmt = useFormatters();
    const [showTable, setShowTable] = useState(result.visual === 'table');
    const metrics = result.columns.filter((c) => c.kind === 'metric');
    const dims = result.columns.filter((c) => c.kind === 'dimension');
    const first = metrics[0];

    return (
        <div className="igr:space-y-3">
            <div className="igr:grid igr:grid-cols-[repeat(auto-fill,minmax(210px,1fr))] igr:gap-2">
                {metrics.map((m) => (
                    <StatTile
                        key={m.key}
                        label={m.label}
                        value={result.totals[m.key] ?? null}
                        unit={chartUnit(m.unit)}
                        previous={result.compare_totals?.[m.key] ?? null}
                        compareMode={result.compare_totals ? 'previous' : null}
                        fmt={fmt}
                    />
                ))}
            </div>
            {first && result.visual === 'line' && !showTable ? (
                <div>
                    <p className="igr:mb-1 igr:text-xs igr:font-medium igr:text-muted-foreground">{first.label}</p>
                    <TrendChart
                        data={result.rows.map((r): Record<string, string | number | null> & { date: string } => ({ ...r, date: String(r.date ?? '') }))}
                        metric={first.key}
                        label={first.label}
                        unit={chartUnit(first.unit)}
                        granularity={granularity === 'week' || granularity === 'month' ? granularity : 'day'}
                        fmt={fmt}
                        height={220}
                    />
                </div>
            ) : null}
            {first && dims[0] && result.visual === 'bar' && !showTable ? (
                <div>
                    <p className="igr:mb-2 igr:text-xs igr:font-medium igr:text-muted-foreground">
                        {first.label} · {dims[0].label}
                    </p>
                    <BarList
                        rows={result.rows}
                        label={(r) => cell(fmt, dims[0] as AskColumn, r[(dims[0] as AskColumn).key])}
                        value={(r) => Number(r[first.key] ?? 0)}
                        format={(v) => fmt.byUnit(chartUnit(first.unit), v)}
                        max={12}
                    />
                </div>
            ) : null}
            {result.rows.length > 0 && result.visual !== 'kpi' ? (
                <div>
                    {result.visual !== 'table' ? (
                        <Button variant="ghost" size="sm" onClick={() => setShowTable((v) => !v)} aria-pressed={showTable}>
                            <Table2 className="igr:size-3.5" aria-hidden />
                            {showTable ? t('intelligence.ask.showChart') : t('intelligence.ask.showTable')}
                        </Button>
                    ) : null}
                    {showTable ? (
                        <div className="igr:max-h-80 igr:overflow-auto igr:rounded-md igr:border">
                            <Table>
                                <THead>
                                    <TR>
                                        {result.columns.map((c) => (
                                            <TH key={c.key} className={c.kind === 'metric' ? 'igr:text-right' : undefined}>
                                                {c.label}
                                            </TH>
                                        ))}
                                    </TR>
                                </THead>
                                <TBody>
                                    {result.rows.map((r, i) => (
                                        <TR key={i}>
                                            {result.columns.map((c) => (
                                                <TD key={c.key} className={c.kind === 'metric' ? 'igr:text-right igr:tabular-nums' : undefined}>
                                                    {cell(fmt, c, r[c.key])}
                                                </TD>
                                            ))}
                                        </TR>
                                    ))}
                                </TBody>
                            </Table>
                        </div>
                    ) : null}
                </div>
            ) : null}
        </div>
    );
}
