import type { RouteObject } from 'react-router';

/** Agenda de citas (M18): calendario, servicios, personas y horarios, y la página pública de reservas. */
export const workspaceRoutes: RouteObject[] = [
    {
        path: 'agenda',
        lazy: async () => ({
            Component: (await import('./AgendaPage')).AgendaPage,
        }),
    },
];
