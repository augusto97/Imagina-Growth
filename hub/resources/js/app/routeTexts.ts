/*
 * Módulos de textos (`locales/<locale>/<módulo>.json`) que necesita cada carpeta de `features/` además de los del
 * arranque (lib/i18n.ts, EAGER_TEXTS). Las rutas los esperan junto con su chunk (`withTexts`; app/modules.ts y
 * app/router.tsx). Lo calcula y lo verifica `routeTexts.test.ts` leyendo el código: si una pantalla empieza a usar
 * textos de otro módulo, la prueba falla. Para regenerarlo: `UPDATE_ROUTE_TEXTS=1 npx vitest run
 * resources/js/app/routeTexts.test.ts`.
 */
export const ROUTE_TEXTS: Record<string, readonly string[]> = {
    agency: ['agency', 'platform'],
    agenda: ['agenda', 'contacts'],
    agent: ['agent', 'contacts'],
    analytics: ['agency', 'analytics', 'contacts', 'home', 'insights', 'regions'],
    audit: ['activity'],
    calendar: ['analytics'],
    campaigns: ['analytics', 'campaigns', 'contacts', 'copilot'],
    channels: ['channels', 'connections'],
    connections: ['channels', 'connections', 'contacts', 'platform-settings'],
    consent: ['channels', 'consent', 'contacts', 'privacy'],
    contacts: ['analytics', 'channels', 'contacts', 'intelligence', 'leads', 'loyalty', 'predictions', 'privacy', 'qualification', 'regions'],
    copilot: ['copilot'],
    flows: ['analytics', 'copilot', 'flows', 'loyalty', 'reactivation', 'segments'],
    home: ['agency', 'analytics', 'connections', 'contacts', 'home', 'insights'],
    inbox: ['contacts', 'inbox', 'leads', 'qualification'],
    insights: ['analytics', 'insights'],
    integrations: ['contacts', 'integrations'],
    intelligence: ['analytics', 'intelligence'],
    leads: ['analytics', 'contacts', 'leads', 'qualification', 'today'],
    loyalty: ['analytics', 'campaigns', 'contacts', 'loyalty'],
    measurement: ['audiences', 'connections', 'measurement'],
    onsite: ['analytics', 'contacts', 'onsite'],
    platform: ['analytics', 'backups', 'channels', 'platform', 'platform-settings', 'updates'],
    predictions: ['analytics', 'predictions'],
    privacy: ['contacts', 'privacy'],
    quotes: ['contacts', 'onsite', 'quotes'],
    recommendations: ['analytics', 'onsite-commerce'],
    referrals: ['analytics', 'referrals'],
    reviews: ['analytics', 'contacts', 'intelligence', 'reviews'],
    sections: ['analytics'],
    segments: ['segments'],
    settings: ['agent', 'business'],
    sites: ['connections', 'contacts'],
    'style-guide': ['styleguide'],
    survey: ['contacts', 'survey'],
    team: ['team'],
    templates: ['contacts', 'copilot', 'templates'],
    today: ['contacts', 'leads', 'qualification', 'today'],
};
