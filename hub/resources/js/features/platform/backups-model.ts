import type { BackupJob, BackupJobKey, BackupSettingsInput, BackupsPayload } from './backups-api';

/* Lógica pura de Plataforma → Copias de seguridad (docs/despliegue.md §6), sin React, para probarla sola. */

export const BACKUP_JOBS: BackupJobKey[] = ['mariadb', 'clickhouse', 'verify'];

/** Códigos que root (growth-backup-dispatch y las copias) explica con un texto propio en `platform.backups.errors`. */
export const KNOWN_ERRORS = [
    'not_configured',
    'invalid_request',
    'invalid_host',
    'invalid_port',
    'invalid_user',
    'invalid_path',
    'invalid_auth',
    'invalid_keep_days',
    'invalid_heartbeat',
    'invalid_password',
    'invalid_encryption',
    'password_required',
    'encryption_required',
    'missing_tool',
    'host_not_found',
    'connection_refused',
    'timeout',
    'host_key_changed',
    'auth_failed',
    'permission_denied',
    'no_space',
    'encryption_mismatch',
    'already_running',
    'start_failed',
    'auth_not_key',
    'key_install_failed',
    'internal',
    'bad_config',
    'dump_failed',
    'compress_failed',
    'upload_failed',
    'cleanup_failed',
    'download_failed',
    'restore_failed',
    'no_backups',
    'verify_mismatch',
    'unknown',
] as const;

/** La clave del texto para un código de error, o null si root mandó uno que el panel no conoce (se muestra su mensaje). */
export function errorKey(code: string | null | undefined): string | null {
    return code && (KNOWN_ERRORS as readonly string[]).includes(code) ? `platform.backups.errors.${code}` : null;
}

/** ¿Hay algo en marcha que conviene ver en vivo? Un pedido que espera, uno que root atiende o una copia en curso. */
export function shouldPoll(p: BackupsPayload): boolean {
    if (p.pending !== null && !p.pending.stale) return true;
    if (p.last_request?.state === 'running') return true;
    return BACKUP_JOBS.some((k) => {
        const s = p.jobs[k]?.state;
        return s === 'queued' || s === 'running';
    });
}

export type Tone = 'ok' | 'critical' | 'active' | 'neutral';

export function jobTone(job: BackupJob | null | undefined): Tone {
    if (!job?.state) return 'neutral';
    if (job.state === 'succeeded') return 'ok';
    if (job.state === 'failed') return 'critical';
    return 'active';
}

/** Tamaño legible (KB, MB, GB, TB) con el formato del espacio de trabajo. */
export function formatBytes(bytes: number | null | undefined, locale = 'es-CO'): string {
    if (bytes === null || bytes === undefined || !Number.isFinite(bytes) || bytes < 0) return '—';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let value = bytes;
    let unit = 0;
    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }
    const digits = unit === 0 || value >= 100 ? 0 : 1;
    return `${new Intl.NumberFormat(locale, { maximumFractionDigits: digits, minimumFractionDigits: 0 }).format(value)} ${units[unit]}`;
}

/** Del servidor de Hetzner sale el usuario: u123456.your-storagebox.de → u123456 (solo para sugerirlo). */
export function userFromHost(host: string): string | null {
    const m = /^(u\d{3,10}(?:-sub\d{1,3})?)\.your-storagebox\.de$/i.exec(host.trim());
    return m?.[1]?.toLowerCase() ?? null;
}

const ALPHABET = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

/**
 * Una frase de cifrado al azar en el navegador (nunca pasa por el servidor hasta guardarla): 6 grupos de 5
 * caracteres sin los que se confunden (l, 1, I, O, 0), unos 170 bits.
 */
export function generatePassphrase(random: (n: number) => Uint8Array = (n) => crypto.getRandomValues(new Uint8Array(n))): string {
    const groups: string[] = [];
    let group = '';
    // Rechazo de los bytes que sesgarían el reparto (256 no es múltiplo del tamaño del alfabeto).
    const limit = 256 - (256 % ALPHABET.length);
    while (groups.length < 6) {
        for (const byte of random(32)) {
            if (byte >= limit) continue;
            group += ALPHABET.charAt(byte % ALPHABET.length);
            if (group.length === 5) {
                groups.push(group);
                group = '';
                if (groups.length === 6) break;
            }
        }
    }
    return groups.join('-');
}

export interface BackupForm {
    host: string;
    port: string;
    user: string;
    path: string;
    auth: 'key' | 'password';
    keep_days: string;
    password: string;
    encryption_password: string;
    heartbeats: Record<BackupJobKey, string>;
    clear_heartbeats: BackupJobKey[];
}

/** El formulario con lo que ya está guardado (los secretos y los heartbeats nunca vuelven: van vacíos). */
export function formFromPayload(p: BackupsPayload | undefined): BackupForm {
    const d = p?.destination;
    return {
        host: d?.host ?? '',
        port: String(d?.port ?? 23),
        user: d?.user ?? '',
        path: d?.path ?? 'imagina-growth',
        auth: d?.auth ?? 'key',
        keep_days: String(d?.keep_days ?? 30),
        password: '',
        encryption_password: '',
        heartbeats: { mariadb: '', clickhouse: '', verify: '' },
        clear_heartbeats: [],
    };
}

/** Lo que se manda: solo los heartbeats escritos, y los secretos solo si se escribieron (si no, root conserva los suyos). */
export function settingsInput(form: BackupForm, currentPassword: string): BackupSettingsInput {
    const heartbeats: Partial<Record<BackupJobKey, string>> = {};
    for (const k of BACKUP_JOBS) {
        const url = form.heartbeats[k].trim();
        if (url !== '' && !form.clear_heartbeats.includes(k)) heartbeats[k] = url;
    }
    return {
        host: form.host.trim(),
        port: Number.parseInt(form.port, 10),
        user: form.user.trim(),
        path: form.path.trim().replace(/^\/+|\/+$/g, ''),
        auth: form.auth,
        keep_days: Number.parseInt(form.keep_days, 10),
        heartbeats,
        clear_heartbeats: [...form.clear_heartbeats],
        ...(form.auth === 'password' && form.password !== '' ? { password: form.password } : {}),
        ...(form.encryption_password !== '' ? { encryption_password: form.encryption_password } : {}),
        current_password: currentPassword,
    };
}

/** ¿El último pedido terminado es el que se acaba de mandar? (para avisar el resultado una sola vez). */
export function finishedRequest(p: BackupsPayload | undefined, id: string | null): BackupsPayload['last_request'] {
    const last = p?.last_request;
    if (!id || !last || last.id !== id || last.state === 'running' || last.state === null) return null;
    return last;
}
