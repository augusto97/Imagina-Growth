import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Skeleton } from '@/components/ui/skeleton';
import { StatCard, StatGrid } from '@/components/ui/stat-card';
import type { CompareMode, MetricUnit } from '../api';
import { delta } from '../format';
import type { Formatters } from '../format';

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
}) {
    const { t } = useTranslation();
    const change = compareMode && compareMode !== 'none' ? delta(value, previous, unit) : null;
    const changeText =
        change === null
            ? null
            : unit === 'ratio'
              ? t('analytics.tile.points', { value: `${change > 0 ? '+' : ''}${fmt.decimal(change * 100, 2)}` })
              : `${change > 0 ? '+' : ''}${fmt.percent(change, 1)}`;

    // La tarjeta del sistema v2 (components/ui/stat-card.tsx); aquí solo se calcula la variación con la unidad de la métrica.
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
        />
    );
}

export function TileGrid({ children, className }: { children: ReactNode; className?: string }) {
    return <StatGrid className={className}>{children}</StatGrid>;
}

export function DashboardSkeleton() {
    return (
        <div className="igr:space-y-4" aria-busy>
            <TileGrid>
                {Array.from({ length: 4 }, (_, i) => (
                    <Skeleton key={i} className="igr:h-[118px] igr:rounded-xl" />
                ))}
            </TileGrid>
            <Skeleton className="igr:h-72 igr:rounded-xl" />
            <div className="igr:grid igr:gap-4 igr:lg:grid-cols-2">
                <Skeleton className="igr:h-64 igr:rounded-xl" />
                <Skeleton className="igr:h-64 igr:rounded-xl" />
            </div>
        </div>
    );
}
