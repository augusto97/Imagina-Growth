import { useQueryClient, type QueryClient } from '@tanstack/react-query';
import { matchRoutes } from 'react-router';

/*
 * Precarga al pasar el puntero o enfocar un enlace del menú (docs/rendimiento.md §7.1, 5): el chunk de la pantalla con
 * sus textos (el mismo `lazy` de la ruta; el navegador guarda el módulo y React Router lo encuentra listo) y, en las
 * pantallas principales, su primera consulta (respeta el minuto fresco de TanStack Query: no repite lo que ya está).
 * Lo de las pantallas se importa al precargar, así el arranque no carga sus APIs.
 */
type Prefetcher = (qc: QueryClient, ws: string) => Promise<unknown>;

/** Primera consulta de cada pantalla principal, por su ruta relativa a /w/:workspaceId. */
const section = (key: 'customers' | 'conversations' | 'messages' | 'onsite' | 'results' | 'settings'): Prefetcher => async (qc, ws) =>
    qc.query((await import('@/features/sections/api')).sectionSummaryQuery(ws, key));

const DATA: Record<string, Prefetcher> = {
    '': async (qc, ws) => qc.query((await import('@/features/home/api')).homeQuery(ws)),
    estrategias: async (qc, ws) => qc.query((await import('@/features/strategies/api')).strategiesQuery(ws)),
    clientes: section('customers'),
    conversaciones: section('conversations'),
    mensajes: section('messages'),
    'en-tu-web': section('onsite'),
    resultados: section('results'),
    configuracion: section('settings'),
    segmentos: async (qc, ws) => qc.query((await import('@/features/segments/api')).segmentsQuery(ws)),
    flujos: async (qc, ws) => qc.query((await import('@/features/flows/api')).flowsQuery(ws)),
    campanas: async (qc, ws) => qc.query((await import('@/features/campaigns/api')).campaignsQuery(ws)),
};

/** Cuándo se precargó cada ruta: pasar el puntero varias veces no repite el trabajo dentro del minuto fresco. */
const done = new Map<string, number>();
const FRESH_MS = 60_000;

/** La parte de la ruta después de /w/:workspaceId ('' para Inicio), o null si el enlace es de otro lado. */
export function workspacePath(pathname: string): { ws: string; rest: string } | null {
    const m = /^\/w\/([^/?#]+)(?:\/([^?#]*))?/.exec(pathname);
    if (!m) {
        return null;
    }
    return { ws: decodeURIComponent(m[1] ?? ''), rest: (m[2] ?? '').replace(/\/+$/, '') };
}

/** Precarga la pantalla de un enlace del panel: chunk, textos y su primera consulta. Nunca falla (es solo una ayuda). */
export function prefetchPath(qc: QueryClient, to: string): void {
    const pathname = to.split(/[?#]/)[0] ?? to;
    const where = workspacePath(pathname);
    const last = done.get(pathname);
    if (!where || (last !== undefined && Date.now() - last < FRESH_MS)) {
        return;
    }
    done.set(pathname, Date.now());
    void (async () => {
        // El router se importa aquí (ya está en el arranque): importarlo arriba haría un ciclo con el marco de la app.
        const { router } = await import('@/app/router');
        for (const match of matchRoutes(router.routes, pathname) ?? []) {
            const lazy = match.route.lazy;
            if (typeof lazy === 'function') {
                await lazy();
            }
        }
        await DATA[where.rest]?.(qc, where.ws);
    })().catch(() => {
        done.delete(pathname);
    });
}

/** Props para un enlace que precarga su pantalla al pasar el puntero o al enfocarlo con el teclado. */
export function useIntentPrefetch(): (to: string) => { onMouseEnter: () => void; onFocus: () => void } {
    const qc = useQueryClient();
    return (to: string) => ({ onMouseEnter: () => prefetchPath(qc, to), onFocus: () => prefetchPath(qc, to) });
}
