import { useMutation, useQueryClient } from '@tanstack/react-query';
import { keys, wsPath } from '@/api/queries';
import type { Workspace } from '@/api/types';
import { api } from '@/lib/api';
import type { Hours } from './logic';

/**
 * Guarda el horario de atención (`PATCH /api/w/{ws}` con `business_hours`, en `workspace.settings`). Refresca `/api/me`
 * (de ahí lo lee la pantalla) y los ajustes del agente con IA, que lo muestran y lo usan fuera de horario.
 */
export function useSaveBusinessHours(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (hours: Hours) => api.patch<Workspace>(wsPath(ws), { business_hours: hours }),
        onSuccess: () => {
            void qc.invalidateQueries({ queryKey: keys.me });
            void qc.invalidateQueries({ queryKey: ['ws', ws, 'agent', 'settings'] });
        },
    });
}
