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
    plans: (PlanInfo & { public: boolean })[];
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
