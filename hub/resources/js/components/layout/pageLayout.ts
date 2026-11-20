/**
 * Cómo ocupa la pantalla cada ruta dentro de `WorkspaceLayout` (hub/CLAUDE.md §14), según el `handle` de la ruta:
 * - `default`: columna centrada (máx. 6xl) con márgenes; la página se desplaza dentro de `main`.
 * - `fullBleed` (`handle: { fullBleed: true }`): lienzo de todo el ancho y el alto, sin márgenes (constructor de flujos).
 * - `fill` (`handle: { fill: true }`): la página llena el alto de `main` y reparte el espacio con flex; en el celular sin
 *   márgenes, para usarlo de borde a borde (la bandeja: lista y chat con su propio desplazamiento).
 */
export type PageLayout = 'default' | 'fullBleed' | 'fill';

interface LayoutHandle {
    fullBleed?: boolean;
    fill?: boolean;
}

/** La ruta más profunda que lo declare decide. */
export function pageLayout(handles: readonly unknown[]): PageLayout {
    for (let i = handles.length - 1; i >= 0; i--) {
        const handle = handles[i] as LayoutHandle | null | undefined;
        if (handle?.fullBleed === true) {
            return 'fullBleed';
        }
        if (handle?.fill === true) {
            return 'fill';
        }
    }
    return 'default';
}

export const LAYOUT_CLASS: Record<PageLayout, string> = {
    default: 'igr:mx-auto igr:max-w-6xl igr:px-4 igr:py-4 igr:md:px-8 igr:md:py-6',
    fullBleed: 'igr:flex igr:min-h-0 igr:flex-col igr:md:h-full',
    fill: 'igr:mx-auto igr:flex igr:h-full igr:min-h-0 igr:max-w-6xl igr:flex-col igr:md:px-8 igr:md:py-6',
};
