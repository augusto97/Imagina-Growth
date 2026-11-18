import { describe, expect, it } from 'vitest';
import type { WaTemplate } from './api';
import { emptyForm, fill, formErrors, formFromTemplate, marketingWords, slugName, variables, variablesInOrder } from './templates';

describe('editor de plantillas de WhatsApp', () => {
    it('detecta las variables y su orden', () => {
        expect(variables('Hola {{1}}, tu pedido {{2}} y {{1}}')).toEqual([1, 2]);
        expect(variablesInOrder('Hola {{1}} {{2}}')).toBe(true);
        expect(variablesInOrder('Hola {{2}}')).toBe(false);
        expect(fill('Hola {{1}}, total {{2}}', ['Ana'])).toBe('Hola Ana, total {{2}}');
    });

    it('advierte el marketing disfrazado de utilidad, como el hub', () => {
        const form = { ...emptyForm('cha_1'), body: { text: '¿Confirmas tu pedido? Aprovecha 10% de descuento', examples: [] } };
        expect(marketingWords('UTILITY', form)).toEqual(['descuento', 'aprovecha', '%']);
        expect(marketingWords('MARKETING', form)).toEqual([]);
    });

    it('valida antes de mandar a Meta', () => {
        const form = { ...emptyForm('cha_1'), name: 'Confirmar Pedido', body: { text: 'Hola {{1}} y {{2}}', examples: ['Ana'] } };
        expect(formErrors(form, true)).toMatchObject({ name: 'channels.wa.errors.name', 'body.examples': 'channels.wa.errors.examples' });
        expect(slugName('Confirmar Pedido Ñandú')).toBe('confirmar_pedido_nandu');
        const ok = { ...form, name: 'confirmar_pedido', body: { text: 'Hola {{1}}', examples: ['Ana'] }, buttons: [{ type: 'URL' as const, text: 'Ver', url: '', tracked: true, phone: '', example: '' }] };
        expect(formErrors(ok, true)).toEqual({});
    });

    it('arma el formulario desde los componentes de Meta (botón con seguimiento incluido)', () => {
        const tpl: WaTemplate = {
            id: 'wat_1', account_id: 'cha_1', name: 'carrito', language: 'es', category: 'MARKETING', status: 'APPROVED', quality: null, rejected_reason: null, synced_at: null,
            components: [
                { type: 'HEADER', format: 'TEXT', text: 'Hola' },
                { type: 'BODY', text: 'Tu carrito {{1}}', example: { body_text: [['https://x.co']] } },
                { type: 'BUTTONS', buttons: [{ type: 'URL', text: 'Ir', url: 'https://go.test/c/{{1}}' }, { type: 'QUICK_REPLY', text: 'No gracias' }] },
            ],
        };
        const form = formFromTemplate(tpl, 'https://go.test');
        expect(form.header).toEqual({ format: 'TEXT', text: 'Hola', example: '' });
        expect(form.body).toEqual({ text: 'Tu carrito {{1}}', examples: ['https://x.co'] });
        expect(form.buttons[0]).toMatchObject({ type: 'URL', tracked: true, url: '' });
        expect(form.buttons[1]).toMatchObject({ type: 'QUICK_REPLY', text: 'No gracias' });
    });
});
