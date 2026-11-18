import { useMemo } from 'react';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatMoney, formatNumber, formatPercent } from '@/lib/format';
import type { Granularity, MetricUnit } from './api';

/** Formatos de los tableros con la configuración regional y la moneda del espacio de trabajo. */
export function makeFormatters(locale: string, currency: string) {
    const compact = (value: number, style: 'currency' | 'decimal') => {
        try {
            return new Intl.NumberFormat(locale, { notation: 'compact', maximumFractionDigits: 1, ...(style === 'currency' ? { style, currency } : {}) }).format(value);
        } catch {
            return formatNumber(value, locale);
        }
    };
    const day = (ymd: string, options: Intl.DateTimeFormatOptions) => {
        const date = new Date(`${ymd.slice(0, 10)}T12:00:00Z`);
        return Number.isNaN(date.getTime()) ? ymd : new Intl.DateTimeFormat(locale, { timeZone: 'UTC', ...options }).format(date);
    };
    const fmt = {
        locale,
        currency,
        money: (v: number | string | null | undefined) => (v === null || v === undefined ? '—' : formatMoney(v, currency, locale)),
        number: (v: number | null | undefined) => (v === null || v === undefined ? '—' : formatNumber(v, locale)),
        decimal: (v: number | null | undefined, digits = 1) => (v === null || v === undefined ? '—' : new Intl.NumberFormat(locale, { maximumFractionDigits: digits }).format(v)),
        percent: (v: number | null | undefined, digits = 1) => (v === null || v === undefined ? '—' : formatPercent(v, locale, digits)),
        compactMoney: (v: number) => compact(v, 'currency'),
        compactNumber: (v: number) => compact(v, 'decimal'),
        /** Etiqueta del periodo de una serie según su granularidad. */
        bucket: (ymd: string, granularity: Granularity) =>
            granularity === 'month' ? day(ymd, { month: 'short', year: 'numeric' }) : day(ymd, { day: 'numeric', month: 'short' }),
        date: (ymd: string | null | undefined) => (ymd ? day(ymd, { day: 'numeric', month: 'short', year: 'numeric' }) : '—'),
        month: (ym: string) => day(`${ym.slice(0, 7)}-01`, { month: 'short', year: 'numeric' }),
        byUnit: (unit: MetricUnit, v: number | null | undefined) => (unit === 'money' ? fmt.money(v) : unit === 'ratio' ? fmt.percent(v, 2) : fmt.number(v)),
    };
    return fmt;
}

export type Formatters = ReturnType<typeof makeFormatters>;

export function useFormatters(currency?: string): Formatters {
    const { workspace } = useWorkspace();
    const cur = currency ?? workspace.currency;
    return useMemo(() => makeFormatters(workspace.locale, cur), [workspace.locale, cur]);
}

/** Cambio relativo (o en puntos para tasas). null si no hay base de comparación. */
export function delta(current: number | null | undefined, previous: number | null | undefined, unit: MetricUnit): number | null {
    if (current === null || current === undefined || previous === null || previous === undefined) {
        return null;
    }
    if (unit === 'ratio') {
        return current - previous;
    }
    return previous === 0 ? null : (current - previous) / Math.abs(previous);
}
