import { beforeEach, describe, expect, it, vi } from 'vitest';
import { contractErrors } from './schema';
import { beacons, boot, reset, setUrl, settle } from './helpers';

beforeEach(() => {
    reset();
    setUrl('https://tienda.co/');
});

describe('tracker', () => {
    it('envía page.viewed y product.viewed válidos contra el contrato', async () => {
        setUrl('https://tienda.co/producto/pintura?utm_source=facebook&utm_medium=paid_social&fbclid=Ab1', 'https://l.facebook.com/');
        const b = beacons();
        await boot({}, { type: 'product', objectId: 88, objectType: 'product', product: { id: 88, sku: 'PNT-1', name: 'Pintura Azul', price: '150000.00', currency: 'COP', categories: [12], inStock: true } });
        await settle();
        const events = b.events();
        expect(events.map((e) => e.event)).toEqual(['page.viewed', 'product.viewed']);
        for (const e of events) expect(contractErrors(e)).toEqual([]);
        const [pv] = events;
        expect(pv?.schema_version).toBe(4);
        expect(pv?.source).toMatchObject({ type: 'browser', site_id: 'st_01JD3QG7Y2V8K6N4M5P9R0S1T2', sdk: 'tracker/0.1.0' });
        expect(pv?.identity['anonymous_id']).toMatch(/^[0-9a-f-]{36}$/);
        expect(pv?.context['utm']).toEqual({ source: 'facebook', medium: 'paid_social' });
        expect(pv?.context['click_ids']).toEqual({ fbclid: 'Ab1' });
        expect((pv?.context['ad_cookies'] as Record<string, string>)['fbc']).toMatch(/^fb\.1\.\d+\.Ab1$/);
        expect(JSON.parse(b.bodies[0] ?? '{}').k).toBe('pk_' + 'a'.repeat(32));
    });

    it('modo estricto sin consentimiento: solo page.viewed, sin cookies ni identificadores', async () => {
        setUrl('https://tienda.co/?gclid=abc&utm_source=google');
        const b = beacons();
        await boot({ consentMode: 'strict', consent: { analytics: false, marketing: false, source: 'complianz' } });
        (window as unknown as { igr: unknown[] }).igr.push(['track', 'custom.algo', {}]);
        await settle();
        const events = b.events();
        expect(events.map((e) => e.event)).toEqual(['page.viewed']);
        expect(events[0]?.identity).toEqual({});
        expect(events[0]?.context['click_ids']).toBeUndefined();
        expect((events[0]?.context['page'] as { url: string }).url).toBe('https://tienda.co/');
        expect(document.cookie).not.toContain('_igr_aid');
        expect(localStorage.length + sessionStorage.length).toBe(0);
        expect(contractErrors(events[0])).toEqual([]);
    });

    it('al otorgar consentimiento después crea los identificadores', async () => {
        const b = beacons();
        await boot({ consentMode: 'strict', consent: { analytics: false, marketing: false, source: 'none' } });
        (window as unknown as { igr: unknown[] }).igr.push(['consent', { analytics: true, marketing: false }], ['track', 'custom.boton', { x: 1 }]);
        await settle();
        const custom = b.events().find((e) => e.event === 'custom.boton');
        expect(custom?.identity['anonymous_id']).toBeTruthy();
        expect(custom?.context['consent']).toMatchObject({ analytics: true, marketing: false });
        expect(custom?.context['ad_cookies']).toBeUndefined();
    });

    it('quita _igr de la URL y lo manda como link_token', async () => {
        setUrl('https://tienda.co/oferta?_igr=lt_ABC123&x=1');
        const b = beacons();
        await boot();
        await settle();
        expect(location.href).toBe('https://tienda.co/oferta?x=1');
        const pv = b.events()[0];
        expect(pv?.identity['link_token']).toBe('lt_ABC123');
        expect((pv?.context['page'] as { url: string }).url).not.toContain('_igr');
        const idf = b.events().find((e) => e.event === 'identify');
        expect(idf?.properties).toEqual({ method: 'link_token' });
        expect(idf?.identity['link_token']).toBe('lt_ABC123');
        expect(contractErrors(idf)).toEqual([]);
    });

    it('identifica con el token de igrUser una vez por sesión', async () => {
        const token = 'eyJyZWYiOiJ3Yzo3NyJ9.' + 'a'.repeat(64);
        (window as unknown as Record<string, unknown>)['igrUser'] = { ref: 'wc:77', token };
        const b = beacons();
        await boot({}, { type: 'account' });
        await settle();
        const id = b.events().filter((e) => e.event === 'identify');
        expect(id).toHaveLength(1);
        expect(id[0]?.properties).toEqual({ method: 'login', token });
        expect(contractErrors(id[0])).toEqual([]);
    });

    it('procesa la cola previa y el clic a WhatsApp con token antes de navegar', async () => {
        (window as unknown as Record<string, unknown>)['igr'] = [['track', 'custom.previo', { a: 1 }]];
        const b = beacons();
        await boot({ waRef: { enabled: true, numbers: ['573001234567'] } });
        await new Promise((r) => setTimeout(r, 5));
        const a = document.createElement('a');
        a.href = 'https://wa.me/573001234567?text=Hola';
        a.textContent = 'WhatsApp';
        a.addEventListener('click', (e) => e.preventDefault());
        document.body.appendChild(a);
        a.click();
        await settle();
        const cta = b.events().find((e) => e.event === 'cta.clicked');
        expect(cta?.properties).toMatchObject({ kind: 'whatsapp', label: 'WhatsApp' });
        expect(new URL(a.href).searchParams.get('text')).toBe('Hola (ref: ' + String(cta?.properties['wa_ref']) + ')');
        expect(contractErrors(cta)).toEqual([]);
        expect(b.events().some((e) => e.event === 'custom.previo')).toBe(true);
    });

    it('si sendBeacon falla usa fetch y guarda para reintentar si fetch también falla', async () => {
        Object.defineProperty(navigator, 'sendBeacon', { configurable: true, value: () => false });
        const fetchMock = vi.fn(() => Promise.reject(new Error('offline')));
        vi.stubGlobal('fetch', fetchMock);
        await boot();
        await settle();
        await new Promise((r) => setTimeout(r, 5));
        expect(fetchMock).toHaveBeenCalled();
        const saved = JSON.parse(sessionStorage.getItem('_igr_rq') ?? '[]') as unknown[];
        expect(saved.length).toBeGreaterThan(0);
        vi.unstubAllGlobals();
    });

    it('lotes de máximo 20 eventos por envío', async () => {
        const b = beacons();
        await boot();
        const q = (window as unknown as { igr: unknown[] }).igr;
        for (let i = 0; i < 45; i++) q.push(['track', 'custom.n', { i }]);
        await settle();
        for (const body of b.bodies) expect((JSON.parse(body) as { events: unknown[] }).events.length).toBeLessThanOrEqual(20);
        expect(b.events().filter((e) => e.event === 'custom.n')).toHaveLength(45);
    });

    it('v4: guarda el código de referido en _igr_src y lo manda en context.referral_code del aterrizaje', async () => {
        setUrl('https://tienda.co/?ref=ANA7K2&utm_source=whatsapp');
        const b = beacons();
        await boot();
        await settle();
        const pv = b.events().find((e) => e.event === 'page.viewed');
        expect(pv?.context['referral_code']).toBe('ANA7K2');
        expect((pv?.context['last_touch'] as Record<string, unknown>)['referral_code']).toBe('ANA7K2');
        expect((pv?.context['first_touch'] as Record<string, unknown>)['referral_code']).toBe('ANA7K2');
        expect(contractErrors(pv)).toEqual([]);
        // El código no es un dato del visitante: la URL queda como está (otros plugins de afiliados también leen ?ref=).
        expect(location.href).toBe('https://tienda.co/?ref=ANA7K2&utm_source=whatsapp');
        const src = JSON.parse(decodeURIComponent(document.cookie.match(/_igr_src=([^;]*)/)?.[1] ?? '{}')) as { l?: { referral_code?: string } };
        expect(src.l?.referral_code).toBe('ANA7K2');

        // En la página siguiente ya no va en el contexto, pero sigue en el último contacto.
        setUrl('https://tienda.co/tienda/', 'https://tienda.co/?ref=ANA7K2');
        delete (window as unknown as Record<string, unknown>)['igr'];
        const b2 = beacons();
        await boot();
        await settle();
        const next = b2.events().find((e) => e.event === 'page.viewed');
        expect(next?.context['referral_code']).toBeUndefined();
        expect((next?.context['last_touch'] as Record<string, unknown>)['referral_code']).toBe('ANA7K2');
    });

    it('ignora un ?ref= con formato inválido', async () => {
        setUrl('https://tienda.co/?ref=<script>');
        const b = beacons();
        await boot();
        await settle();
        const pv = b.events().find((e) => e.event === 'page.viewed');
        expect(pv?.context['referral_code']).toBeUndefined();
        expect(contractErrors(pv)).toEqual([]);
    });

    it('pide widgets.json con el tipo de página y la ruta, y carga el runtime solo si hay widgets o slots', async () => {
        const fetchMock = vi.fn((url: string) => Promise.resolve(new Response(JSON.stringify({ widgets: url.includes('tienda') ? [{ id: 'wg_1', kind: 'popup' }] : [] }), { status: 200 })));
        vi.stubGlobal('fetch', fetchMock);
        beacons();
        setUrl('https://tienda.co/tienda/');
        await boot({ widgetsUrl: 'https://hub.test/p/pk_x/widgets.json', widgetsJs: 'https://tienda.co/a/igr-widgets.js?ver=1' }, { type: 'category' });
        await settle();
        await new Promise((r) => setTimeout(r, 20));
        expect(fetchMock.mock.calls[0]?.[0]).toBe('https://hub.test/p/pk_x/widgets.json?page_type=category&path=%2Ftienda%2F');
        expect(document.head.querySelector('script[src*="igr-widgets.js"]')).not.toBeNull();

        // Sin widgets ni slots no se carga nada.
        reset();
        vi.stubGlobal('fetch', fetchMock);
        beacons();
        setUrl('https://tienda.co/contacto/');
        await boot({ widgetsUrl: 'https://hub.test/p/pk_x/widgets.json', widgetsJs: 'https://tienda.co/a/igr-widgets.js?ver=1' }, { type: 'landing' });
        await settle();
        await new Promise((r) => setTimeout(r, 20));
        expect(document.head.querySelector('script[src*="igr-widgets.js"]')).toBeNull();

        // Nunca en el checkout.
        reset();
        fetchMock.mockClear();
        vi.stubGlobal('fetch', fetchMock);
        beacons();
        setUrl('https://tienda.co/checkout/');
        await boot({ widgetsUrl: 'https://hub.test/p/pk_x/widgets.json', widgetsJs: 'https://tienda.co/a/igr-widgets.js?ver=1' }, { type: 'checkout' });
        await settle();
        expect(fetchMock.mock.calls.filter((c) => String(c[0]).includes('widgets.json'))).toHaveLength(0);
        vi.unstubAllGlobals();
    });
});
