/*
 * Configuración que depende del servidor y no del build (docs/despliegue.md §3): el HTML que
 * sirve Laravel define `window.igrRuntime` antes de cargar la SPA. En desarrollo con Vite sin
 * ese valor, se usan las variables VITE_REVERB_*.
 */

export interface ReverbRuntime {
    key: string;
    host: string;
    port: number;
    scheme: 'http' | 'https';
}

export interface IgrRuntime {
    version?: string;
    reverb?: ReverbRuntime | null;
}

declare global {
    interface Window {
        igrRuntime?: IgrRuntime;
    }
}

/** La conexión pública a Reverb, o null si este servidor no tiene tiempo real. */
export function reverbConfig(): ReverbRuntime | null {
    const fromServer = typeof window !== 'undefined' ? window.igrRuntime?.reverb : undefined;
    if (fromServer && typeof fromServer.key === 'string' && fromServer.key !== '') {
        const scheme = fromServer.scheme === 'http' ? 'http' : 'https';
        return {
            key: fromServer.key,
            host: fromServer.host || window.location.hostname,
            port: fromServer.port > 0 ? fromServer.port : scheme === 'https' ? 443 : 80,
            scheme,
        };
    }
    const key = import.meta.env.VITE_REVERB_APP_KEY as string | undefined;
    if (typeof key !== 'string' || key === '') {
        return null;
    }
    const scheme = (import.meta.env.VITE_REVERB_SCHEME as string | undefined) === 'http' ? 'http' : 'https';
    return {
        key,
        host: (import.meta.env.VITE_REVERB_HOST as string | undefined) || window.location.hostname,
        port: Number(import.meta.env.VITE_REVERB_PORT ?? (scheme === 'https' ? 443 : 80)),
        scheme,
    };
}

/** Versión instalada que anunció el servidor al cargar la página. */
export function serverVersion(): string | null {
    const v = typeof window !== 'undefined' ? window.igrRuntime?.version : undefined;
    return typeof v === 'string' && v !== '' ? v : null;
}
