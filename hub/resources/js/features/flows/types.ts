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
    };
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
