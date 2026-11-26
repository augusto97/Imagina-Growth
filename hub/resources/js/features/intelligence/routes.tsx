import type { RouteObject } from 'react-router';

/* Inteligencia e IA (M17, Fase 4). `inteligencia/predicciones` la registra el módulo de predicciones. */
export const workspaceRoutes: RouteObject[] = [
    { path: 'inteligencia', lazy: async () => ({ Component: (await import('./IntelligencePage')).IntelligencePage }) },
    { path: 'inteligencia/preguntale', lazy: async () => ({ Component: (await import('./AskPage')).AskPage }) },
    // Resultados → Informes: el mensual (antes en features/agency) y el semanal del analista, en una sola pantalla.
    { path: 'reportes', lazy: async () => ({ Component: (await import('./InformesPage')).InformesPage }) },
    { path: 'inteligencia/informes', lazy: async () => ({ Component: (await import('./InformesPage')).WeeklyInformesPage }) },
];
