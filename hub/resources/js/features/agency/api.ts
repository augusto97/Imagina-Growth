import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { wsPath } from '@/api/queries';
import { postMultipart } from '@/features/contacts/api';
import { api } from '@/lib/api';

/* Capa de agencia (modulos.md M22). Rutas en hub/routes/api/agency.php. */

export type HealthStatus = 'healthy' | 'degraded' | 'disconnected' | 'no_sites';
export type PortableKind = 'flow' | 'segment' | 'widget' | 'template' | 'campaign';
export const PORTABLE_KINDS: PortableKind[] = ['flow', 'segment', 'widget', 'template', 'campaign'];

export interface AgencyClient {
    id: string;
    name: string;
    account: { id: string; name: string } | null;
    country: string;
    currency: string;
    health: { status: HealthStatus; sites: number; connected: number; last_event_at: string | null; minutes_since_event: number | null };
    money: {
        currency: string;
        total: string;
        orders: number;
        store_total: string;
        share_pct: number;
        previous_total: string;
        delta_pct: number | null;
        top_module: string | null;
    };
    critical_insights: number;
    usage: { plan: string | null; metric: 'orders' | 'contacts'; used: number; limit: number | null; pct: number | null };
    billing: { status: string | null; trial_ends_at: string | null; grace_ends_at: string | null };
}

export interface AgencyOverview {
    accounts: { id: string; name: string; billing_status: string | null; can_manage: boolean }[];
    clients: AgencyClient[];
    totals: { clients: number; money_by_currency: Record<string, number>; critical_insights: number; disconnected: number; over_limit: number };
}

export interface AgencyTemplate {
    id: string;
    account_id: string;
    kind: PortableKind;
    name: string;
    description: string | null;
    source_workspace: string | null;
    includes: { templates: number; segments: number };
    warnings: string[];
    created_at: string | null;
    updated_at: string | null;
}

export interface ApplyResult {
    workspace_id: string;
    workspace: string;
    ok: boolean;
    kind?: PortableKind;
    id?: string;
    name?: string;
    warnings?: string[];
    error?: string;
}

export interface ReportSummary {
    id: string;
    period: string;
    month: string;
    currency: string;
    generated: number;
    change_pct: number | null;
    summary_source: 'ai' | 'fallback' | null;
    has_pdf: boolean;
    sent_at: string | null;
    recipients: { count: number; masked: string[] };
    created_at: string | null;
    summary?: string | null;
}

export interface AgencyReportRow {
    workspace_id: string;
    workspace: string;
    period: string;
    owners: number;
    report: ReportSummary | null;
}

export interface DomainRecord {
    type: 'CNAME' | 'TXT';
    name: string;
    value: string;
}

export interface CustomDomain {
    id: string;
    host: string;
    purpose: 'panel' | 'links' | 'booking' | 'tracking';
    workspace: string | null;
    status: 'pending' | 'verified' | 'failed';
    verified_at: string | null;
    last_error: string | null;
    records: DomainRecord[];
}

export interface BrandingForm {
    brand_name: string | null;
    logo_url?: string | null;
    primary_color: string | null;
    sender_name: string | null;
    sender_email: string | null;
    support_email: string | null;
    white_label: boolean;
}

export interface AgencyBrandingAccount {
    id: string;
    name: string;
    can_manage: boolean;
    branding: BrandingForm;
    sender_verified_in: number;
    workspaces: { id: string; name: string }[];
    domains: CustomDomain[];
    cname_target: string;
}

const base = (ws: string) => `${wsPath(ws)}/agency`;
const keys = {
    overview: (ws: string) => ['ws', ws, 'agency', 'overview'] as const,
    templates: (ws: string) => ['ws', ws, 'agency', 'templates'] as const,
    items: (ws: string, client: string, kind: string) => ['ws', ws, 'agency', 'items', client, kind] as const,
    reports: (ws: string, month: string) => ['ws', ws, 'agency', 'reports', month] as const,
    branding: (ws: string) => ['ws', ws, 'agency', 'branding'] as const,
    clientReports: (ws: string) => ['ws', ws, 'reports'] as const,
};

export function useAgencyOverview(ws: string) {
    return useQuery({ queryKey: keys.overview(ws), queryFn: ({ signal }) => api.get<AgencyOverview>(`${base(ws)}/overview`, signal) });
}

export interface NewAgencyClient {
    account_id: string;
    name: string;
    country: string;
    currency: string;
    timezone: string;
    invite_email?: string;
    invite_role?: 'owner' | 'admin';
}

/** Alta de un cliente de la agencia: un espacio nuevo en su cuenta y, si se da, la invitación al dueño. */
export function useCreateAgencyClient(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (body: NewAgencyClient) => api.post<{ workspace_id: string; name: string }>(`${base(ws)}/clients`, body),
        onSuccess: () => {
            void qc.invalidateQueries({ queryKey: keys.overview(ws) });
            void qc.invalidateQueries({ queryKey: ['me'] });
        },
    });
}

export function useEnterClient(ws: string) {
    return useMutation({
        mutationFn: ({ client, reason }: { client: string; reason?: string }) =>
            api.post<{ workspace_id: string; support_session: string | null }>(`${base(ws)}/clients/${encodeURIComponent(client)}/enter`, { reason }),
    });
}

export function useClientItems(ws: string, client: string, kind: PortableKind) {
    return useQuery({
        queryKey: keys.items(ws, client, kind),
        enabled: client !== '',
        queryFn: ({ signal }) =>
            api.get<{ items: { id: string; name: string; detail: string | null }[] }>(`${base(ws)}/clients/${encodeURIComponent(client)}/items?kind=${kind}`, signal),
    });
}

export function useAgencyTemplates(ws: string) {
    return useQuery({ queryKey: keys.templates(ws), queryFn: ({ signal }) => api.get<{ templates: AgencyTemplate[] }>(`${base(ws)}/templates`, signal) });
}

export function useSaveTemplate(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (body: { source_workspace: string; kind: PortableKind; id: string; name?: string; description?: string }) =>
            api.post<{ template: AgencyTemplate }>(`${base(ws)}/templates`, body),
        onSuccess: () => void qc.invalidateQueries({ queryKey: keys.templates(ws) }),
    });
}

export function useApplyTemplate(ws: string) {
    return useMutation({
        mutationFn: ({ template, workspaces, name }: { template: string; workspaces: string[]; name?: string }) =>
            api.post<{ results: ApplyResult[] }>(`${base(ws)}/templates/${encodeURIComponent(template)}/apply`, { workspaces, name }),
    });
}

export function useCopyBetweenClients(ws: string) {
    return useMutation({
        mutationFn: (body: { source_workspace: string; kind: PortableKind; id: string; workspaces: string[] }) =>
            api.post<{ results: ApplyResult[] }>(`${base(ws)}/copy`, body),
    });
}

export function useDeleteTemplate(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (template: string) => api.delete<{ ok: boolean }>(`${base(ws)}/templates/${encodeURIComponent(template)}`),
        onSuccess: () => void qc.invalidateQueries({ queryKey: keys.templates(ws) }),
    });
}

export function useAgencyReports(ws: string, month: string) {
    return useQuery({
        queryKey: keys.reports(ws, month),
        queryFn: ({ signal }) => api.get<{ month: string; clients: AgencyReportRow[] }>(`${base(ws)}/reports?month=${month}`, signal),
    });
}

export function useAgencyGenerateReport(ws: string, month: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: ({ client, send }: { client: string; send: boolean }) =>
            api.post<{ report: ReportSummary; sent: { status: string; count: number } | null }>(`${base(ws)}/reports/${encodeURIComponent(client)}/generate`, { month, send }),
        onSuccess: () => void qc.invalidateQueries({ queryKey: keys.reports(ws, month) }),
    });
}

export function useAgencySendReport(ws: string, month: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: ({ client, report }: { client: string; report: string }) =>
            api.post<{ sent: { status: string; count: number } }>(`${base(ws)}/reports/${encodeURIComponent(client)}/${encodeURIComponent(report)}/send`),
        onSuccess: () => void qc.invalidateQueries({ queryKey: keys.reports(ws, month) }),
    });
}

export const agencyReportPdfUrl = (ws: string, client: string, report: string) =>
    `${base(ws)}/reports/${encodeURIComponent(client)}/${encodeURIComponent(report)}/pdf`;

export function useAgencyBranding(ws: string) {
    return useQuery({ queryKey: keys.branding(ws), queryFn: ({ signal }) => api.get<{ accounts: AgencyBrandingAccount[] }>(`${base(ws)}/branding`, signal) });
}

/** La marca sale en el menú lateral desde /me y el espacio de trabajo: se recargan los dos. */
function refreshBranding(qc: ReturnType<typeof useQueryClient>, ws: string): void {
    void qc.invalidateQueries({ queryKey: ['ws', ws] });
    void qc.invalidateQueries({ queryKey: ['me'] });
}

export function useUpdateBranding(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: ({ account, body }: { account: string; body: Omit<BrandingForm, 'logo_url'> }) =>
            api.put<{ ok: boolean }>(`${base(ws)}/branding/${encodeURIComponent(account)}`, body),
        onSuccess: () => refreshBranding(qc, ws),
    });
}

export function useUploadLogo(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: ({ account, file }: { account: string; file: File }) => {
            const form = new FormData();
            form.append('logo', file);
            return postMultipart<{ logo_url: string }>(`${base(ws)}/branding/${encodeURIComponent(account)}/logo`, form);
        },
        onSuccess: () => refreshBranding(qc, ws),
    });
}

export function useDeleteLogo(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (account: string) => api.delete<{ ok: boolean }>(`${base(ws)}/branding/${encodeURIComponent(account)}/logo`),
        onSuccess: () => refreshBranding(qc, ws),
    });
}

export function useAddDomain(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (body: { account_id: string; host: string; purpose: CustomDomain['purpose']; workspace_id?: string }) =>
            api.post<{ domain: CustomDomain }>(`${base(ws)}/domains`, body),
        onSuccess: () => void qc.invalidateQueries({ queryKey: keys.branding(ws) }),
    });
}

export function useVerifyDomain(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (domain: string) => api.post<{ domain: CustomDomain }>(`${base(ws)}/domains/${encodeURIComponent(domain)}/verify`),
        onSuccess: () => void qc.invalidateQueries({ queryKey: keys.branding(ws) }),
    });
}

export function useDeleteDomain(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (domain: string) => api.delete<{ ok: boolean }>(`${base(ws)}/domains/${encodeURIComponent(domain)}`),
        onSuccess: () => void qc.invalidateQueries({ queryKey: keys.branding(ws) }),
    });
}

/* Vista del cliente: sus reportes mensuales. */
export function useClientReports(ws: string) {
    return useQuery({
        queryKey: keys.clientReports(ws),
        queryFn: ({ signal }) => api.get<{ reports: ReportSummary[]; can_manage: boolean; last_month: string }>(`${wsPath(ws)}/reports`, signal),
    });
}

export function useGenerateClientReport(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: ({ month, send }: { month: string; send: boolean }) =>
            api.post<{ report: ReportSummary; sent: { status: string; count: number } | null }>(`${wsPath(ws)}/reports/generate`, { month, send }),
        onSuccess: () => void qc.invalidateQueries({ queryKey: keys.clientReports(ws) }),
    });
}

export function useResendClientReport(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (report: string) => api.post<{ sent: { status: string; count: number } }>(`${wsPath(ws)}/reports/${encodeURIComponent(report)}/send`),
        onSuccess: () => void qc.invalidateQueries({ queryKey: keys.clientReports(ws) }),
    });
}

export const clientReportPdfUrl = (ws: string, report: string) => `${wsPath(ws)}/reports/${encodeURIComponent(report)}/pdf`;

export interface PublicBranding {
    name: string;
    logo_url: string | null;
    primary_color: string | null;
    white_label: boolean;
}

/** La marca de la pantalla de ingreso según el dominio (marca blanca). */
export function usePublicBranding(enabled: boolean) {
    return useQuery({
        queryKey: ['public', 'branding'],
        enabled,
        staleTime: Infinity,
        retry: false,
        queryFn: ({ signal }) => api.get<{ branding: PublicBranding }>('/api/public/branding', signal),
    });
}
