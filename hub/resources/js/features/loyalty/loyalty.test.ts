import { describe, expect, it } from 'vitest';
import i18n from '@/lib/i18n';
import { explainerText } from './ExplainerTab';
import { entryAmount, reasonLabel, statusTone } from './labels';
import { pointsFor, presetTiers, programProblems, rewardReturn, tierProblems, toProgramDraft, toProgramPayload, toRewardDraft, toRewardPayload, toTierDrafts, toTiersPayload } from './program';
import type { Program, Tier } from './types';

const t = i18n.t.bind(i18n);

const program: Program = {
    id: 'lp_1',
    status: 'active',
    currency_name: 'Puntos Pintor',
    earn_rules: { per_amount: { points: 1, amount: '1000.00' }, basis: 'without_shipping', signup: 100, birthday: 200, review: 50, review_media: 100, referral: 500, profile: 50, category_multipliers: [{ category_id: '12', multiplier: 2 }], campaigns: [] },
    redeem_rules: { min_points: 0, coupon_expiry_days: 30 },
    expiry_policy: { months: 12, warning_days: 15 },
    pending_days: 15,
    wallet_rules: { cashback_pct: '2.00' },
};

describe('reglas del programa', () => {
    it('ida y vuelta entre la API y el formulario', () => {
        const draft = toProgramDraft(program);
        expect(draft.amount_per).toBe('1000');
        expect(draft.cashback_pct).toBe('2');
        expect(draft.fixed.review_media).toBe('100');
        const payload = toProgramPayload({ ...draft, amount_per: '2.000,5'.replace('.', '') }, 'paused');
        expect(payload.status).toBe('paused');
        expect(payload.earn_rules.per_amount).toEqual({ points: 1, amount: 2000.5 });
        expect(payload.earn_rules.category_multipliers).toEqual([{ category_id: '12', multiplier: 2 }]);
        expect(payload.earn_rules).toMatchObject({ signup: 100, profile: 50 });
        expect(payload.wallet_rules.cashback_pct).toBe(2);
    });

    it('calcula los puntos como el servidor (hacia abajo) y lo que devuelve una recompensa', () => {
        const draft = toProgramDraft(program);
        expect(pointsFor(282000, draft)).toBe(282);
        expect(pointsFor(999, draft)).toBe(0);
        expect(pointsFor(100000, { points_per: '3', amount_per: '1000' })).toBe(300);
        expect(rewardReturn(draft, { points_cost: '500', value: '20000' })).toBeCloseTo(0.04);
        expect(rewardReturn({ points_per: '0', amount_per: '1000' }, { points_cost: '500', value: '20000' })).toBe(0);
    });

    it('marca el nombre, el monto y las campañas al revés', () => {
        const draft = { ...toProgramDraft(program), currency_name: ' ', amount_per: '0', campaigns: [{ name: 'BF', multiplier: '2', starts_on: '2026-11-30', ends_on: '2026-11-27' }] };
        expect(programProblems(draft)).toEqual(['currency_name', 'amount_per', 'campaigns.0']);
    });
});

describe('niveles', () => {
    const tiers: Tier[] = [
        { id: 'tr_2', name: 'Plata', rank: 2, threshold_type: 'spend_12m', threshold: '500000.00', benefits: { multiplier: 1.5, free_shipping: true, early_access: false, gifts: '' }, wc_role: 'cliente_plata', members: 3 },
        { id: 'tr_1', name: 'Bronce', rank: 1, threshold_type: 'spend_12m', threshold: '0.00', benefits: { multiplier: 1, free_shipping: false, early_access: false, gifts: '' }, wc_role: null, members: 10 },
    ];

    it('ordena por rango y conserva los IDs al guardar', () => {
        const drafts = toTierDrafts(tiers);
        expect(drafts.map((d) => d.name)).toEqual(['Bronce', 'Plata']);
        expect(toTiersPayload(drafts, 'spend_12m')).toEqual([
            { id: 'tr_1', name: 'Bronce', threshold_type: 'spend_12m', threshold: 0, benefits: { multiplier: 1, free_shipping: false, early_access: false, gifts: '' }, wc_role: null },
            { id: 'tr_2', name: 'Plata', threshold_type: 'spend_12m', threshold: 500000, benefits: { multiplier: 1.5, free_shipping: true, early_access: false, gifts: '' }, wc_role: 'cliente_plata' },
        ]);
    });

    it('exige umbrales que suben, nombre y un rol válido', () => {
        const drafts = toTierDrafts(tiers);
        expect(tierProblems(drafts)).toEqual([]);
        expect(tierProblems([...drafts].reverse())).toEqual([1]);
        const [bronce, plata] = drafts;
        if (!bronce || !plata) throw new Error('faltan niveles');
        expect(tierProblems([{ ...bronce, name: '' }, { ...plata, wc_role: 'Cliente VIP' }])).toEqual([0, 1]);
        expect(presetTiers('orders_12m', ['A', 'B', 'C']).map((d) => d.threshold)).toEqual(['0', '3', '8']);
    });
});

describe('recompensas', () => {
    it('el envío gratis no lleva valor y el producto gratis lleva su ID', () => {
        expect(toRewardPayload({ ...toRewardDraft(), kind: 'free_shipping', value: '999' }).value).toBeNull();
        const product = toRewardPayload({ ...toRewardDraft(), kind: 'free_product', product_id: ' 88 ', min_order: '', expires_in_days: '10' });
        expect(product.config).toEqual({ min_order: null, expires_in_days: 10, product_id: '88' });
        expect(toRewardPayload(toRewardDraft()).config.product_id).toBeNull();
    });
});

describe('libro de movimientos', () => {
    it('nombra cada movimiento y su signo', () => {
        expect(reasonLabel(t, { reason: 'order', kind: 'points', status: 'pending', delta: 270 })).toBe('Compra');
        expect(reasonLabel(t, { reason: 'order', kind: 'wallet', status: 'pending', delta: '-35000.00' })).toBe('Usó saldo en una compra');
        expect(reasonLabel(t, { reason: 'order', kind: 'wallet', status: 'reversed', delta: '35000.00' })).toBe('Saldo liberado');
        expect(reasonLabel(t, { reason: 'refund', kind: 'points', status: 'confirmed', delta: -100 })).toBe('Reembolso del pedido');
        expect(reasonLabel(t, { reason: 'algo_nuevo', kind: 'points', status: 'confirmed', delta: 1 })).toBe('algo_nuevo');
        expect(entryAmount({ kind: 'points', delta: -500 }, 'COP', 'es-CO')).toBe('−500');
        expect(entryAmount({ kind: 'wallet', delta: '5000.00' }, 'COP', 'es-CO').replace(/\s/g, ' ')).toBe('+$ 5.000');
        expect(statusTone('pending')).toBe('warning');
    });

    it('arma el texto plano de la página del programa', () => {
        expect(explainerText({ title: 'Programa X', intro: 'Hola', sections: [{ key: 'earn', title: 'Cómo ganar', items: ['1 por cada $1.000'] }] })).toBe('Programa X\n\nHola\n\nCómo ganar\n• 1 por cada $1.000');
    });
});
