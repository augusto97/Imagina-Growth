import type { RouteObject } from 'react-router';

/** Fidelización (M13): programa de puntos, niveles VIP, recompensas, saldo a favor y miembros. */
export const workspaceRoutes: RouteObject[] = [
    { path: 'fidelizacion', lazy: async () => ({ Component: (await import('./LoyaltyPage')).LoyaltyPage }) },
];
