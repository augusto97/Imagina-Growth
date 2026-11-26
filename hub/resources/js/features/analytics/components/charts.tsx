import { lazy, Suspense, type ComponentProps, type ComponentType } from 'react';
import { Skeleton } from '@/components/ui/skeleton';
import type { CategoryBars as CategoryBarsImpl, LineTrend as LineTrendImpl, StackedBars as StackedBarsImpl, TrendChart as TrendChartImpl } from './charts-recharts';

export type { ChartTooltipContent } from './charts-recharts';

/*
 * Gráficos de los tableros (skill dataviz). Las gráficas de Recharts (`TrendChart`, `StackedBars`) se cargan perezosas
 * en su propio chunk (docs/rendimiento.md §7.1, 3): Inicio, el perfil y Resultados pintan primero sus cifras y la
 * gráfica llega después, con un esqueleto del mismo alto mientras tanto (sin saltos de diseño). La leyenda, las barras
 * en lista y el mapa de calor no usan Recharts y van con la pantalla.
 */
export { BarList, Heatmap, Legend } from './charts-lite';

const LazyTrend = lazy(async () => ({ default: (await import('./charts-recharts')).TrendChart as ComponentType<unknown> }));
const LazyStacked = lazy(async () => ({ default: (await import('./charts-recharts')).StackedBars as ComponentType<unknown> }));
const LazyCategory = lazy(async () => ({ default: (await import('./charts-recharts')).CategoryBars as ComponentType<unknown> }));
const LazyLine = lazy(async () => ({ default: (await import('./charts-recharts')).LineTrend as ComponentType<unknown> }));

/** Alto de la leyenda que `StackedBars` pinta arriba de las barras (para que el esqueleto mida lo mismo). */
const LEGEND_HEIGHT = 24;

export function TrendChart<T extends { date: string }>(props: ComponentProps<typeof TrendChartImpl<T>>) {
    const Chart = LazyTrend as ComponentType<ComponentProps<typeof TrendChartImpl<T>>>;
    return (
        <Suspense fallback={<Skeleton className="igr:rounded-lg" style={{ height: props.height ?? 240 }} />}>
            <Chart {...props} />
        </Suspense>
    );
}

export function StackedBars<T extends { date: string }>(props: ComponentProps<typeof StackedBarsImpl<T>>) {
    const Chart = LazyStacked as ComponentType<ComponentProps<typeof StackedBarsImpl<T>>>;
    return (
        <Suspense fallback={<Skeleton className="igr:rounded-lg" style={{ height: (props.height ?? 240) + LEGEND_HEIGHT }} />}>
            <Chart {...props} />
        </Suspense>
    );
}

/** Barras por categoría (deciles, rangos, notas), perezosas como las demás; el esqueleto mide lo mismo (con su leyenda). */
export function CategoryBars<T extends object>(props: ComponentProps<typeof CategoryBarsImpl<T>>) {
    const Chart = LazyCategory as ComponentType<ComponentProps<typeof CategoryBarsImpl<T>>>;
    const legend = props.legend !== false && props.series.length > 1 ? LEGEND_HEIGHT : 0;
    return (
        <Suspense fallback={<Skeleton className="igr:rounded-lg" style={{ height: (props.height ?? 224) + legend }} />}>
            <Chart {...props} />
        </Suspense>
    );
}

/** Una serie como línea con puntos, perezosa. */
export function LineTrend<T extends object>(props: ComponentProps<typeof LineTrendImpl<T>>) {
    const Chart = LazyLine as ComponentType<ComponentProps<typeof LineTrendImpl<T>>>;
    return (
        <Suspense fallback={<Skeleton className="igr:rounded-lg" style={{ height: props.height ?? 220 }} />}>
            <Chart {...props} />
        </Suspense>
    );
}
