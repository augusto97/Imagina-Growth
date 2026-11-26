import { createContext, useContext, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Skeleton } from '@/components/ui/skeleton';
import { StatCard, StatCardSkeleton, StatGrid, StatList, StatRow } from '@/components/ui/stat-card';
import { PHONE_QUERY, useMedia } from '@/hooks/useMedia';
import { cn } from '@/lib/cn';
import type { CompareMode, MetricUnit } from '../api';
import { delta } from '../format';
import type { Formatters } from '../format';

/** Dentro de una `TileGrid` en el celular, las cifras van como filas de una lista compacta (`StatList`). */
const AsList = createContext(false);

/**
 * Cifra principal con su comparación. El color del cambio usa los tonos de
 * estado del tema y siempre va con flecha y texto (nunca color solo).
 * `better` indica si subir es bueno («up»), malo («down») o neutro.
 */
export function StatTile({
    label,
    value,
    unit,
    previous,
    compareMode,
    fmt,
    help,
    hint,
    better = 'up',
    display,
    trend,
}: {
    label: string;
    value: number | null | undefined;
    unit: MetricUnit;
    previous?: number | null;
    compareMode?: CompareMode | null;
    fmt: Formatters;
    help?: string;
    hint?: ReactNode;
    better?: 'up' | 'down' | 'neutral';
    /** Texto ya formateado en lugar del valor (p. ej. el retorno «12×» o un costo en dólares). */
    display?: string;
    /** Serie para la minigráfica de la tarjeta (en la lista del celular no va). */
    trend?: readonly (number | null | undefined)[];
}) {
    const { t } = useTranslation();
    const asList = useContext(AsList);
    const change = compareMode && compareMode !== 'none' ? delta(value, previous, unit) : null;
    const changeText =
        change === null
            ? null
            : unit === 'ratio'
              ? t('analytics.tile.points', { value: `${change > 0 ? '+' : ''}${fmt.decimal(change * 100, 2)}` })
              : `${change > 0 ? '+' : ''}${fmt.percent(change, 1)}`;

    // La tarjeta del sistema v2 (components/ui/stat-card.tsx); aquí solo se calcula la variación con la unidad de la métrica.
    if (asList) {
        return (
            <StatRow
                label={label}
                value={display ?? fmt.byUnit(unit, value)}
                help={help}
                helpLabel={help ? `${t('analytics.tile.howCalculated')} ${label}` : undefined}
                change={change}
                changeLabel={changeText ?? undefined}
                better={better}
                flat={unit === 'ratio' ? 0.0005 : 0.005}
                hint={hint}
            />
        );
    }
    return (
        <StatCard
            label={label}
            value={display ?? fmt.byUnit(unit, value)}
            help={help}
            helpLabel={help ? `${t('analytics.tile.howCalculated')} ${label}` : undefined}
            change={change}
            changeLabel={changeText ?? undefined}
            compareLabel={changeText !== null ? t(`analytics.tile.vs.${compareMode ?? 'previous'}`) : undefined}
            better={better}
            flat={unit === 'ratio' ? 0.0005 : 0.005}
            hint={hint}
            trend={trend}
        />
    );
}

/** Las cifras de un tablero: tarjetas en la tableta y el escritorio, una lista compacta en el celular. */
export function TileGrid({ children, className }: { children: ReactNode; className?: string }) {
    const { t } = useTranslation();
    const phone = useMedia(PHONE_QUERY);
    if (phone) {
        return (
            <AsList.Provider value>
                <StatList className={className} label={t('analytics.tile.listLabel')}>
                    {children}
                </StatList>
            </AsList.Provider>
        );
    }
    return <StatGrid className={className}>{children}</StatGrid>;
}

/**
 * El esqueleto de una `TileGrid` con `count` cifras, del mismo alto que lo que llega: tarjetas en filas en la tableta
 * y el escritorio, la lista de filas de 56 px en el celular (sin saltos de diseño).
 */
export function TileGridSkeleton({ count, className }: { count: number; className?: string }) {
    const phone = useMedia(PHONE_QUERY);
    if (count <= 0) {
        return null;
    }
    if (phone) {
        return <Skeleton className={cn('igr:rounded-xl', className)} style={{ height: count * 57 + 1 }} />;
    }
    return (
        <StatGrid className={className}>
            {Array.from({ length: count }, (_, i) => (
                <StatCardSkeleton key={i} />
            ))}
        </StatGrid>
    );
}

/** Mientras carga un tablero: sus cifras (`tiles`, las de su primera rejilla) y los bloques de abajo. */
export function DashboardSkeleton({ tiles = 4 }: { tiles?: number }) {
    return (
        <div className="igr:space-y-4" aria-busy>
            <TileGridSkeleton count={tiles} />
            <Skeleton className="igr:h-72 igr:rounded-xl" />
            <div className="igr:grid igr:gap-4 igr:lg:grid-cols-2">
                <Skeleton className="igr:h-64 igr:rounded-xl" />
                <Skeleton className="igr:h-64 igr:rounded-xl" />
            </div>
        </div>
    );
}
