import type { RouteObject } from 'react-router';

/* Plantillas y contenido (modulos.md M08). El editor de correo (GrapesJS) va en su propio chunk. */
export const workspaceRoutes: RouteObject[] = [
    { path: 'plantillas', lazy: async () => ({ Component: (await import('./TemplatesPage')).TemplatesPage }) },
    { path: 'plantillas/:templateId', lazy: async () => ({ Component: (await import('./TemplateEditorPage')).TemplateEditorPage }) },
];
