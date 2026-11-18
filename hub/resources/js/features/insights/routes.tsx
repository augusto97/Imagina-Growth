import type { RouteObject } from 'react-router';

/** Hallazgos (M17): lista con filtros por estado y detalle con hechos, impacto y acción. */
export const workspaceRoutes: RouteObject[] = [
    { path: 'hallazgos', lazy: async () => ({ Component: (await import('./InsightsPage')).InsightsPage }) },
    { path: 'hallazgos/:insightId', lazy: async () => ({ Component: (await import('./InsightDetailPage')).InsightDetailPage }) },
];
