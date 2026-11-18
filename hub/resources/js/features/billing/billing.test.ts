import { describe, expect, it } from 'vitest';
import esCO from '@/locales/es-CO/billing.json';
import type { PlanInfo } from './api';
import { billingBanner, comparablePlans, meterState, meterWidth, planPrice, splitMeters } from './model';

const plan = (id: string, metric: 'orders' | 'contacts', usd: string, cop: string | null = null): PlanInfo => ({
    id,
    name: id,
    metric,
    included: 100,
    modules: [],
    limits: {},
    price_usd: usd,
    price_cop: cop,
});

describe('plan y facturación', () => {
    it('marca los umbrales del 80 % y el 100 %', () => {
        expect(meterState(null)).toBe('ok');
        expect(meterState(79)).toBe('ok');
        expect(meterState(80)).toBe('warning');
        expect(meterState(99)).toBe('warning');
        expect(meterState(100)).toBe('critical');
        expect(meterState(140)).toBe('critical');
    });

    it('la barra no pasa del 100 % ni baja de 0', () => {
        expect(meterWidth({ value: 150, limit: 100 })).toBe(1);
        expect(meterWidth({ value: 40, limit: 100 })).toBe(0.4);
        expect(meterWidth({ value: 5, limit: null })).toBe(0);
        expect(meterWidth({ value: 5, limit: 0 })).toBe(0);
    });

    it('separa los medidores con límite de los conteos', () => {
        const { limited, counts } = splitMeters([
            { metric: 'orders', value: 1, limit: 10, pct: 10, state: 'ok' },
            { metric: 'sms', value: 3, limit: null, pct: null, state: 'ok' },
        ]);
        expect(limited.map((m) => m.metric)).toEqual(['orders']);
        expect(counts.map((m) => m.metric)).toEqual(['sms']);
    });

    it('muestra la franja de gracia, de suspensión o de prueba por vencer', () => {
        const now = new Date('2026-11-18T12:00:00Z');
        expect(billingBanner('active', null, null, now)).toBeNull();
        expect(billingBanner('grace', '2026-11-25T00:00:00Z', null, now)).toEqual({
            tone: 'warning',
            kind: 'grace',
            until: '2026-11-25T00:00:00Z',
        });
        expect(billingBanner('suspended', null, null, now)?.tone).toBe('critical');
        expect(billingBanner('trial', null, '2026-11-20T00:00:00Z', now)?.kind).toBe('trial');
        expect(billingBanner('trial', null, '2026-12-20T00:00:00Z', now)).toBeNull();
    });

    it('cobra en COP si el plan tiene precio en COP; si no, en USD', () => {
        expect(planPrice(plan('a', 'orders', '49', '199000'), 'COP')).toEqual({
            amount: '199000',
            currency: 'COP',
        });
        expect(planPrice(plan('a', 'orders', '49', null), 'COP')).toEqual({
            amount: '49',
            currency: 'USD',
        });
        expect(planPrice(plan('a', 'orders', '49', '199000'), 'MXN')).toEqual({
            amount: '49',
            currency: 'USD',
        });
    });

    it('compara solo planes de la misma métrica, del más barato al más caro', () => {
        const all = [plan('pro', 'orders', '349'), plan('serv', 'contacts', '39'), plan('inicio', 'orders', '49')];
        expect(comparablePlans(all, all[2] ?? null).map((p) => p.id)).toEqual(['inicio', 'pro']);
        expect(comparablePlans(all, null).map((p) => p.id)).toEqual(['serv', 'inicio', 'pro']);
    });

    it('tiene texto para cada métrica, estado y módulo que manda el servidor', () => {
        const b = esCO.billing;
        for (const m of ['orders', 'contacts', 'emails', 'wa_marketing', 'wa_utility', 'wa_service', 'sms', 'events', 'ai_cost_usd']) expect(b.metric).toHaveProperty(m);
        for (const s of ['trial', 'active', 'grace', 'suspended']) expect(b.status).toHaveProperty(s);
        for (const s of ['draft', 'sent', 'paid', 'overdue', 'void']) expect(b.invoiceStatus).toHaveProperty(s);
        for (const m of ['onsite', 'loyalty', 'referrals', 'reviews', 'inbox', 'ai', 'ai_agent', 'services', 'bookings', 'quotes', 'api', 'mcp', 'agency_reports', 'white_label'])
            expect(b.module).toHaveProperty(m);
    });
});
