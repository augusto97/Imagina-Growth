import { describe, expect, it } from 'vitest';
import { addTag, consentSource, eventGroup, eventKey, initials, toggleGroup, touchDetail } from './labels';

describe('etiquetas del perfil 360', () => {
    it('agrupa cada evento como el hub (ProfileQuery::TIMELINE_GROUPS)', () => {
        expect(eventGroup('page.viewed')).toBe('navegacion');
        expect(eventGroup('identify')).toBe('navegacion');
        expect(eventGroup('checkout.started')).toBe('carrito');
        expect(eventGroup('order.status_changed')).toBe('pedidos');
        expect(eventGroup('form.submitted')).toBe('cuenta');
        expect(eventGroup('review.submitted')).toBe('resenas');
        expect(eventGroup('consent.revoked')).toBe('consentimiento');
        expect(eventGroup('segment.entered')).toBe('segmentos');
    });

    it('convierte el nombre del evento en una clave plana de i18n', () => {
        expect(eventKey('order.status_changed')).toBe('order_status_changed');
        expect(eventKey('cart.item_added')).toBe('cart_item_added');
    });

    it('alterna grupos y conserva el orden de la pantalla', () => {
        expect(toggleGroup(['pedidos'], 'navegacion')).toEqual(['navegacion', 'pedidos']);
        expect(toggleGroup(['navegacion', 'pedidos'], 'navegacion')).toEqual(['pedidos']);
    });

    it('agrega etiquetas sin duplicar ni dejar espacios', () => {
        expect(addTag(['VIP'], '  vip ')).toEqual(['VIP']);
        expect(addTag(['VIP'], '  cliente   mayorista ')).toEqual(['VIP', 'cliente mayorista']);
        expect(addTag([], '   ')).toEqual([]);
        expect(addTag([], 'x'.repeat(60))[0]).toHaveLength(40);
    });

    it('lee el origen del consentimiento sin usar el texto crudo como clave', () => {
        expect(consentSource('checkout')).toEqual({ key: 'contacts.privacy.sources.checkout', values: {} });
        expect(consentSource('form:cf7:12')).toEqual({ key: 'contacts.privacy.sources.form', values: { plugin: 'cf7', id: '12' } });
        expect(consentSource('raro')).toEqual({ key: 'contacts.privacy.sources.other', values: { source: 'raro' } });
        expect(consentSource(null)).toBeNull();
    });

    it('resume un toque de atribución y saca iniciales', () => {
        expect(touchDetail({ channel: 'paid_google', utm_source: 'google', utm_medium: 'cpc', utm_campaign: 'rebajas' })).toBe('google / cpc · rebajas');
        expect(touchDetail({ channel: 'direct' })).toBeNull();
        expect(initials('María José Pérez')).toBe('MP');
        expect(initials('')).toBe('·');
    });
});
