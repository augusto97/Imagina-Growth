import { describe, expect, it } from 'vitest';
import type { AgencyClient } from './api';
import { filterClients, formatDelta, needsAttention, normalizeHost, previousMonth, shareWidth, sortClients } from './model';

const client = (over: Partial<AgencyClient> & { name: string }): AgencyClient => ({
    id: `ws_${over.name}`,
    account: { id: 'acc_1', name: 'Imagina La Web' },
    country: 'CO',
    currency: 'COP',
    health: { status: 'healthy', sites: 1, connected: 1, last_event_at: null, minutes_since_event: 5 },
    money: { currency: 'COP', total: '0', orders: 0, store_total: '0', share_pct: 0, previous_total: '0', delta_pct: null, top_module: null },
    critical_insights: 0,
    usage: { plan: 'growth', metric: 'orders', used: 10, limit: 100, pct: 10 },
    billing: { status: 'active', trial_ends_at: null, grace_ends_at: null },
    ...over,
});

describe('panel de agencia', () => {
    const a = client({ name: 'Pinturas', money: { ...client({ name: 'x' }).money, total: '570000', delta_pct: 470 } });
    const b = client({ name: 'Café', critical_insights: 2, money: { ...client({ name: 'x' }).money, total: '120000', delta_pct: -10 } });
    const c = client({ name: 'Tienda', health: { status: 'disconnected', sites: 1, connected: 0, last_event_at: null, minutes_since_event: null } });
    const d = client({ name: 'Moda', usage: { plan: 'starter', metric: 'orders', used: 520, limit: 500, pct: 104 }, billing: { status: 'grace', trial_ends_at: null, grace_ends_at: null } });

    it('ordena por dinero, cambio y nombre', () => {
        expect(sortClients([b, a, c], 'money', 'desc').map((x) => x.name)).toEqual(['Pinturas', 'Café', 'Tienda']);
        expect(sortClients([a, b, c], 'delta', 'asc').map((x) => x.name)).toEqual(['Tienda', 'Café', 'Pinturas']);
        expect(sortClients([a, b, c], 'name', 'asc').map((x) => x.name)).toEqual(['Café', 'Pinturas', 'Tienda']);
    });

    it('filtra los que necesitan atención y busca por nombre', () => {
        expect(needsAttention(a)).toBe(false);
        expect(filterClients([a, b, c, d], 'attention', '').map((x) => x.name)).toEqual(['Café', 'Tienda', 'Moda']);
        expect(filterClients([a, b, c, d], 'over_limit', '').map((x) => x.name)).toEqual(['Moda']);
        expect(filterClients([a, b, c, d], 'billing', '').map((x) => x.name)).toEqual(['Moda']);
        expect(filterClients([a, b, c, d], 'all', 'café').map((x) => x.name)).toEqual(['Café']);
        expect(filterClients([a, b], 'all', 'imagina')).toHaveLength(2);
    });

    it('formatea el cambio, la barra y el mes anterior', () => {
        expect(formatDelta(12.5)).toBe('▲ 12,5 %');
        expect(formatDelta(-3)).toBe('▼ 3 %');
        expect(formatDelta(null)).toBe('—');
        expect(shareWidth(0, 100)).toBe(0);
        expect(shareWidth(1, 1000)).toBe(2);
        expect(shareWidth(50, 100)).toBe(50);
        expect(previousMonth(new Date(2026, 0, 15))).toBe('2025-12');
        expect(normalizeHost(' https://Panel.Agencia.co/login ')).toBe('panel.agencia.co');
    });
});
