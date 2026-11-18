import type { RouteObject } from 'react-router';

/** Referidos, afiliados e influencers (M14): resumen, referidos y revisión, afiliados, comisiones y programa. */
export const workspaceRoutes: RouteObject[] = [
    { path: 'referidos', lazy: async () => ({ Component: (await import('./ReferralsPage')).ReferralsPage }) },
    { path: 'referidos/afiliados/:affiliateId', lazy: async () => ({ Component: (await import('./AffiliateDetailPage')).AffiliateDetailPage }) },
];
