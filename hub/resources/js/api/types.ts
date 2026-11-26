/** Formas JSON de la API interna (hub/app/Http/Controllers/Spa). */

export type WorkspaceRole = 'owner' | 'admin' | 'marketing' | 'agent' | 'viewer';

export type Ability =
    | 'viewWorkspace'
    | 'viewDashboards'
    | 'manageWorkspace'
    | 'deleteWorkspace'
    | 'manageBilling'
    | 'manageMembers'
    | 'viewSites'
    | 'manageSites'
    | 'viewAllContacts'
    | 'viewAssignedContacts'
    | 'exportContacts'
    | 'deleteContacts'
    | 'mergeContacts'
    | 'editContacts'
    | 'viewPaymentData'
    | 'manageMarketing'
    | 'viewAuditLog'
    | 'useInbox'
    | 'manageInbox'
    | 'useAi'
    | 'useLeads'
    | 'manageServices'
    | 'manageApi'
    | 'viewAgency'
    | 'platformAdmin';

export interface Workspace {
    id: string;
    name: string;
    slug: string;
    account_id: string;
    country: string;
    timezone: string;
    currency: string;
    locale: string;
    industry: string | null;
    /** A qué se dedica (rediseño v2 §11.1): `store`, `professional`, `health`… Se edita con el PATCH del espacio de trabajo. */
    vertical?: string | null;
    legal_name: string | null;
    privacy_policy_url: string | null;
    settings: Record<string, unknown>;
    status: string;
    role: WorkspaceRole | null;
    abilities: Ability[];
    requires_two_factor: boolean;
    /** Marca que ve el panel en este espacio de trabajo (M22, marca blanca). */
    branding?: WorkspaceBranding;
    /** M21: estado de facturación de la cuenta (aviso de gracia o suspensión). */
    billing?: { status: 'trial' | 'active' | 'grace' | 'suspended'; grace_ends_at: string | null; trial_ends_at: string | null };
    /** Módulos del plan activos en este espacio de trabajo (M21; el menú oculta los demás). */
    modules?: PlanModule[];
    /** Tipo de negocio según sus sitios y su plan: null = todavía no se sabe. */
    capabilities?: WorkspaceCapabilities;
    /** Web y canales conectados, para los «bloqueos que enseñan» del menú y las páginas (rediseño v2 §3). */
    connections?: WorkspaceConnections;
    /** Para cada módulo fuera del plan, el nombre del plan más barato que lo incluye. */
    upgrade_plans?: Partial<Record<PlanModule, string>>;
}

/**
 * `site`: `connected` (algún sitio conectado o con fallas), `disconnected` (se conectó alguna vez: sus datos siguen),
 * `pending` (conexión empezada sin terminar) o `none`. `channels`: canales con una cuenta en uso (email, whatsapp…).
 */
export interface WorkspaceConnections {
    site: 'connected' | 'disconnected' | 'pending' | 'none';
    channels: string[];
}

/** Módulos del plan (config/igr-plans.php). */
export type PlanModule =
    | 'onsite'
    | 'loyalty'
    | 'referrals'
    | 'reviews'
    | 'inbox'
    | 'ai'
    | 'ai_agent'
    | 'services'
    | 'bookings'
    | 'quotes'
    | 'api'
    | 'mcp'
    | 'agency_reports'
    | 'white_label';

export interface WorkspaceCapabilities {
    /** Tiene una tienda WooCommerce conectada (false: solo sitios WordPress; null: sin sitios). */
    store: boolean | null;
    /** Vende servicios (leads, agenda, cotizador). false: solo tienda; null: sin datos. */
    services: boolean | null;
}

export interface WorkspaceBranding {
    name: string;
    logo_url: string | null;
    primary_color: string | null;
    white_label: boolean;
}

export interface Me {
    user: {
        id: string;
        name: string;
        email: string;
        locale: string;
        two_factor_enabled: boolean;
        two_factor_pending: boolean;
        is_platform_admin: boolean;
    };
    workspaces: Workspace[];
    require_two_factor: boolean;
}

export type SiteStatus = 'pending' | 'connected' | 'degraded' | 'disconnected';

export interface BackfillEntity {
    total?: number;
    sent?: number;
}

export interface Backfill {
    status: 'queued' | 'running' | 'paused' | 'completed' | 'failed' | null;
    phase: string | null;
    from: string | null;
    started_at: string | null;
    finished_at: string | null;
    entities: Record<string, BackfillEntity>;
    progress: number | null;
    error: string | null;
    reported_at: string | null;
}

/** Semáforo de salud del sitio (docs/auditoria-2026-11.md U4; SiteHealth del hub). */
export type SiteHealthState = 'ok' | 'lagging' | 'no_data';

export type SiteHealthCauseKey =
    | 'disconnected'
    | 'pending'
    | 'never_received'
    | 'waiting_first_data'
    | 'server_late'
    | 'server_silent'
    | 'cron_late'
    | 'outbox_late'
    | 'browser_missing'
    | 'browser_late'
    | 'plugin_outdated'
    | 'rejections'
    | 'outbox_dead'
    | 'backfill_stalled'
    | 'backfill_failed';

export interface SiteHealthCause {
    key: SiteHealthCauseKey;
    severity: 'error' | 'warning';
    params: Record<string, string | number>;
}

export interface SiteHealthStatus {
    state: SiteHealthState;
    causes: SiteHealthCause[];
    checked_at: string;
}

export interface Site {
    id: string;
    name: string | null;
    url: string;
    host: string;
    platform: 'woocommerce' | 'wordpress';
    status: SiteStatus;
    plugin_version: string | null;
    wp_version: string | null;
    wc_version: string | null;
    connected_at: string | null;
    last_seen_at: string | null;
    last_event_at: string | null;
    backfill: Backfill | null;
    health_status?: SiteHealthStatus | null;
}

export interface SiteDetail extends Site {
    public_key: string;
    rest_url: string | null;
    timezone: string | null;
    currency: string | null;
    php_version: string | null;
    capabilities: Record<string, unknown>;
    settings: Record<string, unknown>;
    health: {
        outbox_pending?: number;
        outbox_dead?: number;
        oldest_pending_at?: string | null;
        last_error?: string | null;
        heartbeat_at?: string;
    };
    secret_rotated_at: string | null;
}

export interface ReconciliationRow {
    status: string;
    wc_count: number;
    hub_count: number;
    wc_total: string;
    hub_total: string;
    ok: boolean;
}

export type Reconciliation =
    | { available: false }
    | {
          available: true;
          ok: boolean;
          currency: string | null;
          rows: ReconciliationRow[];
          totals: {
              wc_count: number;
              hub_count: number;
              wc_total: string;
              hub_total: string;
              wc_refunded?: string;
              hub_refunded?: string;
              refunds_ok?: boolean;
          };
          from: string | null;
          until: string | null;
      };

export interface IngestRejection {
    event_id: string | null;
    event: string | null;
    reason: string;
    detail: string | null;
    created_at: string;
}

export interface SiteShowResponse {
    site: SiteDetail;
    reconciliation: Reconciliation;
    rejections: IngestRejection[];
    events_24h: { event: string; c: number }[];
}

export interface Overview {
    currency: string;
    sites: { total: number; connected: number };
    orders: { total: number; paid: number; cod_pending: number };
    revenue_ws: string;
    contacts: { total: number; customers: number };
    events_24h: number | null;
}

export interface Member {
    id: string;
    name: string;
    email: string;
    role: WorkspaceRole;
    two_factor_enabled: boolean;
    last_login_at: string | null;
}

export interface PendingInvitation {
    id: string;
    email: string;
    role: WorkspaceRole;
    expires_at: string;
}

export interface MembersResponse {
    members: Member[];
    invitations: PendingInvitation[];
}

export interface ContactRow {
    id: string;
    name: string;
    email: string | null;
    phone: string | null;
    city: string | null;
    lifecycle_stage: string | null;
    orders_count: number;
    revenue_total_ws: string;
    last_order_at: string | null;
    last_seen_at: string | null;
}

export interface Paginated<T> {
    data: T[];
    meta: { total: number; page: number; per_page: number };
}

export interface AuditEntry {
    id: number;
    action: string;
    user: string | null;
    subject_type: string | null;
    subject_id: string | null;
    changes: Record<string, unknown> | null;
    created_at: string | null;
}

export interface ConnectCode {
    code: string;
    redirect_url: string | null;
    expires_at: string;
}

export interface InvitationPreview {
    workspace: string | null;
    email: string;
    role: WorkspaceRole;
    expires_at: string;
    has_account: boolean;
}
