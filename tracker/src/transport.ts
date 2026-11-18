import type { Envelope } from './types';
import { store } from './util';

const RETRY = '_igr_rq';
const MAX_BATCH = 20; // api-connector.md §4.2
let queue: Envelope[] = [];
let timer: ReturnType<typeof setTimeout> | 0 = 0;
let endpoint = '';
let key = '';
let canStore = (): boolean => false;

export function initTransport(url: string, siteKey: string, storageAllowed: () => boolean): void {
    endpoint = url;
    key = siteKey;
    canStore = storageAllowed;
}

/** Envía cada 2 s, al llegar a 10 eventos o al ocultar la página (tracker/CLAUDE.md §6). */
export function enqueue(e: Envelope): void {
    queue.push(e);
    if (queue.length >= 10) flush();
    else if (!timer) timer = setTimeout(flush, 2000);
}

export function flush(): void {
    if (timer) clearTimeout(timer);
    timer = 0;
    while (queue.length) send(queue.splice(0, MAX_BATCH));
}

/** sendBeacon (text/plain, sin preflight) → fetch keepalive → sessionStorage para reintentar. */
export function send(events: Envelope[]): void {
    const body = JSON.stringify({ k: key, events });
    try {
        if (navigator.sendBeacon && navigator.sendBeacon(endpoint, new Blob([body], { type: 'text/plain;charset=UTF-8' }))) return;
    } catch {
        /* se intenta con fetch */
    }
    try {
        fetch(endpoint, { method: 'POST', body, keepalive: true, credentials: 'omit', headers: { 'Content-Type': 'text/plain;charset=UTF-8' } })
            .then((r) => {
                if (r.status >= 500) save(events);
            })
            .catch(() => save(events));
    } catch {
        save(events);
    }
}

/** Guarda hasta 50 eventos para el siguiente intento (solo con consentimiento de analítica). */
export function save(events: Envelope[]): void {
    if (!canStore()) return;
    let kept: Envelope[] = [];
    try {
        kept = JSON.parse(store('s', RETRY) ?? '[]') as Envelope[];
    } catch {
        kept = [];
    }
    store('s', RETRY, JSON.stringify(kept.concat(events).slice(-50)));
}

/** Reenvía lo pendiente de una página anterior. */
export function retryPending(): void {
    if (!canStore()) return;
    const raw = store('s', RETRY);
    if (!raw) return;
    store('s', RETRY, null);
    try {
        const events = JSON.parse(raw) as Envelope[];
        for (let i = 0; i < events.length; i += MAX_BATCH) send(events.slice(i, i + MAX_BATCH));
    } catch {
        /* basura en el almacenamiento: se descarta */
    }
}

/** Para pruebas. */
export function pending(): Envelope[] {
    return queue;
}
