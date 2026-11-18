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
                { key: 'area', label: 'Área a pintar', type: 'number', required: true, unit: 'm²', min: 5, max: 2000, step: 1, default: null },
                { key: 'tipo', label: 'Tipo de pintura', type: 'select', required: true, options: [{ value: 'vinilo', label: 'Vinilo' }, { value: 'esmalte', label: 'Esmalte' }] },
                { key: 'extras', label: 'Extras', type: 'checkbox', required: false, options: [{ value: 'techo', label: 'Techo' }] },
            ],
            packages: [],
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

beforeEach(() => {
    reset();
    vi.resetModules();
    vi.unstubAllGlobals();
    setUrl('https://tienda.co/cotizar/?utm_source=google&utm_medium=cpc&otro=1');
});

describe('cotizador', () => {
    it('arma las preguntas y el contacto, y muestra el precio que calcula el hub', async () => {
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
        const consent = root.querySelector('input[name=consent]') as HTMLInputElement;
        expect(consent.checked).toBe(false);
        const labels = Array.from(root.querySelectorAll('label.l')).map((l) => l.textContent);
        expect(labels).toEqual(['Área a pintar', 'Tipo de pintura', 'Nombre', 'WhatsApp']);
        // Cada campo tiene su etiqueta y su mensaje de error enlazados.
        const area = root.querySelector('input[name=area]') as HTMLInputElement;
        expect(root.getElementById(area.getAttribute('aria-describedby') ?? '')).not.toBeNull();

        area.value = '40';
        (root.querySelector('select[name=tipo]') as HTMLSelectElement).value = 'esmalte';
        (root.querySelector('input[name=extras]') as HTMLInputElement).checked = true;
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
        (Array.from(root.querySelectorAll('button')).find((b) => b.textContent === 'Cotizar de nuevo') as HTMLButtonElement).click();
        expect((root.querySelector('input[name=area]') as HTMLInputElement).value).toBe('40');
    });

    it('muestra los errores del hub junto a cada campo', async () => {
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
        expect(root.querySelector('[role=alert]')?.textContent).toBe('Revisa los campos marcados.');
        expect(root.querySelector('.big')).toBeNull();
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
