import type { CostFields, CostMeta } from '@/features/analytics/cost';
/* Formas JSON de la API de campañas (hub/routes/api/campaigns.php, modulos.md M10). */

export type CampaignChannel = 'email' | 'whatsapp' | 'sms';
export type CampaignStatus = 'draft' | 'scheduled' | 'sending' | 'sent' | 'paused' | 'cancelled';
export type SendMode = 'now' | 'scheduled' | 'contact_timezone' | 'best_time';
export type RuleType = 'purchased_within_days' | 'messaged_within_days' | 'has_consent' | 'never_purchased';
export type VariantKey = 'A' | 'B' | 'C' | 'D';

export interface AudienceRule {
    type: RuleType;
    days?: number;
    channel?: CampaignChannel;
}

export interface Audience {
    segments_include: string[];
    segments_exclude: string[];
    all_contacts: boolean;
    rules_include: AudienceRule[];
    rules_exclude: AudienceRule[];
}

export interface Variant {
    key: VariantKey;
    template_id: string | null;
    weight: number;
}

export interface AbConfig {
    sample_pct: number;
    metric: 'clicks' | 'revenue';
    wait_hours: number;
}

export interface PolicyBucket {
    reason: string;
    label: string;
    count: number;
}

export interface ComplianceCheck {
    checked_at: string;
    checked: number;
    estimated: boolean;
    channel_available: boolean;
    will_send: number;
    deferred: PolicyBucket[];
    excluded: PolicyBucket[];
    excluded_total: number;
    first_send_at: string | null;
    last_send_at: string | null;
    holdout: number;
    audience: number;
}

export interface CostEstimate {
    channel: CampaignChannel;
    recipients: number;
    by_country: { country: string; recipients: number; rate_usd: number | null; total_usd: number }[];
    total_usd: number;
    currency: string;
    total_local: number | null;
    rates_effective_from: string | null;
    rates_stale: boolean;
    missing_rates: boolean;
    note: string;
}

export interface CampaignSummary {
    id: string;
    name: string;
    channel: CampaignChannel;
    status: CampaignStatus;
    send_mode: SendMode;
    send_at: string | null;
    started_at: string | null;
    finished_at: string | null;
    holdout_pct: number;
    is_ab: boolean;
    winner_variant: VariantKey | null;
    recipients_estimate: number | null;
    cost_estimate_usd: number | null;
    created_at: string | null;
    updated_at: string | null;
    /** Solo en la lista y para las que ya empezaron. */
    recipients?: number | null;
    sent?: number | null;
}

export interface CampaignDetail extends CampaignSummary {
    audience: Audience;
    template_id: string | null;
    variants: Variant[];
    ab_config: AbConfig | null;
    cost_estimate: CostEstimate | null;
    compliance_check: ComplianceCheck | null;
    editable: boolean;
}

export interface CampaignOptions {
    segments: { id: string; name: string; member_count: number; is_system: boolean; folder: string | null }[];
    templates: { id: string; name: string; channel: CampaignChannel; category: string; subject: string | null; status: string }[];
    active_channels: CampaignChannel[];
    /** Canales conectados que no mandan marketing (un WhatsApp no oficial): la campaña dice por qué. */
    marketing_blocked?: CampaignChannel[];
    rules: RuleType[];
    workspace: { timezone: string; country: string; currency: string };
    wa_rates: { effective_from: string | null; stale: boolean; source_url: string };
}

export interface CalendarEvent {
    key: string;
    name: string;
    date: string;
    ends: string;
    starts_communicating: string;
    ideas: string[];
}

export interface CalendarResponse {
    year: number;
    country: 'CO' | 'MX' | 'VE';
    events: CalendarEvent[];
    campaigns: CampaignSummary[];
}

export interface VariantScore {
    sent: number;
    clicks: number;
    click_rate: number;
    revenue: number;
    revenue_per_sent: number;
}

export interface Uplift {
    treatment: { recipients: number; converted: number; rate: number; revenue_per_recipient: number };
    control: { recipients: number; converted: number; rate: number; revenue_per_recipient: number };
    lift: number;
    lift_relative: number | null;
    ci_low: number;
    ci_high: number;
    significant: boolean;
    incremental_revenue: number;
    window_days: number;
    complete: boolean;
}

export interface CampaignReport {
    currency: string;
    recipients: { total: number; holdout: number; by_status: Record<string, number>; reasons: { status: string; reason: string; count: number }[] };
    delivery: {
        queued: number;
        sent: number;
        delivered: number;
        read: number;
        opened: number;
        opened_excludes_mpp: boolean;
        clicked: number;
        unsubscribed: number;
        failed: number;
        click_rate: number | null;
    };
    revenue: { orders: number; total: number };
    /** Costo estimado de los mensajes de la campaña y su retorno (F11). */
    cost: CostFields & { meta: CostMeta };
    variants: Record<string, VariantScore>;
    winner_variant: VariantKey | null;
    uplift: Uplift | null;
}

export interface ReviewResponse {
    cost: CostEstimate;
    compliance: ComplianceCheck;
    campaign: CampaignDetail;
}

/** A quién llega de verdad la audiencia: por canal, cuántos aceptaron marketing (POST campaigns/{id}/audience-count). */
export interface AudienceReach {
    count: number;
    by_channel?: Record<CampaignChannel, { consented: number; connected: boolean }>;
    /** Escribirles a todos los que aceptaron WhatsApp, con la tarifa de marketing de Meta de su país. */
    whatsapp_cost?: { usd: number; local: number | null; currency: string } | null;
}

/** La ventana legal explicada (GET campaigns/send-window). */
export interface SendWindow {
    country: string;
    timezone: string;
    requested_at: string;
    sends_at: string;
    moved: boolean;
    /** Por qué se mueve: `sunday`, `holiday`, `outside_hours` o `past` (la hora ya pasó). */
    reason: 'sunday' | 'holiday' | 'outside_hours' | 'past' | null;
    /** La franja del día pedido [desde, hasta), o null si ese día no se envía. */
    requested_window: [string, string] | null;
    /** Días completos que se salta (domingos y festivos). */
    skipped: { date: string; reason: string; holiday: string | null }[];
    /** El festivo del día pedido. */
    holiday: string | null;
    /** «A la hora de cada contacto» o «mejor hora»: cada uno tiene la suya. */
    per_contact: boolean;
    /** Día ISO (1 = lunes) → [desde, hasta). */
    windows: Record<string, [string, string]>;
}
