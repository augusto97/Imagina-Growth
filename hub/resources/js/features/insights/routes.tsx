import type { RouteObject } from 'react-router';

/** Hallazgos (M17): lista con filtros por estado, categoría y severidad, detalle con hechos, impacto y acción, y ajustes. */
export const workspaceRoutes: RouteObject[] = [
    { path: 'hallazgos', lazy: async () => ({ Component: (await import('./InsightsPage')).InsightsPage }) },
    { path: 'hallazgos/ajustes', lazy: async () => ({ Component: (await import('./InsightSettingsPage')).InsightSettingsPage }) },
    { path: 'hallazgos/:insightId', lazy: async () => ({ Component: (await import('./InsightDetailPage')).InsightDetailPage }) },
];
