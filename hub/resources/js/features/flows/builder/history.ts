/*
 * Deshacer y rehacer del constructor. Se guarda el estado de ANTES de cada cambio. Los cambios
 * seguidos con la misma clave (escribir en un campo, mover con las flechas) dentro de una ventana
 * corta se juntan en un solo paso, para que deshacer no vaya letra por letra.
 */

export interface History<T> {
    past: T[];
    future: T[];
    /** Clave y hora del último cambio, para juntar los seguidos. */
    key: string | null;
    at: number;
}

export const HISTORY_LIMIT = 100;
export const COALESCE_MS = 1200;

export function emptyHistory<T>(): History<T> {
    return { past: [], future: [], key: null, at: 0 };
}

export function record<T>(history: History<T>, before: T, options: { key?: string | null; now?: number; limit?: number; window?: number } = {}): History<T> {
    const now = options.now ?? Date.now();
    const key = options.key ?? null;
    if (key !== null && history.key === key && now - history.at < (options.window ?? COALESCE_MS) && history.past.length > 0) {
        return { ...history, future: [], at: now };
    }
    const limit = options.limit ?? HISTORY_LIMIT;
    return { past: [...history.past, before].slice(-limit), future: [], key, at: now };
}

export function undo<T>(history: History<T>, current: T): { history: History<T>; state: T } | null {
    const state = history.past.at(-1);
    if (state === undefined) {
        return null;
    }
    return { history: { past: history.past.slice(0, -1), future: [current, ...history.future], key: null, at: 0 }, state };
}

export function redo<T>(history: History<T>, current: T): { history: History<T>; state: T } | null {
    const state = history.future[0];
    if (state === undefined) {
        return null;
    }
    return { history: { past: [...history.past, current], future: history.future.slice(1), key: null, at: 0 }, state };
}
