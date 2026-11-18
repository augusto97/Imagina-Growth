import type { RouteObject } from 'react-router';

/* Leads (M18): tablero, lista, indicadores y ajustes. */
export const workspaceRoutes: RouteObject[] = [{ path: 'leads', lazy: async () => ({ Component: (await import('./LeadsPage')).LeadsPage }) }];
