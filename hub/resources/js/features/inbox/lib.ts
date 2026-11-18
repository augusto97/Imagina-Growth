import type { QuickReply } from './types';

/* Lógica pura de la bandeja (probada con Vitest). */

/** Tiempo que le queda a la ventana de 24 h, en milisegundos (0 si está cerrada). */
export function windowLeft(expiresAt: string | null, now: Date = new Date()): number {
    if (!expiresAt) return 0;
    return Math.max(0, new Date(expiresAt).getTime() - now.getTime());
}

/** «23 h 05 min», «12 min», «menos de 1 min». */
export function formatLeft(ms: number): { hours: number; minutes: number } {
    const minutes = Math.floor(ms / 60_000);
    return { hours: Math.floor(minutes / 60), minutes: minutes % 60 };
}

/** Segundos a «2 min», «1 h 30 min», «45 s». */
export function formatDuration(seconds: number | null | undefined): string {
    if (seconds === null || seconds === undefined) return '—';
    if (seconds < 60) return `${seconds} s`;
    const minutes = Math.round(seconds / 60);
    if (minutes < 60) return `${minutes} min`;
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    if (h < 48) return m === 0 ? `${h} h` : `${h} h ${m} min`;
    return `${Math.round(h / 24)} d`;
}

/** Si el texto termina en `/atajo`, las respuestas rápidas que coinciden. */
export function quickReplyQuery(text: string): string | null {
    const match = /(^|\s)\/([a-z0-9_-]*)$/.exec(text);
    return match ? (match[2] ?? '') : null;
}

export function matchQuickReplies(replies: QuickReply[], query: string): QuickReply[] {
    const q = query.toLowerCase();
    return replies.filter((r) => r.shortcut.startsWith(q) || r.title.toLowerCase().includes(q)).slice(0, 6);
}

/** Reemplaza el `/atajo` final por el cuerpo de la respuesta rápida. */
export function applyQuickReply(text: string, reply: QuickReply): string {
    return text.replace(/(^|\s)\/[a-z0-9_-]*$/, (_m, space: string) => `${space}${reply.body}`);
}

/** Etiquetas desde un texto separado por comas: sin vacías, sin repetidas, máximo 10. */
export function parseLabels(text: string): string[] {
    const out: string[] = [];
    for (const raw of text.split(',')) {
        const label = raw.trim().slice(0, 32);
        if (label !== '' && !out.includes(label)) out.push(label);
    }
    return out.slice(0, 10);
}

export function newClientId(): string {
    if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) return crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });
}

/** Clave de base64url a Uint8Array (llave pública VAPID para pushManager.subscribe). */
export function base64UrlToBytes(value: string): Uint8Array {
    const padded = (value + '='.repeat((4 - (value.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(padded);
    return Uint8Array.from(raw, (c) => c.charCodeAt(0));
}
