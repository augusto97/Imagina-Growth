import type { RouteObject } from 'react-router';

/**
 * Conexiones, el centro único (rediseño v2 §3): tu web, WhatsApp, correo, Messenger e Instagram, anuncios y agenda.
 * `conexiones/:card` abre la tarjeta (`conexiones?tarjeta=`); `sitios` y `canales` redirigen desde sus carpetas.
 */
export const workspaceRoutes: RouteObject[] = [
    { path: 'conexiones', lazy: async () => ({ Component: (await import('./ConnectionsPage')).ConnectionsPage }) },
    { path: 'conexiones/:card', lazy: async () => ({ Component: (await import('./redirects')).CardRedirect }) },
];
