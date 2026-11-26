import { ArrowDownRight, ArrowUpRight, Minus, type LucideIcon } from 'lucide-react';
import { useId, type ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { Card } from './card';
import { HelpTip } from './help-tip';
import { IconTile, type IconTone } from './icon-tile';
import { Skeleton } from './skeleton';

/** Hacia dónde es bueno que se mueva la cifra. */
export type Better = 'up' | 'down' | 'neutral';

/**
 * El tono de una variación: buena, mala o neutra. Un cambio menor que `flat` (en valor absoluto) cuenta como plano.
 * Siempre va con flecha y texto: el color nunca es la única señal.
 */
export function changeTone(change: number | null | undefined, better: Better = 'up', flat = 0.005): 'good' | 'bad' | 'flat' | null {
    if (change === null || change === undefined || !Number.isFinite(change)) {
        return null;
    }
    if (Math.abs(change) < flat || better === 'neutral') {
        return 'flat';
    }
    const up = change > 0;
    return (better === 'up') === up ? 'good' : 'bad';
}

/**
 * Puntos de la minigráfica en un lienzo de `width` × `height` (con 2 px de margen para el trazo). Los valores nulos o
 * no finitos se toman como 0; una serie plana queda en el medio.
 */
export function sparklinePoints(values: readonly (number | null | undefined)[], width = 96, height = 28): [number, number][] {
    const data = values.map((v) => (typeof v === 'number' && Number.isFinite(v) ? v : 0));
    if (data.length === 0) {
        return [];
    }
    const min = Math.min(...data);
    const max = Math.max(...data);
    const pad = 2;
    const span = max - min;
    const step = data.length > 1 ? (width - pad * 2) / (data.length - 1) : 0;
    return data.map((v, i) => [pad + i * step, span === 0 ? height / 2 : pad + (1 - (v - min) / span) * (height - pad * 2)]);
}

/** Minigráfica de tendencia (decorativa; la cifra y la variación dicen lo importante). Línea de 2 px y área tenue. */
export function Sparkline({ values, className }: { values: readonly (number | null | undefined)[]; className?: string }) {
    const id = useId();
    const points = sparklinePoints(values);
    if (points.length < 2) {
        return null;
    }
    const line = points.map(([x, y], i) => `${i === 0 ? 'M' : 'L'}${x.toFixed(1)} ${y.toFixed(1)}`).join(' ');
    const last = points[points.length - 1] ?? [0, 0];
    const first = points[0] ?? [0, 0];
    const area = `${line} L${last[0].toFixed(1)} 28 L${first[0].toFixed(1)} 28 Z`;
    return (
        <svg viewBox="0 0 96 28" preserveAspectRatio="none" aria-hidden focusable="false" className={cn('igr:h-7 igr:w-24 igr:overflow-visible igr:text-primary', className)}>
            <defs>
                <linearGradient id={`spark-${id}`} x1="0" x2="0" y1="0" y2="1">
                    <stop offset="0%" stopColor="currentColor" stopOpacity="0.18" />
                    <stop offset="100%" stopColor="currentColor" stopOpacity="0" />
                </linearGradient>
            </defs>
            <path d={area} fill={`url(#spark-${id})`} />
            <path d={line} fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" vectorEffect="non-scaling-stroke" />
        </svg>
    );
}

/**
 * Tarjeta de una métrica (StatCard / MetricTile, rediseño v2 §7): nombre con su ayuda «?», la cifra con `tabular-nums`,
 * la variación con flecha y texto, y una minigráfica opcional. La cifra y la variación llegan ya formateadas (cada
 * pantalla sabe su moneda y su unidad); `change` es la variación como fracción (0,12 = +12 %) solo para el tono.
 */
export function StatCard({
    label,
    value,
    help,
    helpLabel,
    change,
    changeLabel,
    compareLabel,
    better = 'up',
    flat,
    hint,
    trend,
    icon,
    tone = 'indigo',
    loading = false,
    size = 'default',
    className,
}: {
    label: ReactNode;
    value: ReactNode;
    help?: ReactNode;
    /** Nombre accesible del botón «?» (por defecto, el nombre de la métrica). */
    helpLabel?: string;
    /** Variación como fracción, para el color y la flecha. */
    change?: number | null;
    /** La variación ya formateada («+12 %», «+1,5 pp»). */
    changeLabel?: ReactNode;
    /** Contra qué se compara («vs. mes anterior»). */
    compareLabel?: ReactNode;
    better?: Better;
    flat?: number;
    /** Texto de apoyo debajo («3.777 con al menos una compra»). */
    hint?: ReactNode;
    /** Serie para la minigráfica. */
    trend?: readonly (number | null | undefined)[];
    icon?: LucideIcon;
    tone?: IconTone;
    loading?: boolean;
    size?: 'default' | 'hero';
    className?: string;
}) {
    const toneOf = changeTone(change, better, flat);
    const Arrow = toneOf === 'flat' ? Minus : (change ?? 0) > 0 ? ArrowUpRight : ArrowDownRight;
    return (
        <Card className={cn('igr:flex igr:min-w-0 igr:flex-col igr:p-3.5 igr:sm:p-5', className)}>
            {/* En el celular van de a dos: sin el ícono y con el nombre en hasta dos líneas (siempre reserva las dos). */}
            <div className="igr:flex igr:items-center igr:gap-2 igr:max-sm:min-h-10">
                {icon ? (
                    <span className="igr:max-sm:hidden">
                        <IconTile icon={icon} tone={tone} size="sm" />
                    </span>
                ) : null}
                <span className="igr:min-w-0 igr:truncate igr:text-support igr:font-medium igr:text-muted-foreground igr:max-sm:line-clamp-2 igr:max-sm:whitespace-normal">{label}</span>
                {help ? <HelpTip content={help} label={helpLabel ?? (typeof label === 'string' ? label : undefined)} /> : null}
                {trend && !loading ? <Sparkline values={trend} className="igr:ml-auto igr:h-6 igr:w-20 igr:shrink-0" /> : null}
            </div>
            <div className="igr:mt-2">
                {loading ? (
                    <Skeleton className={size === 'hero' ? 'igr:h-12 igr:w-40' : 'igr:h-9 igr:w-28'} />
                ) : (
                    <div className={cn('igr:min-w-0 igr:truncate igr:font-semibold igr:text-foreground igr:tabular-nums', size === 'hero' ? 'igr:text-hero' : 'igr:text-[22px]/[30px] igr:sm:text-figure')}>
                        {value}
                    </div>
                )}
            </div>
            {/* Siempre está, aunque vaya vacía: todas las tarjetas miden lo mismo y los esqueletos también (sin saltos). */}
            <div className="igr:mt-1.5 igr:flex igr:min-h-5 igr:items-center igr:gap-x-1.5 igr:overflow-hidden igr:text-caption igr:whitespace-nowrap igr:text-muted-foreground">
                {changeLabel !== undefined && changeLabel !== null ? (
                    <span
                        className={cn(
                            'igr:inline-flex igr:shrink-0 igr:items-center igr:gap-0.5 igr:rounded-full igr:px-1.5 igr:py-0.5 igr:font-medium igr:tabular-nums',
                            toneOf === 'good' && 'igr:bg-success/10 igr:text-success',
                            toneOf === 'bad' && 'igr:bg-destructive/10 igr:text-destructive',
                            (toneOf === 'flat' || toneOf === null) && 'igr:bg-muted igr:text-muted-foreground',
                        )}
                    >
                        {toneOf !== null ? <Arrow className="igr:size-3.5" aria-hidden /> : null}
                        {changeLabel}
                    </span>
                ) : null}
                {changeLabel !== undefined && changeLabel !== null && compareLabel ? <span className="igr:min-w-0 igr:truncate">{compareLabel}</span> : null}
                {hint ? <span className="igr:min-w-0 igr:truncate">{hint}</span> : null}
            </div>
        </Card>
    );
}

/** El esqueleto de una `StatCard`: el mismo alto (134 px en el celular, 132 desde `sm`), para que nada salte al cargar. */
export function StatCardSkeleton({ className }: { className?: string }) {
    return <Skeleton className={cn('igr:h-[134px] igr:rounded-xl igr:sm:h-[132px]', className)} />;
}

/** Rejilla de tarjetas de métricas: una columna en el celular, dos en la tableta y hasta cuatro en el escritorio. */
export function StatGrid({ children, className }: { children: ReactNode; className?: string }) {
    // De a dos desde el celular (una sola columna obligaba a desplazarse mucho); la última de un número impar ocupa la fila.
    return <div className={cn('igr:grid igr:grid-cols-2 igr:gap-3 igr:lg:grid-cols-4 igr:max-sm:[&>*:last-child:nth-child(odd)]:col-span-2', className)}>{children}</div>;
}

/**
 * Lista compacta de métricas (StatList / StatRow): una tarjeta con una fila por cifra, para el celular, donde ocho
 * `StatCard` en una sola columna obligan a desplazarse mucho. Cada fila mide siempre 56 px (sin saltos al cargar) y
 * lleva el nombre con su ayuda «?», la cifra con `tabular-nums` y la variación con flecha y texto.
 */
export function StatList({ children, className, label }: { children: ReactNode; className?: string; label?: string }) {
    return (
        <Card className={cn('igr:overflow-hidden', className)}>
            <ul className="igr:divide-y" aria-label={label}>
                {children}
            </ul>
        </Card>
    );
}

/** Una fila de `StatList` (las mismas props de la variación que `StatCard`). */
export function StatRow({
    label,
    value,
    help,
    helpLabel,
    change,
    changeLabel,
    better = 'up',
    flat,
    hint,
    loading = false,
}: {
    label: ReactNode;
    value: ReactNode;
    help?: ReactNode;
    helpLabel?: string;
    change?: number | null;
    changeLabel?: ReactNode;
    better?: Better;
    flat?: number;
    hint?: ReactNode;
    loading?: boolean;
}) {
    const toneOf = changeTone(change, better, flat);
    const Arrow = toneOf === 'flat' ? Minus : (change ?? 0) > 0 ? ArrowUpRight : ArrowDownRight;
    const showChange = changeLabel !== undefined && changeLabel !== null && !loading;
    return (
        <li className="igr:flex igr:h-14 igr:items-center igr:gap-3 igr:px-4">
            <div className="igr:min-w-0 igr:flex-1">
                <div className="igr:flex igr:items-center igr:gap-1.5">
                    <span className="igr:min-w-0 igr:truncate igr:text-support igr:text-muted-foreground">{label}</span>
                    {help ? <HelpTip content={help} label={helpLabel ?? (typeof label === 'string' ? label : undefined)} /> : null}
                </div>
                {hint ? <p className="igr:truncate igr:text-caption igr:text-muted-foreground">{hint}</p> : null}
            </div>
            <div className="igr:flex igr:shrink-0 igr:flex-col igr:items-end">
                {loading ? <Skeleton className="igr:h-5 igr:w-20" /> : <span className="igr:text-[15px] igr:font-semibold igr:text-foreground igr:tabular-nums">{value}</span>}
                {showChange ? (
                    <span
                        className={cn(
                            'igr:inline-flex igr:items-center igr:gap-0.5 igr:text-caption igr:font-medium igr:tabular-nums',
                            toneOf === 'good' && 'igr:text-success',
                            toneOf === 'bad' && 'igr:text-destructive',
                            (toneOf === 'flat' || toneOf === null) && 'igr:text-muted-foreground',
                        )}
                    >
                        {toneOf !== null ? <Arrow className="igr:size-3" aria-hidden /> : null}
                        {changeLabel}
                    </span>
                ) : null}
            </div>
        </li>
    );
}
