import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { wsPath } from '@/api/queries';
import { api } from '@/lib/api';

/* Plantillas y contenido (modulos.md M08). Rutas en hub/routes/api/templates.php. */

export type TemplateChannel = 'email' | 'sms' | 'whatsapp';
export type TemplateCategory = 'marketing' | 'utility' | 'transactional';
export type TemplateStatus = 'draft' | 'ready' | 'archived';

export const CHANNELS: TemplateChannel[] = ['email', 'sms', 'whatsapp'];
export const CATEGORIES: TemplateCategory[] = ['marketing', 'utility', 'transactional'];

export interface TemplateCheck {
    level: 'error' | 'warning';
    code: string;
    message: string;
}

export interface TemplateSummary {
    id: string;
    channel: TemplateChannel;
    name: string;
    category: TemplateCategory;
    subject: string | null;
    /** Asunto del correo o inicio del SMS, para la lista. */
    excerpt: string | null;
    status: TemplateStatus;
    library_key: string | null;
    wa_template_name: string | null;
    created_at: string | null;
    updated_at: string | null;
}

/** Mapeo de cada parámetro de la plantilla de WhatsApp a una expresión Liquid. */
export interface WaVariables {
    header?: Record<string, string>;
    header_media?: string | null;
    body?: Record<string, string>;
    buttons?: Record<string, string>;
}

export interface TemplateDetail extends TemplateSummary {
    preheader: string | null;
    body_mjml: string | null;
    body_html: string | null;
    body_text: string | null;
    editor_state: string | null;
    wa_template_id: string | null;
    variables: WaVariables;
    checks: TemplateCheck[];
}

export type TemplateInput = Partial<
    Pick<TemplateDetail, 'name' | 'category' | 'subject' | 'preheader' | 'body_mjml' | 'body_html' | 'body_text' | 'editor_state' | 'wa_template_id' | 'variables'>
> & { status?: 'draft' | 'ready'; channel?: TemplateChannel };

export interface WaComponent {
    type: string;
    sub_type?: string;
    index?: number;
    parameters: Record<string, unknown>[];
}

export interface PreviewResult {
    ok: boolean;
    subject: string | null;
    preheader: string | null;
    html: string | null;
    text: string | null;
    whatsapp: { name: string; language: string; components: WaComponent[] } | null;
    sms: { encoding: 'GSM-7' | 'UCS-2'; length: number; segments: number } | null;
    checks: TemplateCheck[];
    contact: { id: string; name: string } | null;
}

export interface LibraryEntry {
    key: string;
    name: string;
    flow: number;
    channel: 'email' | 'sms';
    category: TemplateCategory;
    description: string;
    subject: string | null;
    preheader: string | null;
    text: string | null;
    has_industry_copy: boolean;
    installed_id: string | null;
}

export interface LibraryResponse {
    industry: string | null;
    industries: string[];
    data: LibraryEntry[];
}

export interface VariableItem {
    expr: string;
    label: string;
    example: string;
    phase?: number;
}

export interface VariableGroup {
    key: string;
    label: string;
    items: VariableItem[];
}

export interface WaSlots {
    header: string[];
    header_media: 'image' | 'video' | 'document' | null;
    body: string[];
    buttons: { index: number; type: string; text: string; needs_value: boolean }[];
}

export interface WaSource {
    id: string;
    name: string;
    language: string;
    category: string;
    status: string;
    slots: WaSlots;
    components: { type: string; format?: string; text?: string; buttons?: { type: string; text?: string; url?: string }[] }[];
}

export interface BusinessProfile {
    name: string;
    address: string;
    contact: string;
    whatsapp: string;
    logo_url: string;
}

const base = (ws: string) => `${wsPath(ws)}/templates`;
const keys = {
    all: (ws: string) => ['ws', ws, 'templates'] as const,
    list: (ws: string, filters: object) => ['ws', ws, 'templates', 'list', filters] as const,
    one: (ws: string, id: string) => ['ws', ws, 'templates', 'one', id] as const,
    library: (ws: string, industry: string | null) => ['ws', ws, 'templates', 'library', industry] as const,
};

export function useTemplates(ws: string, filters: { channel?: TemplateChannel | ''; status?: TemplateStatus | 'all' | ''; q?: string } = {}) {
    const params = new URLSearchParams();
    if (filters.channel) params.set('channel', filters.channel);
    if (filters.status) params.set('status', filters.status);
    if (filters.q) params.set('q', filters.q);
    const qs = params.toString();
    return useQuery({
        queryKey: keys.list(ws, filters),
        queryFn: ({ signal }) => api.get<{ data: TemplateSummary[] }>(`${base(ws)}${qs ? `?${qs}` : ''}`, signal),
        select: (r) => r.data,
    });
}

export function useTemplate(ws: string, id: string) {
    return useQuery({
        queryKey: keys.one(ws, id),
        queryFn: ({ signal }) => api.get<TemplateDetail>(`${base(ws)}/${id}`, signal),
        staleTime: Infinity,
    });
}

export function useCreateTemplate(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (data: TemplateInput & { channel: TemplateChannel; name: string }) => api.post<TemplateDetail>(base(ws), data),
        onSuccess: (t) => {
            qc.setQueryData(keys.one(ws, t.id), t);
            void qc.invalidateQueries({ queryKey: keys.all(ws) });
        },
    });
}

export function useUpdateTemplate(ws: string, id: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (data: TemplateInput) => api.patch<TemplateDetail>(`${base(ws)}/${id}`, data),
        onSuccess: (t) => {
            qc.setQueryData(keys.one(ws, id), t);
            void qc.invalidateQueries({ queryKey: ['ws', ws, 'templates', 'list'] });
            void qc.invalidateQueries({ queryKey: ['ws', ws, 'templates', 'library'] });
        },
    });
}

export function useDuplicateTemplate(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (id: string) => api.post<TemplateDetail>(`${base(ws)}/${id}/duplicate`),
        onSuccess: () => void qc.invalidateQueries({ queryKey: keys.all(ws) }),
    });
}

export function useArchiveTemplate(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (id: string) => api.delete<{ ok: true }>(`${base(ws)}/${id}`),
        onSuccess: () => void qc.invalidateQueries({ queryKey: keys.all(ws) }),
    });
}

export function useRestoreTemplate(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (id: string) => api.patch<TemplateDetail>(`${base(ws)}/${id}`, { status: 'draft' }),
        onSuccess: () => void qc.invalidateQueries({ queryKey: keys.all(ws) }),
    });
}

/** Vista previa de una plantilla guardada (con datos de ejemplo o un contacto real). */
export function usePreviewTemplate(ws: string, id: string) {
    return useMutation({
        mutationFn: (contactId: string | null) => api.post<PreviewResult>(`${base(ws)}/${id}/preview`, contactId ? { contact_id: contactId } : {}),
    });
}

/** Vista previa de lo que hay en el editor, sin guardar. */
export function usePreviewDraft(ws: string) {
    return useMutation({
        mutationFn: (draft: TemplateInput & { channel: TemplateChannel; contact_id?: string | null }) => api.post<PreviewResult>(`${base(ws)}/preview`, draft),
    });
}

export function useVariableCatalog(ws: string) {
    return useQuery({
        queryKey: ['ws', ws, 'templates', 'variables'],
        queryFn: ({ signal }) => api.get<{ groups: VariableGroup[] }>(`${base(ws)}/variables`, signal),
        select: (r) => r.groups,
        staleTime: Infinity,
    });
}

export function useLibrary(ws: string, industry: string | null) {
    return useQuery({
        queryKey: keys.library(ws, industry),
        queryFn: ({ signal }) => api.get<LibraryResponse>(`${base(ws)}/library${industry ? `?industry=${encodeURIComponent(industry)}` : ''}`, signal),
    });
}

export function useLibraryPreview(ws: string, key: string | null, industry: string | null) {
    return useQuery({
        queryKey: ['ws', ws, 'templates', 'library-preview', key, industry],
        queryFn: ({ signal }) => api.get<PreviewResult>(`${base(ws)}/library/${key}/preview${industry ? `?industry=${encodeURIComponent(industry)}` : ''}`, signal),
        enabled: key !== null,
        staleTime: 5 * 60_000,
    });
}

export function useInstallFromLibrary(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: ({ key, industry, copy }: { key: string; industry: string | null; copy?: boolean }) =>
            api.post<TemplateDetail>(`${base(ws)}/library/${key}`, { industry, copy: copy ?? false }),
        onSuccess: (t) => {
            qc.setQueryData(keys.one(ws, t.id), t);
            void qc.invalidateQueries({ queryKey: keys.all(ws) });
        },
    });
}

export function useWaSources(ws: string, enabled = true) {
    return useQuery({
        queryKey: ['ws', ws, 'templates', 'wa-sources'],
        queryFn: ({ signal }) => api.get<{ data: WaSource[] }>(`${base(ws)}/wa-sources`, signal),
        select: (r) => r.data,
        enabled,
    });
}

export function useBusinessProfile(ws: string) {
    return useQuery({
        queryKey: ['ws', ws, 'templates', 'business-profile'],
        queryFn: ({ signal }) => api.get<BusinessProfile>(`${base(ws)}/business-profile`, signal),
    });
}

export function useUpdateBusinessProfile(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (data: Partial<BusinessProfile>) => api.put<BusinessProfile>(`${base(ws)}/business-profile`, data),
        onSuccess: (p) => qc.setQueryData(['ws', ws, 'templates', 'business-profile'], p),
    });
}

/** Búsqueda de contactos para la vista previa (API de Contactos, M03). */
export function useContactSearch(ws: string, q: string, enabled: boolean) {
    return useQuery({
        queryKey: ['ws', ws, 'templates', 'contact-search', q],
        queryFn: ({ signal }) =>
            api.get<{ data: { id: string; name: string; email: string | null; city: string | null }[] }>(`${wsPath(ws)}/contacts?q=${encodeURIComponent(q)}&per_page=10`, signal),
        select: (r) => r.data,
        enabled: enabled && q.trim().length >= 2,
        staleTime: 30_000,
    });
}
