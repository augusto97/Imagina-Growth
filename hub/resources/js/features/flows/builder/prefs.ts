import { useSyncExternalStore } from 'react';
import { create } from 'zustand';
import { persist } from 'zustand/middleware';

/** Preferencias del constructor de flujos que se recuerdan entre visitas. `null` = según el ancho de la pantalla. */
interface BuilderPrefs {
    paletteOpen: boolean | null;
    minimap: boolean | null;
    setPaletteOpen: (open: boolean) => void;
    setMinimap: (open: boolean) => void;
}

export const useBuilderPrefs = create<BuilderPrefs>()(
    persist(
        (set) => ({
            paletteOpen: null,
            minimap: null,
            setPaletteOpen: (paletteOpen) => set({ paletteOpen }),
            setMinimap: (minimap) => set({ minimap }),
        }),
        { name: 'igr-flow-builder', partialize: (s) => ({ paletteOpen: s.paletteOpen, minimap: s.minimap }) },
    ),
);

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

/** `true` si la pantalla cumple la media query (se actualiza al cambiar el tamaño). */
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

/** Celular: el lienzo no se edita ahí (menos de 768 px de ancho). */
export const PHONE_QUERY = '(max-width: 767px)';
/** Pantalla ancha: la lista de pasos y el minimapa se muestran abiertos de entrada. */
export const WIDE_QUERY = '(min-width: 1440px)';
