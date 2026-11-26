import type { RouteObject } from 'react-router';

/* Estrategias (docs/diseno/rediseno-v2.md §6). Página provisional en el estilo nuevo: la reemplaza el catálogo con su API. */
export const workspaceRoutes: RouteObject[] = [{ path: 'estrategias', lazy: async () => ({ Component: (await import('./StrategiesPage')).StrategiesPage }) }];
