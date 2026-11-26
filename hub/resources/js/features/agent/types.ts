export type AgentMode = 'off' | 'assist' | 'auto';

export const POLICY_KEYS = ['shipping', 'returns', 'payment_methods', 'hours', 'other'] as const;
export type PolicyKey = (typeof POLICY_KEYS)[number];

export interface AgentSettings {
    enabled: boolean;
    default_mode: AgentMode;
    scope: string | null;
    tone: string | null;
    policies: Record<PolicyKey, string>;
    handoff_rules: {
        keywords: string[];
        intents: string[];
        hours: { mode: 'always' | 'out_of_hours'; days: number[]; start: string; end: string };
    };
    discount_rules: { enabled: boolean; coupon_code: string | null; min_subtotal: number | string | null; description: string | null };
    max_steps: number;
    updated_at?: string | null;
}

export interface AgentSettingsResponse {
    settings: AgentSettings;
    ai_available: boolean;
    store_connected: boolean;
    can_manage: boolean;
    /** Las cuentas activas que conversan (whatsapp, messenger, instagram): dónde responde. */
    channels?: string[];
    /** El horario de atención de Tu negocio (manda sobre el del agente); null si no lo han definido. */
    business_hours?: { days: number[]; start: string; end: string } | null;
    /** Servicios: preguntas de calificación de Leads y servicios de la agenda que puede ofrecer en el chat. */
    services?: { qualification: { sets: number; active: number }; booking: { module: boolean; services: number } };
}

export type TurnStatus = 'replied' | 'suggested' | 'handoff' | 'blocked' | 'failed';

export interface ToolCall {
    name: string;
    input: Record<string, unknown>;
    output: string | null;
    error: string | null;
}

export interface TurnRow {
    id: string;
    created_at: string | null;
    mode: 'assist' | 'auto';
    status: TurnStatus;
    is_test: boolean;
    handoff_reason: string | null;
    flagged: boolean;
    review_status: 'ok' | 'wrong' | 'needs_fix' | null;
    conversation_id: string | null;
    contact_name: string | null;
    tools: string[];
    validation_ok: boolean;
    has_cart: boolean;
    latency_ms: number | null;
}

export interface TurnDetail extends TurnRow {
    suggestion: string | null;
    tool_calls: ToolCall[];
    validation: { ok?: boolean; attempts?: { ok: boolean; unknown: string[]; needs_lookup: boolean }[]; error?: string } | null;
    tokens_in: number;
    tokens_out: number;
}

export interface ReviewSummary {
    turns: number;
    replied: number;
    suggested: number;
    handoff: number;
    blocked: number;
    failed: number;
    conversations: number;
    handoff_rate: number | null;
    flagged: number;
    orders: number;
    revenue: string;
    currency: string;
    days: number;
}

export interface ReviewResponse {
    summary: ReviewSummary;
    data: TurnRow[];
    meta: { page: number; last_page: number; total: number };
}

export type ReviewFilter = 'all' | 'flagged' | 'handoff' | 'replied' | 'suggested' | 'test';

export interface ChatMessage {
    role: 'user' | 'assistant';
    content: string;
}

export interface SandboxResult {
    id: string;
    status: TurnStatus;
    reply: string | null;
    handoff_reason: string | null;
    flagged: boolean;
    tool_calls: ToolCall[];
    validation: TurnDetail['validation'];
}
