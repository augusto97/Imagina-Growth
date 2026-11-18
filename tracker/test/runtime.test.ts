import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reset, setUrl } from './helpers';
import { BASE, fakeTracker, hubFetch, tick, tracked } from './whelpers';

const W = window as unknown as Record<string, unknown>;

function boot(widgets: unknown[], fakes: Record<string, unknown>, page = 'home'): Promise<unknown> {
    W['igrPage'] = { type: page };
    W['igrConfig'] = { widgetsUrl: BASE + 'widgets.json', widgetsJs: 'https://tienda.co/a/igr-widgets.js?ver=1', currency: 'COP' };
    W['__igrWidgets'] = { widgets };
    W['__igrW'] = { r: fakes };
    return import('../widgets/runtime');
}

beforeEach(() => {
    reset();
    vi.resetModules();
    vi.unstubAllGlobals();
    setUrl('https://tienda.co/');
});

describe('runtime de widgets', () => {
    it('popup inmediato: variante determinista, vista registrada y widget.shown', async () => {
        const pushed = fakeTracker(true);
        document.cookie = '_igr_aid=c3b0f4a2-1111-4b2b-9d3e-aaaaaaaaaaaa; Path=/';
        const popup = vi.fn();
        await boot([{ id: 'wg_1', kind: 'popup', slot: null, variants: [{ key: 'a', weight: 50, content: { title: 'A' } }, { key: 'b', weight: 50, content: { title: 'B' } }], content: { cta: 'Quiero' }, targeting: { trigger: { type: 'immediate' } } }], { popup });
        await tick(10);
        expect(popup).toHaveBeenCalledTimes(1);
        const live = popup.mock.calls[0]?.[1] as { variant: string; content: { title: string; cta: string } };
        expect(live.content.title).toBe(live.variant.toUpperCase());
        expect(live.content.cta).toBe('Quiero');
        expect(tracked(pushed)).toEqual(['widget.shown']);
        expect(pushed[0]?.[2]).toEqual({ widget_id: 'wg_1', variant: live.variant, kind: 'popup' });
        const f = JSON.parse(localStorage.getItem('_igr_wf') ?? '{}') as Record<string, { v: number; k: string }>;
        expect(f['wg_1']).toMatchObject({ v: 1, k: live.variant });
        expect(sessionStorage.getItem('_igr_wf')).toBeNull();
    });

    it('sin consentimiento de analítica la frecuencia vive solo en la sesión', async () => {
        fakeTracker(false);
        const popup = vi.fn();
        await boot([{ id: 'wg_1', kind: 'popup', targeting: { trigger: { type: 'immediate' } } }], { popup });
        await tick(10);
        expect(popup).toHaveBeenCalledTimes(1);
        expect(localStorage.getItem('_igr_wf')).toBeNull();
        expect(JSON.parse(sessionStorage.getItem('_igr_wf') ?? '{}')['wg_1'].v).toBe(1);
    });

    it('respeta la frecuencia guardada y nunca corre en el checkout', async () => {
        fakeTracker(true);
        localStorage.setItem('_igr_wf', JSON.stringify({ wg_1: { v: 1, s: Date.now() } }));
        const popup = vi.fn();
        await boot([{ id: 'wg_1', kind: 'popup' }, { id: 'wg_2', kind: 'bar' }], { popup, bar: popup });
        await tick(10);
        expect(popup.mock.calls.map((c) => (c[1] as { id: string }).id)).toEqual(['wg_2']);

        reset();
        vi.resetModules();
        fakeTracker(true);
        const again = vi.fn();
        await boot([{ id: 'wg_3', kind: 'bar' }], { bar: again }, 'checkout');
        await tick(10);
        expect(again).not.toHaveBeenCalled();
    });

    it('sin reglas de visitante no pide visitor.json', async () => {
        fakeTracker(true);
        const calls = hubFetch({ 'visitor.json': () => [200, {}] });
        const bar = vi.fn();
        await boot([{ id: 'wg_1', kind: 'bar' }], { bar });
        await tick(10);
        expect(bar).toHaveBeenCalledTimes(1);
        expect(calls).toHaveLength(0);
    });

    it('pide visitor.json una vez cuando un widget usa país, segmento o suscripción', async () => {
        fakeTracker(true);
        document.cookie = '_igr_aid=aid-12345678; Path=/';
        const calls = hubFetch({ 'visitor.json': () => [200, { known: false, returning: false, subscribed: {}, country: 'CO', segments: [] }] });
        const shown = vi.fn();
        await boot([{ id: 'wg_co', kind: 'bar', targeting: { countries: ['CO'] } }, { id: 'wg_mx', kind: 'popup', targeting: { countries: ['MX'] } }], { bar: shown, popup: shown });
        await tick(20);
        expect(calls.map((c) => c.url.pathname + c.url.search)).toEqual(['/p/pk_x/visitor.json?aid=aid-12345678']);
        expect(shown.mock.calls.map((c) => (c[1] as { id: string }).id)).toEqual(['wg_co']);
    });

    it('un solo modal a la vez; data-igr-open abre un widget de clic', async () => {
        fakeTracker(true);
        const popup = vi.fn();
        await boot(
            [
                { id: 'wg_a', kind: 'popup' },
                { id: 'wg_b', kind: 'popup' },
                { id: 'wg_c', kind: 'popup', targeting: { trigger: { type: 'click' } } },
            ],
            { popup },
        );
        await tick(10);
        expect(popup).toHaveBeenCalledTimes(1);
        const btn = document.createElement('button');
        btn.setAttribute('data-igr-open', 'wg_c');
        document.body.append(btn);
        // Cerrar el primero libera el turno.
        const rt = (W['__igrW'] as { x: { mark: (w: unknown, what: string) => void } }).x;
        rt.mark({ id: 'wg_a', kind: 'popup', variant: null }, 'close');
        btn.click();
        await tick(10);
        expect(popup.mock.calls.map((c) => (c[1] as { id: string }).id)).toEqual(['wg_a', 'wg_c']);
    });

    it('slots: cada uno con su paquete y su contexto; un formulario que no existe se recoge', async () => {
        fakeTracker(true);
        const reco = vi.fn();
        const form = vi.fn();
        document.body.innerHTML =
            '<div class="igr-slot" data-igr-slot="reco" data-igr-ctx=\'{"kind":"bought_together","productId":"88","limit":4}\' style="min-height:320px"></div>' +
            '<div class="igr-slot" data-igr-slot="form" data-igr-ctx=\'{"widgetId":"wg_x"}\' style="min-height:280px"></div>' +
            '<div class="igr-slot" data-igr-slot="form" data-igr-ctx=\'{"widgetId":"wg_f"}\' style="min-height:280px"></div>';
        await boot([{ id: 'wg_f', kind: 'embedded_form', slot: 'form', content: { title: 'Suscríbete' } }], { reco, form });
        await tick(10);
        expect(reco.mock.calls[0]?.[3]).toEqual({ kind: 'bought_together', productId: '88', limit: 4 });
        const slots = document.querySelectorAll<HTMLElement>('[data-igr-slot="form"]');
        expect(slots[0]?.hidden).toBe(true);
        expect(form.mock.calls[0]?.[0]).toBe(slots[1]);
        expect((form.mock.calls[0]?.[1] as { content: { title: string } }).content.title).toBe('Suscríbete');
    });

    it('ruta, dispositivo y referido de la URL', async () => {
        fakeTracker(true);
        setUrl('https://tienda.co/tienda/pinturas/?ref=ANA7K2');
        const popup = vi.fn();
        await boot(
            [
                { id: 'wg_ref', kind: 'popup', targeting: { referral: true, pages: { include: ['/tienda/*'] } } },
                { id: 'wg_home', kind: 'bar', targeting: { pages: { include: ['/'] } } },
            ],
            { popup, bar: popup },
        );
        await tick(10);
        expect(popup.mock.calls.map((c) => (c[1] as { id: string }).id)).toEqual(['wg_ref']);
        expect((W['__igrW'] as { x: { ref: () => string } }).x.ref()).toBe('ANA7K2');
    });
});
