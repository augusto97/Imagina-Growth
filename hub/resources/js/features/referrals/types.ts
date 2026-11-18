/** Tipos de la API de referidos y afiliados (M14), tal como los devuelve el hub. */

export type ProgramStatus = 'draft' | 'active' | 'paused';
export type DiscountKind = 'percent' | 'fixed_cart' | 'free_shipping';
export type ReferrerRewardType = 'points' | 'wallet' | 'coupon';

export interface FriendReward {
    kind: DiscountKind;
    amount: string;
    expires_days: number;
    minimum_amount: string | null;
}

export interface ReferrerReward {
    type: ReferrerRewardType;
    points: number | null;
    amount: string | null;
    coupon_kind: DiscountKind | null;
    expires_days: number | null;
}

export interface ProgramRules {
    min_order: string | null;
    monthly_limit: number | null;
    return_days: number;
    qualify_on: 'completed' | 'paid';
    review_suspicious: boolean;
    signal_window_days: number;
}

export interface Program {
    exists: boolean;
    id: string | null;
    status: ProgramStatus;
    friend_reward: FriendReward;
    referrer_reward: ReferrerReward;
    rules: ProgramRules;
    share_text: string | null;
    default_share_text: string;
    labels: { friend_reward: string; referrer_reward: string; share_preview: string; example_link: string };
    loyalty_active: boolean;
    currency: string;
}

export interface ProgramInput {
    status: ProgramStatus;
    friend_reward: { kind: DiscountKind; amount: number | null; expires_days: number; minimum_amount: number | null };
    referrer_reward: { type: ReferrerRewardType; points: number | null; amount: number | null; coupon_kind: DiscountKind | null; expires_days: number | null };
    rules: { min_order: number | null; monthly_limit: number | null; return_days: number; qualify_on: 'completed' | 'paid'; review_suspicious: boolean; signal_window_days: number };
    share_text: string | null;
}

export type ReferralStatus = 'clicked' | 'signed_up' | 'ordered' | 'qualified' | 'rewarded' | 'rejected';
export type FraudSignal = 'same_person' | 'same_device' | 'same_ip' | 'same_address';

export interface PersonRef {
    id: string;
    name: string;
}

export interface Referral {
    id: string;
    status: ReferralStatus;
    review_status: 'pending_review' | 'approved' | 'rejected' | null;
    rejected_reason: string | null;
    code: string;
    referrer: PersonRef | null;
    friend: PersonRef | null;
    signals: FraudSignal[];
    order: { id: string; number: string } | null;
    order_amount_ws: string | null;
    friend_coupon: boolean;
    reward: { type: ReferrerRewardType | null; state: 'issuing' | 'done' | 'failed' | null; label: string | null; error: string | null } | null;
    reward_cost_ws: string | null;
    ordered_at: string | null;
    qualifies_at: string | null;
    rewarded_at: string | null;
    created_at: string | null;
}

export interface Paginated<T> {
    data: T[];
    meta: { page: number; last_page: number; total: number };
}

export interface ReferralList extends Paginated<Referral> {
    counts: Partial<Record<ReferralStatus, number>>;
    pending_review: number;
}

export type AffiliateKind = 'influencer' | 'affiliate' | 'partner';

export interface AffiliateStats {
    orders: number;
    revenue: string;
    commission: string;
    pending: string;
    approved: string;
}

export interface Affiliate {
    id: string;
    name: string;
    kind: AffiliateKind;
    status: 'active' | 'paused' | 'archived';
    email: string | null;
    has_email: boolean;
    payout_info: string | null;
    coupon_codes: string[];
    link_code: string;
    commission: { type: 'percent' | 'fixed'; value: string; basis?: 'net' | 'total' };
    commission_label: string;
    material: { label: string; url: string }[];
    portal_sent_at: string | null;
    created_at: string | null;
    stats?: AffiliateStats;
}

export interface AffiliateInput {
    name: string;
    kind: AffiliateKind;
    email?: string | null;
    coupon_codes: string[];
    link_code?: string | null;
    commission: { type: 'percent' | 'fixed'; value: number; basis: 'net' | 'total' };
    material: { label: string; url: string }[];
    payout_info?: string | null;
    status?: 'active' | 'paused';
}

export type ConversionStatus = 'pending' | 'approved' | 'paid' | 'void';

export interface Conversion {
    id: string;
    affiliate: PersonRef | null;
    order: { id: string; number: string } | null;
    campaign: string | null;
    via: 'coupon' | 'link';
    amount_ws: string;
    commission_ws: string;
    status: ConversionStatus;
    void_reason: string | null;
    qualifies_at: string | null;
    approved_at: string | null;
    paid_at: string | null;
    created_at: string | null;
}

export interface AffiliateDetail extends Affiliate {
    stats: AffiliateStats;
    links: { site: string; url: string }[];
    conversions: Conversion[];
}

export interface Payout {
    affiliate_id: string;
    name: string;
    kind: AffiliateKind;
    email: string | null;
    payout_info: string | null;
    orders: number;
    commission: string;
    conversion_ids: string[];
}

export interface ReportPoint {
    period: string;
    referrals: string;
    affiliates: string;
}

export interface Report {
    from: string;
    to: string;
    currency: string;
    kpis: {
        referral_revenue: string;
        referral_orders: number;
        affiliate_revenue: string;
        affiliate_orders: number;
        commissions: string;
        signups: number;
        rewarded: number;
        rejected: number;
        reward_cost: string;
        cost_per_referral: string | null;
        points_given: number;
        pending_review: number;
    };
    series: { bucket: 'week' | 'month'; points: ReportPoint[] };
    top_referrers: { contact: PersonRef | null; referrals: number; revenue: string }[];
    creators: {
        affiliate: { id: string; name: string; kind: AffiliateKind };
        orders: number;
        revenue: string;
        commission: string;
        best_campaign: { campaign: string | null; via: 'coupon' | 'link' | null; orders: number; revenue: string } | null;
    }[];
}
