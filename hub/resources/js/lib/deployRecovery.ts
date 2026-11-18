/*
 * Pestañas abiertas durante una actualización (docs/despliegue.md §5): la versión nueva ya
 * no tiene los archivos de la anterior, así que cargar una pantalla que todavía no se había
 * abierto falla con `vite:preloadError`. Se recarga la página una sola vez para tomar la
 * versión nueva; si vuelve a fallar enseguida, no se insiste (nada de bucles).
 */

const KEY = 'igr:deploy-reload';

/** Cuánto tiene que funcionar la página después de recargar para volver a permitir otra recarga. */
export const STABLE_AFTER_MS = 10_000;

interface Deps {
    target: Pick<Window, 'addEventListener'>;
    storage: Pick<Storage, 'getItem' | 'setItem' | 'removeItem'>;
    reload: () => void;
    setTimer: (fn: () => void, ms: number) => unknown;
}

function defaults(): Deps {
    return {
        target: window,
        storage: window.sessionStorage,
        reload: () => window.location.reload(),
        setTimer: (fn, ms) => window.setTimeout(fn, ms),
    };
}

export function installDeployRecovery(deps: Deps = defaults()): void {
    deps.target.addEventListener('vite:preloadError', (event: Event) => {
        try {
            if (deps.storage.getItem(KEY) !== null) {
                return; // ya se recargó hace poco: no insistir
            }
            deps.storage.setItem(KEY, String(Date.now()));
        } catch {
            return; // sin sessionStorage no se puede evitar un bucle: mejor no recargar
        }
        event.preventDefault();
        deps.reload();
    });
    // La página cargó bien: pasado un rato, una actualización futura puede volver a recargar.
    deps.setTimer(() => {
        try {
            deps.storage.removeItem(KEY);
        } catch {
            // nada
        }
    }, STABLE_AFTER_MS);
}
