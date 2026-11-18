/* Leads (M18): tipos de la API. Las cifras de dinero llegan como texto decimal. */

export type Temperature = 'cold' | 'warm' | 'hot';
export type Origin = 'form' | 'whatsapp' | 'quote' | 'booking' | 'manual' | 'api';
export type StageKind = 'open' | 'won' | 'lost';

export interface Stage {
    key: string;
    label: string;
    kind: StageKind;
}

export interface Pipeline {
    id: string;
    name: string;
    is_default: boolean;
    stages: Stage[];
}

export interface LeadRow {
    id: string;
    stage: string;
    pipeline_id: string | null;
    contact: { id: string; name: string | null; city: string | null } | null;
    service: string | null;
    value: string | null;
    currency: string;
    owner: { id: string; name: string } | null;
    score: number;
    temperature: Temperature;
    origin: Origin;
    channel: string | null;
    channel_label: string | null;
    next_step: string | null;
    next_step_at: string | null;
    first_response_at: string | null;
    last_activity_at: string | null;
    won_at: string | null;
    lost_at: string | null;
    lost_reason: string | null;
    created_at: string | null;
}

export interface ScoreReason {
    signal: string;
    points: number;
    at: string | null;
    detail: Record<string, unknown> | null;
    reason: string | null;
}

export interface Activity {
    id: number;
    kind: string;
    actor: 'system' | 'ai' | 'api' | 'user';
    actor_name: string | null;
    data: Record<string, unknown>;
    at: string;
}

export interface LeadDetail extends LeadRow {
    pipeline_stages: Stage[];
    email: string | null;
    phone: string | null;
    source: { channel?: string; utm_source?: string; utm_medium?: string; utm_campaign?: string; page?: string; form_name?: string; referrer_host?: string; has_gclid: boolean; has_fbclid: boolean };
    score_reasons: ScoreReason[];
    offline_conversion_at: string | null;
    external_crm_id: string | null;
    activities: Activity[];
}

export interface BoardColumn extends Stage {
    count: number;
    value: string;
    leads: LeadRow[];
}

export interface BoardResponse {
    pipeline: Pipeline;
    currency: string;
    columns: BoardColumn[];
}

export interface ListResponse {
    pipeline: Pipeline;
    data: LeadRow[];
    meta: { page: number; last_page: number; total: number };
}

export interface LeadFilters {
    pipeline?: string;
    contact?: string;
    stage?: string;
    owner?: string;
    temperature?: Temperature | '';
    source?: string;
    service?: string;
    status?: 'open' | 'won' | 'lost' | 'all' | '';
    sort?: 'recent' | 'score' | 'value' | 'activity';
    page?: number;
}

export interface Kpis {
    from: string;
    to: string;
    days: number;
    currency: string;
    leads: number;
    won_in_cohort: number;
    conversion_rate: number | null;
    won: { count: number; value: string };
    open: { count: number; hot: number; unanswered: number };
    first_response: { answered: number; unanswered: number; median_seconds: number | null; within_15m: number; within_15m_rate: number | null };
    by_source: { channel: string; label: string; leads: number; won: number; won_value: string; conversion_rate: number | null }[];
    funnel: { stage: string; label: string; kind: StageKind; leads: number; rate: number | null }[];
}

export interface TeamMember {
    id: string;
    name: string;
    role: string;
}

export interface Seller {
    id: string;
    user: { id: string; name: string | null };
    phone: string | null;
    services: string[];
    active: boolean;
    open_leads: number;
    last_assigned_at: string | null;
}

export interface ScoringRules {
    points: Record<string, number>;
    pricing_paths: string[];
    service_cities: string[];
    personal_domains: string[];
    thresholds: { warm: number; hot: number };
    ai_max_points: number;
}

export interface AlertRules {
    on_hot: boolean;
    on_new: boolean;
    channel: 'whatsapp' | 'email';
    whatsapp_template_id: string | null;
}

export interface LeadSettings {
    scoring: ScoringRules;
    alerts: AlertRules;
    assignment: { mode: 'round_robin' | 'rules' | 'none' };
    whatsapp_leads: boolean;
    ai_qualify: boolean;
    ai_available: boolean;
    signals: string[];
    sellers: Seller[];
    pipelines: Pipeline[];
    whatsapp_templates: { id: string; name: string }[];
    can_manage: boolean;
}
