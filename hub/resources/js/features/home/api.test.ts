import { describe, expect, it } from 'vitest';
import { heroMode, isBlocking, moneyTrend } from './api';
import type { HomeMoney, HomePotential } from './types';

const money = (overrides: Partial<HomeMoney> = {}): HomeMoney => ({
    total: '0.00',
    orders: 0,
    store_total: '0.00',
    share_pct: 0,
    previous: { total: '0.00', orders: 0, share_pct: 0, from: '2026-10-01', to: '2026-10-31' },
    change_pct: null,
    breakdown: [],
    low: true,
    ...overrides,
});

const potential: HomePotential = {
    strategy: 'abandoned_cart',
    name: 'Recupera carritos abandonados',
    status: 'available',
    kind: 'abandoned_carts',
    count: 37,
    value: '4200000.00',
    estimated: '210000.00',
    rate: 0.05,
    rate_key: 'cart_recovery_rate',
    window_days: 30,
    summary: 'En los últimos 30 días hubo 37 carritos abandonados por $4.200.000.',
};

describe('Inicio', () => {
    it('la tarjeta héroe muestra el dinero, o el potencial si todavía no hay', () => {
        expect(heroMode({ money: money({ total: '300000.00', low: false }), potential: null })).toBe('money');
        expect(heroMode({ money: money(), potential })).toBe('potential');
        expect(heroMode({ money: money(), potential: null })).toBe('empty');
        expect(heroMode({ money: money({ total: '5000.00' }), potential: null })).toBe('money');
    });

    it('la tendencia frente al mes anterior', () => {
        expect(moneyTrend(money({ change_pct: 12.5 }))).toBe('up');
        expect(moneyTrend(money({ change_pct: -3 }))).toBe('down');
        expect(moneyTrend(money({ change_pct: 0 }))).toBe('flat');
        expect(moneyTrend(money())).toBeNull();
    });

    it('conectar la web o un canal bloquea lo demás', () => {
        expect(isBlocking({ kind: 'connect_site' })).toBe(true);
        expect(isBlocking({ kind: 'insight' })).toBe(false);
    });
});
