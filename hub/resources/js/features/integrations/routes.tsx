import type { RouteObject } from 'react-router';

/* API e integraciones (M19): tokens, webhooks, servidor MCP y ecosistema Imagina. */
export const workspaceRoutes: RouteObject[] = [{ path: 'integraciones', lazy: async () => ({ Component: (await import('./IntegrationsPage')).IntegrationsPage }) }];
