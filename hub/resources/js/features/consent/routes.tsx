import type { RouteObject } from 'react-router';

/* Consentimiento: textos versionados, modo, política de privacidad y solicitudes de los titulares. */
export const workspaceRoutes: RouteObject[] = [{ path: 'consentimiento', lazy: async () => ({ Component: (await import('./ConsentPage')).ConsentPage }) }];
