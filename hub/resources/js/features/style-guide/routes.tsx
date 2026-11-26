import type { RouteObject } from 'react-router';

/* Guía de estilo del panel v2 (solo superadmin; docs/diseno/rediseno-v2.md §7). */
export const workspaceRoutes: RouteObject[] = [{ path: 'guia-de-estilo', lazy: async () => ({ Component: (await import('./StyleGuidePage')).StyleGuidePage }) }];
