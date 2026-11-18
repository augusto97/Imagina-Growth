import type { Consent } from './types';
import { getCookie } from './util';

/** Eventos de los banners que avisan un cambio de consentimiento (tracker/CLAUDE.md §7). */
export const CONSENT_EVENTS = [
    'wp_listen_for_consent_change',
    'cmplz_status_change',
    'cmplz_fire_categories',
    'cookieyes_consent_update',
    'CookiebotOnConsentReady',
    'CookiebotOnAccept',
    'CookiebotOnDecline',
    'igr:consent',
];

interface ConsentWindow {
    wp_has_consent?: (category: string) => boolean;
    Cookiebot?: { hasResponse?: boolean; consent?: { statistics?: boolean; marketing?: boolean } };
}

/**
 * Estado de consentimiento del visitante, en este orden: WP Consent API,
 * Complianz, CookieYes, Cookiebot, el banner ligero propio y, si no hay
 * ninguna señal, el valor por defecto que calcula el connector.
 */
export function detectConsent(fallback: Consent): Consent {
    const w = window as unknown as ConsentWindow;
    try {
        if (typeof w.wp_has_consent === 'function') {
            return { analytics: !!w.wp_has_consent('statistics'), marketing: !!w.wp_has_consent('marketing'), source: 'wp-consent-api' };
        }
    } catch {
        /* la API del banner falló: se sigue con las cookies */
    }
    const cs = getCookie('cmplz_statistics');
    const cm = getCookie('cmplz_marketing');
    if (cs || cm) return { analytics: cs === 'allow', marketing: cm === 'allow', source: 'complianz' };
    const cy = getCookie('cookieyes-consent');
    if (cy) return { analytics: /analytics:yes/.test(cy), marketing: /advertisement:yes/.test(cy), source: 'cookieyes' };
    const cb = w.Cookiebot;
    if (cb && cb.hasResponse && cb.consent) return { analytics: !!cb.consent.statistics, marketing: !!cb.consent.marketing, source: 'cookiebot' };
    const own = getCookie('_igr_consent');
    if (own) return { analytics: own.indexOf('a1') >= 0, marketing: own.indexOf('m1') >= 0, source: 'igr-banner' };
    return fallback;
}
