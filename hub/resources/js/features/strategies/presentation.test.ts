import { describe, expect, it } from 'vitest';
import { cardAction, filterCatalog, hiddenCount, monthRevenue, relevantGoals, statusBadge, statusGroup, strategyStyle } from './presentation';
import type { StrategyCatalog, StrategyStatus, StrategySummary } from './types';

function strategy(key: StrategySummary['key'], goal: StrategySummary['goal'], status: StrategyStatus, extra: Partial<StrategySummary> = {}): StrategySummary {
    return {
        key,
        goal,
        audience: 'store',
        business_types: ['store'],
        verticals: 'all',
        relevant: true,
        recommended: false,
        recommended_rank: null,
        available: status !== 'coming_soon',
        unavailable_reason: null,
        name: key,
        promise: '',
        status,
        problems: [],
        modules: [],
        plans: [],
        requirements: [],
        abilities: ['manageMarketing'],
        potential: null,
        result: null,
        manage_to: null,
        activated_at: null,
        paused_at: null,
        ...extra,
    };
}

const catalog: Pick<StrategyCatalog, 'goals' | 'data'> = {
    goals: [
        { key: 'recover_sales', name: 'Recuperar ventas', description: '' },
        { key: 'services', name: 'Servicios', description: '' },
        { key: 'ads', name: 'Anuncios', description: '' },
    ],
    data: [
        strategy('payment_pending', 'recover_sales', 'needs_setup', { recommended_rank: 3 }),
        strategy('abandoned_cart', 'recover_sales', 'active', { result: { revenue: '300000.00', orders: 2, conversions: null, messages: 10 } }),
        strategy('cod_confirm', 'recover_sales', 'available', { recommended_rank: 2 }),
        strategy('lead_nurture', 'services', 'coming_soon', { relevant: false }),
        strategy('service_lead', 'services', 'paused', { relevant: false, result: { revenue: '50000.00', orders: 1, conversions: null, messages: 3 } }),
    ],
};

describe('presentación de las estrategias', () => {
    it('cada estado tiene su insignia, su grupo de filtro y la acción de su tarjeta', () => {
        expect(statusBadge('active')).toBe('success');
        expect(statusBadge('attention')).toBe('warning');
        expect(statusBadge('not_in_plan')).toBe('info');
        expect(statusBadge('coming_soon')).toBe('outline');
        expect(statusGroup('attention')).toBe('on');
        expect(statusGroup('paused')).toBe('ready');
        expect(statusGroup('not_in_plan')).toBe('setup');
        expect(cardAction({ status: 'available' })).toBe('activate');
        expect(cardAction({ status: 'needs_setup' })).toBe('fix');
        expect(cardAction({ status: 'paused' })).toBe('resume');
        expect(cardAction({ status: 'coming_soon' })).toBe('soon');
    });

    it('el ícono es el de la estrategia y el color el de su objetivo', () => {
        expect(strategyStyle({ key: 'cod_confirm', goal: 'recover_sales' }).tone).toBe('orange');
        expect(strategyStyle({ key: 'service_lead', goal: 'services' }).tone).toBe('emerald');
    });

    it('agrupa por objetivo: primero las prendidas, después las listas, las que piden algo y las de «Próximamente»', () => {
        const groups = filterCatalog(catalog, { goal: 'all', status: 'all', showAll: false });
        expect(groups.map((g) => g.goal.key)).toEqual(['recover_sales']);
        expect(groups[0]?.items.map((s) => s.key)).toEqual(['abandoned_cart', 'cod_confirm', 'payment_pending']);
        const all = filterCatalog(catalog, { goal: 'all', status: 'all', showAll: true });
        expect(all.map((g) => g.goal.key)).toEqual(['recover_sales', 'services']);
        expect(all[1]?.items.map((s) => s.key)).toEqual(['service_lead', 'lead_nurture']);
    });

    it('filtra por objetivo y por estado, y cuenta las que no aplican al negocio', () => {
        expect(filterCatalog(catalog, { goal: 'recover_sales', status: 'on', showAll: false })[0]?.items.map((s) => s.key)).toEqual(['abandoned_cart']);
        expect(filterCatalog(catalog, { goal: 'ads', status: 'all', showAll: true })).toEqual([]);
        expect(relevantGoals(catalog, false).map((g) => g.key)).toEqual(['recover_sales']);
        expect(hiddenCount(catalog)).toBe(2);
    });

    it('suma lo que generaron este mes, también la que se pausó después de vender', () => {
        expect(monthRevenue(catalog.data)).toBe(350000);
    });
});
