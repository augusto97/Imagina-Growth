import { beforeEach, describe, expect, it } from 'vitest';
import { adCookies, anonymousId, currentTouch, readSources, session, updateSources } from '../src/ids';
import { getCookie, shortToken, uuid4, uuid7 } from '../src/util';
import { reset, setUrl } from './helpers';

beforeEach(reset);

describe('identificadores', () => {
    it('genera UUID v4 y v7 válidos', () => {
        expect(uuid4()).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
        const v7 = uuid7(1763420700000);
        expect(v7).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
        expect(v7.replace(/-/g, '').slice(0, 12)).toBe((1763420700000).toString(16).padStart(12, '0'));
    });

    it('token de WhatsApp sin caracteres ambiguos', () => {
        for (let i = 0; i < 50; i++) expect(shortToken(5)).toMatch(/^[A-HJ-NP-Z2-9]{5}$/);
    });

    it('el ID anónimo se repara entre cookie y localStorage', () => {
        const first = anonymousId();
        expect(first.created).toBe(true);
        expect(getCookie('_igr_aid')).toBe(first.id);
        document.cookie = '_igr_aid=; Max-Age=0; Path=/';
        const again = anonymousId();
        expect(again).toEqual({ id: first.id, created: false });
        expect(getCookie('_igr_aid')).toBe(first.id);
        localStorage.clear();
        anonymousId();
        expect(localStorage.getItem('_igr_aid')).toBe(first.id);
    });
});

describe('fuentes', () => {
    it('captura UTM, click IDs y referrer externo; ignora la navegación interna', () => {
        setUrl('https://tienda.co/x?utm_source=facebook&utm_medium=paid_social&utm_campaign=nov&fbclid=IwAR0x&gclid=bad%20value', 'https://l.facebook.com/');
        const t = currentTouch();
        expect(t?.utm).toEqual({ source: 'facebook', medium: 'paid_social', campaign: 'nov' });
        expect(t?.click_ids).toEqual({ fbclid: 'IwAR0x' });
        expect(t?.referrer).toBe('https://l.facebook.com/');
        expect(t?.landing).toBe('https://tienda.co/x');
        setUrl('https://tienda.co/y', 'https://www.tienda.co/x');
        expect(currentTouch()).toBeNull();
    });

    it('el primer contacto dura 90 días y el último es el último no directo', () => {
        setUrl('https://tienda.co/?utm_source=google&utm_medium=cpc&gclid=g1');
        updateSources(currentTouch());
        setUrl('https://tienda.co/otra');
        updateSources(currentTouch());
        let s = readSources();
        expect(s.f?.click_ids).toEqual({ gclid: 'g1' });
        expect(s.l?.click_ids).toEqual({ gclid: 'g1' });
        setUrl('https://tienda.co/?utm_source=newsletter&utm_medium=email');
        updateSources(currentTouch());
        s = readSources();
        expect(s.f?.utm?.['source']).toBe('google');
        expect(s.l?.utm?.['source']).toBe('newsletter');
        updateSources(currentTouch(), undefined, Date.now() + 91 * 86400000);
        expect(readSources().f?.utm?.['source']).toBe('newsletter');
    });

    it('nueva sesión si cambia la campaña o llega un click ID nuevo', () => {
        setUrl('https://tienda.co/?utm_campaign=a');
        const a = session(currentTouch());
        expect(a.id).toMatch(/^s_[0-9a-f]{20}$/);
        setUrl('https://tienda.co/p2');
        expect(session(currentTouch()).id).toBe(a.id);
        setUrl('https://tienda.co/?utm_campaign=b');
        expect(session(currentTouch()).id).not.toBe(a.id);
    });

    it('crea _fbc desde fbclid con el formato de Meta y lee _ga', () => {
        setUrl('https://tienda.co/?fbclid=AbC123');
        document.cookie = '_ga=GA1.1.123456.7890';
        const ads = adCookies();
        expect(ads['fbc']).toMatch(/^fb\.1\.\d{13}\.AbC123$/);
        expect(ads['ga_client_id']).toBe('123456.7890');
    });
});
