/*
 * AST de segmentos (hub/CLAUDE.md §6) y respuestas de la API de segmentos.
 * El servidor valida y normaliza; aquí solo se arma.
 */

export type Window =
    | { kind: 'ever' }
    | { kind: 'this_month' }
    | { kind: 'last_days'; days: number }
    | { kind: 'between'; from: string; to: string };

export type CountOperator = 'ever' | 'never' | 'count_gte' | 'count_lte' | 'count_eq';

export type Scalar = string | number;

export interface AttributeCondition {
    type: 'attribute';
    field: string;
    operator: string;
    value?: Scalar | Scalar[] | null;
}

export interface PurchaseAggregateCondition {
    type: 'purchase';
    field: string;
    operator: string;
    value?: Scalar | Scalar[] | null;
    window?: Window;
}

export type PurchaseScope = 'any' | 'product' | 'category' | 'brand' | 'coupon' | 'payment_method';

export interface PurchaseItemsCondition {
    type: 'purchase';
    scope: PurchaseScope;
    ids: string[];
    operator: CountOperator;
    value?: number;
    window?: Window;
}

export interface EventFilter {
    prop: string;
    operator: 'eq' | 'neq' | 'in' | 'contains';
    value: string | string[];
}

export interface EventCondition {
    type: 'event';
    event: string;
    filters: EventFilter[];
    measure: 'events' | 'sessions' | 'days';
    operator: CountOperator;
    value?: number;
    window?: Window;
}

export interface CartCondition {
    type: 'cart';
    operator: 'abandoned';
    window: Window;
}

export interface PredictionCondition {
    type: 'prediction';
    field: string;
    operator: string;
    value?: Scalar | Scalar[] | null;
}

export interface ConsentCondition {
    type: 'consent';
    channel: 'email' | 'whatsapp' | 'sms';
    purpose: 'marketing' | 'transactional';
    status: 'granted' | 'not_granted';
}

export type MessageAction = 'received' | 'opened' | 'read' | 'clicked' | 'replied';

export type MessageChannel = 'any' | 'email' | 'whatsapp' | 'sms';

/** Mensajería (Fase 2): qué hizo con los mensajes, con conteo y ventana. */
export interface MessagingCondition {
    type: 'messaging';
    action: MessageAction;
    channel: MessageChannel;
    kind: 'any' | 'marketing';
    flow_id?: string;
    campaign_id?: string;
    operator: CountOperator;
    value?: number;
    window?: Window;
}

/** Puntaje de interacción de los últimos 90 días (0 a 100). */
export interface EngagementCondition {
    type: 'messaging';
    action: 'engagement';
    operator: string;
    value?: Scalar | Scalar[] | null;
}

/** Fidelización (Fase 3): nivel, puntos, saldo, puntos por vencer y referidos. */
export interface LoyaltyCondition {
    type: 'loyalty';
    field: string;
    operator: string;
    value?: Scalar | Scalar[] | null;
}

/** Leads (Fase 5): tiene un lead (abierto) o alguno de sus leads cumple la condición. */
export interface LeadCondition {
    type: 'lead';
    field: string;
    operator: string;
    value?: Scalar | Scalar[] | null;
}

/** Citas (Fase 5): tiene una cita próxima, alguna vez agendó o alguna de sus citas cumple la condición. */
export interface ServiceCondition {
    type: 'service';
    field: string;
    operator: string;
    value?: Scalar | Scalar[] | null;
}

export interface SegmentRefCondition {
    type: 'segment';
    segment_id: string;
    operator: 'in' | 'not_in';
}

export type Condition =
    | AttributeCondition
    | PurchaseAggregateCondition
    | PurchaseItemsCondition
    | EventCondition
    | CartCondition
    | PredictionCondition
    | ConsentCondition
    | SegmentRefCondition
    | MessagingCondition
    | EngagementCondition
    | LoyaltyCondition
    | LeadCondition
    | ServiceCondition;

export interface Group {
    op: 'and' | 'or';
    negate?: boolean;
    conditions: Node[];
}

export type Node = Group | Condition;

export type ConditionType = Condition['type'];

export function isGroup(node: Node): node is Group {
    return 'conditions' in node && 'op' in node;
}

export function isPurchaseItems(c: Condition): c is PurchaseItemsCondition {
    return c.type === 'purchase' && 'scope' in c;
}

export function isEngagement(c: Condition): c is EngagementCondition {
    return c.type === 'messaging' && c.action === 'engagement';
}

// ── API ────────────────────────────────────────────────────────────────

export interface SegmentSummary {
    id: string;
    name: string;
    description: string | null;
    folder: string | null;
    kind: 'dynamic' | 'static';
    is_system: boolean;
    system_key: string | null;
    /** Los del sistema: para tiendas, para servicios o para los dos (null). */
    audience?: 'store' | 'services' | null;
    member_count: number;
    revenue_ws: string;
    channel_counts: { email?: number; whatsapp?: number };
    last_computed_at: string | null;
    updated_at: string | null;
    /** Tamaño de los últimos 30 días (uno por día medido); `change` es null con menos de dos días. */
    trend?: { points: number[]; change: number | null; days: number } | null;
}

export interface SegmentDetail extends SegmentSummary {
    definition: Group | null;
}

export interface SegmentContact {
    id: string;
    name: string;
    email: string | null;
    phone: string | null;
    city: string | null;
    lifecycle_stage: string | null;
    rfm_segment: string | null;
    orders_count: number;
    revenue_total_ws: string;
    last_order_at: string | null;
    last_seen_at: string | null;
}

export interface Preview {
    count: number;
    sample: SegmentContact[];
    channels: { email: number; whatsapp: number };
    value: { revenue_ws: string; predicted_clv_ws: string | null };
    took_ms: number;
}

export interface FieldOption {
    key: string;
    type: 'string' | 'enum' | 'number' | 'date' | 'tags' | 'birthday' | 'bool' | 'tier' | 'expiring';
    operators: string[];
    values: string[] | null;
    label?: string;
}

export interface SegmentOptions {
    attributes: FieldOption[];
    purchase_fields: FieldOption[];
    predictions: FieldOption[];
    custom_attributes: FieldOption[];
    events: string[];
    event_columns: string[];
    rfm_segments: { key: string; name: string }[];
    values: {
        city: string[];
        region_code: string[];
        country: string[];
        payment_method: { id: string; title: string | null }[];
        brand: string[];
        hdyhau: string[];
    };
    segments: { id: string; name: string }[];
    message_actions: MessageAction[];
    message_channels: MessageChannel[];
    engagement_days: number;
    flows: { id: string; name: string }[];
    campaigns: { id: string; name: string }[];
    lead_fields: FieldOption[];
    service_fields: FieldOption[];
    loyalty_fields: FieldOption[];
    loyalty_tiers: { id: string; name: string; rank: number }[];
}

export interface LibraryResponse {
    system: { key: string; name: string; description: string; folder: string; audience?: 'store' | 'services' | null; segment_id: string | null }[];
    templates: { key: string; name: string; description: string; params: { name: string; type: 'category' | 'product'; label: string }[] }[];
}

export interface PickerItem {
    id: string;
    name: string;
    sku?: string | null;
}
