import { describe, expect, it } from 'vitest';
import type { Ability } from '@/api/types';
import { ApiError } from '@/lib/api';
import { activationProblem, blockingRequirements, byGoal, canActivate, canManage, isOn, nextFix } from './api';
import type { StrategyCatalog, StrategyRequirement, StrategySummary } from './types';

const requirement = (key: StrategyRequirement['key'], met: boolean, required = true): StrategyRequirement => ({
    key,
    met,
    required,
    label: key,
    hint: met ? null : `falta ${key}`,
    action: null,
    to: met ? null : 'canales',
});

const strategy = (overrides: Partial<StrategySummary> = {}): StrategySummary => ({
    key: 'abandoned_cart',
    goal: 'recover_sales',
    audience: 'store',
    relevant: true,
    recommended: false,
    name: 'Recupera carritos abandonados',
    promise: '…',
    status: 'available',
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
    ...overrides,
});

describe('estrategias', () => {
    it('prendida es activa o en atención; se puede activar si no está prendida ni fuera del plan', () => {
        expect(isOn('active')).toBe(true);
        expect(isOn('attention')).toBe(true);
        expect(isOn('paused')).toBe(false);
        expect(canActivate(strategy({ status: 'paused' }))).toBe(true);
        expect(canActivate(strategy({ status: 'not_in_plan' }))).toBe(false);
        expect(canActivate(strategy({ status: 'active' }))).toBe(false);
    });

    it('solo quien tiene todos los permisos de la estrategia la cambia', () => {
        const marketing: Ability[] = ['viewDashboards', 'manageMarketing'];
        expect(canManage(strategy(), marketing)).toBe(true);
        expect(canManage(strategy({ abilities: ['manageMarketing', 'manageInbox'] }), marketing)).toBe(false);
    });

    it('lo que bloquea son los requisitos obligatorios que faltan, en su orden; los opcionales solo avisan', () => {
        const s = strategy({ requirements: [requirement('store', true), requirement('wa_templates', false, false), requirement('channel', false), requirement('history', false)] });
        expect(blockingRequirements(s).map((r) => r.key)).toEqual(['channel', 'history']);
        expect(nextFix(s)?.key).toBe('channel');
        expect(nextFix(strategy())).toBeNull();
    });

    it('agrupa por objetivo en su orden, con las que le sirven al negocio primero', () => {
        const catalog: Pick<StrategyCatalog, 'goals' | 'data'> = {
            goals: [
                { key: 'recover_sales', name: 'Recuperar ventas', description: '' },
                { key: 'ads', name: 'Anuncios', description: '' },
                { key: 'services', name: 'Servicios', description: '' },
            ],
            data: [
                strategy({ key: 'lead_response', goal: 'services', relevant: false }),
                strategy({ key: 'abandoned_cart' }),
                strategy({ key: 'renewal', goal: 'services', relevant: true }),
            ],
        };
        expect(byGoal(catalog).map((g) => [g.goal, g.items.map((i) => i.key)])).toEqual([
            ['recover_sales', ['abandoned_cart']],
            ['services', ['renewal', 'lead_response']],
        ]);
    });

    it('lee el motivo de una activación rechazada', () => {
        const needs = new ApiError(422, 'needs_setup', 'Falta', {}, { error: 'needs_setup', message: 'Falta', requirements: [requirement('store', false)], issues: {}, to: 'sitios?conectar=1' });
        const plan = new ApiError(402, 'plan_required', 'Plan', {}, { error: 'plan_required', module: 'loyalty', modules: ['loyalty'], plans: [{ id: 'tienda_crece', name: 'Crece' }], message: 'Plan' });
        expect(activationProblem(needs)).toMatchObject({ kind: 'needs_setup', body: { to: 'sitios?conectar=1' } });
        expect(activationProblem(plan)).toMatchObject({ kind: 'plan_required', body: { module: 'loyalty' } });
        expect(activationProblem(new Error('x'))).toBeNull();
    });
});
