import { FIXED_REASONS, type FixedReason, type Program, type ProgramStatus, type Reward, type RewardKind, type Tier, type ThresholdType } from './types';

/*
 * Borradores de los formularios de Fidelización: lo que se edita (texto) y lo
 * que se envía a la API (números). Funciones puras, probadas en Vitest.
 */

export interface ProgramDraft {
    status: ProgramStatus;
    currency_name: string;
    points_per: string;
    amount_per: string;
    basis: 'without_shipping' | 'total';
    fixed: Record<FixedReason, string>;
    category_multipliers: { category_id: string; multiplier: string }[];
    campaigns: { name: string; multiplier: string; starts_on: string; ends_on: string }[];
    min_points: string;
    coupon_expiry_days: string;
    expiry_months: string;
    warning_days: string;
    pending_days: string;
    cashback_pct: string;
}

const n = (v: unknown, fallback = 0): number => {
    const x = typeof v === 'number' ? v : typeof v === 'string' && v.trim() !== '' ? Number(v.replace(',', '.')) : NaN;
    return Number.isFinite(x) ? x : fallback;
};
/** «1000.00» → «1000» y «1.50» → «1.5»: sin ceros que estorben al editar. */
const trim = (v: unknown): string => {
    const x = n(v, NaN);
    return Number.isFinite(x) ? String(x) : '';
};

export function toProgramDraft(p: Program): ProgramDraft {
    const e = p.earn_rules;
    const fixed = Object.fromEntries(FIXED_REASONS.map((r) => [r, String(e[r] ?? 0)])) as Record<FixedReason, string>;
    return {
        status: p.status,
        currency_name: p.currency_name,
        points_per: String(e.per_amount.points),
        amount_per: trim(e.per_amount.amount),
        basis: e.basis ?? 'without_shipping',
        fixed,
        category_multipliers: (e.category_multipliers ?? []).map((m) => ({ category_id: m.category_id, multiplier: String(m.multiplier) })),
        campaigns: (e.campaigns ?? []).map((c) => ({ name: c.name, multiplier: String(c.multiplier), starts_on: c.starts_on, ends_on: c.ends_on })),
        min_points: String(p.redeem_rules.min_points),
        coupon_expiry_days: String(p.redeem_rules.coupon_expiry_days),
        expiry_months: String(p.expiry_policy.months),
        warning_days: String(p.expiry_policy.warning_days),
        pending_days: String(p.pending_days),
        cashback_pct: trim(p.wallet_rules.cashback_pct) || '0',
    };
}

export function toProgramPayload(d: ProgramDraft, status?: ProgramStatus) {
    return {
        status: status ?? d.status,
        currency_name: d.currency_name.trim(),
        pending_days: Math.round(n(d.pending_days)),
        earn_rules: {
            per_amount: { points: Math.round(n(d.points_per)), amount: n(d.amount_per, 1000) },
            basis: d.basis,
            category_multipliers: d.category_multipliers.filter((m) => m.category_id !== '').map((m) => ({ category_id: m.category_id, multiplier: n(m.multiplier, 1) })),
            campaigns: d.campaigns.map((c) => ({ name: c.name.trim(), multiplier: n(c.multiplier, 2), starts_on: c.starts_on, ends_on: c.ends_on })),
            ...Object.fromEntries(FIXED_REASONS.map((r) => [r, Math.max(0, Math.round(n(d.fixed[r])))])),
        },
        redeem_rules: { min_points: Math.round(n(d.min_points)), coupon_expiry_days: Math.round(n(d.coupon_expiry_days, 30)) },
        expiry_policy: { months: Math.round(n(d.expiry_months)), warning_days: Math.round(n(d.warning_days, 15)) },
        wallet_rules: { cashback_pct: n(d.cashback_pct) },
    };
}

/** Problemas que se ven antes de enviar (el servidor valida todo de nuevo). */
export function programProblems(d: ProgramDraft): string[] {
    const out: string[] = [];
    if (d.currency_name.trim().length < 2) out.push('currency_name');
    if (n(d.amount_per, 0) <= 0) out.push('amount_per');
    if (n(d.points_per, -1) < 0) out.push('points_per');
    d.campaigns.forEach((c, i) => {
        if (c.starts_on === '' || c.ends_on === '' || c.ends_on < c.starts_on) out.push(`campaigns.${i}`);
    });
    return out;
}

/** Puntos que da una compra con la regla base (lo mismo que calcula el servidor, sin multiplicadores). */
export function pointsFor(amount: number, draft: Pick<ProgramDraft, 'points_per' | 'amount_per'>): number {
    const per = n(draft.amount_per, 0);
    return per > 0 ? Math.floor((amount / per) * n(draft.points_per) + 1e-9) : 0;
}

export interface TierDraft {
    uid: string;
    id: string | null;
    name: string;
    threshold: string;
    multiplier: string;
    free_shipping: boolean;
    early_access: boolean;
    gifts: string;
    wc_role: string;
}

let seq = 0;
const uid = () => `t${++seq}`;

export function toTierDrafts(tiers: Tier[]): TierDraft[] {
    return [...tiers]
        .sort((a, b) => a.rank - b.rank)
        .map((t) => ({
            uid: uid(),
            id: t.id,
            name: t.name,
            threshold: trim(t.threshold),
            multiplier: String(t.benefits.multiplier),
            free_shipping: t.benefits.free_shipping,
            early_access: t.benefits.early_access,
            gifts: t.benefits.gifts,
            wc_role: t.wc_role ?? '',
        }));
}

/** Niveles sugeridos según cómo se miden (Bronce desde 0, Plata y Oro). */
export function presetTiers(type: ThresholdType, names: [string, string, string]): TierDraft[] {
    const thresholds: Record<ThresholdType, [string, string, string]> = {
        spend_12m: ['0', '500000', '2000000'],
        points_12m: ['0', '500', '2000'],
        orders_12m: ['0', '3', '8'],
    };
    const [a, b, c] = thresholds[type];
    return [
        { uid: uid(), id: null, name: names[0], threshold: a, multiplier: '1', free_shipping: false, early_access: false, gifts: '', wc_role: '' },
        { uid: uid(), id: null, name: names[1], threshold: b, multiplier: '1.25', free_shipping: false, early_access: true, gifts: '', wc_role: '' },
        { uid: uid(), id: null, name: names[2], threshold: c, multiplier: '1.5', free_shipping: true, early_access: true, gifts: '', wc_role: '' },
    ];
}

export function toTiersPayload(drafts: TierDraft[], type: ThresholdType) {
    return drafts.map((d) => ({
        ...(d.id ? { id: d.id } : {}),
        name: d.name.trim(),
        threshold_type: type,
        threshold: n(d.threshold),
        benefits: { multiplier: n(d.multiplier, 1), free_shipping: d.free_shipping, early_access: d.early_access, gifts: d.gifts.trim() },
        wc_role: d.wc_role.trim() === '' ? null : d.wc_role.trim(),
    }));
}

/** Índices de niveles con problemas: nombre vacío, umbral que no sube o rol inválido. */
export function tierProblems(drafts: TierDraft[]): number[] {
    const out: number[] = [];
    let previous = -1;
    drafts.forEach((d, i) => {
        const value = n(d.threshold, NaN);
        if (d.name.trim() === '' || !Number.isFinite(value) || value <= previous || (d.wc_role !== '' && !/^[a-z0-9_-]+$/.test(d.wc_role))) {
            out.push(i);
        }
        previous = Number.isFinite(value) ? value : previous;
    });
    return out;
}

export interface RewardDraft {
    name: string;
    kind: RewardKind;
    points_cost: string;
    value: string;
    min_order: string;
    expires_in_days: string;
    product_id: string;
}

export function toRewardDraft(r?: Reward): RewardDraft {
    return r
        ? { name: r.name, kind: r.kind, points_cost: String(r.points_cost), value: trim(r.value), min_order: trim(r.config.min_order), expires_in_days: r.config.expires_in_days ? String(r.config.expires_in_days) : '', product_id: r.config.product_id ?? '' }
        : { name: '', kind: 'coupon_fixed', points_cost: '500', value: '20000', min_order: '', expires_in_days: '', product_id: '' };
}

export function toRewardPayload(d: RewardDraft) {
    return {
        name: d.name.trim(),
        kind: d.kind,
        points_cost: Math.round(n(d.points_cost)),
        value: d.kind === 'free_shipping' ? null : n(d.value),
        config: {
            min_order: d.min_order.trim() === '' ? null : n(d.min_order),
            expires_in_days: d.expires_in_days.trim() === '' ? null : Math.round(n(d.expires_in_days)),
            product_id: d.kind === 'free_product' ? d.product_id.trim() : null,
        },
    };
}

/** Qué parte de lo comprado devuelve una recompensa (valor ÷ lo que hay que gastar para ganar sus puntos). */
export function rewardReturn(draft: Pick<ProgramDraft, 'points_per' | 'amount_per'>, reward: Pick<RewardDraft, 'points_cost' | 'value'>): number {
    const per = n(draft.points_per);
    const spend = per > 0 ? (n(reward.points_cost) / per) * n(draft.amount_per) : 0;
    return spend > 0 && n(reward.value) > 0 ? n(reward.value) / spend : 0;
}
