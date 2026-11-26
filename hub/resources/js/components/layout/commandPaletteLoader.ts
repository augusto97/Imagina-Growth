import { lazy } from 'react';

/**
 * La paleta ⌘K (cmdk) se baja al abrirla por primera vez: no pesa en el arranque (docs/rendimiento.md §7.1, 7). El botón
 * de buscar la empieza a bajar al pasar el puntero o enfocarlo (`preloadCommandPalette`).
 */
const load = () => import('./CommandPalette');

export const LazyCommandPalette = lazy(async () => ({ default: (await load()).CommandPalette }));

export function preloadCommandPalette(): void {
    void load();
}
