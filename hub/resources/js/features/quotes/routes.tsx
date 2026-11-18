import type { RouteObject } from 'react-router';

/** Cotizador (M18): calculadoras por servicio, área o paquetes y las cotizaciones que piden en el sitio. */
export const workspaceRoutes: RouteObject[] = [
    { path: 'en-el-sitio/cotizador', lazy: async () => ({ Component: (await import('./QuotesPage')).QuotesPage }) },
    { path: 'en-el-sitio/cotizador/:calculatorId', lazy: async () => ({ Component: (await import('./QuoteEditorPage')).QuoteEditorPage }) },
];
