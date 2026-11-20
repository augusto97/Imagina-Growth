import type { RouteObject } from 'react-router';

/* «Hoy», el asistente del vendedor (M18): a quién escribirle hoy y qué decirle. */
export const workspaceRoutes: RouteObject[] = [{ path: 'hoy', lazy: async () => ({ Component: (await import('./TodayPage')).TodayPage }) }];
