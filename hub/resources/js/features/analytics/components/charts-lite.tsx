import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Tooltip } from '@/components/ui/tooltip';
import { cn } from '@/lib/cn';
import { sequentialCell, useChartTheme } from '../theme';

/*
 * Las piezas de los gráficos que no necesitan Recharts (leyenda, barras en lista y mapa de calor): van con la pantalla.
 */

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
