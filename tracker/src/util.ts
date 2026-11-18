/* Utilidades sin dependencias. Todo lo que toca el navegador tolera errores. */

const C = typeof crypto !== 'undefined' ? crypto : undefined;

function bytes(n: number): Uint8Array {
    const a = new Uint8Array(n);
    if (C && C.getRandomValues) {
        C.getRandomValues(a);
    } else {
        for (let i = 0; i < n; i++) a[i] = Math.floor(Math.random() * 256);
    }
    return a;
}

function uuidFrom(b: Uint8Array, version: number): string {
    b[6] = ((b[6] ?? 0) & 15) | (version << 4);
    b[8] = ((b[8] ?? 0) & 63) | 128;
    let h = '';
    for (let i = 0; i < 16; i++) h += ((b[i] ?? 0) < 16 ? '0' : '') + (b[i] ?? 0).toString(16);
    return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' + h.slice(16, 20) + '-' + h.slice(20);
}

/** UUID v4 para el ID anónimo. */
export function uuid4(): string {
    return uuidFrom(bytes(16), 4);
}

/** UUID v7 (ordenado por tiempo) para cada evento. */
export function uuid7(ms: number = Date.now()): string {
    const b = bytes(16);
    let t = ms;
    for (let i = 5; i >= 0; i--) {
        b[i] = t % 256;
        t = Math.floor(t / 256);
    }
    return uuidFrom(b, 7);
}

/** Token corto sin caracteres ambiguos (I, O, 0, 1): `K7Q2`. */
export function shortToken(len = 5): string {
    const abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    let s = '';
    for (const x of bytes(len)) s += abc[x % 32];
    return s;
}

export function getCookie(name: string): string | null {
    const m = document.cookie.match('(?:^|; )' + name + '=([^;]*)');
    if (!m) return null;
    try {
        return decodeURIComponent(m[1] ?? '');
    } catch {
        return null;
    }
}

export function setCookie(name: string, value: string, seconds: number, domain?: string): void {
    document.cookie =
        name + '=' + encodeURIComponent(value) + '; Max-Age=' + Math.round(seconds) + '; Path=/; SameSite=Lax' +
        (location.protocol === 'https:' ? '; Secure' : '') + (domain ? '; Domain=' + domain : '');
}

/** Acceso a localStorage / sessionStorage que nunca lanza (Safari privado, cuotas). */
export function store(kind: 'l' | 's', key: string, value?: string | null): string | null {
    try {
        const s = kind === 'l' ? localStorage : sessionStorage;
        if (value === undefined) return s.getItem(key);
        if (value === null) s.removeItem(key);
        else s.setItem(key, value);
    } catch {
        /* sin almacenamiento */
    }
    return null;
}

export function cut(v: unknown, n: number): string {
    return String(v == null ? '' : v).slice(0, n);
}

export function idle(fn: () => void): void {
    const w = window as unknown as { requestIdleCallback?: (cb: () => void, o?: { timeout: number }) => void };
    if (w.requestIdleCallback) w.requestIdleCallback(fn, { timeout: 2000 });
    else setTimeout(fn, 1);
}
