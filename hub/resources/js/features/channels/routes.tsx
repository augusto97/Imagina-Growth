import type { RouteObject } from 'react-router';

/**
 * Canales (M07): las cuentas viven ahora en Conexiones (rediseño v2 §3) y `canales?tab=…` lleva a la tarjeta del canal
 * (la supresión, a Privacidad y consentimiento). Las plantillas de WhatsApp de cada número siguen aquí.
 */
export const workspaceRoutes: RouteObject[] = [
    { path: 'canales', lazy: async () => ({ Component: (await import('@/features/connections/redirects')).ChannelsRedirect }) },
    { path: 'canales/plantillas-whatsapp', lazy: async () => ({ Component: (await import('./WaTemplatesPage')).WaTemplatesPage }) },
];
