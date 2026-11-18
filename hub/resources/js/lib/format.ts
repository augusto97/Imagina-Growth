/** Formatos según la moneda y la configuración regional del espacio de trabajo (hub/CLAUDE.md §14). */

const ZERO_DECIMAL = new Set(['COP', 'CLP', 'PYG', 'JPY', 'VES']);

export function formatMoney(value: string | number | null | undefined, currency: string, locale = 'es-CO'): string {
    const amount = typeof value === 'string' ? Number(value) : (value ?? 0);
    const digits = ZERO_DECIMAL.has(currency.toUpperCase()) ? 0 : 2;
    try {
        return new Intl.NumberFormat(locale, {
            style: 'currency',
            currency,
            minimumFractionDigits: digits,
            maximumFractionDigits: digits,
        }).format(Number.isFinite(amount) ? amount : 0);
    } catch {
        return `${currency} ${String(amount)}`;
    }
}

export function formatNumber(value: number | null | undefined, locale = 'es-CO'): string {
    return new Intl.NumberFormat(locale).format(value ?? 0);
}

export function formatPercent(ratio: number | null | undefined, locale = 'es-CO', digits = 0): string {
    return new Intl.NumberFormat(locale, { style: 'percent', maximumFractionDigits: digits }).format(ratio ?? 0);
}

export function formatDateTime(iso: string | null | undefined, locale = 'es-CO', timeZone?: string): string {
    if (!iso) {
        return '—';
    }
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) {
        return '—';
    }
    return new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit', timeZone }).format(date);
}

export function formatDate(iso: string | null | undefined, locale = 'es-CO', timeZone?: string): string {
    if (!iso) {
        return '—';
    }
    const date = new Date(iso.length === 10 ? `${iso}T12:00:00Z` : iso);
    if (Number.isNaN(date.getTime())) {
        return '—';
    }
    return new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'short', year: 'numeric', timeZone }).format(date);
}

/** «hace 5 minutos», «en 3 días». */
export function formatRelative(iso: string | null | undefined, locale = 'es-CO', now: Date = new Date()): string {
    if (!iso) {
        return '—';
    }
    const then = new Date(iso).getTime();
    if (Number.isNaN(then)) {
        return '—';
    }
    const seconds = Math.round((then - now.getTime()) / 1000);
    const rtf = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });
    const abs = Math.abs(seconds);
    if (abs < 60) {
        return rtf.format(seconds, 'second');
    }
    if (abs < 3600) {
        return rtf.format(Math.round(seconds / 60), 'minute');
    }
    if (abs < 86400) {
        return rtf.format(Math.round(seconds / 3600), 'hour');
    }
    if (abs < 2592000) {
        return rtf.format(Math.round(seconds / 86400), 'day');
    }
    return rtf.format(Math.round(seconds / 2592000), 'month');
}
