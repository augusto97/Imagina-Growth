import type { RouteObject } from 'react-router';

/**
 * Resultados (rediseño v2 §3): el Resumen, entrada de la sección (`resultados`; reemplaza a la página de tarjetas de
 * features/sections), y los tableros de M05: Ventas, Embudo, Canales, Clientes, Productos, Búsquedas, Geografía,
 * Cupones, Mensajería, Rentabilidad, Servicios y «¿Cómo nos conociste?».
 */
export const workspaceRoutes: RouteObject[] = [
    { path: 'resultados', lazy: async () => ({ Component: (await import('./summary/ResultsSummaryPage')).ResultsSummaryPage }) },
    { path: 'analitica', lazy: async () => ({ Component: (await import('./AnalyticsPage')).AnalyticsPage }) },
    { path: 'analitica/:dashboard', lazy: async () => ({ Component: (await import('./AnalyticsPage')).AnalyticsPage }) },
];
