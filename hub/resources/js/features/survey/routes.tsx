import type { RouteObject } from 'react-router';

/* Encuesta «¿Cómo nos conociste?». */
export const workspaceRoutes: RouteObject[] = [{ path: 'encuesta', lazy: async () => ({ Component: (await import('./SurveyPage')).SurveyPage }) }];
