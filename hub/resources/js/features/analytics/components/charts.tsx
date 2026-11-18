import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Area, AreaChart, Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip as RTooltip, XAxis, YAxis } from 'recharts';
import type { TooltipContentProps } from 'recharts';
import { Tooltip } from '@/components/ui/tooltip';
import { cn } from '@/lib/cn';
import type { Granularity, MetricUnit } from '../api';
import type { Formatters } from '../format';
import { sequentialCell, useChartTheme } from '../theme';

/*
 * Gráficos de los tableros (skill dataviz): marcas finas, una sola escala,
 * cuadrícula tenue, leyenda desde 2 series, tooltip al pasar el mouse y
 * la tabla como vista alternativa (Section).
 */

function axisFormatter(fmt: Formatters, unit: MetricUnit) {
    return (v: number) => (unit === 'money' ? fmt.compactMoney(v) : unit === 'ratio' ? fmt.percent(v, 0) : fmt.compactNumber(v));
}

function TooltipBox({ title, rows }: { title: ReactNode; rows: { label: ReactNode; value: ReactNode; color?: string }[] }) {
    return (
        <div className="igr:min-w-36 igr:rounded-md igr:border igr:bg-popover igr:px-2.5 igr:py-2 igr:text-xs igr:text-popover-foreground igr:shadow-md">
            <p className="igr:mb-1 igr:font-medium">{title}</p>
            {rows.map((r, i) => (
                <div key={i} className="igr:flex igr:items-center igr:justify-between igr:gap-4">
                    <span className="igr:flex igr:items-center igr:gap-1.5 igr:text-muted-foreground">
                        {r.color ? <span className="igr:size-2 igr:rounded-full" style={{ background: r.color }} /> : null}
                        {r.label}
                    </span>
                    <span className="igr:font-medium igr:tabular-nums">{r.value}</span>
                </div>
            ))}
        </div>
    );
}

export function Legend({ items }: { items: { label: string; color: string }[] }) {
    return (
        <ul className="igr:mb-2 igr:flex igr:flex-wrap igr:gap-x-4 igr:gap-y-1 igr:text-xs igr:text-muted-foreground">
            {items.map((item) => (
                <li key={item.label} className="igr:flex igr:items-center igr:gap-1.5">
                    <span className="igr:h-2 igr:w-3 igr:rounded-sm" style={{ background: item.color }} />
                    {item.label}
                </li>
            ))}
        </ul>
    );
}

/** Serie de tiempo de una métrica (línea de 2px con área tenue y cruz al pasar el mouse). */
export function TrendChart<T extends { date: string }>({
    data,
    metric,
    label,
    unit,
    granularity,
    fmt,
    height = 240,
}: {
    data: T[];
    metric: keyof T & string;
    label: string;
    unit: MetricUnit;
    granularity: Granularity;
    fmt: Formatters;
    height?: number;
}) {
    const theme = useChartTheme();
    const color = theme.series[0] ?? '#2a78d6';
    const id = `fill-${metric}`;
    return (
        <div style={{ height }} role="img" aria-label={label}>
            <ResponsiveContainer width="100%" height="100%">
                <AreaChart data={data} margin={{ top: 8, right: 8, bottom: 0, left: 0 }}>
                    <defs>
                        <linearGradient id={id} x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stopColor={color} stopOpacity={0.18} />
                            <stop offset="100%" stopColor={color} stopOpacity={0.02} />
                        </linearGradient>
                    </defs>
                    <CartesianGrid vertical={false} stroke={theme.grid} strokeWidth={1} />
                    <XAxis
                        dataKey="date"
                        tickFormatter={(v: string) => fmt.bucket(v, granularity)}
                        tick={{ fill: theme.muted, fontSize: 11 }}
                        tickLine={false}
                        axisLine={{ stroke: theme.axis }}
                        minTickGap={24}
                    />
                    <YAxis tickFormatter={axisFormatter(fmt, unit)} tick={{ fill: theme.muted, fontSize: 11 }} tickLine={false} axisLine={false} width={64} />
                    <RTooltip
                        cursor={{ stroke: theme.axis, strokeWidth: 1 }}
                        content={(props: TooltipContentProps) => {
                            const row = props.payload[0]?.payload as T | undefined;
                            if (!props.active || row === undefined) return null;
                            return <TooltipBox title={fmt.bucket(row.date, granularity)} rows={[{ label, value: fmt.byUnit(unit, Number(row[metric])), color }]} />;
                        }}
                    />
                    <Area type="monotone" dataKey={(row: T) => Number(row[metric])} stroke={color} strokeWidth={2} fill={`url(#${id})`} dot={false} activeDot={{ r: 4, strokeWidth: 2, stroke: theme.surface }} isAnimationActive={false} />
                </AreaChart>
            </ResponsiveContainer>
        </div>
    );
}

/** Barras apiladas de 2 o 3 series sobre el tiempo (p. ej. pedidos nuevos y recurrentes). */
export function StackedBars<T extends { date: string }>({
    data,
    series,
    unit,
    granularity,
    fmt,
    height = 240,
    ariaLabel,
}: {
    data: T[];
    series: { key: keyof T & string; label: string }[];
    unit: MetricUnit;
    granularity: Granularity;
    fmt: Formatters;
    height?: number;
    ariaLabel: string;
}) {
    const theme = useChartTheme();
    const colors = series.map((_, i) => theme.series[i] ?? theme.muted);
    return (
        <div>
            <Legend items={series.map((s, i) => ({ label: s.label, color: colors[i] ?? theme.muted }))} />
            <div style={{ height }} role="img" aria-label={ariaLabel}>
                <ResponsiveContainer width="100%" height="100%">
                    <BarChart data={data} margin={{ top: 4, right: 8, bottom: 0, left: 0 }} barCategoryGap="20%">
                        <CartesianGrid vertical={false} stroke={theme.grid} />
                        <XAxis
                            dataKey="date"
                            tickFormatter={(v: string) => fmt.bucket(v, granularity)}
                            tick={{ fill: theme.muted, fontSize: 11 }}
                            tickLine={false}
                            axisLine={{ stroke: theme.axis }}
                            minTickGap={16}
                        />
                        <YAxis tickFormatter={axisFormatter(fmt, unit)} tick={{ fill: theme.muted, fontSize: 11 }} tickLine={false} axisLine={false} width={64} />
                        <RTooltip
                            cursor={{ fill: theme.grid, opacity: 0.5 }}
                            content={(props: TooltipContentProps) => {
                                const row = props.payload[0]?.payload as T | undefined;
                                if (!props.active || row === undefined) return null;
                                return (
                                    <TooltipBox
                                        title={fmt.bucket(row.date, granularity)}
                                        rows={series.map((s, i) => ({ label: s.label, value: fmt.byUnit(unit, Number(row[s.key])), color: colors[i] }))}
                                    />
                                );
                            }}
                        />
                        {series.map((s, i) => (
                            <Bar
                                key={s.key}
                                dataKey={(row: T) => Number(row[s.key])}
                                name={s.label}
                                stackId="a"
                                fill={colors[i]}
                                stroke={theme.surface}
                                strokeWidth={1}
                                radius={i === series.length - 1 ? [4, 4, 0, 0] : 0}
                                isAnimationActive={false}
                            />
                        ))}
                    </BarChart>
                </ResponsiveContainer>
            </div>
        </div>
    );
}

/** Barras horizontales con nombre y valor directos (categorías ordenadas por magnitud). */
export function BarList<T>({
    rows,
    label,
    value,
    format,
    detail,
    max: maxRows = 10,
    onSelect,
}: {
    rows: T[];
    label: (row: T) => string;
    value: (row: T) => number;
    format: (v: number) => string;
    detail?: (row: T) => ReactNode;
    max?: number;
    onSelect?: (row: T) => void;
}) {
    const { t } = useTranslation();
    const theme = useChartTheme();
    const visible = rows.slice(0, maxRows);
    const top = Math.max(0, ...visible.map(value));
    if (visible.length === 0) {
        return <p className="igr:py-6 igr:text-center igr:text-[13px] igr:text-muted-foreground">{t('analytics.table.empty')}</p>;
    }
    return (
        <ul className="igr:space-y-1.5">
            {visible.map((row, i) => {
                const v = value(row);
                const width = top > 0 ? Math.max(1, (v / top) * 100) : 0;
                const content = (
                    <div className={cn('igr:group igr:rounded-md igr:px-1 igr:py-1', onSelect && 'igr:cursor-pointer igr:hover:bg-muted/50')}>
                        <div className="igr:flex igr:items-baseline igr:justify-between igr:gap-3 igr:text-[13px]">
                            <span className="igr:truncate">{label(row)}</span>
                            <span className="igr:shrink-0 igr:font-medium igr:tabular-nums">{format(v)}</span>
                        </div>
                        <div className="igr:mt-1 igr:h-1.5 igr:w-full igr:rounded-full igr:bg-muted">
                            <div className="igr:h-1.5 igr:rounded-full" style={{ width: `${width}%`, background: theme.series[0] }} />
                        </div>
                    </div>
                );
                const wrapped = detail ? <Tooltip content={detail(row)}>{content}</Tooltip> : content;
                return (
                    <li key={`${label(row)}-${i}`} onClick={onSelect ? () => onSelect(row) : undefined}>
                        {wrapped}
                    </li>
                );
            })}
        </ul>
    );
}

/**
 * Mapa de calor (cohortes, cuadrícula RFM): rampa secuencial de un solo tono,
 * con el valor escrito en cada celda y detalle al pasar el mouse.
 */
export function Heatmap({
    rows,
    columns,
    cell,
    corner,
    cellClassName,
}: {
    rows: { key: string; label: ReactNode; sub?: ReactNode }[];
    columns: { key: string; label: ReactNode }[];
    cell: (row: string, column: string) => { t: number | null; text: ReactNode; detail?: ReactNode; onClick?: () => void } | null;
    corner?: ReactNode;
    cellClassName?: string;
}) {
    const theme = useChartTheme();
    return (
        <div className="igr:w-full igr:overflow-x-auto">
            <table className="igr:w-full igr:border-separate igr:border-spacing-0.5 igr:text-xs">
                <thead>
                    <tr>
                        <th className="igr:px-2 igr:py-1 igr:text-left igr:font-medium igr:text-muted-foreground">{corner}</th>
                        {columns.map((c) => (
                            <th key={c.key} className="igr:px-1 igr:py-1 igr:text-center igr:font-medium igr:whitespace-nowrap igr:text-muted-foreground">
                                {c.label}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((r) => (
                        <tr key={r.key}>
                            <th scope="row" className="igr:px-2 igr:py-1 igr:text-left igr:font-normal igr:whitespace-nowrap">
                                <div>{r.label}</div>
                                {r.sub ? <div className="igr:text-[11px] igr:text-muted-foreground">{r.sub}</div> : null}
                            </th>
                            {columns.map((c) => {
                                const v = cell(r.key, c.key);
                                if (v === null || v.t === null) {
                                    return <td key={c.key} className={cn('igr:rounded-sm', cellClassName)} />;
                                }
                                const { background, light } = sequentialCell(theme, v.t);
                                const inner = (
                                    <div
                                        role={v.onClick ? 'button' : undefined}
                                        tabIndex={v.onClick ? 0 : undefined}
                                        onClick={v.onClick}
                                        onKeyDown={v.onClick ? (e) => (e.key === 'Enter' || e.key === ' ') && v.onClick?.() : undefined}
                                        className={cn(
                                            'igr:flex igr:h-8 igr:min-w-11 igr:items-center igr:justify-center igr:rounded-sm igr:px-1 igr:tabular-nums',
                                            v.onClick && 'igr:cursor-pointer igr:hover:ring-2 igr:hover:ring-ring/60',
                                            cellClassName,
                                        )}
                                        style={{ background, color: light ? '#ffffff' : '#0b1b33' }}
                                    >
                                        {v.text}
                                    </div>
                                );
                                return <td key={c.key}>{v.detail ? <Tooltip content={v.detail}>{inner}</Tooltip> : inner}</td>;
                            })}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
