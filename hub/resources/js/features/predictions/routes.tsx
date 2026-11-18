import type { RouteObject } from 'react-router';

/* Inteligencia → Predicciones (M17). La portada de Inteligencia (fork N) enlaza aquí. */
export const workspaceRoutes: RouteObject[] = [{ path: 'inteligencia/predicciones', lazy: async () => ({ Component: (await import('./PredictionsPage')).PredictionsPage }) }];
