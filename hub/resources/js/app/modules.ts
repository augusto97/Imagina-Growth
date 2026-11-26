import type { RouteObject } from 'react-router';
import { withTexts } from '@/lib/i18n';
import { ROUTE_TEXTS } from './routeTexts';

/*
 * Cada módulo registra sus pantallas dentro de /w/:workspaceId en
 * features/<módulo>/routes.tsx exportando `workspaceRoutes`. Así los módulos
 * no editan router.tsx ni se pisan entre sí. Cada pantalla perezosa espera,
 * junto con su chunk, los textos que usa su carpeta (app/routeTexts.ts).
 */
const found = import.meta.glob<RouteObject[]>('../features/*/routes.tsx', { eager: true, import: 'workspaceRoutes' });

/** Las rutas de una carpeta con sus textos: `lazy` espera la pantalla y sus módulos de textos a la vez. */
export function withFeatureTexts(feature: string, routes: readonly RouteObject[]): RouteObject[] {
    const texts = ROUTE_TEXTS[feature] ?? [];
    if (texts.length === 0) {
        return [...routes];
    }
    return routes.map((route) => (typeof route.lazy === 'function' ? { ...route, lazy: withTexts(texts, route.lazy) } : route));
}

export const moduleWorkspaceRoutes: RouteObject[] = Object.keys(found)
    .sort()
    .flatMap((path) => withFeatureTexts(/features\/([^/]+)\/routes\.tsx$/.exec(path)?.[1] ?? '', found[path] ?? []));
