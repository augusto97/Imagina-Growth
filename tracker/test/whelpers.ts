/* Ayudas para probar los paquetes de widgets sin red: hub simulado con fetch y contexto del runtime. */
import { vi } from 'vitest';
import type { Ctx, Live, Renderer, WidgetCfg } from '../widgets/lib/types';

export const BASE = 'https://hub.test/p/pk_x/';
const W = window as unknown as Record<string, unknown>;

export interface Call {
    url: URL;
    body: Record<string, unknown> | null;
}

/** fetch simulado: cada ruta (sufijo del path) responde [estado, cuerpo]. */
export function hubFetch(routes: Record<string, (url: URL, body: Record<string, unknown> | null) => [number, unknown]>): Call[] {
    const calls: Call[] = [];
    vi.stubGlobal(
        'fetch',
        vi.fn((input: string, init?: RequestInit) => {
            const url = new URL(String(input));
            let body: Record<string, unknown> | null = null;
            if (init?.body) {
                try {
                    body = JSON.parse(String(init.body)) as Record<string, unknown>;
                } catch {
                    body = Object.fromEntries(new URLSearchParams(String(init.body)));
                }
            }
            calls.push({ url, body });
            const key = Object.keys(routes).find((k) => url.pathname.endsWith(k));
            const [status, data] = key ? (routes[key] as (u: URL, b: Record<string, unknown> | null) => [number, unknown])(url, body) : [404, {}];
            return Promise.resolve(new Response(JSON.stringify(data), { status }));
        }),
    );
    return calls;
}

/** Tracker simulado: guarda lo que los widgets le empujan. */
export function fakeTracker(analytics = true): unknown[][] {
    const pushed: unknown[][] = [];
    W['igr'] = { push: (...c: unknown[]) => pushed.push(...(c as unknown[][])), consent: () => ({ analytics, marketing: false }) };
    return pushed;
}

/** Contexto real del runtime (sin arrancarlo). */
export async function ctx(widgets: WidgetCfg[] = [], extra: Record<string, unknown> = {}): Promise<Ctx> {
    W['igrConfig'] = { currency: 'COP', ...extra };
    W['__igrWidgets'] = { widgets, consents: extra['consents'] ?? [] };
    const rt = await import('../widgets/runtime');
    return rt.makeCtx(BASE);
}

export function renderer(kind: string): Renderer {
    const r = (W['__igrW'] as { r: Record<string, Renderer> } | undefined)?.r[kind];
    if (!r) throw new Error('sin renderizador ' + kind);
    return r;
}

export function live(x: Ctx, w: WidgetCfg): Live {
    return x.live(w);
}

/** Shadow root del primer widget flotante de ese tipo. */
export function overlay(kind: string): ShadowRoot {
    const host = document.querySelector('[data-igr-w="' + kind + '"]') as HTMLElement | null;
    if (!host?.shadowRoot) throw new Error('sin overlay ' + kind);
    return host.shadowRoot;
}

export const tick = (ms = 20): Promise<void> => new Promise((r) => setTimeout(r, ms));

export function tracked(pushed: unknown[][]): string[] {
    return pushed.filter((c) => c[0] === 'track').map((c) => String(c[1]));
}
