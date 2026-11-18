import type { RouteObject } from 'react-router';

/** Campañas (M10): lista y calendario comercial, asistente de 5 pasos y reporte. */
export const workspaceRoutes: RouteObject[] = [
    { path: 'campanas', lazy: async () => ({ Component: (await import('./CampaignsPage')).CampaignsPage }) },
    { path: 'campanas/:campaignId', lazy: async () => ({ Component: (await import('./CampaignDetailPage')).CampaignDetailPage }) },
    { path: 'campanas/:campaignId/editar', lazy: async () => ({ Component: (await import('./CampaignWizardPage')).CampaignWizardPage }) },
];
