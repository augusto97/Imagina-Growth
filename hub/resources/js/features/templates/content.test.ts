import { describe, expect, it } from 'vitest';
import { BLANK_EMAIL_MJML, fillWaText, insertAt, liveChecks, missingWaSlots, smsSegments } from './content';
import { DYNAMIC_BLOCKS } from './editor/blocks';

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

    it('los bloques dinámicos nunca ponen Liquid entre etiquetas de tabla', () => {
        for (const block of DYNAMIC_BLOCKS) {
            expect(block.content, block.id).not.toMatch(/<\/?(table|tr|tbody)\b[^>]*>\s*\{%/);
            expect(block.content.startsWith('<mj-section'), block.id).toBe(true);
        }
        expect(DYNAMIC_BLOCKS.map((b) => b.id)).toEqual(expect.arrayContaining(['igr-product', 'igr-recommendations', 'igr-cart', 'igr-coupon', 'igr-points', 'igr-reviews', 'igr-whatsapp']));
    });
});
