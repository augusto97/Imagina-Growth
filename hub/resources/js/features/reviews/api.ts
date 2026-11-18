import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { wsPath } from '@/api/queries';
import { api } from '@/lib/api';
import { postMultipart } from '@/features/contacts/api';
import type {
    ImportResult,
    Page,
    RejectReason,
    Review,
    ReviewFilters,
    ReviewRequestRow,
    ReviewSettings,
    ReviewSettingsResponse,
    ReviewSummary,
    SurveyKind,
    SurveyReport,
    SurveySettings,
} from './types';

/* Reseñas, NPS y CSAT (M15). */

export const reviewKeys = {
    all: (ws: string) => ['ws', ws, 'reviews'] as const,
    list: (ws: string, f: ReviewFilters) => ['ws', ws, 'reviews', 'list', f] as const,
    summary: (ws: string) => ['ws', ws, 'reviews', 'summary'] as const,
    requests: (ws: string, status: string, page: number) => ['ws', ws, 'reviews', 'requests', status, page] as const,
    settings: (ws: string) => ['ws', ws, 'reviews', 'settings'] as const,
    survey: (ws: string, kind: SurveyKind) => ['ws', ws, 'reviews', 'survey', kind] as const,
    report: (ws: string, kind: SurveyKind, days: number) => ['ws', ws, 'reviews', 'report', kind, days] as const,
};

/** Query string de los filtros (sin los vacíos). */
export function filterQuery(f: ReviewFilters): string {
    const p = new URLSearchParams();
    if (f.status) p.set('status', f.status);
    if (f.rating) p.set('rating', String(f.rating));
    if (f.source) p.set('source', f.source);
    if (f.media) p.set('media', '1');
    if (f.flagged) p.set('flagged', '1');
    if (f.q && f.q.trim() !== '') p.set('q', f.q.trim());
    if (f.page && f.page > 1) p.set('page', String(f.page));
    const s = p.toString();
    return s === '' ? '' : `?${s}`;
}

export function useReviews(ws: string, filters: ReviewFilters) {
    return useQuery({
        queryKey: reviewKeys.list(ws, filters),
        queryFn: ({ signal }) => api.get<Page<Review>>(`${wsPath(ws)}/reviews${filterQuery(filters)}`, signal),
        placeholderData: keepPreviousData,
    });
}

export function useReviewSummary(ws: string) {
    return useQuery({ queryKey: reviewKeys.summary(ws), queryFn: ({ signal }) => api.get<ReviewSummary>(`${wsPath(ws)}/reviews/summary`, signal) });
}

export function useReviewRequests(ws: string, status: string, page: number) {
    return useQuery({
        queryKey: reviewKeys.requests(ws, status, page),
        queryFn: ({ signal }) => api.get<Page<ReviewRequestRow>>(`${wsPath(ws)}/reviews/requests?page=${page}${status ? `&status=${status}` : ''}`, signal),
        placeholderData: keepPreviousData,
    });
}

export function useModeration(ws: string) {
    const qc = useQueryClient();
    const done = () => void qc.invalidateQueries({ queryKey: reviewKeys.all(ws) });
    return {
        approve: useMutation({ mutationFn: (id: string) => api.post<Review>(`${wsPath(ws)}/reviews/${id}/approve`), onSuccess: done }),
        reject: useMutation({ mutationFn: (v: { id: string; reason: RejectReason }) => api.post<Review>(`${wsPath(ws)}/reviews/${v.id}/reject`, { reason: v.reason }), onSuccess: done }),
        reply: useMutation({ mutationFn: (v: { id: string; text: string | null }) => api.put<Review>(`${wsPath(ws)}/reviews/${v.id}/reply`, { text: v.text }), onSuccess: done }),
        bulk: useMutation({
            mutationFn: (v: { action: 'approve' | 'reject'; ids: string[]; reason?: RejectReason }) => api.post<{ updated: number }>(`${wsPath(ws)}/reviews/bulk`, v),
            onSuccess: done,
        }),
    };
}

export function useReviewSettings(ws: string) {
    return useQuery({ queryKey: reviewKeys.settings(ws), queryFn: ({ signal }) => api.get<ReviewSettingsResponse>(`${wsPath(ws)}/reviews/settings`, signal) });
}

export function useSaveReviewSettings(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (s: ReviewSettings) => api.put<ReviewSettingsResponse>(`${wsPath(ws)}/reviews/settings`, s),
        onSuccess: (data) => qc.setQueryData(reviewKeys.settings(ws), data),
    });
}

export function useSurvey(ws: string, kind: SurveyKind) {
    return useQuery({ queryKey: reviewKeys.survey(ws, kind), queryFn: ({ signal }) => api.get<SurveySettings>(`${wsPath(ws)}/reviews/surveys/${kind}`, signal) });
}

export function useSaveSurvey(ws: string, kind: SurveyKind) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (s: Omit<SurveySettings, 'id' | 'kind' | 'scale'>) => api.put<SurveySettings>(`${wsPath(ws)}/reviews/surveys/${kind}`, s),
        onSuccess: (data) => qc.setQueryData(reviewKeys.survey(ws, kind), data),
    });
}

export function useSurveyReport(ws: string, kind: SurveyKind, days: number) {
    return useQuery({
        queryKey: reviewKeys.report(ws, kind, days),
        queryFn: ({ signal }) => api.get<SurveyReport>(`${wsPath(ws)}/reviews/surveys/${kind}/report?days=${days}`, signal),
        placeholderData: keepPreviousData,
    });
}

export function useImportReviews(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (v: { file: File; format: 'csv' | 'judgeme'; siteId: string | null }) => {
            const form = new FormData();
            form.append('file', v.file);
            form.append('format', v.format);
            if (v.siteId) form.append('site_id', v.siteId);
            return postMultipart<ImportResult>(`${wsPath(ws)}/reviews/imports`, form);
        },
        onSuccess: () => void qc.invalidateQueries({ queryKey: reviewKeys.all(ws) }),
    });
}
