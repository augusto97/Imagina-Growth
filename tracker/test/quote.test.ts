import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reset, setUrl } from './helpers';
import { ctx, fakeTracker, hubFetch, renderer, tick } from './whelpers';

const ID = 'qc_01JE0000000000000000000000';

const definition = {
    ok: true,
    calculator: {
        id: ID, name: 'Pintura de interiores', kind: 'area', currency: 'COP',
        definition: {
            fields: [
                { key: 'area', label: 'Área a pintar', type: 'number', required: true, icon: '📐', unit: 'm²', min: 5, max: 2000, step: 1, default: null },
                { key: 'tipo', label: 'Tipo de pintura', type: 'select', required: true, options: [{ value: 'vinilo', label: 'Vinilo', icon: '🏠', description: 'Interiores' }, { value: 'esmalte', label: 'Esmalte' }] },
                { key: 'extras', label: 'Extras', type: 'checkbox', required: false, options: [{ value: 'techo', label: 'Techo' }] },
            ],
            packages: [],
            intro: 'Tres preguntas y ves tu precio.',
            note: 'Incluye materiales.',
        },
        capture: { required: true, fields: ['first_name', 'phone'], require: 'phone_or_email', consents: [{ channel: 'whatsapp', label: 'Acepto recibir ofertas por WhatsApp', version: 'v1' }], title: '¿A dónde te enviamos tu cotización?', cta: 'Ver mi cotización' },
    },
};

function slot(ctxJson: string): HTMLElement {
    const s = document.createElement('div');
    s.setAttribute('data-igr-slot', 'quote');
    s.setAttribute('data-igr-ctx', ctxJson);
    s.style.minHeight = '320px';
    document.body.append(s);
    return s;
}

const visible = (root: ShadowRoot): HTMLFieldSetElement[] => Array.from(root.querySelectorAll('fieldset')).filter((f) => !f.hidden);
const legend = (root: ShadowRoot): string => visible(root)[0]?.querySelector('legend')?.textContent ?? '';
const button = (root: ShadowRoot, text: string): HTMLButtonElement => Array.from(root.querySelectorAll('button')).find((b) => b.textContent === text) as HTMLButtonElement;
const wait = (ms: number): Promise<void> => new Promise((r) => setTimeout(r, ms));

beforeEach(() => {
    reset();
    vi.resetModules();
    vi.unstubAllGlobals();
    setUrl('https://tienda.co/cotizar/?utm_source=google&utm_medium=cpc&otro=1');
});

describe('cotizador', () => {
    it('una pregunta por paso: revisa el número, avanza al elegir y muestra el precio que calcula el hub', async () => {
        fakeTracker(true);
        const calls = hubFetch({
            ['/quotes/' + ID]: () => [200, definition],
            ['/quotes/' + ID + '/estimate']: () => [200, { ok: true, quote_id: 'qt_1', estimate: { min: '686000.00', max: '839000.00', currency: 'COP', text: '$686.000 – $839.000 COP' }, lines: [{ label: 'Área a pintar (40 m²)', amount: '480000.00' }], note: 'Incluye materiales.', message: 'Es un valor estimado.', whatsapp: 'https://wa.me/573001112233?text=Hola' }],
        });
        const x = await ctx();
        await import('../widgets/quote');
        const s = slot(JSON.stringify({ calculatorId: ID }));
        renderer('quote')(s, null, x, { calculatorId: ID });
        await tick();
        const root = s.shadowRoot as ShadowRoot;
        expect(root.querySelector('h2')?.textContent).toBe('Pintura de interiores');
        expect(root.textContent).toContain('Tres preguntas y ves tu precio.');
        expect(root.querySelector('.pg')?.textContent).toBe('Paso 1 de 4');
        expect(visible(root)).toHaveLength(1);
        expect(legend(root)).toBe('📐 Área a pintar');
        expect(button(root, 'Ver mi cotización').hidden).toBe(true);
        expect(button(root, 'Atrás').hidden).toBe(true);

        // El número se revisa antes de seguir; el error queda enlazado al campo.
        const area = root.querySelector('input[name=area]') as HTMLInputElement;
        expect(area.getAttribute('aria-label')).toBe('Área a pintar');
        button(root, 'Siguiente').click();
        expect(root.getElementById(area.getAttribute('aria-describedby') ?? '')?.textContent).toBe('Escribe un número entre 5 y 2000.');
        expect(legend(root)).toBe('📐 Área a pintar');
        button(root, '+').click();
        expect(area.value).toBe('5');
        area.value = '40';
        button(root, 'Siguiente').click();
        expect(root.querySelector('.pg')?.textContent).toBe('Paso 2 de 4');

        // Opciones en tarjetas; al elegir una, avanza solo.
        expect(legend(root)).toBe('Tipo de pintura');
        expect(visible(root)[0]?.querySelector('.o small')?.textContent).toBe('Interiores');
        const esmalte = root.querySelector('input[name=tipo][value=esmalte]') as HTMLInputElement;
        esmalte.checked = true;
        esmalte.dispatchEvent(new Event('change', { bubbles: true }));
        await wait(250);
        expect(legend(root)).toBe('Extras');
        (root.querySelector('input[name=extras]') as HTMLInputElement).checked = true;
        button(root, 'Siguiente').click();

        // El contacto, con su casilla sin marcar.
        expect(legend(root)).toBe('¿A dónde te enviamos tu cotización?');
        expect(Array.from(root.querySelectorAll('label.l')).map((l) => l.textContent)).toEqual(['Nombre', 'WhatsApp']);
        const consent = root.querySelector('input[name=consent]') as HTMLInputElement;
        expect(consent.checked).toBe(false);
        expect(button(root, 'Ver mi cotización').hidden).toBe(false);
        (root.querySelector('input[name=c_first_name]') as HTMLInputElement).value = 'Ana';
        (root.querySelector('input[name=c_phone]') as HTMLInputElement).value = '300 123 4567';
        consent.checked = true;
        (root.querySelector('form') as HTMLFormElement).dispatchEvent(new Event('submit', { cancelable: true }));
        await tick();

        const post = calls.find((c) => c.url.pathname.endsWith('/estimate'));
        expect(post?.body).toMatchObject({
            answers: { area: 40, tipo: 'esmalte', extras: ['techo'] },
            fields: { first_name: 'Ana', phone: '300 123 4567' },
            consents: { whatsapp: true },
            utm: { utm_source: 'google', utm_medium: 'cpc' },
        });
        expect(Object.keys((post?.body?.['utm'] as Record<string, string>) ?? {})).not.toContain('otro');
        expect(root.querySelector('.big')?.textContent).toBe('$686.000 – $839.000 COP');
        expect(root.querySelector('[role=status]')).not.toBeNull();
        expect(root.querySelector('a.wa')?.getAttribute('href')).toBe('https://wa.me/573001112233?text=Hola');
        // «Cotizar de nuevo» vuelve al formulario con lo que había.
        button(root, 'Cotizar de nuevo').click();
        expect((root.querySelector('input[name=area]') as HTMLInputElement).value).toBe('40');
    });

    it('muestra los errores del hub junto a cada campo y vuelve al primer paso con error', async () => {
        fakeTracker(true);
        hubFetch({
            ['/quotes/' + ID]: () => [200, definition],
            ['/quotes/' + ID + '/estimate']: () => [422, { ok: false, message: 'Escribe tu WhatsApp o tu correo.', errors: { 'fields.phone': ['Escribe tu WhatsApp o tu correo.'], 'answers.area': ['Escribe un número entre 5 y 2.000.'] } }],
        });
        const x = await ctx();
        await import('../widgets/quote');
        const s = slot('{}');
        renderer('quote')(s, null, x, { calculatorId: ID });
        await tick();
        const root = s.shadowRoot as ShadowRoot;
        (root.querySelector('form') as HTMLFormElement).dispatchEvent(new Event('submit', { cancelable: true }));
        await tick();
        const area = root.querySelector('input[name=area]') as HTMLInputElement;
        expect(root.getElementById(area.getAttribute('aria-describedby') ?? '')?.textContent).toBe('Escribe un número entre 5 y 2.000.');
        expect(root.getElementById((root.querySelector('input[name=c_phone]') as HTMLInputElement).getAttribute('aria-describedby') ?? '')?.textContent).toBe('Escribe tu WhatsApp o tu correo.');
        expect(root.querySelector('[role=alert]')?.textContent).toBe('Revisa los campos marcados.');
        expect(legend(root)).toBe('📐 Área a pintar');
        expect(root.activeElement).toBe(area);
        expect(root.querySelector('.big')).toBeNull();
    });

    it('con los precios públicos, cada tarjeta dice cuánto suma o ajusta; el paquete viene elegido', async () => {
        fakeTracker(true);
        hubFetch({
            ['/quotes/' + ID]: () => [200, { ok: true, calculator: { ...definition.calculator, kind: 'package', capture: { ...definition.calculator.capture, required: false }, definition: {
                packages: [{ key: 'landing', label: 'Página de ventas', icon: '🚀', price: 1400000 }, { key: 'tienda', label: 'Tienda en línea', price: 6000000 }],
                fields: [
                    { key: 'n', label: 'Páginas', type: 'number', required: true, unit: 'páginas', min: 1, max: 20, step: 1, default: 1, unit_price: 200000 },
                    { key: 'extras', label: 'Extras', type: 'checkbox', options: [{ value: 'urgente', label: 'Urgente', price: 0, multiplier: 1.25 }, { value: 'textos', label: 'Textos', price: 600000, multiplier: 1 }] },
                ],
            } } }],
        });
        const x = await ctx();
        await import('../widgets/quote');
        const s = slot('{}');
        renderer('quote')(s, null, x, { calculatorId: ID });
        await tick();
        const root = s.shadowRoot as ShadowRoot;
        expect(legend(root)).toBe('Elige un paquete');
        expect((root.querySelector('input[name=package][value=landing]') as HTMLInputElement).checked).toBe(true);
        const tags = Array.from(root.querySelectorAll('.p')).map((p) => p.textContent?.replace(/\s/g, ' '));
        expect(tags).toEqual(['$ 1.400.000', '$ 6.000.000', '+25 %', '+ $ 600.000']);
        expect(root.textContent?.replace(/\s/g, ' ')).toContain('$ 200.000 por páginas');
        expect(root.querySelector('.pg')?.textContent).toBe('Paso 1 de 3');
        expect(root.querySelector('input[name=c_phone]')).toBeNull();
    });

    it('se recoge si el cotizador no existe o el ID no es válido', async () => {
        fakeTracker(true);
        hubFetch({ ['/quotes/' + ID]: () => [404, { ok: false, error: { code: 'unknown_calculator' } }] });
        const x = await ctx();
        await import('../widgets/quote');
        const a = slot('{}');
        renderer('quote')(a, null, x, { calculatorId: ID });
        const b = slot('{}');
        renderer('quote')(b, null, x, { calculatorId: '<script>' });
        await tick();
        expect(a.hidden).toBe(true);
        expect(b.hidden).toBe(true);
        expect(['0', '0px']).toContain(a.style.minHeight);
    });
});
