import type { RouteObject } from 'react-router';

/* Capa de agencia (M22): el panel de la agencia y los reportes mensuales del cliente. */
export const workspaceRoutes: RouteObject[] = [
    {
        path: 'agencia',
        lazy: async () => ({ Component: (await import('./AgencyPage')).AgencyPage }),
    },
    {
        path: 'reportes',
        lazy: async () => ({ Component: (await import('./ClientReportsPage')).ClientReportsPage }),
    },
];
