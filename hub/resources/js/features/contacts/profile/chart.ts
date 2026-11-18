import type { MonthlyPoint } from '../api';

export interface MonthBar {
    month: string;
    orders: number;
    revenue: number;
}

function monthKey(year: number, monthIndex: number): string {
    return `${year}-${String(monthIndex + 1).padStart(2, '0')}`;
}

/**
 * Completa los meses sin compras con cero para que el eje sea continuo.
 * Termina en el mes de `end` y cubre `count` meses (el hub manda los últimos 24).
 */
export function fillMonths(points: readonly MonthlyPoint[], end: Date, count = 24): MonthBar[] {
    const byMonth = new Map(points.map((p) => [p.month, p]));
    const out: MonthBar[] = [];
    for (let i = count - 1; i >= 0; i--) {
        const d = new Date(Date.UTC(end.getUTCFullYear(), end.getUTCMonth() - i, 1));
        const key = monthKey(d.getUTCFullYear(), d.getUTCMonth());
        const point = byMonth.get(key);
        const revenue = point ? Number(point.revenue) : 0;
        out.push({ month: key, orders: point?.orders ?? 0, revenue: Number.isFinite(revenue) ? revenue : 0 });
    }
    return out;
}

/** Recorta los meses vacíos del inicio, dejando al menos `min` para que el gráfico no quede vacío. */
export function trimLeadingEmpty(bars: readonly MonthBar[], min = 6): MonthBar[] {
    const first = bars.findIndex((b) => b.orders > 0);
    if (first === -1) {
        return bars.slice(-min);
    }
    return bars.slice(Math.min(first, Math.max(0, bars.length - min)));
}

/** «nov 2026» o «nov» según se necesite el año. */
export function monthLabel(month: string, locale: string, withYear = false): string {
    const [y, m] = month.split('-').map(Number);
    const date = new Date(Date.UTC(y ?? 1970, (m ?? 1) - 1, 15));
    return new Intl.DateTimeFormat(locale, { month: 'short', ...(withYear ? { year: 'numeric' } : {}), timeZone: 'UTC' }).format(date).replace('.', '');
}

/** Valores cortos para el eje: «1,2 M», «350 mil». */
export function compactNumber(value: number, locale: string): string {
    return new Intl.NumberFormat(locale, { notation: 'compact', maximumFractionDigits: 1 }).format(value);
}
