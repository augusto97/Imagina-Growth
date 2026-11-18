/* Fidelización (modulos.md M13): formas de la API de LoyaltyController. */

export type ProgramStatus = 'draft' | 'active' | 'paused';
export type ThresholdType = 'spend_12m' | 'points_12m' | 'orders_12m';
export type RewardKind = 'coupon_fixed' | 'coupon_percent' | 'free_shipping' | 'free_product' | 'wallet';
export const REWARD_KINDS: RewardKind[] = ['coupon_fixed', 'coupon_percent', 'free_shipping', 'free_product', 'wallet'];
/** Puntos fijos de M13. Los de referidos se configuran en el programa de referidos (M14). */
export const FIXED_REASONS = ['signup', 'birthday', 'review', 'review_media', 'profile'] as const;
export type FixedReason = (typeof FIXED_REASONS)[number];

export interface EarnRules {
    per_amount: { points: number; amount: string };
    basis?: 'without_shipping' | 'total';
    category_multipliers?: { category_id: string; multiplier: number }[];
    campaigns?: { name: string; multiplier: number; starts_on: string; ends_on: string }[];
    signup?: number;
    birthday?: number;
    review?: number;
    review_media?: number;
    profile?: number;
}

export interface Program {
    id: string | null;
    status: ProgramStatus;
    currency_name: string;
    earn_rules: EarnRules;
    redeem_rules: { min_points: number; coupon_expiry_days: number };
    expiry_policy: { months: number; warning_days: number };
    pending_days: number;
    wallet_rules: { cashback_pct: string | number };
}

export interface TierBenefits {
    multiplier: number;
    free_shipping: boolean;
    early_access: boolean;
    gifts: string;
}

export interface Tier {
    id: string;
    name: string;
    rank: number;
    threshold_type: ThresholdType;
    threshold: string;
    benefits: TierBenefits;
    wc_role: string | null;
    members: number | null;
}

export interface Reward {
    id: string;
    name: string;
    kind: RewardKind;
    points_cost: number;
    value: string;
    config: { min_order: string | null; expires_in_days: number | null; product_id: string | null };
    status: 'active' | 'archived';
    redeemed: number | null;
}

export interface Explainer {
    title: string;
    intro: string;
    sections: { key: string; title: string; items: string[] }[];
}

export interface LoyaltyOverview {
    program: Program;
    tiers: Tier[];
    rewards: Reward[];
    categories: { id: string; name: string }[];
    currency: string;
    explainer: Explainer | null;
}

export interface MonthlyStat {
    month: string;
    issued: number;
    redeemed: number;
    expired: number;
}

export interface LoyaltyStats {
    members: number;
    points_outstanding: number;
    points_pending: number;
    wallet_outstanding: string;
    point_value: string | null;
    points_liability: string | null;
    monthly: MonthlyStat[];
    by_tier: { id: string; name: string; members: number }[];
    redemptions_30d: number;
    redeemed_orders_revenue_30d: string;
}

export interface Member {
    id: string;
    name: string;
    email: string | null;
    points: number;
    wallet: string;
    tier: { id: string; name: string } | null;
    points_expiring: number;
    points_expiring_at: string | null;
    orders_count: number;
}

export interface Paged<T> {
    data: T[];
    meta: { page: number; last_page: number; total: number };
}

export interface LedgerEntry {
    id: string;
    kind: 'points' | 'wallet';
    delta: number | string;
    balance_after: number | string;
    currency: string | null;
    status: 'pending' | 'confirmed' | 'reversed' | 'expired';
    /** Cómo terminó una entrada pendiente (la fila original no cambia). */
    settled?: 'confirmed' | 'reversed' | null;
    reason: string;
    ref_type: string | null;
    ref_id: string | null;
    parent_id: string | null;
    expires_at: string | null;
    created_at: string | null;
}

export interface Balances {
    points: number;
    pending: number;
    wallet: string;
    expiring: number;
    expiring_at: string | null;
}

export interface TierProgress {
    id: string | null;
    name: string | null;
    benefits: string[];
    next_id: string | null;
    next_name: string | null;
    next_threshold_type: ThresholdType | null;
    next_threshold: string | null;
    value: number | null;
    progress_pct: number;
}

export interface ContactLoyalty {
    program: { status: ProgramStatus; currency_name: string } | null;
    currency: string;
    balances: Balances;
    tier: TierProgress | null;
    ledger: LedgerEntry[];
    meta: { page: number; last_page: number; total: number };
    rewards: Reward[];
}

export interface Adjustment {
    kind: 'points' | 'wallet';
    direction: 'add' | 'remove';
    amount: number;
    reason: 'manual' | 'refund' | 'goodwill' | 'correction';
    note: string;
}
