import type { RouteObject } from 'react-router';

/* Agente de ventas con IA de la bandeja (M11): ajustes, prueba y revisión. */
export const workspaceRoutes: RouteObject[] = [{ path: 'bandeja/agente', lazy: async () => ({ Component: (await import('./AgentPage')).AgentPage }) }];
