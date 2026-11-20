import { describe, expect, it } from 'vitest';
import type { Ability } from '@/api/types';
import { guideVisible, isComplete, nextStep, stepAction, type GuideStep } from './gettingStarted';

const steps = (overrides: Partial<Record<GuideStep['key'], Partial<GuideStep>>> = {}): GuideStep[] =>
    [
        { key: 'connect_site', done: false, site_id: null, pending: false },
        { key: 'import', done: false, status: null, progress: null, site_id: null },
        { key: 'channel', done: false, channels: [] },
        { key: 'flow', done: false, status: 'missing', flow_id: null, library_key: 'abandoned_cart' },
        { key: 'first_sale', done: false, count: 0, revenue: '0.00', first_at: null, first_revenue: null },
    ].map((s) => ({ ...s, ...(overrides[s.key as GuideStep['key']] ?? {}) }) as GuideStep);

function pick<K extends GuideStep['key']>(list: GuideStep[], key: K): Extract<GuideStep, { key: K }> {
    const found = list.find((s) => s.key === key);
    if (!found) {
        throw new Error(key);
    }
    return found as Extract<GuideStep, { key: K }>;
}

const owner: Ability[] = ['viewDashboards', 'manageSites', 'manageWorkspace', 'manageMarketing'];
const viewer: Ability[] = ['viewDashboards'];

describe('primeros pasos', () => {
    it('resalta el primer paso pendiente aunque uno posterior ya esté listo', () => {
        expect(nextStep(steps())).toBe('connect_site');
        expect(nextStep(steps({ connect_site: { done: true }, channel: { done: true } }))).toBe('import');
        expect(nextStep(steps({ connect_site: { done: true }, import: { done: true }, channel: { done: true }, flow: { done: true }, first_sale: { done: true } }))).toBeNull();
    });

    it('cada paso lleva a donde se hace, según su estado', () => {
        const list = steps({
            import: { status: 'failed', site_id: 'st_1' },
            flow: { status: 'draft', flow_id: 'fl_9' },
        });
        expect(stepAction(pick(list, 'connect_site'), owner)).toEqual({ label: 'connect', to: 'sitios?conectar=1' });
        expect(stepAction(pick(list, 'import'), owner)).toEqual({ label: 'fixImport', to: 'sitios/st_1' });
        expect(stepAction(pick(list, 'channel'), owner)).toEqual({ label: 'connectChannel', to: 'canales' });
        expect(stepAction(pick(list, 'flow'), owner)).toEqual({ label: 'reviewFlow', to: 'flujos/fl_9' });
        expect(stepAction(pick(list, 'first_sale'), owner)).toEqual({ label: 'viewFlows', to: 'flujos' });

        expect(stepAction(pick(steps(), 'flow'), owner)).toEqual({ label: 'activateFlow', to: 'flujos?tab=biblioteca' });
        const pendingSite = pick(steps({ connect_site: { pending: true, site_id: 'st_2' } }), 'connect_site');
        expect(stepAction(pendingSite, viewer)).toEqual({ label: 'viewSite', to: 'sitios/st_2' });
    });

    it('sin permiso no ofrece el botón (lo pide a quien puede)', () => {
        const list = steps();
        expect(stepAction(pick(list, 'connect_site'), viewer)).toBeNull();
        expect(stepAction(pick(list, 'channel'), viewer)).toBeNull();
        expect(stepAction(pick(list, 'flow'), viewer)).toBeNull();
    });

    it('se oculta solo con todo listo y vuelve si algo deja de estarlo', () => {
        const done = { steps: steps({ connect_site: { done: true }, import: { done: true }, channel: { done: true }, flow: { done: true }, first_sale: { done: true } }) };
        expect(isComplete(done)).toBe(true);
        expect(guideVisible(done, true)).toBe(false);
        expect(guideVisible(done, false)).toBe(true);
        const paused = { steps: steps({ connect_site: { done: true }, import: { done: true }, channel: { done: true }, first_sale: { done: true } }) };
        expect(guideVisible(paused, true)).toBe(true);
        expect(guideVisible(undefined, false)).toBe(false);
    });
});
