import { useSyncExternalStore } from 'react';

/*
 * Puntos de quiebre del diseño adaptable (hub/CLAUDE.md §14), los mismos de Tailwind:
 * celular < md (768 px) ≤ tableta < lg (1024 px) ≤ escritorio. La barra lateral fija empieza en `lg`.
 */
/** Escritorio: barra lateral fija (contraíble). Por debajo, barra superior con el menú como panel. */
export const DESKTOP_QUERY = '(min-width: 1024px)';
/** Celular: una sola columna (la bandeja muestra la lista o el chat, no ambos). */
export const PHONE_QUERY = '(max-width: 767px)';
/** Pantalla ancha: la bandeja muestra el perfil del contacto como tercera columna. */
export const XL_QUERY = '(min-width: 1280px)';

function subscribeTo(query: string) {
    return (onChange: () => void) => {
        if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') {
            return () => undefined;
        }
        const list = window.matchMedia(query);
        list.addEventListener('change', onChange);
        return () => list.removeEventListener('change', onChange);
    };
}

const subscribers = new Map<string, (onChange: () => void) => () => void>();

/** `true` si la pantalla cumple la media query (se actualiza al cambiar el tamaño o girar el dispositivo). */
export function useMedia(query: string, fallback = false): boolean {
    let subscribe = subscribers.get(query);
    if (subscribe === undefined) {
        subscribe = subscribeTo(query);
        subscribers.set(query, subscribe);
    }
    return useSyncExternalStore(
        subscribe,
        () => (typeof window !== 'undefined' && typeof window.matchMedia === 'function' ? window.matchMedia(query).matches : fallback),
        () => fallback,
    );
}
