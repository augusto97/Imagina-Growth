import { beforeEach, describe, expect, it } from 'vitest';
import { detectConsent } from '../src/consent';
import { reset } from './helpers';

const def = { analytics: false, marketing: false, source: 'default' };
beforeEach(reset);

describe('consentimiento', () => {
    it('prefiere la WP Consent API', () => {
        (window as unknown as Record<string, unknown>)['wp_has_consent'] = (c: string) => c === 'statistics';
        document.cookie = 'cmplz_marketing=allow';
        expect(detectConsent(def)).toEqual({ analytics: true, marketing: false, source: 'wp-consent-api' });
    });

    it('lee Complianz, CookieYes y el banner propio', () => {
        document.cookie = 'cmplz_statistics=allow';
        document.cookie = 'cmplz_marketing=deny';
        expect(detectConsent(def)).toMatchObject({ analytics: true, marketing: false, source: 'complianz' });
        reset();
        document.cookie = 'cookieyes-consent=' + encodeURIComponent('consentid:x,consent:yes,analytics:yes,advertisement:no');
        expect(detectConsent(def)).toMatchObject({ analytics: true, marketing: false, source: 'cookieyes' });
        reset();
        document.cookie = '_igr_consent=a1m1';
        expect(detectConsent(def)).toMatchObject({ analytics: true, marketing: true, source: 'igr-banner' });
        reset();
        expect(detectConsent(def)).toBe(def);
    });
});
