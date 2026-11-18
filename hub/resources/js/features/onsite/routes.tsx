import type { RouteObject } from 'react-router';

/** En el sitio (M12): widgets de captación y su constructor. Recomendaciones y avisos (M16) registran sus propias rutas. */
export const workspaceRoutes: RouteObject[] = [
    { path: 'en-el-sitio', lazy: async () => ({ Component: (await import('./OnsitePage')).OnsitePage }) },
    { path: 'en-el-sitio/:widgetId', lazy: async () => ({ Component: (await import('./WidgetEditorPage')).WidgetEditorPage }) },
];
