import { ArrowDown, ArrowUp, CircleHelp } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { Tooltip } from '@/components/ui/tooltip';
import { cn } from '@/lib/cn';
import type { CsvColumn, CsvValue } from '../csv';

export interface Column<T> {
    key: string;
    label: string;
    help?: string;
    align?: 'left' | 'right';
    /** Celda en pantalla. */
    render: (row: T) => ReactNode;
    /** Valor crudo para ordenar y exportar (números sin formato). */
    value?: (row: T) => CsvValue;
    sortable?: boolean;
}

export function csvColumns<T>(columns: Column<T>[]): CsvColumn<T>[] {
    return columns.filter((c) => c.value !== undefined).map((c) => ({ label: c.label, value: c.value as (row: T) => CsvValue }));
}

/** Tabla de un tablero: ordenable por columna, números alineados a la derecha y cifras tabulares. */
export function DataTable<T>({
    columns,
    rows,
    rowKey,
    initialSort,
    limit,
    empty,
    onRowClick,
}: {
    columns: Column<T>[];
    rows: T[];
    rowKey: (row: T, index: number) => string;
    initialSort?: { key: string; dir: 'asc' | 'desc' };
    limit?: number;
    empty?: ReactNode;
    onRowClick?: (row: T) => void;
}) {
    const { t } = useTranslation();
    const [sort, setSort] = useState(initialSort ?? null);
    const [expanded, setExpanded] = useState(false);

    const sorted = useMemo(() => {
        if (sort === null) {
            return rows;
        }
        const column = columns.find((c) => c.key === sort.key);
        if (!column?.value) {
            return rows;
        }
        const get = column.value;
        const factor = sort.dir === 'asc' ? 1 : -1;
        return [...rows].sort((a, b) => {
            const va = get(a);
            const vb = get(b);
            if (va === vb) return 0;
            if (va === null || va === undefined) return 1;
            if (vb === null || vb === undefined) return -1;
            return (typeof va === 'number' && typeof vb === 'number' ? va - vb : String(va).localeCompare(String(vb), 'es')) * factor;
        });
    }, [rows, columns, sort]);

    const visible = limit !== undefined && !expanded ? sorted.slice(0, limit) : sorted;

    const toggle = (key: string) =>
        setSort((prev) => (prev?.key === key ? { key, dir: prev.dir === 'desc' ? 'asc' : 'desc' } : { key, dir: 'desc' }));

    return (
        <>
            <Table>
                <THead>
                    <TR className="igr:hover:bg-transparent">
                        {columns.map((c) => {
                            const active = sort?.key === c.key;
                            const sortable = c.sortable !== false && c.value !== undefined;
                            return (
                                <TH key={c.key} className={cn(c.align === 'right' && 'igr:text-right', 'igr:whitespace-nowrap')} aria-sort={active ? (sort.dir === 'asc' ? 'ascending' : 'descending') : undefined}>
                                    <span className={cn('igr:inline-flex igr:items-center igr:gap-1', c.align === 'right' && 'igr:flex-row-reverse')}>
                                        {sortable ? (
                                            <button type="button" className="igr:inline-flex igr:items-center igr:gap-1 igr:hover:text-foreground" onClick={() => toggle(c.key)}>
                                                {c.label}
                                                {active ? sort.dir === 'desc' ? <ArrowDown className="igr:size-3" /> : <ArrowUp className="igr:size-3" /> : null}
                                            </button>
                                        ) : (
                                            c.label
                                        )}
                                        {c.help ? (
                                            <Tooltip content={c.help}>
                                                <CircleHelp className="igr:size-3 igr:cursor-help igr:text-muted-foreground" aria-label={c.help} />
                                            </Tooltip>
                                        ) : null}
                                    </span>
                                </TH>
                            );
                        })}
                    </TR>
                </THead>
                <TBody>
                    {visible.length === 0 ? (
                        <TR className="igr:hover:bg-transparent">
                            <TD colSpan={columns.length} className="igr:py-8 igr:text-center igr:text-muted-foreground">
                                {empty ?? t('analytics.table.empty')}
                            </TD>
                        </TR>
                    ) : (
                        visible.map((row, i) => (
                            <TR key={rowKey(row, i)} className={cn(onRowClick && 'igr:cursor-pointer')} onClick={onRowClick ? () => onRowClick(row) : undefined}>
                                {columns.map((c) => (
                                    <TD key={c.key} className={cn(c.align === 'right' && 'igr:text-right igr:tabular-nums igr:whitespace-nowrap')}>
                                        {c.render(row)}
                                    </TD>
                                ))}
                            </TR>
                        ))
                    )}
                </TBody>
            </Table>
            {limit !== undefined && sorted.length > limit ? (
                <div className="igr:border-t igr:px-3 igr:py-2">
                    <button type="button" className="igr:text-xs igr:font-medium igr:text-primary igr:hover:underline" onClick={() => setExpanded((v) => !v)}>
                        {expanded ? t('analytics.table.showLess') : t('analytics.table.showAll', { count: sorted.length })}
                    </button>
                </div>
            ) : null}
        </>
    );
}
