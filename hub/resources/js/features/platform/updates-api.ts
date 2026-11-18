import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { api } from "@/lib/api";
import { isActive } from "./updates-model";

/* Actualizaciones desde el panel (docs/despliegue.md §5). Rutas en App\Domain\Updates\ModuleServiceProvider. */

const base = "/api/platform/updates";

export type UpdateState =
    | "queued"
    | "running"
    | "succeeded"
    | "failed"
    | "rolled_back";
export type StepStatus = "pending" | "running" | "done" | "failed" | "skipped";

export interface UpdateStep {
    key: string;
    status: StepStatus;
    started_at: string | null;
    finished_at: string | null;
    message: string | null;
}

export interface UpdateStatus {
    id: string | null;
    from: string | null;
    to: string | null;
    requested_by: string | null;
    state: UpdateState;
    step: string | null;
    steps: UpdateStep[];
    started_at: string | null;
    finished_at: string | null;
    error: string | null;
}

export interface UpdatesPayload {
    current: string;
    latest: {
        version: string;
        tag: string;
        name: string | null;
        notes: string | null;
        published_at: string | null;
        html_url: string | null;
    } | null;
    checked_at: string | null;
    check_error: string | null;
    update_available: boolean;
    busy: boolean;
    can_install: boolean;
    pending: {
        version: string;
        id: string;
        requested_by: string;
        requested_at?: string;
        accepted_at?: string;
    } | null;
    status: UpdateStatus | null;
    history: UpdateStatus[];
}

const key = ["platform", "updates"] as const;

/** Mientras hay un pedido o una actualización en curso, se consulta cada 3 s. */
export function useUpdates() {
    return useQuery({
        queryKey: key,
        queryFn: ({ signal }) => api.get<UpdatesPayload>(base, signal),
        refetchInterval: (query) =>
            query.state.data &&
            (query.state.data.pending !== null ||
                isActive(query.state.data.status))
                ? 3000
                : false,
        // Durante el reinicio el panel puede no responder unos segundos: se reintenta sin mostrar error.
        retry: 5,
    });
}

export function useCheckUpdates() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: () => api.post<UpdatesPayload>(`${base}/check`),
        onSuccess: (data) => qc.setQueryData(key, data),
    });
}

export function useInstallUpdate() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (v: { version: string; password: string }) =>
            api.post<UpdatesPayload>(`${base}/install`, v),
        onSuccess: (data) => qc.setQueryData(key, data),
    });
}
