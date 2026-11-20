import type { RouteObject } from 'react-router';

/* Bandeja de conversaciones (M11). `bandeja/agente` lo registra el agente de IA (otro módulo). */
const page = async () => ({
    Component: (await import('./InboxPage')).InboxPage,
});

export const workspaceRoutes: RouteObject[] = [
    // Llena el alto de la pantalla: lista y chat con su propio desplazamiento (pageLayout.ts, «fill»).
    { path: 'bandeja', handle: { fill: true }, lazy: page },
    { path: 'bandeja/:conversation', handle: { fill: true }, lazy: page },
];
