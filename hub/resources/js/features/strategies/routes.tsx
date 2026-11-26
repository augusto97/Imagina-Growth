import type { RouteObject } from 'react-router';

/*
 * Estrategias (docs/diseno/rediseno-v2.md §6): el catálogo y, en /estrategias/:key, el mismo catálogo con el detalle
 * abierto en una hoja (lo enlazan el Inicio, «Para hoy», las recomendaciones y el asistente).
 */
const page = async () => ({ Component: (await import('./StrategiesPage')).StrategiesPage });

export const workspaceRoutes: RouteObject[] = [
    { path: 'estrategias', lazy: page },
    { path: 'estrategias/:key', lazy: page },
];
