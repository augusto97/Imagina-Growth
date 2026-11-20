import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { shouldPoll } from './backups-model';

/* Plataforma → Copias de seguridad (docs/despliegue.md §6). Rutas en App\Domain\Backups\ModuleServiceProvider. */

const base = '/api/platform/backups';

export type BackupJobKey = 'mariadb' | 'clickhouse' | 'verify';
export type BackupJobState = 'queued' | 'running' | 'succeeded' | 'failed';
export type BackupAction = 'configure' | 'test' | 'backup_now' | 'install_key' | 'refresh';

export interface BackupJob {
    state: BackupJobState | null;
    started_at: string | null;
    finished_at: string | null;
    name: string | null;
    size_bytes: number | null;
    code: string | null;
    message: string | null;
    last_success: { finished_at: string | null; name: string | null; size_bytes: number | null } | null;
}

export interface BackupDestination {
    host: string | null;
    port: number | null;
    user: string | null;
    path: string | null;
    auth: 'key' | 'password';
    keep_days: number | null;
}

export interface BackupRequestState {
    id: string | null;
    action: BackupAction | null;
    requested_by: string | null;
    state: 'running' | 'succeeded' | 'failed' | null;
    code: string | null;
    message: string | null;
    started_at: string | null;
    finished_at: string | null;
}

export interface BackupsPayload {
    /** ¿Root ya publicó su estado alguna vez? Si no, el servidor todavía no tiene el despachador o no respondió. */
    answered: boolean;
    updated_at: string | null;
    configured: boolean;
    /** Configurado desde el panel (si no, a mano en el servidor: `manual_remote`). */
    managed: boolean;
    manual_remote: string | null;
    destination: BackupDestination | null;
    secrets: { password: boolean; encryption: boolean };
    heartbeats: Record<BackupJobKey, boolean>;
    timers_enabled: boolean | null;
    ssh_key: { public: string; fingerprint: string | null; created_at: string | null } | null;
    host_key: { fingerprint: string | null } | null;
    last_request: BackupRequestState | null;
    last_test: { ok: boolean; code: string | null; message: string | null; at: string | null; free_bytes: number | null } | null;
    jobs: Record<BackupJobKey, BackupJob | null>;
    schedule: Partial<Record<BackupJobKey, string | null>>;
    pending: { id: string | null; action: BackupAction | null; requested_at: string | null; stale: boolean } | null;
    busy: boolean;
}

export interface BackupSettingsInput {
    host: string;
    port: number;
    user: string;
    path: string;
    auth: 'key' | 'password';
    keep_days: number;
    /** Solo los que se cambian: los vacíos no se mandan (se conserva el guardado). */
    heartbeats: Partial<Record<BackupJobKey, string>>;
    clear_heartbeats: BackupJobKey[];
    password?: string;
    encryption_password?: string;
    current_password: string;
}

const key = ['platform', 'backups'] as const;

/** Mientras hay un pedido o una copia en curso, se consulta cada 2 s; si no, cada minuto. */
export function useBackups() {
    return useQuery({
        queryKey: key,
        queryFn: ({ signal }) => api.get<BackupsPayload>(base, signal),
        refetchInterval: (query) => (query.state.data && shouldPoll(query.state.data) ? 2000 : 60_000),
    });
}

function useBackupMutation<V>(fn: (v: V) => Promise<BackupsPayload>) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: fn,
        onSuccess: (data) => qc.setQueryData(key, data),
    });
}

export function useSaveBackupSettings() {
    return useBackupMutation((v: BackupSettingsInput) => api.put<BackupsPayload>(`${base}/settings`, v));
}

export function useTestBackupConnection() {
    return useBackupMutation(() => api.post<BackupsPayload>(`${base}/test`));
}

export function useRunBackupNow() {
    return useBackupMutation(() => api.post<BackupsPayload>(`${base}/run`));
}

export function useRefreshBackups() {
    return useBackupMutation(() => api.post<BackupsPayload>(`${base}/refresh`));
}

export function useInstallBackupKey() {
    return useBackupMutation((v: { password: string; current_password: string }) => api.post<BackupsPayload>(`${base}/install-key`, v));
}
