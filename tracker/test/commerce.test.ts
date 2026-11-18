import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reset, setUrl } from './helpers';
import { ctx, fakeTracker, hubFetch, renderer, tick } from './whelpers';

const W = window as unknown as Record<string, unknown>;

function slot(kind: string): HTMLElement {
    const s = document.createElement('div');
    s.className = 'igr-slot';
    s.setAttribute('data-igr-slot', kind);
    s.style.minHeight = '40px';
    document.body.append(s);
    return s;
}

beforeEach(() => {
    reset();
    vi.resetModules();
    vi.unstubAllGlobals();
    setUrl('https://tienda.co/producto/pintura/');
});

const product = { id: '91', name: 'Rodillo profesional', price: '25000.00', regular_price: '30000.00', sale_price: '25000.00', currency: 'COP', image: 'https://tienda.co/91.jpg', url: 'https://tienda.co/p/91', rating_avg: 4.5, rating_count: 12 };

describe('recomendaciones', () => {
    it('pide /reco con el tipo y el contexto, y pinta tarjetas accesibles', async () => {
        fakeTracker(true);
        document.cookie = '_igr_aid=aid-12345678; Path=/';
        const calls = hubFetch({ '/reco': () => [200, { kind: 'para_ti', products: [product, { ...product, id: '92', url: 'javascript:alert(1)' }] }] });
        const x = await ctx();
        await import('../widgets/commerce');
        const s = slot('reco');
        renderer('reco')(s, null, x, { kind: 'para_ti', productId: '88', limit: 4 });
        await tick();
        const q = calls[0]?.url.searchParams;
        expect(calls[0]?.url.pathname).toBe('/p/pk_x/reco');
        expect(Object.fromEntries(q ?? [])).toEqual({ kind: 'para_ti', product_id: '88', aid: 'aid-12345678', limit: '4' });
        const root = s.shadowRoot as ShadowRoot;
        expect(root.querySelector('h2')?.textContent).toBe('Recomendados para ti');
        const cards = root.querySelectorAll('.pc');
        expect(cards).toHaveLength(2);
        expect(cards[0]?.querySelector('a')?.getAttribute('href')).toBe('https://tienda.co/p/91');
        expect(cards[1]?.querySelector('a')?.getAttribute('href')).toBe('#');
        expect(cards[0]?.querySelector('del')?.textContent).toContain('30.000');
        expect(cards[0]?.querySelector('[role=img]')?.getAttribute('aria-label')).toBe('4,5 de 5 estrellas, 12 reseñas');
    });

    it('sin productos se recoge el espacio; los tipos no personales no mandan aid', async () => {
        fakeTracker(true);
        document.cookie = '_igr_aid=aid-12345678; Path=/';
        const calls = hubFetch({ '/reco': () => [200, { products: [] }] });
        const x = await ctx();
        await import('../widgets/commerce');
        const s = slot('reco');
        renderer('reco')(s, null, x, { kind: 'bought_together', productId: '88' });
        await tick();
        expect(calls[0]?.url.searchParams.get('aid')).toBeNull();
        expect(s.hidden).toBe(true);
        expect(s.style.minHeight).toBe('0');
    });
});

describe('avísame', () => {
    it('un campo para correo o WhatsApp, casilla de marketing opcional y POST /watch', async () => {
        fakeTracker(true);
        const calls = hubFetch({ '/watch': () => [200, { ok: true, message: 'Te avisaremos cuando vuelva.' }] });
        const x = await ctx([], { consents: [{ channel: 'email', label: 'Quiero recibir ofertas por correo.', version: 'v1' }] });
        await import('../widgets/commerce');
        const s = slot('bis');
        renderer('bis')(s, null, x, { productId: '88', kind: 'back_in_stock' });
        const root = s.shadowRoot as ShadowRoot;
        const open = root.querySelector('button') as HTMLButtonElement;
        expect(open.textContent).toBe('Avísame cuando vuelva');
        expect(open.getAttribute('aria-expanded')).toBe('false');
        open.click();
        const input = root.querySelector('input[type=text]') as HTMLInputElement;
        expect(root.querySelector('label[for="' + input.id + '"]')?.textContent).toBe('Tu correo o WhatsApp');
        const ck = root.querySelector('label.ck') as HTMLElement;
        expect(ck.hidden).toBe(true); // WhatsApp sin texto del hub: no hay casilla
        input.value = 'ana@example.com';
        input.dispatchEvent(new Event('input'));
        expect(ck.hidden).toBe(false);
        expect((ck.querySelector('input') as HTMLInputElement).checked).toBe(false);
        root.querySelector('form')?.dispatchEvent(new Event('submit', { cancelable: true }));
        await tick();
        expect(calls[0]?.body).toEqual({ anonymous_id: null, product_id: '88', kind: 'back_in_stock', consents: { email: false }, consent_version: 'v1', email: 'ana@example.com' });
        expect(root.querySelector('[role=status]')?.textContent).toBe('Te avisaremos cuando vuelva.');
    });
});

describe('puntos y banner', () => {
    it('«Gana N puntos» o se recoge si no hay programa', async () => {
        fakeTracker(true);
        hubFetch({ '/points': (u) => [200, u.searchParams.get('product_id') === '88' ? { points: 150, label: 'Gana 150 Puntos Pintor' } : { points: 0 }] });
        const x = await ctx();
        await import('../widgets/commerce');
        const a = slot('points');
        const b = slot('points');
        renderer('points')(a, null, x, { productId: '88', price: '150000.00' });
        renderer('points')(b, null, x, { productId: '89' });
        await tick();
        expect(a.shadowRoot?.querySelector('.pts')?.textContent).toBe('Gana 150 Puntos Pintor');
        expect(b.hidden).toBe(true);
    });

    it('banner de Mi cuenta con el token; null se recoge', async () => {
        fakeTracker(true);
        W['igrUser'] = { token: 'abc.' + 'f'.repeat(64) };
        const calls = hubFetch({ '/banner': (u) => [200, { banner: u.searchParams.get('placement') === 'account' ? { text: 'Eres VIP: envío gratis este mes', cta: 'Ver beneficios', url: 'https://tienda.co/vip', style: 'vip' } : null }] });
        const x = await ctx();
        await import('../widgets/commerce');
        const acc = slot('banner');
        const top = slot('banner');
        renderer('banner')(acc, null, x, { placement: 'account' });
        renderer('banner')(top, null, x, { placement: 'top' });
        await tick();
        expect(calls[0]?.url.searchParams.get('token')).toBe('abc.' + 'f'.repeat(64));
        expect(acc.shadowRoot?.querySelector('.bn.vip p')?.textContent).toBe('Eres VIP: envío gratis este mes');
        expect(top.hidden).toBe(true);
    });
});
