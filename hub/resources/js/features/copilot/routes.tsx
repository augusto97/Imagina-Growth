import type { RouteObject } from 'react-router';

/** Copiloto (M10, inteligencia.md §6.4–6.5): historial de propuestas y tono de marca. */
export const workspaceRoutes: RouteObject[] = [{ path: 'copiloto', lazy: async () => ({ Component: (await import('./CopilotPage')).CopilotPage }) }];
