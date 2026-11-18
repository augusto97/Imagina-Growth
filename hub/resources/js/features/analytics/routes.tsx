import type { RouteObject } from 'react-router';

/** Analítica (M05): Inicio, Embudo, Canales, Clientes, Productos, Búsquedas, Geografía, Cupones, Mensajería, Rentabilidad, Servicios y «¿Cómo nos conociste?». */
export const workspaceRoutes: RouteObject[] = [
    { path: 'analitica', lazy: async () => ({ Component: (await import('./AnalyticsPage')).AnalyticsPage }) },
    { path: 'analitica/:dashboard', lazy: async () => ({ Component: (await import('./AnalyticsPage')).AnalyticsPage }) },
];
