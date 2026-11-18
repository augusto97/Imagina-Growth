/** Tipos del copiloto (hub: app/Domain/Intelligence/Ai/Copilot). */
export type DraftKind = 'campaign' | 'flow' | 'content';
export type DraftStatus = 'draft' | 'applied' | 'discarded';
export type CopyField = 'subject' | 'preheader' | 'headline' | 'intro' | 'cta';

export interface ChannelReview {
    audience: number;
    will_send: number;
    excluded: number;
    excluded_reasons: { reason: string; count: number }[];
    deferred_reasons: { reason: string; count: number }[];
    channel_available: boolean;
    first_send_at: string | null;
    cost_usd: number;
    cost_local: number | null;
    currency: string | null;
    estimated: boolean;
}

export interface CampaignProposal {
    name: string;
    segment: { mode: 'existing' | 'new'; segment_id: string | null; name: string | null; definition: unknown; members?: number; reach?: Record<string, number> };
    channels: {
        channel: 'email' | 'whatsapp' | 'sms';
        template: { mode: 'existing' | 'library'; template_id: string | null; library_key: string | null; name: string | null; copy: Partial<Record<CopyField, string>> };
        review?: ChannelReview;
    }[];
    schedule: { send_mode: 'scheduled' | 'contact_timezone' | 'best_time'; send_at: string; date: string; hour: number; local: string; timezone: string; note: string | null; reason: string | null };
    coupon: { enabled: boolean; kind: 'percent' | 'fixed_cart' | 'free_shipping'; amount: number; expires_in_days: number; minimum_amount: number | null };
    holdout_pct: number;
    warnings: string[];
    summary: string;
    summary_source: 'ai' | 'fallback' | 'template';
    applied?: { id: string; channel: string; name: string }[];
}

export interface FlowProposal {
    name: string;
    description: string | null;
    trigger: Record<string, unknown>;
    goal: { event: string } | null;
    reentry_policy: { mode: string; days?: number };
    steps: { id: string; type: string; label: string }[];
    pending_whatsapp: string[];
    warnings: string[];
    summary: string;
    summary_source: 'ai' | 'fallback';
    applied?: { id: string; name: string; issues: Record<string, string[]> }[];
}

export interface DraftSummary {
    id: string;
    kind: DraftKind;
    status: DraftStatus;
    prompt: string;
    name: string | null;
    summary: string | null;
    applied: { id: string; name?: string; channel?: string }[];
    created_at: string;
}

export interface CampaignDraft extends DraftSummary {
    kind: 'campaign';
    proposal: CampaignProposal;
    model: string | null;
}

export interface FlowDraft extends DraftSummary {
    kind: 'flow';
    proposal: FlowProposal;
    model: string | null;
}

export type Draft = CampaignDraft | FlowDraft;

export interface BrandTone {
    summary: string;
    traits: string[];
    do: string[];
    dont: string[];
    examples: string[];
    sources: string[];
    updated_at: string;
}

export interface CampaignEdits {
    name?: string;
    holdout_pct?: number;
    schedule?: { date?: string; hour?: number; send_mode?: CampaignProposal['schedule']['send_mode'] };
    channels?: Partial<Record<CopyField, string | null>>[];
}
