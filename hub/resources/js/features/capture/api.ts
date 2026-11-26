import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { wsPath } from '@/api/queries';
import { api } from '@/lib/api';
import { postMultipart } from '../contacts/api';
import type {
    CaptureOptions,
    GoogleWebhookCreated,
    LandingPage,
    LandingPageDetail,
    LandingPagePatch,
    LandingStats,
    LandingSubmission,
    LeadAdForm,
    LeadAdFormMapping,
    LeadAdLeadRow,
    LeadAdPlatform,
    LeadAdsState,
    MetaPageOption,
    WhatsAppLink,
    WhatsAppLinkInput,
    WhatsAppLinkKind,
    WhatsAppLinkStats,
} from './types';

/**
 * Hooks de TanStack Query de la captación sin tienda (routes/api/capture.php): páginas de captura y su QR, chats de
 * WhatsApp con atribución y leads de anuncios. Sin pantallas: las arma el sistema visual.
 */

export const captureKeys = {
    all: (ws: string) => ['ws', ws, 'capture'] as const,
    options: (ws: string) => ['ws', ws, 'capture', 'options'] as const,
    pages: (ws: string, status: string) => ['ws', ws, 'capture', 'pages', status] as const,
    page: (ws: string, id: string) => ['ws', ws, 'capture', 'page', id] as const,
    pageStats: (ws: string, id: string, days: number) => ['ws', ws, 'capture', 'page', id, 'stats', days] as const,
    submissions: (ws: string, id: string) => ['ws', ws, 'capture', 'page', id, 'submissions'] as const,
    waLinks: (ws: string, kind: WhatsAppLinkKind) => ['ws', ws, 'capture', 'wa-links', kind] as const,
    waLinkStats: (ws: string, id: string, days: number) => ['ws', ws, 'capture', 'wa-link', id, 'stats', days] as const,
    leadAds: (ws: string) => ['ws', ws, 'capture', 'lead-ads'] as const,
    metaPages: (ws: string) => ['ws', ws, 'capture', 'lead-ads', 'meta-pages'] as const,
    forms: (ws: string, source: string) => ['ws', ws, 'capture', 'lead-ads', 'forms', source] as const,
    adLeads: (ws: string, platform: string, status: string) => ['ws', ws, 'capture', 'lead-ads', 'leads', platform, status] as const,
};

const base = (ws: string) => `${wsPath(ws)}/capture`;
const enc = encodeURIComponent;

/** URL para descargar un QR (`format` svg o png) desde las rutas que devuelve la API. */
export function qrDownloadUrl(path: string, size?: number): string {
    return `${path}&download=1${size ? `&size=${size}` : ''}`;
}

export function useCaptureOptions(ws: string) {
    return useQuery({
        queryKey: captureKeys.options(ws),
        queryFn: ({ signal }) => api.get<CaptureOptions>(`${base(ws)}/options`, signal),
        staleTime: 60_000,
    });
}

export function useLandingPages(ws: string, status = '') {
    return useQuery({
        queryKey: captureKeys.pages(ws, status),
        queryFn: ({ signal }) => api.get<{ data: LandingPage[]; from: string }>(`${base(ws)}/pages${status ? `?status=${enc(status)}` : ''}`, signal),
    });
}

export function useLandingPage(ws: string, id: string | null) {
    return useQuery({
        queryKey: captureKeys.page(ws, id ?? ''),
        queryFn: ({ signal }) => api.get<LandingPageDetail>(`${base(ws)}/pages/${enc(id ?? '')}`, signal),
        enabled: id !== null && id !== '',
    });
}

export function useLandingStats(ws: string, id: string, days = 30) {
    return useQuery({
        queryKey: captureKeys.pageStats(ws, id, days),
        queryFn: ({ signal }) => api.get<LandingStats>(`${base(ws)}/pages/${enc(id)}/stats?days=${days}`, signal),
        placeholderData: keepPreviousData,
    });
}

export function useLandingSubmissions(ws: string, id: string) {
    return useQuery({
        queryKey: captureKeys.submissions(ws, id),
        queryFn: ({ signal }) => api.get<{ data: LandingSubmission[] }>(`${base(ws)}/pages/${enc(id)}/submissions`, signal),
    });
}

/** Crear, editar, publicar, despublicar, archivar y las imágenes: todo refresca la captación del espacio de trabajo. */
export function useLandingMutations(ws: string) {
    const qc = useQueryClient();
    const done = () => void qc.invalidateQueries({ queryKey: captureKeys.all(ws) });
    return {
        create: useMutation({
            mutationFn: (data: { name: string; title?: string; slug?: string }) => api.post<LandingPageDetail>(`${base(ws)}/pages`, data),
            onSuccess: done,
        }),
        update: useMutation({
            mutationFn: ({ id, data }: { id: string; data: LandingPagePatch }) => api.patch<LandingPageDetail>(`${base(ws)}/pages/${enc(id)}`, data),
            onSuccess: done,
        }),
        publish: useMutation({
            mutationFn: (id: string) => api.post<LandingPageDetail>(`${base(ws)}/pages/${enc(id)}/publish`),
            onSuccess: done,
        }),
        unpublish: useMutation({
            mutationFn: (id: string) => api.post<LandingPageDetail>(`${base(ws)}/pages/${enc(id)}/unpublish`),
            onSuccess: done,
        }),
        remove: useMutation({
            mutationFn: (id: string) => api.delete<{ archived?: boolean; deleted?: boolean }>(`${base(ws)}/pages/${enc(id)}`),
            onSuccess: done,
        }),
        uploadImage: useMutation({
            mutationFn: ({ id, kind, file }: { id: string; kind: 'logo' | 'image'; file: File }) => {
                const form = new FormData();
                form.append('kind', kind);
                form.append('file', file);
                return postMultipart<LandingPageDetail>(`${base(ws)}/pages/${enc(id)}/images`, form);
            },
            onSuccess: done,
        }),
        deleteImage: useMutation({
            mutationFn: ({ id, kind }: { id: string; kind: 'logo' | 'image' }) => api.delete<LandingPageDetail>(`${base(ws)}/pages/${enc(id)}/images/${kind}`),
            onSuccess: done,
        }),
    };
}

export function useWhatsAppLinks(ws: string, kind: WhatsAppLinkKind = 'qr') {
    return useQuery({
        queryKey: captureKeys.waLinks(ws, kind),
        queryFn: ({ signal }) => api.get<{ data: WhatsAppLink[]; from: string }>(`${base(ws)}/whatsapp-links?kind=${kind}`, signal),
    });
}

export function useWhatsAppLinkStats(ws: string, id: string, days = 30) {
    return useQuery({
        queryKey: captureKeys.waLinkStats(ws, id, days),
        queryFn: ({ signal }) => api.get<WhatsAppLinkStats>(`${base(ws)}/whatsapp-links/${enc(id)}/stats?days=${days}`, signal),
        placeholderData: keepPreviousData,
    });
}

export function useWhatsAppLinkMutations(ws: string) {
    const qc = useQueryClient();
    const done = () => void qc.invalidateQueries({ queryKey: captureKeys.all(ws) });
    return {
        create: useMutation({
            mutationFn: (data: WhatsAppLinkInput) => api.post<WhatsAppLink>(`${base(ws)}/whatsapp-links`, data),
            onSuccess: done,
        }),
        update: useMutation({
            mutationFn: ({ id, data }: { id: string; data: Partial<WhatsAppLinkInput> }) => api.patch<WhatsAppLink>(`${base(ws)}/whatsapp-links/${enc(id)}`, data),
            onSuccess: done,
        }),
        archive: useMutation({
            mutationFn: (id: string) => api.delete<{ archived: boolean }>(`${base(ws)}/whatsapp-links/${enc(id)}`),
            onSuccess: done,
        }),
    };
}

export function useLeadAds(ws: string) {
    return useQuery({
        queryKey: captureKeys.leadAds(ws),
        queryFn: ({ signal }) => api.get<LeadAdsState>(`${base(ws)}/lead-ads`, signal),
    });
}

/** Las páginas de Facebook que autorizó la conexión de Meta (pide a Meta: solo al abrir el selector). */
export function useMetaPages(ws: string, enabled: boolean) {
    return useQuery({
        queryKey: captureKeys.metaPages(ws),
        queryFn: ({ signal }) => api.get<{ data: MetaPageOption[] }>(`${base(ws)}/lead-ads/meta/pages`, signal),
        enabled,
        staleTime: 60_000,
    });
}

export function useLeadAdForms(ws: string, source: string | null) {
    return useQuery({
        queryKey: captureKeys.forms(ws, source ?? ''),
        queryFn: ({ signal }) => api.get<{ data: LeadAdForm[] }>(`${base(ws)}/lead-ads/sources/${enc(source ?? '')}/forms`, signal),
        enabled: source !== null && source !== '',
    });
}

export function useAdLeads(ws: string, platform: LeadAdPlatform | '' = '', status = '') {
    const qs = new URLSearchParams();
    if (platform !== '') qs.set('platform', platform);
    if (status !== '') qs.set('status', status);
    return useQuery({
        queryKey: captureKeys.adLeads(ws, platform, status),
        queryFn: ({ signal }) => api.get<{ data: LeadAdLeadRow[] }>(`${base(ws)}/lead-ads/leads${qs.size > 0 ? `?${qs.toString()}` : ''}`, signal),
        refetchInterval: 60_000,
    });
}

export function useLeadAdsMutations(ws: string) {
    const qc = useQueryClient();
    const done = () => void qc.invalidateQueries({ queryKey: captureKeys.leadAds(ws) });
    return {
        subscribeMetaPage: useMutation({
            mutationFn: (pageId: string) => api.post<LeadAdsState['meta']['pages'][number]>(`${base(ws)}/lead-ads/meta/pages`, { page_id: pageId }),
            onSuccess: done,
        }),
        unsubscribeMetaPage: useMutation({
            mutationFn: (sourceId: string) => api.delete<LeadAdsState['meta']['pages'][number]>(`${base(ws)}/lead-ads/meta/pages/${enc(sourceId)}`),
            onSuccess: done,
        }),
        saveFormMapping: useMutation({
            mutationFn: ({ source, form, data }: { source: string; form: string; data: Omit<LeadAdFormMapping, 'name'> }) =>
                api.put<{ mapping: LeadAdFormMapping }>(`${base(ws)}/lead-ads/sources/${enc(source)}/forms/${enc(form)}`, data),
            onSuccess: done,
        }),
        /** Genera el webhook de Google o rota su clave; la clave solo viene en esta respuesta. */
        enableGoogle: useMutation({
            mutationFn: () => api.post<GoogleWebhookCreated>(`${base(ws)}/lead-ads/google`),
            onSuccess: done,
        }),
        disableGoogle: useMutation({
            mutationFn: () => api.delete<LeadAdsState['google']>(`${base(ws)}/lead-ads/google`),
            onSuccess: done,
        }),
        retryLead: useMutation({
            mutationFn: (id: string) => api.post<{ status: string }>(`${base(ws)}/lead-ads/leads/${enc(id)}/retry`),
            onSuccess: () => void qc.invalidateQueries({ queryKey: captureKeys.all(ws) }),
        }),
    };
}
