import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reset } from './helpers';

const W = window as unknown as Record<string, unknown>;

beforeEach(() => {
    reset();
    vi.resetModules();
});

describe('encuesta ¿cómo nos conociste?', () => {
    it('se pinta en el slot, es accesible y envía la respuesta con el token del pedido', async () => {
        const fetchMock = vi.fn(() => Promise.resolve(new Response('{"ok":true}', { status: 202 })));
        vi.stubGlobal('fetch', fetchMock);
        const pushed: unknown[] = [];
        W['igr'] = { push: (...c: unknown[]) => pushed.push(...c) };
        W['igrConfig'] = { widgetsUrl: 'https://in.hub.test/p/pk_x/widgets.json' };
        W['igrOrder'] = { orderId: '1234', token: 'eyJvcmRlciI6IjEyMzQifQ.' + 'b'.repeat(64) };
        W['__igrWidgets'] = { widgets: [{ id: 'sv_01', kind: 'hdyhau_survey', slot: 'thankyou', title: 'Una pregunta rápida', question: '¿Cómo nos conociste?', options: [{ key: 'instagram', label: 'Instagram' }, { key: 'google', label: 'Google' }], allow_other: true, other_label: 'Otro', thanks: '¡Gracias!' }] };
        document.body.innerHTML = '<div data-igr-slot="thankyou"></div>';
        await import('../widgets/survey');
        const root = (document.querySelector('[data-igr-slot]') as HTMLElement).shadowRoot as ShadowRoot;
        const radios = root.querySelectorAll('input[type=radio]');
        expect(radios).toHaveLength(3);
        for (const r of radios) expect(root.querySelector('label[for="' + r.id + '"]')).not.toBeNull();
        expect(root.querySelector('legend')?.textContent).toBe('¿Cómo nos conociste?');
        (radios[0] as HTMLInputElement).click();
        root.querySelector('form')?.dispatchEvent(new Event('submit', { cancelable: true }));
        await new Promise((r) => setTimeout(r, 5));
        expect(fetchMock).toHaveBeenCalledWith('https://in.hub.test/p/pk_x/surveys/sv_01/answers', expect.objectContaining({ method: 'POST' }));
        const body = JSON.parse((fetchMock.mock.calls[0] as unknown as [string, { body: string }])[1].body) as Record<string, unknown>;
        expect(body).toMatchObject({ answer: 'instagram', other: null, order_token: W['igrOrder'] && (W['igrOrder'] as { token: string }).token });
        expect(root.textContent).toContain('¡Gracias!');
        expect(localStorage.getItem('_igr_sv_1234')).toBe('1');
        expect(pushed.map((c) => (c as unknown[])[1])).toEqual(['widget.shown', 'widget.submitted']);
        vi.unstubAllGlobals();
    });
});

describe('píxeles del modo completo', () => {
    it('dispara PageView y Purchase con el mismo event_id de Imagina Growth', async () => {
        let listener: ((e: unknown) => void) | undefined;
        W['igr'] = { on: (fn: (e: unknown) => void) => (listener = fn), consent: () => ({ marketing: true }) };
        W['igrConfig'] = { pixels: { meta: '123456789012345', google: 'G-ABC123' } };
        W['igrOrder'] = { eventId: '0193a7b2-6c1e-5f3a-9a51-2f6d1c0e4b7a', orderId: '1234', value: '282000.00', currency: 'COP' };
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
