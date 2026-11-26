import { createContext, useContext } from 'react';
import type { NavLocation } from './nav';

/**
 * Dónde está la página dentro de la navegación (lo da `WorkspaceLayout` a partir de la URL). `PageHeader` lo usa para
 * la miga de la sección; las páginas de entrada, para saber qué sección mostrar. Fuera de un espacio de trabajo es null.
 */
export interface PageContextValue extends NavLocation {
    /** /w/:workspaceId */
    base: string;
    /** A qué se dedica el negocio (cambia algunas palabras de la navegación). */
    vertical?: string | null;
}

export const PageContext = createContext<PageContextValue | null>(null);

export function usePageContext(): PageContextValue | null {
    return useContext(PageContext);
}
