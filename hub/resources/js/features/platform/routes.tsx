import type { RouteObject } from 'react-router';

/* Panel interno de la plataforma (solo superadmin). Vive dentro del espacio activo porque el router base solo monta rutas por espacio. */
export const workspaceRoutes: RouteObject[] = [
    {
        path: 'plataforma',
        lazy: async () => ({
            Component: (await import('./PlatformPage')).PlatformPage,
        }),
    },
];
