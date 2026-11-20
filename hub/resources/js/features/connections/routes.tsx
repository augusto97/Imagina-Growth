import type { RouteObject } from 'react-router';

/** Conexiones con un clic (docs/auditoria-2026-11.md §6): Meta, Google y TikTok. */
export const workspaceRoutes: RouteObject[] = [{ path: 'conexiones', lazy: async () => ({ Component: (await import('./ConnectionsPage')).ConnectionsPage }) }];
