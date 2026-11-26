import i18n from 'i18next';
import { describe, expect, it } from 'vitest';
import { describeTargeting, rulesSentence } from './rules';
import { templatesFor } from './templates';
import { addVariant, consentsForFields, evenWeights, fillPlaceholders, linesToList, nextQuestion, payload, prizeOdds, removeVariant, slugKey } from './model';
import type { Quiz, Targeting, WidgetDraft, WidgetTemplate, WidgetVariant } from './types';

const variant = (key: string, weight = 100): WidgetVariant => ({
    key,
    weight,
    design: { position: 'center', theme: { background: '#FFFFFF', text: '#111827', accent: '#4F46E5', accent_text: '#FFFFFF' } },
    content: { title: 'Hola', body: '', fields: [{ key: 'email', type: 'email', required: true, label: 'Correo' }], cta: 'Ok', success: '', footer: '' },
});

describe('variantes A/B', () => {
    it('reparte el 100 % y renombra las variantes en orden', () => {
        expect(evenWeights(3)).toEqual([34, 33, 33]);
        const two = addVariant([variant('a')]);
        expect(two.map((v) => [v.key, v.weight])).toEqual([
            ['a', 50],
            ['b', 50],
        ]);
        const three = addVariant(two);
        expect(three.map((v) => v.key)).toEqual(['a', 'b', 'c']);
        expect(removeVariant(three, 0).map((v) => [v.key, v.weight])).toEqual([
            ['a', 50],
            ['b', 50],
        ]);
        expect(removeVariant([variant('a')], 0)).toHaveLength(1);
        expect(addVariant(['a', 'b', 'c', 'd'].map((k) => variant(k, 25)))).toHaveLength(4);
    });
});

describe('ruleta y textos', () => {
    it('calcula la probabilidad de cada premio sin contar pesos negativos', () => {
        expect(prizeOdds([{ key: 'a', label: '5%', weight: 3, coupon: null }, { key: 'b', label: '10%', weight: 1, coupon: null }, { key: 'c', label: 'x', weight: -2, coupon: null }])).toEqual([0.75, 0.25, 0]);
        expect(prizeOdds([{ key: 'a', label: '', weight: 0, coupon: null }])).toEqual([0]);
    });

    it('llena el código y el premio de ejemplo', () => {
        expect(fillPlaceholders('¡Ganaste {{premio}}! Tu cupón es {{ codigo }}', { premio: '10%', codigo: 'RULETA-1' })).toBe('¡Ganaste 10%! Tu cupón es RULETA-1');
    });

    it('lee rutas y IDs por línea o coma y arma claves legibles', () => {
        expect(linesToList(' /tienda/*\n\n/ofertas , 88')).toEqual(['/tienda/*', '/ofertas', '88']);
        expect(slugKey('Piel muy seca', 'o1')).toBe('piel_muy_seca');
        expect(slugKey('¿?', 'o2')).toBe('o2');
    });
});

describe('consentimiento', () => {
    it('muestra cada casilla junto al campo de su canal', () => {
        const email = [{ key: 'email' as const, required: true, label: '' }];
        const phone = [{ key: 'phone' as const, required: true, label: '' }];
        expect(consentsForFields(['email', 'whatsapp', 'sms'], email)).toEqual(['email']);
        expect(consentsForFields(['email', 'whatsapp', 'sms'], phone)).toEqual(['whatsapp', 'sms']);
    });
});

describe('quiz', () => {
    const quiz: Quiz = {
        questions: [
            { id: 'q1', text: '¿Dónde?', options: [{ key: 'in', label: 'Interior', next: 'q3' }, { key: 'out', label: 'Exterior', next: 'end' }, { key: 'x', label: 'Otro' }] },
            { id: 'q2', text: 'Saltada', options: [] },
            { id: 'q3', text: '¿Presupuesto?', options: [] },
        ],
        outcomes: { rules: [], fallback: { products: { product_ids: [], category_ids: [], brands: [], max_price: null, kind: 'trending' }, message: '' }, limit: 4 },
    };
    it('sigue la lógica de cada respuesta', () => {
        const first = quiz.questions[0] as Quiz['questions'][number];
        expect(nextQuestion(quiz, first, 'in')?.id).toBe('q3');
        expect(nextQuestion(quiz, first, 'out')).toBeNull();
        expect(nextQuestion(quiz, first, 'x')?.id).toBe('q2');
    });
});

describe('payload', () => {
    it('no manda el tipo de campo (lo pone el hub)', () => {
        const draft = { variants: [variant('a')] } as unknown as WidgetDraft;
        expect(payload(draft).variants[0]?.content.fields[0]).toEqual({ key: 'email', required: true, label: 'Correo' });
    });
});

const targeting = (patch: Partial<Targeting> = {}): Targeting => ({
    trigger: { type: 'time', value: 8 },
    pages: { include: [], exclude: [] },
    page_types: [],
    devices: [],
    new_visitors: null,
    utm: {},
    referral: false,
    countries: [],
    segments: [],
    cart_value_min: null,
    hide_for_subscribers: true,
    frequency: { max_views: 2, cooldown_days: 7 },
    ...patch,
});

describe('reglas en palabras', () => {
    const t = i18n.getFixedT('es-CO');

    it('dice cuándo, dónde, a quién y cuántas veces', () => {
        const summary = describeTargeting('popup', targeting(), t);
        expect(summary.when).toBe('A los 8 segundos de abrir la página');
        expect(summary.conditions).toEqual(['en todas las páginas', 'no a quien ya te dejó sus datos']);
        expect(summary.frequency).toBe('Máximo 2 veces cada 7 días por persona');
    });

    it('nombra las condiciones que restringen y calla las que no', () => {
        const summary = describeTargeting(
            'popup',
            targeting({
                trigger: { type: 'exit_intent', value: null },
                page_types: ['product', 'category'],
                devices: ['mobile'],
                new_visitors: true,
                segments: ['sg_1'],
                hide_for_subscribers: false,
                frequency: { max_views: 0, cooldown_days: 0 },
            }),
            t,
            { segments: [{ id: 'sg_1', name: 'VIP', member_count: 3 }] },
        );
        expect(rulesSentence(summary)).toBe('Cuando la persona va a salir de la página · en producto y categoría · en celular · solo a quien visita por primera vez · solo a VIP · Sin límite de veces');
    });

    it('un formulario incrustado está siempre donde lo pongas, sin frecuencia', () => {
        const summary = describeTargeting('embedded_form', targeting({ hide_for_subscribers: false }), t);
        expect(summary.when).toMatch(/Siempre visible/);
        expect(summary.conditions).toEqual([]);
        expect(summary.frequency).toBeNull();
    });
});

describe('galería de plantillas', () => {
    const tpl = (key: string, audience: WidgetTemplate['audience'], gives_coupon = false): WidgetTemplate => ({ key, kind: 'popup', objective: 'capture', audience, gives_coupon });
    const all = [tpl('whatsapp_coupon', 'store', true), tpl('wa_button', 'both'), tpl('contact_form', 'services'), tpl('survey', 'store')];

    it('un negocio sin tienda ve el contacto y WhatsApp primero y nunca un cupón', () => {
        expect(templatesFor(all, { store: false, services: true }).map((x) => x.key)).toEqual(['contact_form', 'wa_button']);
    });

    it('una tienda empieza por el cupón y no ve lo de servicios', () => {
        expect(templatesFor(all, { store: true, services: false }).map((x) => x.key)).toEqual(['whatsapp_coupon', 'survey', 'wa_button']);
    });
});
