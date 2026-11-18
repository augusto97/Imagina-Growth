import type { RouteObject } from 'react-router';

/** Canales (M07): correo, WhatsApp, SMS y supresión; plantillas de WhatsApp de cada número. */
export const workspaceRoutes: RouteObject[] = [
    { path: 'canales', lazy: async () => ({ Component: (await import('./ChannelsPage')).ChannelsPage }) },
    { path: 'canales/plantillas-whatsapp', lazy: async () => ({ Component: (await import('./WaTemplatesPage')).WaTemplatesPage }) },
];
