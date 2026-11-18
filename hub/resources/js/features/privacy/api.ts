import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { wsPath } from '@/api/queries';
import { api } from '@/lib/api';

/* Solicitudes de los titulares (cumplimiento.md §9): acceso (exportar) y supresión (borrar). */

export type DataRequestKind = 'access' | 'delete';

export interface DataRequest {
    id: string;
    kind: DataRequestKind;
    status: 'pending' | 'completed' | (string & {});
    contact_id: string | null;
    origin: string | null;
    due_at: string | null;
    completed_at: string | null;
    created_at: string | null;
    result: { deleted?: Record<string, number>; events?: number; result?: string };
    downloadable: boolean;
}

/** Texto que se escribe para confirmar un borrado (DataRequestController::execute). */
export const DELETE_CONFIRMATION = 'BORRAR';

const listKey = (ws: string) => ['ws', ws, 'data-requests'] as const;

export function useDataRequests(ws: string, enabled = true) {
    return useQuery({
        queryKey: listKey(ws),
        queryFn: async ({ signal }) => (await api.get<{ data: DataRequest[] }>(`${wsPath(ws)}/data-requests`, signal)).data,
        enabled,
    });
}

export function downloadUrl(ws: string, id: string): string {
    return `${wsPath(ws)}/data-requests/${encodeURIComponent(id)}/download`;
}

function useInvalidate(ws: string) {
    const qc = useQueryClient();
    return (contactId?: string | null) => {
        void qc.invalidateQueries({ queryKey: listKey(ws) });
        if (contactId) {
            void qc.invalidateQueries({ queryKey: ['ws', ws, 'contact', contactId] });
        }
    };
}

export function useCreateDataRequest(ws: string, contactId: string) {
    const invalidate = useInvalidate(ws);
    return useMutation({
        mutationFn: (kind: DataRequestKind) => api.post<DataRequest>(`${wsPath(ws)}/contacts/${encodeURIComponent(contactId)}/data-requests`, { kind }),
        onSuccess: () => invalidate(contactId),
    });
}

export function useExecuteDataRequest(ws: string) {
    const qc = useQueryClient();
    const invalidate = useInvalidate(ws);
    return useMutation({
        mutationFn: (data: { id: string; confirm?: string }) =>
            api.post<DataRequest>(`${wsPath(ws)}/data-requests/${encodeURIComponent(data.id)}/execute`, data.confirm ? { confirm: data.confirm } : {}),
        onSuccess: (request, vars) => {
            invalidate(request.contact_id);
            if (request.kind === 'delete') {
                // El contacto ya no existe: se limpia todo lo que había de él.
                void qc.invalidateQueries({ queryKey: ['ws', ws, 'contacts'] });
                void qc.invalidateQueries({ queryKey: ['ws', ws, 'contact'] });
            }
            return vars;
        },
    });
}

/** Crea la solicitud y la ejecuta (exportación o borrado desde el perfil). */
export function useRunDataRequest(ws: string, contactId: string) {
    const create = useCreateDataRequest(ws, contactId);
    const execute = useExecuteDataRequest(ws);
    return useMutation({
        mutationFn: async (data: { kind: DataRequestKind; confirm?: string }) => {
            const request = await create.mutateAsync(data.kind);
            return execute.mutateAsync({ id: request.id, ...(data.confirm ? { confirm: data.confirm } : {}) });
        },
    });
}
