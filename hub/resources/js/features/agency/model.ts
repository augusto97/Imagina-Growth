import type { AgencyClient, HealthStatus } from './api';

/* Lógica pura del panel de agencia (con pruebas en agency.test.ts). */

export type ClientSort = 'money' | 'delta' | 'name' | 'critical' | 'usage';
export type ClientFilter = 'all' | 'attention' | 'disconnected' | 'critical' | 'over_limit' | 'billing';

export function healthTone(status: HealthStatus): 'success' | 'warning' | 'destructive' | 'muted' {
    return status === 'healthy' ? 'success' : status === 'degraded' ? 'warning' : status === 'disconnected' ? 'destructive' : 'muted';
}

export function isOverLimit(c: AgencyClient): boolean {
    return c.usage.pct !== null && c.usage.pct >= 100;
}

export function billingNeedsAttention(c: AgencyClient): boolean {
    return c.billing.status === 'grace' || c.billing.status === 'suspended';
}

/** ¿El cliente necesita atención? Desconectado, con hallazgos críticos, sobre el límite o con la facturación atrasada. */
export function needsAttention(c: AgencyClient): boolean {
    return c.health.status === 'disconnected' || c.health.status === 'degraded' || c.critical_insights > 0 || isOverLimit(c) || billingNeedsAttention(c);
}

export function filterClients(clients: AgencyClient[], filter: ClientFilter, search: string): AgencyClient[] {
    const q = search.trim().toLocaleLowerCase('es');
    return clients.filter((c) => {
        if (q !== '' && !c.name.toLocaleLowerCase('es').includes(q) && !(c.account?.name ?? '').toLocaleLowerCase('es').includes(q)) {
            return false;
        }
        switch (filter) {
            case 'attention':
                return needsAttention(c);
            case 'disconnected':
                return c.health.status === 'disconnected';
            case 'critical':
                return c.critical_insights > 0;
            case 'over_limit':
                return isOverLimit(c);
            case 'billing':
                return billingNeedsAttention(c);
            default:
                return true;
        }
    });
}

export function sortClients(clients: AgencyClient[], sort: ClientSort, dir: 'asc' | 'desc'): AgencyClient[] {
    const value = (c: AgencyClient): number | string => {
        switch (sort) {
            case 'name':
                return c.name.toLocaleLowerCase('es');
            case 'delta':
                return c.money.delta_pct ?? Number.NEGATIVE_INFINITY;
            case 'critical':
                return c.critical_insights;
            case 'usage':
                return c.usage.pct ?? -1;
            default:
                return Number(c.money.total);
        }
    };
    const sign = dir === 'asc' ? 1 : -1;
    return [...clients].sort((a, b) => {
        const va = value(a);
        const vb = value(b);
        const cmp = typeof va === 'string' && typeof vb === 'string' ? va.localeCompare(vb, 'es') : (va as number) - (vb as number);
        return cmp !== 0 ? cmp * sign : a.name.localeCompare(b.name, 'es');
    });
}

/** «▲ 12,5 %» / «▼ 3 %» / «—». */
export function formatDelta(pct: number | null | undefined, locale = 'es-CO'): string {
    if (pct === null || pct === undefined || !Number.isFinite(pct)) {
        return '—';
    }
    const n = new Intl.NumberFormat(locale, { maximumFractionDigits: 1 }).format(Math.abs(pct));
    return `${pct >= 0 ? '▲' : '▼'} ${n} %`;
}

/** Ancho de la barra de participación (0–100), con un mínimo visible si hay valor. */
export function shareWidth(value: number, max: number): number {
    if (max <= 0 || value <= 0) {
        return 0;
    }
    return Math.max(2, Math.min(100, (value / max) * 100));
}

/** El mes anterior al de `now`, como AAAA-MM. */
export function previousMonth(now: Date = new Date()): string {
    const d = new Date(now.getFullYear(), now.getMonth() - 1, 1);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
}

/** Normaliza lo que se escribe como dominio: sin esquema, ruta ni mayúsculas. */
export function normalizeHost(input: string): string {
    return input.trim().toLowerCase().replace(/^https?:\/\//, '').split('/')[0]!.replace(/\.$/, '');
}
