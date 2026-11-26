import type { RouteObject } from 'react-router';

/* Capa de agencia (M22): el panel de la agencia. Los informes del cliente (`reportes`) viven en Resultados → Informes (features/intelligence). */
export const workspaceRoutes: RouteObject[] = [
    {
        path: 'agencia',
        lazy: async () => ({ Component: (await import('./AgencyPage')).AgencyPage }),
    },
];
