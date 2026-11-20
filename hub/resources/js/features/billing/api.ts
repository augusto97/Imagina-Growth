import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { wsPath } from '@/api/queries';
import { api } from '@/lib/api';

/* Plan y facturación (modulos.md M21). Rutas en hub/routes/api/billing.php. */

export type MeterState = 'ok' | 'warning' | 'critical';
export type BillingStatus = 'trial' | 'active' | 'grace' | 'suspended';
export type InvoiceStatus = 'draft' | 'sent' | 'paid' | 'overdue' | 'void';

export interface Meter {
    metric: string;
    value: number;
    limit: number | null;
    pct: number | null;
    state: MeterState;
}

export interface Usage {
    period: string;
    computed_at: string | null;
    meters: Meter[];
}

export interface PlanInfo {
    id: string;
    name: string;
    metric: 'orders' | 'contacts';
    included: number;
    modules: string[];
    limits: Record<string, number>;
    price_usd: string;
    price_cop: string | null;
}

export interface InvoiceLine {
    concept: string;
    quantity: number;
    unit_price: string;
    total: string;
}

export interface Invoice {
    id: string;
    number: string;
    period: string;
    lines: InvoiceLine[];
    total: string;
    currency: string;
    status: InvoiceStatus;
    payment_url: string | null;
    due_at: string | null;
    paid_at: string | null;
    sent_at: string | null;
    has_pdf: boolean;
    notes: string | null;
}

export interface BillingOverview {
    account: {
        name: string;
        billing_status: BillingStatus;
        grace_ends_at: string | null;
        trial_ends_at: string | null;
    };
    plan: PlanInfo | null;
    plan_source: 'assigned' | 'default' | 'unrestricted';
    usage: Usage;
    modules: { key: string; enabled: boolean; in_plan: boolean }[];
    plans: PlanInfo[];
    pending_request: {
        id: string;
        to_plan: string;
        created_at: string | null;
    } | null;
    invoices: Invoice[] | null;
    can_manage_billing: boolean;
}

const key = (ws: string) => ['ws', ws, 'billing'] as const;

export function useBilling(ws: string) {
    return useQuery({
        queryKey: key(ws),
        queryFn: ({ signal }) => api.get<BillingOverview>(`${wsPath(ws)}/billing`, signal),
    });
}

export function useRefreshUsage(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: () => api.post<{ usage: Usage }>(`${wsPath(ws)}/billing/usage/refresh`),
        onSuccess: (data) => qc.setQueryData<BillingOverview>(key(ws), (old) => (old ? { ...old, usage: data.usage } : old)),
    });
}

export function useRequestPlan(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (body: { plan_id: string; note?: string }) => api.post<{ id: string; to_plan: string }>(`${wsPath(ws)}/billing/plan-request`, body),
        onSuccess: () => void qc.invalidateQueries({ queryKey: key(ws) }),
    });
}

export const invoicePdfUrl = (ws: string, invoice: string) => `${wsPath(ws)}/billing/invoices/${encodeURIComponent(invoice)}/pdf`;
