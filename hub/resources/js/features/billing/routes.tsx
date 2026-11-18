import type { RouteObject } from 'react-router';

/* Plan y facturación (M21). */
export const workspaceRoutes: RouteObject[] = [
    {
        path: 'facturacion',
        lazy: async () => ({
            Component: (await import('./BillingPage')).BillingPage,
        }),
    },
];
