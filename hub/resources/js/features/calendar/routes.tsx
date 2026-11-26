import type { RouteObject } from 'react-router';

/** Calendario comercial (modulos.md M10): los próximos momentos del país con la campaña lista para crear. Va en Mensajes. */
export const workspaceRoutes: RouteObject[] = [{ path: 'campanas/calendario', lazy: async () => ({ Component: (await import('./CalendarPage')).CalendarPage }) }];
