import type { RouteObject } from 'react-router';

/** Flujos (M09): lista, biblioteca de 20 flujos y constructor con React Flow. */
export const workspaceRoutes: RouteObject[] = [
    { path: 'flujos', lazy: async () => ({ Component: (await import('./FlowsPage')).FlowsPage }) },
    // El lienzo ocupa todo el ancho de la pantalla (guards.tsx, fullBleed).
    { path: 'flujos/:flowId', handle: { fullBleed: true }, lazy: async () => ({ Component: (await import('./builder/FlowBuilderPage')).FlowBuilderPage }) },
];
