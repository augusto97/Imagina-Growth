import { vi } from 'vitest';
import type { Envelope } from '../src/types';

export const SITE = 'st_01JD3QG7Y2V8K6N4M5P9R0S1T2';
export const KEY = 'pk_' + 'a'.repeat(32);

export function setUrl(url: string, referrer = ''): void {
    (window as unknown as { happyDOM: { setURL: (u: string) => void } }).happyDOM.setURL(url);
    Object.defineProperty(document, 'referrer', { value: referrer, configurable: true });
}

export function reset(): void {
    for (const c of document.cookie.split(';')) {
        const name = c.split('=')[0]?.trim();
        if (name) {
            document.cookie = name + '=; Max-Age=0; Path=/';
            document.cookie = name + '=; Max-Age=0';
            document.cookie = name + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; Path=/';
        }
    }
    localStorage.clear();
    sessionStorage.clear();
    const w = window as unknown as Record<string, unknown>;
    for (const k of ['igr', 'igrConfig', 'igrPage', 'igrUser', 'igrOrder', 'wp_has_consent', '__igrWidgets', '__igrW']) delete w[k];
    document.head.innerHTML = '';
    document.body.innerHTML = '';
}

/** Instala un sendBeacon falso y devuelve los eventos enviados. */
export function beacons(): { events: () => Envelope[]; bodies: string[] } {
    const bodies: string[] = [];
    Object.defineProperty(navigator, 'sendBeacon', {
        configurable: true,
        value: vi.fn((_url: string, blob: Blob) => {
            void blob.text().then((t) => bodies.push(t));
            return true;
        }),
    });
    return {
        bodies,
        events: () => bodies.flatMap((b) => (JSON.parse(b) as { events: Envelope[] }).events),
    };
}

export async function boot(config: Record<string, unknown> = {}, page: Record<string, unknown> = { type: 'home' }): Promise<void> {
    const w = window as unknown as Record<string, unknown>;
    w['igrConfig'] = { siteId: SITE, siteKey: KEY, endpoint: 'https://in.hub.test/v1/b', platform: 'woocommerce', currency: 'COP', consent: { analytics: true, marketing: true, source: 'none' }, ...config };
    w['igrPage'] = page;
    vi.resetModules();
    await import('../src/index');
}

export async function settle(): Promise<void> {
    await new Promise((r) => setTimeout(r, 0));
    (window as unknown as { igr: { flush: () => void } }).igr.flush();
    await new Promise((r) => setTimeout(r, 5));
}
