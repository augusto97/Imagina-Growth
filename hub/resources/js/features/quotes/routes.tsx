import type { RouteObject } from 'react-router';

/** Cotizador (M18): calculadoras por servicio, área o paquetes, su asistente y las cotizaciones que piden en el sitio. */
export const workspaceRoutes: RouteObject[] = [
    { path: 'en-el-sitio/cotizador', lazy: async () => ({ Component: (await import('./QuotesPage')).QuotesPage }) },
    { path: 'en-el-sitio/cotizador/nuevo', lazy: async () => ({ Component: (await import('./QuoteWizardPage')).QuoteWizardPage }) },
    { path: 'en-el-sitio/cotizador/:calculatorId', lazy: async () => ({ Component: (await import('./QuoteEditorPage')).QuoteEditorPage }) },
];
