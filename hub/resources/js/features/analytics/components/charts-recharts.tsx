import type { ReactNode } from 'react';
import { Area, AreaChart, Bar, BarChart, CartesianGrid, Line, LineChart, ReferenceLine, ResponsiveContainer, Tooltip as RTooltip, XAxis, YAxis } from 'recharts';
import type { TooltipContentProps } from 'recharts';
import type { Granularity, MetricUnit } from '../api';
import type { Formatters } from '../format';
import { useChartTheme } from '../theme';
import { Legend } from './charts-lite';

/*
 * Las gráficas hechas con Recharts (≈117 kB gzip). Nunca se importan directo: `charts.tsx` las carga perezosas para que
 * la pantalla pinte primero sus cifras y la gráfica llegue después en su propio chunk (docs/rendimiento.md §7.1, 3).
 * Skill dataviz: marcas finas, una sola escala, cuadrícula tenue, leyenda desde 2 series y tooltip al pasar el mouse.
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
                    <Area
                        type="monotone"
                        dataKey={(row: T) => Number(row[metric])}
                        stroke={color}
                        strokeWidth={2}
                        fill={`url(#${id})`}
                        dot={false}
                        activeDot={{ r: 4, strokeWidth: 2, stroke: theme.surface }}
                        isAnimationActive={false}
                    />
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

/** Una fila del cuadro que aparece al pasar el puntero (título y filas con su color). */
export interface ChartTooltipContent {
    title: ReactNode;
    rows: { label: ReactNode; value: ReactNode; color?: string }[];
}

/**
 * Barras por categoría (no por fecha): deciles, rangos, notas de una encuesta… Una o varias series lado a lado o
 * apiladas (`stacked`), con su color y el cuadro del puntero que arma la pantalla. Con 2 o más series lleva leyenda.
 */
export function CategoryBars<T extends object>({
    data,
    x,
    series,
    stacked = false,
    height = 224,
    xTick,
    yTick,
    tooltip,
    ariaLabel,
    legend = true,
}: {
    data: T[];
    x: keyof T & string;
    series: { key: keyof T & string; label: string; color: string }[];
    stacked?: boolean;
    height?: number;
    xTick?: (value: string) => string;
    yTick?: (value: number) => string;
    tooltip: (row: T) => ChartTooltipContent;
    ariaLabel: string;
    legend?: boolean;
}) {
    const theme = useChartTheme();
    return (
        <div>
            {legend && series.length > 1 ? <Legend items={series.map((s) => ({ label: s.label, color: s.color }))} /> : null}
            <div style={{ height }} role="img" aria-label={ariaLabel}>
                <ResponsiveContainer width="100%" height="100%">
                    <BarChart data={data} margin={{ top: 4, right: 4, bottom: 0, left: 0 }} barCategoryGap={stacked ? 2 : '22%'} barGap={2}>
                        <CartesianGrid vertical={false} stroke={theme.grid} />
                        <XAxis dataKey={(row: T) => String(row[x])} tickFormatter={xTick} tick={{ fill: theme.muted, fontSize: 11 }} tickLine={false} axisLine={{ stroke: theme.axis }} minTickGap={4} />
                        <YAxis allowDecimals={false} width={40} tick={{ fill: theme.muted, fontSize: 11 }} tickLine={false} axisLine={false} tickFormatter={yTick} />
                        <RTooltip
                            cursor={{ fill: theme.grid, opacity: 0.5 }}
                            isAnimationActive={false}
                            content={(props: TooltipContentProps) => {
                                const row = props.payload[0]?.payload as T | undefined;
                                if (!props.active || row === undefined) return null;
                                const box = tooltip(row);
                                return <TooltipBox title={box.title} rows={box.rows} />;
                            }}
                        />
                        {series.map((s, i) => (
                            <Bar
                                key={s.key}
                                dataKey={(row: T) => Number(row[s.key] ?? 0)}
                                name={s.label}
                                fill={s.color}
                                stackId={stacked ? 'a' : undefined}
                                radius={!stacked || i === series.length - 1 ? [4, 4, 0, 0] : 0}
                                maxBarSize={stacked ? 36 : 16}
                                isAnimationActive={false}
                            />
                        ))}
                    </BarChart>
                </ResponsiveContainer>
            </div>
        </div>
    );
}

/** Una serie como línea con puntos (p. ej. la nota de una encuesta por semana), con su dominio y una línea de referencia. */
export function LineTrend<T extends object>({
    data,
    x,
    y,
    color,
    height = 220,
    domain,
    referenceY,
    xTick,
    yTick,
    tooltip,
    ariaLabel,
}: {
    data: T[];
    x: keyof T & string;
    y: keyof T & string;
    color?: string;
    height?: number;
    domain?: [number, number];
    referenceY?: number;
    xTick?: (value: string) => string;
    yTick?: (value: number) => string;
    tooltip: (row: T) => ChartTooltipContent;
    ariaLabel: string;
}) {
    const theme = useChartTheme();
    const stroke = color ?? theme.series[0] ?? '#2a78d6';
    return (
        <div style={{ height }} role="img" aria-label={ariaLabel}>
            <ResponsiveContainer width="100%" height="100%">
                <LineChart data={data} margin={{ top: 8, right: 12, bottom: 0, left: 0 }}>
                    <CartesianGrid vertical={false} stroke={theme.grid} />
                    <XAxis dataKey={(row: T) => String(row[x])} tickFormatter={xTick} tick={{ fill: theme.muted, fontSize: 11 }} tickLine={false} axisLine={{ stroke: theme.axis }} minTickGap={24} />
                    <YAxis domain={domain} tickFormatter={yTick} tick={{ fill: theme.muted, fontSize: 11 }} tickLine={false} axisLine={false} width={48} />
                    {referenceY !== undefined ? <ReferenceLine y={referenceY} stroke={theme.axis} /> : null}
                    <RTooltip
                        cursor={{ stroke: theme.axis, strokeWidth: 1 }}
                        content={(props: TooltipContentProps) => {
                            const row = props.payload[0]?.payload as T | undefined;
                            if (!props.active || row === undefined) return null;
                            const box = tooltip(row);
                            return <TooltipBox title={box.title} rows={box.rows} />;
                        }}
                    />
                    <Line
                        type="monotone"
                        dataKey={(row: T) => Number(row[y] ?? 0)}
                        stroke={stroke}
                        strokeWidth={2}
                        dot={{ r: 4, strokeWidth: 2, stroke: theme.surface, fill: stroke }}
                        activeDot={{ r: 5, strokeWidth: 2, stroke: theme.surface }}
                        isAnimationActive={false}
                    />
                </LineChart>
            </ResponsiveContainer>
        </div>
    );
}
