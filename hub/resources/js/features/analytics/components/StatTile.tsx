import { ArrowDownRight, ArrowUpRight, CircleHelp, Minus } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Card } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Tooltip } from '@/components/ui/tooltip';
import { cn } from '@/lib/cn';
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
}) {
    const { t } = useTranslation();
    const change = compareMode && compareMode !== 'none' ? delta(value, previous, unit) : null;
    const flat = change !== null && Math.abs(change) < (unit === 'ratio' ? 0.0005 : 0.005);
    const up = change !== null && change > 0;
    const good = better === 'neutral' || flat ? null : better === 'up' ? up : !up;
    const Icon = flat ? Minus : up ? ArrowUpRight : ArrowDownRight;
    const changeText =
        change === null ? null : unit === 'ratio' ? t('analytics.tile.points', { value: `${change > 0 ? '+' : ''}${fmt.decimal(change * 100, 2)}` }) : `${change > 0 ? '+' : ''}${fmt.percent(change, 1)}`;

    return (
        <Card className="igr:flex igr:min-w-0 igr:flex-col igr:p-4">
            <div className="igr:flex igr:items-center igr:gap-1.5 igr:text-[13px] igr:text-muted-foreground">
                <span className="igr:truncate">{label}</span>
                {help ? (
                    <Tooltip content={help}>
                        <CircleHelp className="igr:size-3.5 igr:shrink-0 igr:cursor-help" aria-label={t('analytics.tile.howCalculated')} />
                    </Tooltip>
                ) : null}
            </div>
            <div className="igr:mt-1.5 igr:truncate igr:text-2xl igr:font-semibold igr:tracking-tight igr:tabular-nums">{fmt.byUnit(unit, value)}</div>
            <div className="igr:mt-1 igr:flex igr:min-h-4 igr:flex-wrap igr:items-center igr:gap-x-1.5 igr:text-xs igr:text-muted-foreground">
                {changeText !== null ? (
                    <span
                        className={cn('igr:inline-flex igr:items-center igr:gap-0.5 igr:font-medium igr:tabular-nums', good === true && 'igr:text-success', good === false && 'igr:text-destructive')}
                    >
                        <Icon className="igr:size-3.5" aria-hidden />
                        {changeText}
                    </span>
                ) : null}
                {changeText !== null ? <span>{t(`analytics.tile.vs.${compareMode ?? 'previous'}`)}</span> : null}
                {hint ? <span className="igr:truncate">{changeText !== null ? '· ' : ''}{hint}</span> : null}
            </div>
        </Card>
    );
}

export function TileGrid({ children, className }: { children: ReactNode; className?: string }) {
    return <div className={cn('igr:grid igr:gap-3 igr:sm:grid-cols-2 igr:lg:grid-cols-4', className)}>{children}</div>;
}

export function DashboardSkeleton() {
    return (
        <div className="igr:space-y-4" aria-busy>
            <TileGrid>
                {Array.from({ length: 4 }, (_, i) => (
                    <Skeleton key={i} className="igr:h-[98px]" />
                ))}
            </TileGrid>
            <Skeleton className="igr:h-72" />
            <div className="igr:grid igr:gap-4 igr:lg:grid-cols-2">
                <Skeleton className="igr:h-64" />
                <Skeleton className="igr:h-64" />
            </div>
        </div>
    );
}
