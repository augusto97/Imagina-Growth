import type { RouteObject } from 'react-router';

/** Medición (M06): destinos de conversiones, entregas, calidad de la señal y gasto. */
export const workspaceRoutes: RouteObject[] = [
    { path: 'medicion', lazy: async () => ({ Component: (await import('./MeasurementPage')).MeasurementPage }) },
];
