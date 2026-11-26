import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { wsPath } from '@/api/queries';
import { api } from '@/lib/api';
import type { GalleryFilters, GalleryInstallInput, GalleryInstallResult, GalleryResponse, WaLibraryPreview } from './types';

/*
 * Galería de plantillas por objetivo y vertical (modulos.md M08 «Galería»). Rutas en hub/routes/api/templates.php:
 * GET templates/gallery, GET templates/library/{clave}/preview y POST templates/library/{clave} (instalar con un clic).
 */

export const galleryKeys = {
    all: (ws: string) => ['ws', ws, 'templates', 'gallery'] as const,
    list: (ws: string, f: GalleryFilters) => ['ws', ws, 'templates', 'gallery', f] as const,
    waPreview: (ws: string, key: string, industry: string | null) => ['ws', ws, 'templates', 'gallery', 'preview', key, industry] as const,
};

const base = (ws: string) => `${wsPath(ws)}/templates`;

/** Sin `vertical`, la del espacio de trabajo; `vertical: 'all'` muestra todas. */
export function useGallery(ws: string, filters: GalleryFilters = {}, enabled = true) {
    const params = new URLSearchParams();
    if (filters.goal) params.set('goal', filters.goal);
    if (filters.vertical) params.set('vertical', filters.vertical);
    if (filters.channel) params.set('channel', filters.channel);
    if (filters.industry) params.set('industry', filters.industry);
    const qs = params.toString();
    return useQuery({
        queryKey: galleryKeys.list(ws, filters),
        queryFn: ({ signal }) => api.get<GalleryResponse>(`${base(ws)}/gallery${qs ? `?${qs}` : ''}`, signal),
        staleTime: 60_000,
        enabled,
    });
}

/**
 * Vista previa de una de WhatsApp de la galería (el texto propuesto con los datos de ejemplo). Las de correo usan la
 * vista previa de siempre (`useLibraryPreview` de features/templates).
 */
export function useWaGalleryPreview(ws: string, key: string | null, industry: string | null = null) {
    return useQuery({
        queryKey: galleryKeys.waPreview(ws, key ?? '', industry),
        queryFn: ({ signal }) =>
            api.get<WaLibraryPreview>(`${base(ws)}/library/${encodeURIComponent(key ?? '')}/preview${industry ? `?industry=${encodeURIComponent(industry)}` : ''}`, signal),
        enabled: key !== null && key !== '',
        staleTime: 5 * 60_000,
    });
}

/**
 * Instala con un clic (idempotente). Una de WhatsApp queda en borrador y, si hay un número oficial activo, se envía a
 * revisión de Meta: `whatsapp_submission` dice qué pasó.
 */
export function useInstallFromGallery(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: ({ key, ...input }: GalleryInstallInput) => api.post<GalleryInstallResult>(`${base(ws)}/library/${encodeURIComponent(key)}`, input),
        onSuccess: () => {
            void qc.invalidateQueries({ queryKey: ['ws', ws, 'templates'] });
        },
    });
}
