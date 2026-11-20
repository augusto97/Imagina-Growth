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

/** Celular: el lienzo no se edita ahí (menos de 768 px de ancho). */
export { PHONE_QUERY, useMedia } from '@/hooks/useMedia';
/** Pantalla ancha: la lista de pasos y el minimapa se muestran abiertos de entrada. */
export const WIDE_QUERY = '(min-width: 1440px)';
