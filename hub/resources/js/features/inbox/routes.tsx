import type { RouteObject } from 'react-router';

/* Bandeja de conversaciones (M11). `bandeja/agente` lo registra el agente de IA (otro módulo). */
const page = async () => ({
    Component: (await import('./InboxPage')).InboxPage,
});

export const workspaceRoutes: RouteObject[] = [
    { path: 'bandeja', lazy: page },
    { path: 'bandeja/:conversation', lazy: page },
];
