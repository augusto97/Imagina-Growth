import type { RouteObject } from 'react-router';

/* Reseñas, NPS y prueba social (M15). */
export const workspaceRoutes: RouteObject[] = [{ path: 'resenas', lazy: async () => ({ Component: (await import('./ReviewsPage')).ReviewsPage }) }];
