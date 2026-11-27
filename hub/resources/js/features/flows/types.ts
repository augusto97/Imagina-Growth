import type { CostMeta } from '@/features/analytics/cost';
/* Formas de la API de flujos (hub/app/Domain/Flows/Http, modulos.md M09). */

export type FlowStatus = 'draft' | 'active' | 'paused' | 'archived';

export interface Filter {
    prop: string;
    operator: string;
    value?: string | number | (string | number)[] | null;
}

export type Trigger =
    | { type: 'event'; event: string | null; filters: Filter[] }
    | { type: 'segment'; segment_id: string | null; on: 'entered' | 'exited'; filters?: Filter[] }
    | { type: 'date'; attribute: string | null; offset_days: number; hour: number }
    | { type: 'threshold'; metric: string | null; operator: string; value: number | null }
    | { type: 'manual' }
    | { type: 'webhook' }
    | { type: null };

export interface Goal {
    event: string;
    filters?: Filter[];
}

export interface ReentryPolicy {
    mode: 'once' | 'every_days' | 'always';
    days?: number;
}

export interface GraphNode {
    id: string;
    type: string;
    config: Record<string, unknown>;
    position?: { x: number; y: number };
}

export interface GraphEdge {
    from: string;
    to: string;
    branch?: string | null;
}

export interface Graph {
    nodes: GraphNode[];
    edges: GraphEdge[];
}

export interface FlowStats {
    entered: number;
    live: number;
    converted: number;
}

/** El resultado del mes de una automatización (GET flows, FlowListResults). */
export interface FlowMonthResult {
    /** Dinero generado este mes (MoneyGenerated, cada venta una sola vez). */
    revenue: string;
    orders: number;
    /** Cuántos lograron la meta este mes (compraron, agendaron, avanzaron…), sin el grupo de control. */
    conversions: number;
    messages: number;
    /** Comparación con el grupo de control de los últimos 90 días. */
    control: {
        treatment_n: number;
        treatment_converted: number;
        holdout_n: number;
        holdout_converted: number;
        /** Al menos 30 en cada grupo. */
        enough: boolean;
        /** Diferencia de tasas (fracción): expuestos − control. */
        difference: number | null;
    };
}

export interface FlowListMeta {
    month: { from: string; to: string };
    currency: string;
    control_days: number;
    min_group: number;
}

export interface FlowSummary {
    id: string;
    name: string;
    description: string | null;
    status: FlowStatus;
    template_key: string | null;
    trigger: Trigger;
    goal: Goal | null;
    holdout_pct: number;
    has_published: boolean;
    activated_at: string | null;
    updated_at: string | null;
    stats: FlowStats;
    /** Solo en la lista. */
    month?: FlowMonthResult | null;
    /** La estrategia que la usa (si viene de la biblioteca). */
    strategy?: string | null;
}

export interface FlowVersionRef {
    id: string;
    version: number;
    published_at: string | null;
}

export interface FlowDetail extends FlowSummary {
    reentry_policy: ReentryPolicy;
    exclusions: string[];
    graph: Graph;
    has_draft: boolean;
    draft_version: number | null;
    current_version: FlowVersionRef | null;
    versions: FlowVersionRef[];
    issues: Record<string, string[]>;
    webhook: { url: string } | null;
    webhook_secret: string | null;
    migrated?: number;
    activated?: boolean;
}

export interface NodeTypeOption {
    type: string;
    category: 'wait' | 'logic' | 'action' | 'control' | 'trigger';
    phase: number | null;
    branches: (string | null)[] | 'dynamic';
}

export interface TemplateOption {
    id: string;
    name: string;
    channel: 'email' | 'whatsapp' | 'sms';
    category: string;
    library_key: string | null;
    status: string;
}

export interface FlowOptions {
    nodes: NodeTypeOption[];
    events: string[];
    templates: TemplateOption[];
    segments: { id: string; name: string; is_system: boolean }[];
    flows: { id: string; name: string }[];
    date_attributes: string[];
    threshold_metrics: string[];
    /** Para asignar, crear tareas y mover la etapa del lead. */
    team?: { id: string; name: string }[];
    lead_stages?: { key: string; label: string; kind: 'open' | 'won' | 'lost'; pipeline: string }[];
    /** El WhatsApp por el que salen los pasos de WhatsApp y lo que puede (ChannelCapabilities); null sin WhatsApp. */
    whatsapp?: { name: string; provider: string; official: boolean; marketing: boolean; templates: boolean; buttons: boolean; media: boolean } | null;
}

export interface LibraryFlow {
    key: string;
    number: number;
    name: string;
    description: string;
    phase: number;
    available: boolean;
    /** Ruta del módulo que hace esta automatización (Fase 3: reseñas, NPS, avisos). */
    module: string | null;
    trigger: Trigger | null;
    templates: string[];
    missing_templates: string[];
    created_flow_id: string | null;
    steps: number;
    /** Evento que la termina como lograda (compró, agendó…). */
    goal_event: string | null;
    /** Objetivo en la galería (LibraryCatalog::GOALS). */
    goal: LibraryGoal;
    audience: 'store' | 'services' | 'both';
    /** null = todas las verticales de su tipo de negocio. */
    verticals: string[] | null;
    /** Lugar en «Recomendadas para ti» para la vertical del espacio de trabajo. */
    rank: number | null;
    /** La estrategia que la usa. */
    strategy: string | null;
    channels: ('whatsapp' | 'email' | 'sms')[];
    messages: number;
}

export type LibraryGoal = 'recover_sales' | 'sell_again' | 'loyalty' | 'capture' | 'reputation' | 'convert_leads' | 'appointments';

export interface LibraryMeta {
    whatsapp_connected: boolean;
    goals: LibraryGoal[];
    vertical: string | null;
    business_type: 'store' | 'services' | 'both';
}

/** Un mensaje del recorrido de un flujo listo, con los datos de ejemplo (GET flows/library/{key}). */
export interface LibraryMessage {
    node_id: string;
    channel: 'whatsapp' | 'email' | 'sms';
    template_key: string;
    name: string | null;
    kind: string;
    subject: string | null;
    preheader: string | null;
    text: string | null;
    buttons: string[];
    /** false: una de WhatsApp aprobada que eliges tú (sin texto propio). */
    in_library: boolean;
}

export interface LibraryFlowPreview {
    key: string;
    number: number;
    name: string;
    description: string;
    module: string | null;
    trigger: Trigger | null;
    goal: Goal | null;
    reentry_policy: ReentryPolicy | null;
    graph: Graph | null;
    messages: LibraryMessage[];
    whatsapp_connected: boolean;
}

export interface GroupStats {
    entered: number;
    live: number;
    converted: number;
    finished: number;
    filtered: number;
    rate: number | null;
    revenue: string;
    revenue_per_contact: string | null;
}

export interface Uplift {
    available: boolean;
    reason?: 'no_holdout' | 'small_sample';
    treatment_rate?: number;
    holdout_rate?: number;
    difference?: number;
    ci_low?: number;
    ci_high?: number;
    relative?: number | null;
    significant?: boolean;
    treatment_n?: number;
    holdout_n?: number;
}

export interface NodeStats {
    reached: number;
    outcomes: Record<string, number>;
    converted: number;
    messages: { sent?: number; delivered?: number; opened?: number; clicked?: number; failed?: number };
}

export interface FlowAnalytics {
    summary: {
        entered: number;
        live: number;
        converted: number;
        conversion_rate: number | null;
        goal_revenue: string;
        attributed_orders: number;
        attributed_revenue: string;
        /** Costo estimado de los mensajes del flujo en el periodo y su retorno (F11). */
        cost: number | null;
        cost_usd: number;
        roi: number | null;
        cost_per_sale: number | null;
    };
    cost_meta: CostMeta;
    treatment: GroupStats;
    holdout: GroupStats;
    uplift: Uplift;
    nodes: Record<string, NodeStats>;
}

export interface FlowRunRow {
    id: string;
    contact: { id: string; name: string } | null;
    status: 'active' | 'waiting' | 'completed' | 'exited' | 'failed';
    current_node_id: string | null;
    is_holdout: boolean;
    exit_reason: string | null;
    next_run_at: string | null;
    started_at: string;
    ended_at: string | null;
}

export interface TestStep {
    node_id: string;
    outcome: string;
    details: Record<string, unknown>;
}

export interface TestResult {
    run_id: string;
    status: string;
    steps: TestStep[];
}
