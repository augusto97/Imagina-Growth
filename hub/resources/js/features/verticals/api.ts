import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { wsPath } from '@/api/queries';
import { api } from '@/lib/api';
import type { ApplyVerticalResult, Vertical, VerticalsResponse } from './types';

/* Perfiles de vertical (modulos.md M18 «Verticales»). Rutas en hub/routes/api/verticals.php. */

export const verticalKeys = {
    all: (ws: string) => ['ws', ws, 'verticals'] as const,
};

/** Los perfiles (solo lectura, `viewWorkspace`) con el tipo de negocio y la vertical del espacio de trabajo. */
export function useVerticals(ws: string, enabled = true) {
    return useQuery({
        queryKey: verticalKeys.all(ws),
        queryFn: ({ signal }) => api.get<VerticalsResponse>(`${wsPath(ws)}/verticals`, signal),
        staleTime: 5 * 60_000,
        enabled,
    });
}

/**
 * Deja los valores por defecto de la vertical (embudo de leads e industria de las plantillas; idempotente, no pisa lo
 * personalizado). Cambia el embudo de Leads, las estrategias recomendadas, el Inicio y el asistente.
 */
export function useApplyVertical(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (vertical: Vertical) => api.post<ApplyVerticalResult>(`${wsPath(ws)}/verticals/${encodeURIComponent(vertical)}/apply`),
        onSuccess: () => {
            void qc.invalidateQueries({ queryKey: verticalKeys.all(ws) });
            void qc.invalidateQueries({ queryKey: ['ws', ws, 'leads'] });
            void qc.invalidateQueries({ queryKey: ['ws', ws, 'strategies'] });
            void qc.invalidateQueries({ queryKey: ['ws', ws, 'home'] });
            void qc.invalidateQueries({ queryKey: ['ws', ws, 'onboarding'] });
            void qc.invalidateQueries({ queryKey: ['ws', ws, 'templates'] });
        },
    });
}
