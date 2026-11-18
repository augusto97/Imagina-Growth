/* Tipos de «En el sitio» (M12): espejo de WidgetPresenter y WidgetStats en el hub. */

export type WidgetKind = 'popup' | 'embedded_form' | 'bar' | 'spin_wheel' | 'teaser' | 'two_step' | 'quiz' | 'wa_widget' | 'referral_welcome';
export type WidgetStatus = 'draft' | 'active' | 'paused' | 'archived';
export type TriggerType = 'exit_intent' | 'time' | 'scroll' | 'immediate' | 'click';
export type FieldKey = 'email' | 'phone' | 'first_name' | 'last_name';
export type ConsentChannel = 'email' | 'whatsapp' | 'sms';
export type CouponKind = 'percent' | 'fixed_cart' | 'free_shipping';

export interface WidgetField {
    key: FieldKey;
    type?: string;
    required: boolean;
    label: string;
}

export interface WidgetTheme {
    background: string;
    text: string;
    accent: string;
    accent_text: string;
}

export interface WidgetDesign {
    position?: string;
    layout?: 'image_left' | 'image_top' | 'text_only';
    display?: 'popup' | 'embedded';
    image_url?: string | null;
    theme: WidgetTheme;
}

export interface WidgetStep {
    title: string;
    body: string;
    fields: WidgetField[];
    cta: string;
    success: string;
    skip?: string;
}

export interface WidgetContent {
    title: string;
    body: string;
    fields: WidgetField[];
    cta: string;
    success: string;
    footer: string;
    step2?: WidgetStep;
    lose?: string;
    start?: string;
    contact_optional?: boolean;
    wa?: { number: string; text: string };
}

export interface WidgetVariant {
    key: string;
    weight: number;
    design: WidgetDesign;
    content: WidgetContent;
}

export interface Targeting {
    trigger: { type: TriggerType; value: number | string | null };
    pages: { include: string[]; exclude: string[] };
    page_types: string[];
    devices: string[];
    new_visitors: boolean | null;
    utm: { source?: string; medium?: string; campaign?: string };
    referral: boolean;
    countries: string[];
    segments: string[];
    cart_value_min: string | null;
    hide_for_subscribers: boolean;
    frequency: { max_views: number; cooldown_days: number };
}

export interface CouponSpec {
    kind: CouponKind;
    amount: string;
    expires_in_days: number;
    prefix: string;
    minimum_amount: string | null;
}

export interface Gift extends CouponSpec {
    enabled: boolean;
}

export interface Prize {
    key: string;
    label: string;
    weight: number;
    coupon: CouponSpec | null;
}

export type CouponConfig = Gift | { prizes: Prize[] };

export interface ConsentConfig {
    channels: ConsentChannel[];
    double_opt_in: boolean;
    template_id: string | null;
}

export interface QuizOption {
    key: string;
    label: string;
    next?: string;
    attributes?: Record<string, string>;
}

export interface QuizQuestion {
    id: string;
    text: string;
    attribute?: string;
    options: QuizOption[];
}

export interface ProductRule {
    product_ids: string[];
    category_ids: string[];
    brands: string[];
    max_price: string | null;
    kind: 'trending' | 'best_sellers_category' | 'para_ti' | null;
}

export interface QuizRule {
    id?: string;
    label: string;
    match: Record<string, string[]>;
    products: ProductRule;
    message: string;
}

export interface Quiz {
    questions: QuizQuestion[];
    outcomes: { rules: QuizRule[]; fallback: { products: ProductRule; message: string }; limit: number };
}

export interface WidgetStatsRow {
    views: number;
    visitors: number;
    submissions: number;
    new_contacts: number;
    known_contacts: number;
    orders: number;
    buyers: number;
    revenue: string;
    submit_rate: number | null;
    conversion_rate: number | null;
}

export interface WidgetSummary {
    id: string;
    name: string;
    kind: WidgetKind;
    status: WidgetStatus;
    live: boolean;
    priority: number;
    site_id: string | null;
    variants_count: number;
    gives_coupon: boolean;
    starts_at: string | null;
    ends_at: string | null;
    updated_at: string | null;
    created_at: string | null;
    stats?: WidgetStatsRow | null;
}

export interface WidgetDetail extends WidgetSummary {
    targeting: Targeting;
    variants: WidgetVariant[];
    consent_config: ConsentConfig;
    coupon_config: CouponConfig;
    quiz: Quiz | null;
    ab: { auto_winner: boolean; winner: { key: string; confidence: number; lift: number | null; decided_at: string } | null };
}

export interface OnsiteOptions {
    kinds: WidgetKind[];
    page_types: string[];
    devices: string[];
    triggers: TriggerType[];
    sites: { id: string; name: string; url: string | null; status: string }[];
    segments: { id: string; name: string; member_count: number }[];
    consent_texts: { kind: string; channel: string; version: string; label: string }[];
    email_templates: { id: string; name: string }[];
    categories: { id: string; name: string }[];
    brands: string[];
    workspace: { currency: string; country: string; timezone: string };
}

export interface AbResult {
    leader: string | null;
    runner_up: string | null;
    lift: number | null;
    confidence: number | null;
    significant: boolean;
    enough_data: boolean;
}

export interface WidgetStatsDetail {
    range: { from: string; to: string };
    totals: WidgetStatsRow;
    variants: (WidgetStatsRow & { key: string })[];
    daily: { date: string; views: number; submissions: number }[];
    ab: AbResult | null;
    currency: string;
    winner: WidgetDetail['ab']['winner'];
}

export interface WidgetCaptureRow {
    contact: { id: string; name: string | null; email: string | null } | null;
    variant: string | null;
    was_known: boolean;
    coupon: string | null;
    prize: string | null;
    answers: Record<string, string> | null;
    captured_at: string;
}

/** Lo que se guarda con «Guardar» (PATCH). */
export interface WidgetDraft {
    name: string;
    site_id: string | null;
    priority: number;
    targeting: Targeting;
    variants: WidgetVariant[];
    consent_config: ConsentConfig;
    coupon_config: CouponConfig;
    quiz: Quiz | null;
    starts_at: string | null;
    ends_at: string | null;
    ab_auto_winner: boolean;
}
