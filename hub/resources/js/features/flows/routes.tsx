import type { RouteObject } from 'react-router';

/** Flujos (M09): lista, biblioteca de 20 flujos y constructor con React Flow. */
export const workspaceRoutes: RouteObject[] = [
    { path: 'flujos', lazy: async () => ({ Component: (await import('./FlowsPage')).FlowsPage }) },
    { path: 'flujos/:flowId', lazy: async () => ({ Component: (await import('./builder/FlowBuilderPage')).FlowBuilderPage }) },
];
