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
    | 'viewPaymentData'
    | 'manageMarketing'
    | 'viewAuditLog';

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
    legal_name: string | null;
    privacy_policy_url: string | null;
    settings: Record<string, unknown>;
    status: string;
    role: WorkspaceRole | null;
    abilities: Ability[];
    requires_two_factor: boolean;
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
