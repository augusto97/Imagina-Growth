import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { wsPath } from '@/api/queries';
import { api } from '@/lib/api';
import type { Affiliate, AffiliateDetail, AffiliateInput, Conversion, ConversionStatus, Paginated, Payout, Program, ProgramInput, Referral, ReferralList, ReferralStatus, Report } from './types';

export const referralKeys = {
    all: (ws: string) => ['ws', ws, 'referrals'] as const,
    program: (ws: string) => ['ws', ws, 'referrals', 'program'] as const,
    list: (ws: string, filter: ReferralFilter) => ['ws', ws, 'referrals', 'list', filter] as const,
    report: (ws: string, from: string, to: string) => ['ws', ws, 'referrals', 'report', from, to] as const,
    affiliates: (ws: string) => ['ws', ws, 'referrals', 'affiliates'] as const,
    affiliate: (ws: string, id: string) => ['ws', ws, 'referrals', 'affiliates', id] as const,
    conversions: (ws: string, status: ConversionStatus | '', page: number) => ['ws', ws, 'referrals', 'conversions', status, page] as const,
    payouts: (ws: string) => ['ws', ws, 'referrals', 'payouts'] as const,
};

export interface ReferralFilter {
    status: ReferralStatus | '';
    review: boolean;
    page: number;
}

const base = (ws: string) => wsPath(ws);

export function useProgram(ws: string) {
    return useQuery({ queryKey: referralKeys.program(ws), queryFn: ({ signal }) => api.get<Program>(`${base(ws)}/referrals/program`, signal) });
}

export function useSaveProgram(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (input: ProgramInput) => api.put<Program>(`${base(ws)}/referrals/program`, input),
        onSuccess: (program) => qc.setQueryData(referralKeys.program(ws), program),
    });
}

export function useReferrals(ws: string, filter: ReferralFilter) {
    const params = new URLSearchParams({ page: String(filter.page) });
    if (filter.status !== '') params.set('status', filter.status);
    if (filter.review) params.set('review', 'pending');
    return useQuery({
        queryKey: referralKeys.list(ws, filter),
        queryFn: ({ signal }) => api.get<ReferralList>(`${base(ws)}/referrals?${params.toString()}`, signal),
        placeholderData: (previous) => previous,
    });
}

export function useReviewReferral(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: ({ id, decision }: { id: string; decision: 'approve' | 'reject' }) => api.post<Referral>(`${base(ws)}/referrals/${encodeURIComponent(id)}/${decision}`),
        onSuccess: () => void qc.invalidateQueries({ queryKey: referralKeys.all(ws) }),
    });
}

export function useReport(ws: string, from: string, to: string) {
    return useQuery({
        queryKey: referralKeys.report(ws, from, to),
        queryFn: ({ signal }) => api.get<Report>(`${base(ws)}/referrals/report?from=${from}&to=${to}`, signal),
        placeholderData: (previous) => previous,
    });
}

export function useAffiliates(ws: string) {
    return useQuery({ queryKey: referralKeys.affiliates(ws), queryFn: async ({ signal }) => (await api.get<{ data: Affiliate[] }>(`${base(ws)}/affiliates`, signal)).data });
}

export function useAffiliate(ws: string, id: string | undefined) {
    return useQuery({
        queryKey: referralKeys.affiliate(ws, id ?? ''),
        queryFn: ({ signal }) => api.get<AffiliateDetail>(`${base(ws)}/affiliates/${encodeURIComponent(id ?? '')}`, signal),
        enabled: id !== undefined,
    });
}

export function useAffiliateActions(ws: string) {
    const qc = useQueryClient();
    const refresh = () => void qc.invalidateQueries({ queryKey: referralKeys.all(ws) });
    const one = (id: string) => `${base(ws)}/affiliates/${encodeURIComponent(id)}`;
    return {
        create: useMutation({ mutationFn: (input: AffiliateInput) => api.post<Affiliate>(`${base(ws)}/affiliates`, input), onSuccess: refresh }),
        update: useMutation({ mutationFn: ({ id, input }: { id: string; input: Partial<AffiliateInput> }) => api.patch<Affiliate>(one(id), input), onSuccess: refresh }),
        archive: useMutation({ mutationFn: (id: string) => api.delete<{ ok: boolean }>(one(id)), onSuccess: refresh }),
        portalLink: useMutation({
            mutationFn: ({ id, email }: { id: string; email: boolean }) => api.post<{ url: string; sent: boolean; reason: string | null }>(`${one(id)}/portal-link`, { email }),
            onSuccess: refresh,
        }),
        storeCoupon: useMutation({
            mutationFn: ({ id, code, kind, amount }: { id: string; code: string; kind: string; amount: number | null }) => api.post<Affiliate>(`${one(id)}/store-coupon`, { code, kind, amount }),
            onSuccess: refresh,
        }),
    };
}

export function useConversions(ws: string, status: ConversionStatus | '', page: number) {
    const params = new URLSearchParams({ page: String(page) });
    if (status !== '') params.set('status', status);
    return useQuery({
        queryKey: referralKeys.conversions(ws, status, page),
        queryFn: ({ signal }) => api.get<Paginated<Conversion>>(`${base(ws)}/affiliate-conversions?${params.toString()}`, signal),
        placeholderData: (previous) => previous,
    });
}

export function usePayouts(ws: string, enabled: boolean) {
    return useQuery({
        queryKey: referralKeys.payouts(ws),
        queryFn: async ({ signal }) => (await api.get<{ data: Payout[] }>(`${base(ws)}/affiliate-conversions/payouts`, signal)).data,
        enabled,
    });
}

export function useMarkPaid(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (ids: string[]) => api.post<{ updated: number }>(`${base(ws)}/affiliate-conversions/mark-paid`, { ids }),
        onSuccess: () => void qc.invalidateQueries({ queryKey: referralKeys.all(ws) }),
    });
}

export const payoutsCsvUrl = (ws: string) => `${base(ws)}/affiliate-conversions/payouts.csv`;
