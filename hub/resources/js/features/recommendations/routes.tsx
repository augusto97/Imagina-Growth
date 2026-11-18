import type { RouteObject } from 'react-router';

/* Comercio en el sitio (M16): recomendaciones, «avísame» de stock y precio, y banners por segmento. */
export const workspaceRoutes: RouteObject[] = [
    { path: 'en-el-sitio/recomendaciones', lazy: async () => ({ Component: (await import('./RecommendationsPage')).RecommendationsPage }) },
    { path: 'en-el-sitio/avisos', lazy: async () => ({ Component: (await import('./WatchesPage')).WatchesPage }) },
    { path: 'en-el-sitio/banners', lazy: async () => ({ Component: (await import('./BannersPage')).BannersPage }) },
];
