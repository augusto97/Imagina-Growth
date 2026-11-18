import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reset, setUrl } from './helpers';
import { BASE, ctx, fakeTracker, hubFetch, overlay, renderer, tick, tracked } from './whelpers';
import type { WidgetCfg } from '../widgets/lib/types';

const W = window as unknown as Record<string, unknown>;
const key = (el: Element, k: string): void => void el.dispatchEvent(new KeyboardEvent('keydown', { key: k, bubbles: true, composed: true }));

beforeEach(() => {
    reset();
    vi.resetModules();
    vi.unstubAllGlobals();
    setUrl('https://tienda.co/');
});

const popupCfg: WidgetCfg = {
    id: 'wg_01',
    kind: 'popup',
    slot: null,
    variants: [{ key: 'a', weight: 100 }],
    content: {
        title: '10% en tu primera compra',
        body: 'Déjanos tu WhatsApp',
        fields: [{ key: 'phone', type: 'tel', required: true, label: 'Tu WhatsApp' }],
        consents: [{ channel: 'whatsapp', label: 'Quiero recibir ofertas por WhatsApp.', version: 'v3' }],
        cta: 'Quiero mi cupón',
        success: '¡Listo! Tu cupón es {{codigo}}',
    },
};

describe('captación', () => {
    it('popup accesible: diálogo modal, etiquetas y casillas sin marcar; envía y muestra el cupón', async () => {
        setUrl('https://tienda.co/?ref=ANA7K2');
        const pushed = fakeTracker(true);
        document.cookie = '_igr_aid=aid-12345678; Path=/';
        const calls = hubFetch({ '/submit': () => [200, { ok: true, coupon: { code: 'BIENV-7K2Q', expires_at: '2026-12-31T23:59:59Z' }, double_opt_in: false }] });
        const x = await ctx([popupCfg]);
        await import('../widgets/capture');
        const live = x.live(popupCfg);
        renderer('popup')(null, live, x, {});
        const root = overlay('popup');
        const dlg = root.querySelector('[role=dialog]') as HTMLElement;
        expect(dlg.getAttribute('aria-modal')).toBe('true');
        expect(root.getElementById(dlg.getAttribute('aria-labelledby') ?? '')?.textContent).toBe('10% en tu primera compra');
        const input = root.querySelector('input[name=phone]') as HTMLInputElement;
        expect(root.querySelector('label[for="' + input.id + '"]')?.textContent).toBe('Tu WhatsApp');
        expect(input.type).toBe('tel');
        const cb = root.querySelector('input[type=checkbox]') as HTMLInputElement;
        expect(cb.checked).toBe(false);
        expect(cb.closest('label')?.textContent).toBe('Quiero recibir ofertas por WhatsApp.');
        expect(root.querySelector('button[aria-label=Cerrar]')).not.toBeNull();

        // Vacío: error accesible y sin envío.
        root.querySelector('form')?.dispatchEvent(new Event('submit', { cancelable: true }));
        expect(input.getAttribute('aria-invalid')).toBe('true');
        expect(root.getElementById(input.getAttribute('aria-describedby') ?? '')?.textContent).toBe('Este dato es obligatorio.');
        expect(calls).toHaveLength(0);

        input.value = '300 123 4567';
        cb.click();
        root.querySelector('form')?.dispatchEvent(new Event('submit', { cancelable: true }));
        await tick();
        expect(calls[0]?.url.href).toBe(BASE + 'widgets/wg_01/submit');
        expect(calls[0]?.body).toEqual({ anonymous_id: 'aid-12345678', variant: 'a', page_url: 'https://tienda.co/?ref=ANA7K2', step: 1, fields: { phone: '300 123 4567' }, consents: { whatsapp: true }, referral_code: 'ANA7K2' });
        const ok = root.querySelector('[role=status]') as HTMLElement;
        expect(ok.textContent).toContain('¡Listo! Tu cupón es BIENV-7K2Q');
        expect(ok.querySelector('strong')?.textContent).toBe('BIENV-7K2Q');
        expect(tracked(pushed)).toEqual(['widget.submitted']);
        expect(JSON.parse(localStorage.getItem('_igr_wf') ?? '{}')['wg_01'].s).toBeGreaterThan(0);

        // Cerrar después de enviar no cuenta como cierre.
        key(dlg, 'Escape');
        expect(document.querySelector('[data-igr-w=popup]')).toBeNull();
        expect(tracked(pushed)).toEqual(['widget.submitted']);
    });

    it('Esc cierra y registra widget.closed; los errores 422 van al campo', async () => {
        const pushed = fakeTracker(true);
        hubFetch({ '/submit': () => [422, { errors: { phone: ['El número no es válido.'] } }] });
        const x = await ctx([popupCfg]);
        await import('../widgets/capture');
        renderer('popup')(null, x.live(popupCfg), x, {});
        const root = overlay('popup');
        const input = root.querySelector('input[name=phone]') as HTMLInputElement;
        input.value = '3001234';
        root.querySelector('form')?.dispatchEvent(new Event('submit', { cancelable: true }));
        await tick();
        expect(root.getElementById(input.getAttribute('aria-describedby') ?? '')?.textContent).toBe('El número no es válido.');
        expect(root.querySelector('[role=alert]')?.textContent).toBe('Revisa los datos e inténtalo de nuevo.');
        key(root.querySelector('[role=dialog]') as HTMLElement, 'Escape');
        expect(document.querySelector('[data-igr-w=popup]')).toBeNull();
        expect(tracked(pushed)).toEqual(['widget.closed']);
        expect(JSON.parse(localStorage.getItem('_igr_wf') ?? '{}')['wg_01'].c).toBeGreaterThan(0);
    });

    it('dos pasos: primero la pregunta y después el formulario; el formulario incrustado va en el slot', async () => {
        fakeTracker(true);
        hubFetch({ '/submit': () => [200, { ok: true, message: 'Gracias' }] });
        const two: WidgetCfg = { ...popupCfg, id: 'wg_2', kind: 'two_step', content: { ...popupCfg.content, cta: 'Sí, quiero', no: 'No, gracias' } };
        const x = await ctx([two]);
        await import('../widgets/capture');
        renderer('two_step')(null, x.live(two), x, {});
        const root = overlay('two_step');
        expect(root.querySelector('form')).toBeNull();
        const buttons = Array.from(root.querySelectorAll('.ac button')).map((b) => b.textContent);
        expect(buttons).toEqual(['Sí, quiero', 'No, gracias']);
        (root.querySelector('.ac button') as HTMLButtonElement).click();
        expect(root.querySelector('input[name=phone]')).not.toBeNull();

        const slot = document.createElement('div');
        slot.setAttribute('data-igr-slot', 'form');
        document.body.append(slot);
        const emb: WidgetCfg = { ...popupCfg, id: 'wg_3', kind: 'embedded_form', slot: 'form' };
        renderer('form')(slot, x.live(emb), x, { widgetId: 'wg_3' });
        expect(slot.shadowRoot?.querySelector('section.in input[name=phone]')).not.toBeNull();
        expect(slot.shadowRoot?.querySelector('[role=dialog]')).toBeNull();
    });

    it('barra con enlace: el clic cuenta como envío', async () => {
        const pushed = fakeTracker(true);
        const bar: WidgetCfg = { id: 'wg_b', kind: 'bar', content: { title: 'Envío gratis', body: 'desde $150.000', cta: 'Ver tienda', url: 'https://tienda.co/tienda/' }, design: { position: 'bottom' } };
        const x = await ctx([bar]);
        await import('../widgets/capture');
        renderer('bar')(null, x.live(bar), x, {});
        const root = overlay('bar');
        expect(root.querySelector('.br.bottom[role=region]')).not.toBeNull();
        const a = root.querySelector('a.go') as HTMLAnchorElement;
        a.addEventListener('click', (e) => e.preventDefault());
        a.click();
        expect(tracked(pushed)).toEqual(['widget.submitted']);
    });

    it('ruleta: el premio lo decide el hub y la ruleta gira hasta esa casilla', async () => {
        fakeTracker(true);
        vi.stubGlobal('matchMedia', (q: string) => ({ matches: q.includes('reduce'), addEventListener: () => undefined }));
        hubFetch({ '/submit': () => [200, { ok: true, prize: { key: '15', label: '15% de descuento' }, coupon: { code: 'RULETA-15' } }] });
        const spin: WidgetCfg = { ...popupCfg, id: 'wg_s', kind: 'spin_wheel', content: { ...popupCfg.content, slices: [{ key: '5', label: '5%' }, { key: '10', label: '10%' }, { key: '15', label: '15%' }, { key: 'envio', label: 'Envío' }] } };
        const x = await ctx([spin]);
        await import('../widgets/wheel');
        renderer('spin_wheel')(null, x.live(spin), x, {});
        const root = overlay('spin_wheel');
        expect(root.querySelectorAll('.wl')).toHaveLength(4);
        expect(root.querySelector('.sr')?.textContent).toBe('Premios posibles: 5%, 10%, 15%, Envío.');
        (root.querySelector('input[name=phone]') as HTMLInputElement).value = '3001234567';
        root.querySelector('form')?.dispatchEvent(new Event('submit', { cancelable: true }));
        for (let i = 0; i < 50 && !root.querySelector('[role=status]'); i++) await tick(10);
        expect((root.querySelector('.wh') as HTMLElement).style.transform).toBe('rotate(' + (1800 - 2.5 * 90) + 'deg)');
        expect(root.querySelector('[role=status] h3')?.textContent).toBe('15% de descuento');
        expect(root.querySelector('[role=status] strong')?.textContent).toBe('RULETA-15');
    });

    it('quiz con ramas: respuestas al hub y productos del resultado', async () => {
        fakeTracker(true);
        const calls = hubFetch({ '/submit': () => [200, { ok: true, message: 'Tu acabado ideal', products: [{ id: '88', name: 'Pintura Mate', price: '150000.00', currency: 'COP', url: 'https://tienda.co/p/88', image: 'https://tienda.co/88.jpg' }] }] });
        const qz: WidgetCfg = {
            id: 'wg_q',
            kind: 'quiz',
            slot: 'quiz',
            content: { title: '¿Qué pintura necesitas?' },
            quiz: {
                questions: [
                    { id: 'q1', text: '¿Dónde vas a pintar?', options: [{ key: 'int', label: 'Interior', next: 'q2' }, { key: 'ext', label: 'Exterior', next: 'q3' }] },
                    { id: 'q2', text: '¿Qué acabado?', options: [{ key: 'mate', label: 'Mate' }] },
                    { id: 'q3', text: '¿Clima?', options: [{ key: 'lluvia', label: 'Lluvioso' }] },
                ],
            },
        };
        const slot = document.createElement('div');
        document.body.append(slot);
        const x = await ctx([qz]);
        await import('../widgets/quiz');
        renderer('quiz')(slot, x.live(qz), x, {});
        const root = slot.shadowRoot as ShadowRoot;
        expect(root.querySelector('h3')?.textContent).toBe('¿Dónde vas a pintar?');
        (Array.from(root.querySelectorAll('.qo button')).find((b) => b.textContent === 'Exterior') as HTMLButtonElement).click();
        expect(root.querySelector('h3')?.textContent).toBe('¿Clima?');
        (root.querySelector('.qo button') as HTMLButtonElement).click();
        await tick();
        expect(calls[0]?.body?.['answers']).toEqual({ q1: 'ext', q3: 'lluvia' });
        expect(root.querySelector('.pc .pn')?.textContent).toBe('Pintura Mate');
        expect(root.querySelector('.pc img')?.getAttribute('loading')).toBe('lazy');
    });
});

describe('WhatsApp', () => {
    it('agrega el token de atribución y envía cta.clicked antes de abrir', async () => {
        const pushed = fakeTracker(true);
        const wa: WidgetCfg = { id: 'wg_wa', kind: 'wa_widget', wa: { number: '573001234567', text: 'Hola, quiero información', position: 'right' }, content: { cta: 'Escríbenos' } };
        const x = await ctx([wa], { waRef: { enabled: false, widgets: true, format: '{text} (ref: {token})', numbers: [] } });
        await import('../widgets/wa');
        renderer('wa_widget')(null, x.live(wa), x, {});
        const a = overlay('wa').querySelector('a') as HTMLAnchorElement;
        expect(a.getAttribute('aria-label')).toBe('Escríbenos');
        a.addEventListener('click', (e) => e.preventDefault());
        a.click();
        const text = new URL(a.href).searchParams.get('text') ?? '';
        const m = text.match(/^Hola, quiero información \(ref: ([A-Z2-9]{5})\)$/);
        expect(m).not.toBeNull();
        const cta = pushed.find((c) => c[1] === 'cta.clicked');
        expect(cta?.[2]).toMatchObject({ kind: 'whatsapp', wa_ref: m?.[1] });
        expect(pushed.some((c) => c[0] === 'flush')).toBe(true);
    });
});

describe('encuesta ¿cómo nos conociste?', () => {
    it('se pinta en el slot, es accesible y envía la respuesta con el token del pedido', async () => {
        const calls = hubFetch({ '/answers': () => [202, { ok: true }] });
        const pushed = fakeTracker(true);
        W['igrOrder'] = { orderId: '1234', token: 'eyJvcmRlciI6IjEyMzQifQ.' + 'b'.repeat(64) };
        const sv = { id: 'sv_01', kind: 'hdyhau_survey', slot: 'thankyou', title: 'Una pregunta rápida', question: '¿Cómo nos conociste?', options: [{ key: 'instagram', label: 'Instagram' }, { key: 'google', label: 'Google' }], allow_other: true, other_label: 'Otro', thanks: '¡Gracias!' };
        document.body.innerHTML = '<div data-igr-slot="thankyou"></div>';
        const x = await ctx();
        await import('../widgets/survey');
        const slot = document.querySelector('[data-igr-slot]') as HTMLElement;
        renderer('thankyou')(slot, null, x, { widget: sv });
        const root = slot.shadowRoot as ShadowRoot;
        const radios = root.querySelectorAll('input[type=radio]');
        expect(radios).toHaveLength(3);
        for (const r of radios) expect(root.querySelector('label[for="' + r.id + '"]')).not.toBeNull();
        expect(root.querySelector('legend')?.textContent).toBe('¿Cómo nos conociste?');
        (radios[0] as HTMLInputElement).click();
        root.querySelector('form')?.dispatchEvent(new Event('submit', { cancelable: true }));
        await tick();
        expect(calls[0]?.url.href).toBe(BASE + 'surveys/sv_01/answers');
        expect(calls[0]?.body).toMatchObject({ answer: 'instagram', other: null, order_token: (W['igrOrder'] as { token: string }).token });
        expect(root.textContent).toContain('¡Gracias!');
        expect(localStorage.getItem('_igr_sv_1234')).toBe('1');
        expect(tracked(pushed)).toEqual(['widget.shown', 'widget.submitted']);
    });
});

describe('píxeles del modo completo', () => {
    it('dispara PageView y Purchase con el mismo event_id de Imagina Growth', async () => {
        let listener: ((e: unknown) => void) | undefined;
        W['igr'] = { on: (fn: (e: unknown) => void) => (listener = fn), consent: () => ({ marketing: true }) };
        W['igrConfig'] = { pixels: { meta: '123456789012345', google: 'G-ABC123' } };
        W['igrOrder'] = { eventId: '0193a7b2-6c1e-5f3a-9a51-2f6d1c0e4b7a', orderId: '1234', value: '282000.00', currency: 'COP', paid: true };
        await import('../src/pixels');
        const fbq = W['fbq'] as { queue: unknown[][] };
        listener?.({ event: 'page.viewed', event_id: 'ev-1', properties: {} });
        const calls = fbq.queue.map((a) => Array.from(a));
        expect(calls[0]).toEqual(['init', '123456789012345']);
        expect(calls).toContainEqual(['track', 'Purchase', { value: 282000, currency: 'COP' }, { eventID: '0193a7b2-6c1e-5f3a-9a51-2f6d1c0e4b7a' }]);
        expect(calls).toContainEqual(['track', 'PageView', {}, { eventID: 'ev-1' }]);
        const gtagCalls = (W['dataLayer'] as IArguments[]).map((a) => Array.from(a));
        expect(gtagCalls).toContainEqual(['event', 'purchase', { transaction_id: '1234', value: 282000, currency: 'COP' }]);
        expect(document.head.querySelector('script[src*="fbevents.js"]')).not.toBeNull();
    });
});
