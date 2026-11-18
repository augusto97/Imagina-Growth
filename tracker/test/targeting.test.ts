import { describe, expect, it } from 'vitest';
import { device, freqAllows, glob, hash, needsVisitor, pickVariant, staticMatch, visitorMatch, type Env } from '../widgets/lib/targeting';
import type { WidgetCfg } from '../widgets/lib/types';

const env = (o: Partial<Env> = {}): Env => ({ pageType: 'home', path: '/', device: 'desktop', utm: [{}], ref: null, cart: 0, ...o });
const w = (o: Partial<WidgetCfg> = {}): WidgetCfg => ({ id: 'wg_1', kind: 'popup', slot: null, ...o });

describe('reglas de muestra', () => {
    it('rutas con comodín y barra final', () => {
        expect(glob('/tienda/*', '/tienda/pinturas/')).toBe(true);
        expect(glob('/', '/')).toBe(true);
        expect(glob('/', '/contacto')).toBe(false);
        expect(glob('/checkout*', '/checkout/order-pay/1')).toBe(true);
        expect(glob('/contacto', '/contacto/')).toBe(true);
        expect(glob('/a.b', '/axb')).toBe(false);
    });

    it('página, tipo, dispositivo, UTM, referido y carrito', () => {
        const t = { pages: { include: ['/', '/tienda/*'], exclude: ['/tienda/ofertas'] }, page_types: ['home', 'category'], devices: ['mobile'] };
        expect(staticMatch(w({ targeting: t }), env({ device: 'mobile' }))).toBe(true);
        expect(staticMatch(w({ targeting: t }), env({ device: 'desktop' }))).toBe(false);
        expect(staticMatch(w({ targeting: t }), env({ device: 'mobile', path: '/tienda/ofertas', pageType: 'category' }))).toBe(false);
        expect(staticMatch(w({ targeting: t }), env({ device: 'mobile', pageType: 'product' }))).toBe(false);
        expect(staticMatch(w({ targeting: { utm: { source: 'Instagram' } } }), env({ utm: [{}, { source: 'instagram' }] }))).toBe(true);
        expect(staticMatch(w({ targeting: { utm: { source: 'instagram' } } }), env({ utm: [{ source: 'google' }] }))).toBe(false);
        expect(staticMatch(w({ targeting: { referral: true } }), env())).toBe(false);
        expect(staticMatch(w({ kind: 'referral_welcome' }), env({ ref: 'ANA7K2' }))).toBe(true);
        expect(staticMatch(w({ kind: 'referral_welcome' }), env())).toBe(false);
        expect(staticMatch(w({ targeting: { cart_value_min: '100000.00' } }), env({ cart: 99000 }))).toBe(false);
        expect(staticMatch(w({ targeting: { cart_value_min: '100000.00' } }), env({ cart: 150000 }))).toBe(true);
        expect(staticMatch(w(), env({ pageType: 'checkout' }))).toBe(false);
        expect(device(390, true)).toBe('mobile');
        expect(device(800, true)).toBe('tablet');
        expect(device(1280, false)).toBe('desktop');
    });

    it('visitor.json solo cuando hace falta, y sus reglas', () => {
        expect(needsVisitor(w({ targeting: { trigger: { type: 'time', value: 5 } } }))).toBe(false);
        expect(needsVisitor(w({ targeting: { countries: ['CO'] } }))).toBe(true);
        expect(needsVisitor(w({ targeting: { hide_for_subscribers: true } }))).toBe(true);
        expect(needsVisitor(w({ targeting: { new_visitors: null } }))).toBe(false);
        expect(needsVisitor(w({ targeting: { new_visitors: true } }))).toBe(true);
        expect(needsVisitor(w({ kind: 'referral_welcome' }))).toBe(true);
        const v = { known: false, returning: true, subscribed: { email: false, whatsapp: true }, country: 'CO', segments: ['sg_1'] };
        expect(visitorMatch(w({ targeting: { countries: ['co', 'MX'] } }), v)).toBe(true);
        expect(visitorMatch(w({ targeting: { countries: ['MX'] } }), v)).toBe(false);
        expect(visitorMatch(w({ targeting: { segments: ['sg_2'] } }), v)).toBe(false);
        expect(visitorMatch(w({ targeting: { new_visitors: true } }), v)).toBe(false);
        expect(visitorMatch(w({ targeting: { hide_for_subscribers: true }, content: { consents: [{ channel: 'whatsapp', label: 'x' }] } }), v)).toBe(false);
        expect(visitorMatch(w({ targeting: { hide_for_subscribers: true }, content: { consents: [{ channel: 'email', label: 'x' }] } }), v)).toBe(true);
        expect(visitorMatch(w({ kind: 'referral_welcome' }), { ...v, known: true })).toBe(false);
        // Sin datos: país y segmentos no se cumplen.
        expect(visitorMatch(w({ targeting: { countries: ['CO'] } }), {})).toBe(false);
    });

    it('frecuencia: envío, máximo de vistas, días de espera y cierre', () => {
        const now = Date.UTC(2026, 10, 18);
        const day = 86400000;
        expect(freqAllows(undefined, undefined, 'popup', now)).toBe(true);
        expect(freqAllows({ s: now - 30 * day }, undefined, 'popup', now)).toBe(false);
        expect(freqAllows({ v: 2, t: now - 30 * day }, { frequency: { max_views: 2, cooldown_days: 7 } }, 'popup', now)).toBe(false);
        expect(freqAllows({ v: 1, t: now - 3 * day }, { frequency: { max_views: 2, cooldown_days: 7 } }, 'popup', now)).toBe(false);
        expect(freqAllows({ v: 1, t: now - 8 * day }, { frequency: { max_views: 2, cooldown_days: 7 } }, 'popup', now)).toBe(true);
        expect(freqAllows({ v: 1, c: now - 3600000 }, undefined, 'popup', now)).toBe(false);
        expect(freqAllows({ v: 1, c: now - 2 * day }, undefined, 'popup', now)).toBe(true);
        expect(freqAllows({ s: now }, undefined, 'wa_widget', now)).toBe(true);
    });

    it('variante determinista por anonymous_id y respetando los pesos', () => {
        const wg = w({ variants: [{ key: 'a', weight: 50 }, { key: 'b', weight: 50 }] });
        const first = pickVariant(wg, 'aid-1:wg_1')?.key;
        for (let i = 0; i < 5; i++) expect(pickVariant(wg, 'aid-1:wg_1')?.key).toBe(first);
        const counts: Record<string, number> = { a: 0, b: 0 };
        for (let i = 0; i < 2000; i++) counts[pickVariant(wg, 'aid-' + i + ':wg_1')?.key ?? '']++;
        expect(counts['a']).toBeGreaterThan(850);
        expect(counts['b']).toBeGreaterThan(850);
        const skew = w({ variants: [{ key: 'a', weight: 90 }, { key: 'b', weight: 10 }] });
        let a = 0;
        for (let i = 0; i < 2000; i++) if (pickVariant(skew, 'x' + i)?.key === 'a') a++;
        expect(a).toBeGreaterThan(1650);
        expect(pickVariant(wg, null, 'b')?.key).toBe('b');
        expect(pickVariant(w(), 'x')).toBeNull();
        expect(hash('abc')).toBe(hash('abc'));
    });
});
