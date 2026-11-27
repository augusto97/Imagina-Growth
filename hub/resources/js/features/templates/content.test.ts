import { describe, expect, it } from 'vitest';
import { BLANK_EMAIL_MJML, fillWaText, insertAt, liveChecks, missingWaSlots, smsSegments, usesPlaceholderImage } from './content';
import { EMAIL_BLOCKS, blocksFor, textOn } from './editor/blocks';

describe('smsSegments', () => {
    it('cuenta partes en GSM-7 y en UCS-2 como el servidor', () => {
        expect(smsSegments('a'.repeat(160))).toEqual({ encoding: 'GSM-7', length: 160, segments: 1 });
        expect(smsSegments('a'.repeat(161)).segments).toBe(2);
        expect(smsSegments('€')).toEqual({ encoding: 'GSM-7', length: 2, segments: 1 });
        expect(smsSegments('Canción')).toEqual({ encoding: 'UCS-2', length: 7, segments: 1 });
        expect(smsSegments('ó'.repeat(71)).segments).toBe(2);
    });
});

describe('liveChecks', () => {
    it('exige asunto y enlace de baja en el correo de marketing, no en el transaccional', () => {
        const codes = (c: ReturnType<typeof liveChecks>) => c.map((x) => x.code);
        expect(codes(liveChecks({ channel: 'email', category: 'marketing', subject: '', body: '<p>Hola</p>' }))).toEqual(['subject_missing', 'unsubscribe_missing']);
        expect(codes(liveChecks({ channel: 'email', category: 'transactional', subject: 'Tu pedido', body: '<p>Hola</p>' }))).toEqual([]);
        expect(codes(liveChecks({ channel: 'email', category: 'marketing', subject: 'x', body: BLANK_EMAIL_MJML }))).toEqual([]);
    });

    it('un SMS de marketing debe decir cómo darse de baja', () => {
        expect(liveChecks({ channel: 'sms', category: 'marketing', body: 'Promo hoy' }).map((c) => c.code)).toEqual(['sms_optout_missing']);
        expect(liveChecks({ channel: 'sms', category: 'marketing', body: 'Promo hoy. Responde BAJA para salir' })).toEqual([]);
    });
});

describe('helpers', () => {
    it('inserta la variable donde está el cursor', () => {
        expect(insertAt('Hola !', 5, 5, '{{ contacto.nombre }}')).toEqual({ value: 'Hola {{ contacto.nombre }}!', caret: 26 });
        expect(insertAt('abc', 1, 2, 'X')).toEqual({ value: 'aXc', caret: 2 });
    });

    it('detecta las ranuras de WhatsApp sin variable y arma la burbuja', () => {
        const slots = { header: ['1'], header_media: null, body: ['1', '2'], buttons: [{ index: 0, type: 'url', text: 'Ver', needs_value: true }] };
        expect(missingWaSlots(slots, { body: { '1': '{{ contacto.nombre }}' } })).toEqual(['header.1', 'body.2', 'buttons.0']);
        expect(fillWaText('Hola {{1}}, tu {{2}}', ['Ana', 'carrito'])).toBe('Hola Ana, tu carrito');
        expect(fillWaText('Pedido {{numero}}', { numero: '#10' })).toBe('Pedido #10');
    });

    it('los bloques nunca ponen Liquid entre etiquetas de tabla y empiezan con una sección', () => {
        const ctx = { brand: '#4f46e5', onBrand: '#ffffff', origin: 'https://growth.test', t: (k: string) => k };
        for (const block of EMAIL_BLOCKS) {
            const content = block.content(ctx);
            expect(content, block.id).not.toMatch(/<\/?(table|tr|tbody)\b[^>]*>\s*\{%/);
            expect(content.startsWith('<mj-section'), block.id).toBe(true);
            // Sin etiquetas de MJML con cierre propio: el parser HTML del editor no las entiende.
            expect(content, block.id).not.toMatch(/<mj-[a-z-]+[^>]*\/>/);
        }
        expect(EMAIL_BLOCKS.map((b) => b.id)).toEqual(expect.arrayContaining(['igr-product', 'igr-recommendations', 'igr-cart', 'igr-coupon', 'igr-points', 'igr-review', 'igr-whatsapp', 'igr-appointment']));
    });

    it('los usados en tienda no se ofrecen sin tienda, ni los de servicios sin servicios', () => {
        const ids = (store: boolean, services: boolean) => blocksFor({ store, services }).map((b) => b.id);
        expect(ids(false, true)).not.toContain('igr-cart');
        expect(ids(false, true)).toContain('igr-appointment');
        expect(ids(true, false)).toContain('igr-coupon');
        expect(ids(true, false)).not.toContain('igr-quote');
        expect(ids(true, true)).toEqual(EMAIL_BLOCKS.map((b) => b.id));
        expect(ids(false, true)).toContain('igr-text');
    });

    it('elige texto blanco u oscuro sobre el color de la marca', () => {
        expect(textOn('#4f46e5')).toBe('#ffffff');
        expect(textOn('#111827')).toBe('#ffffff');
        expect(textOn('#fde68a')).toBe('#111827');
        expect(textOn('no es color')).toBe('#ffffff');
    });

    it('avisa si quedaron imágenes de muestra', () => {
        expect(usesPlaceholderImage('<img src="https://growth.test/images/email/placeholder-hero.png">')).toBe(true);
        expect(usesPlaceholderImage('<img src="https://tienda.co/foto.png">')).toBe(false);
        expect(liveChecks({ channel: 'email', category: 'utility', subject: 'Hola', preheader: '', body: '<img src="/images/email/placeholder-image.png">' }).map((c) => c.code)).toContain('placeholder_image');
    });
});
