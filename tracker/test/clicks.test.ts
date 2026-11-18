import { describe, expect, it } from 'vitest';
import { addBookingContext, addWaRef, classify, waNumber } from '../src/index';

function anchor(href: string, text = 'Escríbenos'): HTMLAnchorElement {
    const a = document.createElement('a');
    a.href = href;
    a.textContent = text;
    document.body.appendChild(a);
    return a;
}

describe('clics', () => {
    it('reconoce los enlaces de WhatsApp', () => {
        expect(waNumber('https://wa.me/573001234567?text=Hola')).toBe('573001234567');
        expect(waNumber('https://api.whatsapp.com/send?phone=573001234567')).toBe('573001234567');
        expect(waNumber('whatsapp://send?phone=573001234567')).toBe('573001234567');
        expect(waNumber('https://tienda.co/whatsapp')).toBeNull();
    });

    it('clasifica teléfono, correo, descargas y data-igr-cta', () => {
        expect(classify(anchor('tel:+573001234567', 'Llámanos'))).toMatchObject({ kind: 'phone', label: 'Llámanos' });
        expect(classify(anchor('mailto:ventas@tienda.co?subject=x'))).toMatchObject({ kind: 'email', target: 'mailto:ventas@tienda.co' });
        expect(classify(anchor('/catalogo.pdf'))).toMatchObject({ kind: 'download' });
        const b = document.createElement('button');
        b.setAttribute('data-igr-cta', 'cotizar');
        b.innerHTML = '<span>Cotizar ahora</span>';
        expect(classify(b.firstElementChild as Element)).toMatchObject({ kind: 'custom', target: 'cotizar', label: 'Cotizar ahora' });
        expect(classify(anchor('/producto/x'))).toBeNull();
    });

    it('agrega el token solo a números conectados y con el formato configurado', () => {
        const conf = { enabled: true, numbers: ['+57 300 123 4567'], format: '{text} (ref: {token})' };
        const a = anchor('https://wa.me/573001234567?text=Hola%2C%20me%20interesa');
        const token = addWaRef(a, conf);
        expect(token).toMatch(/^[A-HJ-NP-Z2-9]{5}$/);
        expect(new URL(a.href).searchParams.get('text')).toBe('Hola, me interesa (ref: ' + token + ')');
        const other = anchor('https://wa.me/573009999999');
        expect(addWaRef(other, conf)).toBeNull();
        expect(other.href).toBe('https://wa.me/573009999999');
        expect(addWaRef(anchor('https://wa.me/573001234567'), { ...conf, enabled: false })).toBeNull();
        const empty = anchor('https://wa.me/573001234567');
        const t2 = addWaRef(empty, conf);
        expect(new URL(empty.href).searchParams.get('text')).toBe('(ref: ' + t2 + ')');
    });

    it('el botón de reservas lleva el visitante y el último contacto (UTM y click IDs) a la página del hub', () => {
        const a = anchor('https://in.growth.test/p/pk_abc/reservar/valoracion', 'Agendar cita');
        addBookingContext(a, 'f2c3a1b0-0000-4000-8000-000000000001', {
            f: { utm: { source: 'facebook' } },
            l: { utm: { source: 'google', medium: 'cpc', campaign: 'ortodoncia' }, click_ids: { gclid: 'Cj0KCQ-x' } },
        });
        const p = new URL(a.href).searchParams;
        expect(new URL(a.href).pathname).toBe('/p/pk_abc/reservar/valoracion');
        expect(p.get('aid')).toBe('f2c3a1b0-0000-4000-8000-000000000001');
        expect(p.get('utm_source')).toBe('google');
        expect(p.get('utm_campaign')).toBe('ortodoncia');
        expect(p.get('gclid')).toBe('Cj0KCQ-x');
        // Sin consentimiento de analítica no hay visitante ni fuentes: el enlace queda igual.
        const b = anchor('https://in.growth.test/p/pk_abc/reservar');
        addBookingContext(b, '', {});
        expect(b.href).toBe('https://in.growth.test/p/pk_abc/reservar');
    });
});
