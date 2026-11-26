import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { BillingStatus, Invoice, Meter, PlanInfo } from '@/features/billing/api';
import { api } from '@/lib/api';

/* Panel interno de la plataforma (hub/CLAUDE.md §16, solo superadmin). Rutas en hub/routes/platform.php. */

const base = '/api/platform';

export interface PlatformWorkspace {
    id: string;
    name: string;
    usage: Meter;
    ai: Meter;
    overrides: Record<string, boolean>;
}

export interface PlatformAccount {
    id: string;
    name: string;
    type: string;
    plan_id: string | null;
    billing_status: BillingStatus;
    grace_ends_at: string | null;
    open_invoices: number;
    overdue_invoices: number;
    workspaces: PlatformWorkspace[];
}

export interface PlatformOverview {
    accounts: PlatformAccount[];
    plans: PlatformPlan[];
    modules: string[];
    plan_requests: {
        id: string;
        account_id: string | null;
        account: string | null;
        from_plan: string | null;
        to_plan: string;
        note: string | null;
        created_at: string | null;
    }[];
    invoices: (Invoice & {
        account_id: string | null;
        account: string | null;
    })[];
}

export interface PlatformHealth {
    ingest:
        | {
              shard: number;
              length: number;
              pending: number;
              lag_seconds: number;
          }[]
        | null;
    rejections_24h: { reason: string; count: number }[];
    failed_deliveries_24h: {
        channel: string;
        provider: string | null;
        count: number;
    }[];
    conversion_failures_24h:
        | {
              platform: string;
              workspace_id: string | null;
              workspace: string | null;
              count: number;
          }[]
        | null;
    ai_cost_month: {
        workspace_id: string;
        workspace: string;
        cost_usd: number;
        budget_usd: number | null;
        pct: number | null;
    }[];
    time: string;
}

export interface InvoiceDraft {
    period: string;
    currency: string;
    lines: { concept: string; quantity: number; unit_price: string }[];
}

const keys = {
    overview: ['platform', 'overview'] as const,
    health: ['platform', 'health'] as const,
};

export function usePlatformOverview(enabled: boolean) {
    return useQuery({
        queryKey: keys.overview,
        queryFn: ({ signal }) => api.get<PlatformOverview>(`${base}/overview`, signal),
        enabled,
    });
}

export function usePlatformHealth(enabled: boolean) {
    return useQuery({
        queryKey: keys.health,
        queryFn: ({ signal }) => api.get<PlatformHealth>(`${base}/health`, signal),
        enabled,
        refetchInterval: 30_000,
    });
}

function useInvalidate() {
    const qc = useQueryClient();
    return () => void qc.invalidateQueries({ queryKey: ['platform'] });
}

export function useAssignPlan() {
    const done = useInvalidate();
    return useMutation({
        mutationFn: (v: { account: string; plan_id: string | null }) =>
            api.put(`${base}/accounts/${v.account}/plan`, {
                plan_id: v.plan_id,
            }),
        onSuccess: done,
    });
}

export function useSetBillingStatus() {
    const done = useInvalidate();
    return useMutation({
        mutationFn: (v: { account: string; status: BillingStatus }) =>
            api.put(`${base}/accounts/${v.account}/billing-status`, {
                status: v.status,
            }),
        onSuccess: done,
    });
}

export function useSetFeature() {
    const done = useInvalidate();
    return useMutation({
        mutationFn: (v: { workspace: string; feature: string; enabled: boolean | null }) =>
            api.put(`${base}/workspaces/${v.workspace}/features/${v.feature}`, {
                enabled: v.enabled,
            }),
        onSuccess: done,
    });
}

export function useInvoiceDraft(account: string | null) {
    return useQuery({
        queryKey: ['platform', 'invoice-draft', account],
        queryFn: ({ signal }) => api.get<InvoiceDraft>(`${base}/accounts/${account ?? ''}/invoice-draft`, signal),
        enabled: account !== null,
        staleTime: 0,
    });
}

export function useCreateInvoice() {
    const done = useInvalidate();
    return useMutation({
        mutationFn: (v: { account: string; period: string; currency: string; lines: InvoiceDraft['lines']; notes?: string; send: boolean }) => {
            const { account, ...body } = v;
            return api.post<Invoice>(`${base}/accounts/${account}/invoices`, body);
        },
        onSuccess: done,
    });
}

export function useInvoiceAction() {
    const done = useInvalidate();
    return useMutation({
        mutationFn: (v: { invoice: string; action: 'send' | 'paid' | 'void' }) => api.post<Invoice>(`${base}/invoices/${v.invoice}/${v.action}`),
        onSuccess: done,
    });
}

export function usePlanRequestAction() {
    const done = useInvalidate();
    return useMutation({
        mutationFn: (v: { id: string; action: 'apply' | 'reject' }) => api.post(`${base}/plan-requests/${v.id}/${v.action}`),
        onSuccess: done,
    });
}

export const platformInvoicePdfUrl = (invoice: string) => `${base}/invoices/${encodeURIComponent(invoice)}/pdf`;

/** Total de una factura en borrador, calculado igual que el servidor (redondeo a 2 decimales por línea). */
export function draftTotal(lines: InvoiceDraft['lines']): number {
    return lines.reduce((sum, l) => sum + Math.round(l.quantity * Number(l.unit_price) * 100) / 100, 0);
}

/** Retraso de ingesta: aviso desde 60 s, crítico desde 300 s. */
export function lagState(seconds: number): 'ok' | 'warning' | 'critical' {
    if (seconds >= 300) return 'critical';
    if (seconds >= 60) return 'warning';
    return 'ok';
}

/* ── Planes y clientes (administración de la plataforma) ─────────────────────────────── */

export interface PlatformPlan extends PlanInfo {
    public: boolean;
    archived: boolean;
    accounts?: number;
}

export interface PlansPayload {
    plans: PlatformPlan[];
    modules: string[];
    limits: string[];
}

export interface PlanInput {
    id?: string;
    name: string;
    metric: 'orders' | 'contacts';
    included: number;
    modules: string[];
    limits: Record<string, number | null>;
    price_usd: number;
    price_cop: number | null;
    public: boolean;
}

export function usePlatformPlans(enabled = true) {
    return useQuery({
        queryKey: ['platform', 'plans'],
        queryFn: () => api.get<PlansPayload>(`${base}/plans`),
        enabled,
    });
}

export function useSavePlan() {
    const done = useInvalidate();
    return useMutation({
        mutationFn: (v: { editing: string | null; plan: PlanInput }) =>
            v.editing === null ? api.post(`${base}/plans`, v.plan) : api.patch(`${base}/plans/${v.editing}`, v.plan),
        onSuccess: done,
    });
}

export function usePlanArchive() {
    const done = useInvalidate();
    return useMutation({
        mutationFn: (v: { plan: string; archive: boolean }) => api.post(`${base}/plans/${v.plan}/${v.archive ? 'archive' : 'restore'}`, {}),
        onSuccess: done,
    });
}

export interface ClientPerson {
    name: string;
    email: string;
    role: string;
}

export interface ClientDetail {
    id: string;
    name: string;
    type: 'direct' | 'agency';
    legal_name: string | null;
    country: string;
    billing_email: string | null;
    plan_id: string | null;
    billing_status: BillingStatus;
    created_at: string | null;
    account_users: ClientPerson[];
    workspaces: {
        id: string;
        name: string;
        country: string;
        currency: string;
        sites: number;
        created_at: string | null;
        members: ClientPerson[];
        invitations: { email: string; role: string; expires_at: string | null }[];
    }[];
}

export interface WorkspaceInput {
    name: string;
    country: string;
    currency: string;
    timezone: string;
}

export interface NewClientInput {
    name: string;
    type: 'direct' | 'agency';
    country: string;
    plan_id: string | null;
    owner_email: string;
    workspace: WorkspaceInput;
}

export function useClientDetail(id: string | null) {
    return useQuery({
        queryKey: ['platform', 'client', id],
        queryFn: () => api.get<{ account: ClientDetail }>(`${base}/accounts/${id}`).then((r) => r.account),
        enabled: id !== null,
    });
}

export function useCreateClient() {
    const done = useInvalidate();
    return useMutation({
        mutationFn: (v: NewClientInput) => api.post<{ account: ClientDetail; invite_url: string }>(`${base}/accounts`, v),
        onSuccess: done,
    });
}

export function useUpdateClient() {
    const done = useInvalidate();
    return useMutation({
        mutationFn: (v: { id: string; data: Partial<Pick<ClientDetail, 'name' | 'legal_name' | 'billing_email' | 'country' | 'type'>> }) =>
            api.patch(`${base}/accounts/${v.id}`, v.data),
        onSuccess: done,
    });
}

export function useAddClientWorkspace() {
    const done = useInvalidate();
    return useMutation({
        mutationFn: (v: { id: string; data: WorkspaceInput & { owner_email?: string } }) =>
            api.post<{ invite_url: string | null }>(`${base}/accounts/${v.id}/workspaces`, v.data),
        onSuccess: done,
    });
}

export function useInviteToClient() {
    const done = useInvalidate();
    return useMutation({
        mutationFn: (v: { id: string; workspace_id: string; email: string; role: string; account_owner?: boolean }) =>
            api.post<{ invite_url: string }>(`${base}/accounts/${v.id}/invitations`, {
                workspace_id: v.workspace_id,
                email: v.email,
                role: v.role,
                account_owner: v.account_owner ?? false,
            }),
        onSuccess: done,
    });
}

/** Zona horaria y moneda por defecto según el país (se pueden cambiar). */
export const COUNTRY_DEFAULTS: Record<string, { currency: string; timezone: string }> = {
    CO: { currency: 'COP', timezone: 'America/Bogota' },
    MX: { currency: 'MXN', timezone: 'America/Mexico_City' },
    VE: { currency: 'USD', timezone: 'America/Caracas' },
    PE: { currency: 'PEN', timezone: 'America/Lima' },
    CL: { currency: 'CLP', timezone: 'America/Santiago' },
    EC: { currency: 'USD', timezone: 'America/Guayaquil' },
    AR: { currency: 'ARS', timezone: 'America/Argentina/Buenos_Aires' },
    PA: { currency: 'USD', timezone: 'America/Panama' },
    CR: { currency: 'USD', timezone: 'America/Costa_Rica' },
    GT: { currency: 'USD', timezone: 'America/Guatemala' },
    DO: { currency: 'USD', timezone: 'America/Santo_Domingo' },
    US: { currency: 'USD', timezone: 'America/New_York' },
    ES: { currency: 'EUR', timezone: 'Europe/Madrid' },
};

/** Moneda y zona horaria sugeridas para un país (Colombia si no está en la lista). */
export function countryDefaults(country: string): { currency: string; timezone: string } {
    return COUNTRY_DEFAULTS[country] ?? { currency: 'COP', timezone: 'America/Bogota' };
}

/* ── Correo del sistema ─────────────────────────────────────────────────────────────── */

export interface PlatformMail {
    configured: boolean;
    mailer: string;
    from_address: string;
    from_name: string;
}

export function usePlatformMail(enabled = true) {
    return useQuery({ queryKey: ['platform', 'mail'], queryFn: () => api.get<PlatformMail>(`${base}/mail`), enabled });
}

/** Lo que dice la prueba del correo de la plataforma (MailTest en el hub). */
export interface MailTestResult {
    sent: boolean;
    reason?: 'from_missing' | 'connection' | 'auth' | 'tls' | 'sender' | 'other';
    message: string;
    detail?: string;
    to: string;
    from: string;
    mailer: string;
    host: string | null;
    server_response?: string | null;
    message_id?: string | null;
}

export function useMailTest() {
    return useMutation({ mutationFn: (body: { to?: string } = {}) => api.post<MailTestResult>(`${base}/mail/test`, body) });
}
