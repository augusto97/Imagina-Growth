import type { RouteObject } from 'react-router';

/**
 * Captación sin tienda (rediseño v2 §11.3, modulos.md M12 y M18): páginas de captura con su QR, chats de WhatsApp con
 * QR y leads de anuncios. Viven en «En tu web» y no necesitan la web conectada.
 */
export const workspaceRoutes: RouteObject[] = [
    { path: 'paginas-de-captura', lazy: async () => ({ Component: (await import('./CapturePagesPage')).CapturePagesPage }) },
    { path: 'paginas-de-captura/:pageId', lazy: async () => ({ Component: (await import('./LandingEditorPage')).LandingEditorPage }) },
    { path: 'whatsapp-qr', lazy: async () => ({ Component: (await import('./WhatsAppLinksPage')).WhatsAppLinksPage }) },
    { path: 'leads-de-anuncios', lazy: async () => ({ Component: (await import('./LeadAdsPage')).LeadAdsPage }) },
];
