import type { RouteObject } from 'react-router';

/*
 * Cada módulo registra sus pantallas dentro de /w/:workspaceId en
 * features/<módulo>/routes.tsx exportando `workspaceRoutes`. Así los módulos
 * no editan router.tsx ni se pisan entre sí.
 */
const found = import.meta.glob<RouteObject[]>('../features/*/routes.tsx', { eager: true, import: 'workspaceRoutes' });

export const moduleWorkspaceRoutes: RouteObject[] = Object.keys(found)
    .sort()
    .flatMap((path) => found[path] ?? []);
