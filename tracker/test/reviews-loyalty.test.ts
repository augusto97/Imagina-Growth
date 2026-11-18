import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reset, setUrl } from './helpers';
import { ctx, fakeTracker, hubFetch, renderer, tick } from './whelpers';

const W = window as unknown as Record<string, unknown>;

function slot(kind: string): HTMLElement {
    const s = document.createElement('div');
    s.setAttribute('data-igr-slot', kind);
    document.body.append(s);
    return s;
}

beforeEach(() => {
    reset();
    vi.resetModules();
    vi.unstubAllGlobals();
    setUrl('https://tienda.co/producto/pintura/');
});

const page1 = {
    summary: { avg: 4.8, count: 23, distribution: { '5': 20, '4': 2, '3': 1, '2': 0, '1': 0 } },
    reviews: [{ id: 'rv_1', rating: 5, title: 'Excelente', body: 'Cubre muy bien', author: 'Ana R.', verified: true, media: [{ url: 'https://cdn.test/1.jpg', type: 'image', thumb: 'https://cdn.test/1t.jpg' }], created_at: '2026-11-01T10:00:00Z', reply: '¡Gracias, Ana!' }],
    next_page: 2,
};

describe('reseñas', () => {
    it('lista con resumen, compra verificada, fotos diferidas, respuesta y «ver más»', async () => {
        fakeTracker(true);
        const calls = hubFetch({ '/reviews': (u) => [200, u.searchParams.get('page') === '1' ? page1 : { reviews: [{ id: 'rv_2', rating: 4, body: 'Bien', author: 'Luis P.' }], next_page: null }] });
        const x = await ctx();
        await import('../widgets/reviews');
        const s = slot('reviews');
        renderer('reviews')(s, null, x, { productId: '88', layout: 'list' });
        await tick();
        expect(Object.fromEntries(calls[0]?.url.searchParams ?? [])).toEqual({ product_id: '88', page: '1', per_page: '5', with_media: '0', sort: 'recent' });
        const root = s.shadowRoot as ShadowRoot;
        expect(root.querySelector('.big')?.textContent).toBe('4,8');
        expect(root.querySelector('.rv h3')?.textContent).toBe('Excelente');
        expect(root.querySelector('.vf')?.textContent).toContain('Compra verificada');
        expect(root.querySelector('.md img')?.getAttribute('loading')).toBe('lazy');
        expect(root.querySelector('.rp')?.textContent).toBe('Respuesta de la tienda: ¡Gracias, Ana!');
        (Array.from(root.querySelectorAll('button')).find((b) => b.textContent === 'Ver más reseñas') as HTMLButtonElement).click();
        await tick();
        expect(root.querySelectorAll('.rv')).toHaveLength(2);
        expect(Array.from(root.querySelectorAll('button')).some((b) => b.textContent === 'Ver más reseñas')).toBe(false);
        // La foto abre un diálogo que se cierra con Esc.
        (root.querySelector('.md button') as HTMLButtonElement).click();
        const dlg = root.querySelector('[role=dialog]') as HTMLElement;
        expect(dlg.getAttribute('aria-modal')).toBe('true');
        dlg.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        expect(root.querySelector('[role=dialog]')).toBeNull();
    });

    it('las insignias de la página van en una sola petición', async () => {
        fakeTracker(true);
        const calls = hubFetch({ '/reviews/badges': () => [200, { '88': { avg: 4.8, count: 23 }, '91': { avg: 0, count: 0 } }] });
        const x = await ctx();
        await import('../widgets/reviews');
        const a = slot('reviews');
        const b = slot('reviews');
        renderer('reviews')(a, null, x, { productId: '88', layout: 'badge' });
        renderer('reviews')(b, null, x, { productId: '91', layout: 'badge' });
        await tick(10);
        expect(calls).toHaveLength(1);
        expect(calls[0]?.url.searchParams.get('product_ids')).toBe('88,91');
        expect(a.shadowRoot?.querySelector('[role=img]')?.getAttribute('aria-label')).toBe('4,8 de 5 estrellas, 23 reseñas');
        expect(b.hidden).toBe(true);
    });
});

const me = {
    points: { balance: 1532, pending: 282, currency_name: 'Puntos Pintor', expiring: { points: 120, at: '2026-12-31' } },
    tier: { name: 'Plata', next_name: 'Oro', progress_pct: 64, benefits: ['Envío gratis'] },
    wallet: { balance: '35000.00', currency: 'COP' },
    referral: { code: 'ANA7K2', link: 'https://tienda.co/?ref=ANA7K2', share_text: 'Te regalo 10% en Pinturas La 14', friend_reward: '10% en su primera compra', referrer_reward: '500 puntos' },
    rewards: [
        { id: 'rw_1', name: '$20.000 de descuento', points: 500, kind: 'coupon_fixed' },
        { id: 'rw_2', name: 'Kit de brochas', points: 5000, kind: 'product' },
    ],
};

describe('Mis recompensas', () => {
    it('puntos, nivel, saldo, canje con cupón y enlace para compartir', async () => {
        fakeTracker(true);
        W['igrUser'] = { ref: 'wc:77', token: 'eyJyZWYiOiJ3Yzo3NyJ9.' + 'a'.repeat(64) };
        const calls = hubFetch({
            '/me': () => [200, me],
            '/me/redeem': () => [200, { coupon: { code: 'PUNTOS-9X2K', description: '$20.000', expires_at: '2027-01-31T00:00:00Z' }, balance: 1032 }],
            '/me/share': () => [202, {}],
        });
        const x = await ctx();
        await import('../widgets/loyalty');
        const s = slot('loyalty');
        renderer('loyalty')(s, null, x, {});
        await tick();
        expect(calls[0]?.url.searchParams.get('token')).toBe((W['igrUser'] as { token: string }).token);
        const root = s.shadowRoot as ShadowRoot;
        expect(root.querySelector('h2')?.textContent).toBe('Mis Puntos Pintor');
        expect(root.textContent).toContain('282 por confirmar');
        expect(root.querySelector('[role=progressbar]')?.getAttribute('aria-valuenow')).toBe('64');
        expect(root.textContent).toContain('35.000');
        const [first, second] = Array.from(root.querySelectorAll('.rw li button')) as HTMLButtonElement[];
        expect(first?.disabled).toBe(false);
        expect(second?.disabled).toBe(true);
        expect(root.textContent).toContain('Te faltan 3.468.');
        first?.click();
        await tick();
        expect(calls.find((c) => c.url.pathname.endsWith('/me/redeem'))?.body).toEqual({ token: (W['igrUser'] as { token: string }).token, reward_id: 'rw_1' });
        expect(root.querySelector('.cp strong')?.textContent).toBe('PUNTOS-9X2K');
        expect(root.querySelector('.big')?.textContent).toBe('1.032');
        const share = root.querySelector('a.wa') as HTMLAnchorElement;
        expect(decodeURIComponent(share.href)).toContain('https://tienda.co/?ref=ANA7K2');
        share.addEventListener('click', (e) => e.preventDefault());
        share.click();
        await tick();
        expect(calls.find((c) => c.url.pathname.endsWith('/me/share'))?.body).toEqual({ token: (W['igrUser'] as { token: string }).token, channel: 'whatsapp' });
    });

    it('recompensa de saldo, errores del hub y texto para compartir que ya trae el enlace', async () => {
        fakeTracker(true);
        W['igrUser'] = { ref: 'wc:77', token: 'eyJyZWYiOiJ3Yzo3NyJ9.' + 'a'.repeat(64) };
        const link = 'https://tienda.co/?ref=ANA7K2';
        const data = {
            ...me,
            referral: { ...me.referral, share_text: 'Te regalo 10% en Pinturas La 14: ' + link },
            rewards: [
                { id: 'rw_1', name: '$20.000 de saldo', points: 500, kind: 'wallet' },
                { id: 'rw_3', name: 'Otro', points: 600, kind: 'coupon_fixed' },
            ],
        };
        hubFetch({
            '/me': () => [200, data],
            '/me/redeem': (_u, b) =>
                b?.['reward_id'] === 'rw_1' ? [200, { ok: true, balance: 1032, wallet: { balance: '55000.00', currency: 'COP' } }] : [422, { ok: false, error: { code: 'not_redeemed', message: 'Esta recompensa se agotó.' } }],
        });
        const x = await ctx();
        await import('../widgets/loyalty');
        const s = slot('loyalty');
        renderer('loyalty')(s, null, x, {});
        await tick();
        const root = s.shadowRoot as ShadowRoot;
        const [first, second] = Array.from(root.querySelectorAll('.rw li button')) as HTMLButtonElement[];
        first?.click();
        await tick();
        expect(root.querySelector('.rw [role=status]')?.textContent).toContain('55.000');
        expect(root.querySelector('.big')?.textContent).toBe('1.032');
        second?.click();
        await tick();
        expect(root.textContent).toContain('Esta recompensa se agotó.');
        const href = decodeURIComponent((root.querySelector('a.wa') as HTMLAnchorElement).href);
        expect(href.split(link)).toHaveLength(2);
    });

    it('sin token o con el token vencido lo dice', async () => {
        fakeTracker(true);
        hubFetch({ '/me': () => [401, { error: 'token' }] });
        const x = await ctx();
        await import('../widgets/loyalty');
        const a = slot('loyalty');
        renderer('loyalty')(a, null, x, {});
        expect(a.shadowRoot?.textContent).toContain('Inicia sesión');
        W['igrUser'] = { token: 'x.' + 'a'.repeat(64) };
        const b = slot('loyalty');
        renderer('loyalty')(b, null, x, {});
        await tick();
        expect(b.shadowRoot?.textContent).toContain('Tu sesión venció');
    });
});

describe('saldo en el checkout', () => {
    it('muestra el botón solo con saldo y lo aplica por el plugin', async () => {
        W['igrUser'] = { token: 'x.' + 'a'.repeat(64) };
        W['igrConfig'] = { widgetsUrl: 'https://hub.test/p/pk_x/widgets.json', currency: 'COP' };
        const triggered: string[] = [];
        W['jQuery'] = () => ({ trigger: (ev: string) => triggered.push(ev) });
        const calls = hubFetch({ '/me': () => [200, { wallet: { balance: '35000.00', currency: 'COP' } }], '/': () => [200, { ok: true, message: 'Aplicamos $35.000 de tu saldo.' }] });
        document.body.innerHTML = '<div class="igr-slot igr-wallet" data-igr-slot="wallet" data-ajax="https://tienda.co/?wc-ajax=igr_wallet_redeem" data-nonce="n123" hidden><p data-igr-wallet-text></p><button type="button">Usar saldo</button><p data-igr-wallet-msg role="status"></p></div>';
        await import('../widgets/wallet');
        await tick();
        const s = document.querySelector('[data-igr-slot=wallet]') as HTMLElement;
        expect(s.hidden).toBe(false);
        expect(s.querySelector('[data-igr-wallet-text]')?.textContent).toContain('35.000');
        (s.querySelector('button') as HTMLButtonElement).click();
        await tick();
        expect(calls[1]?.url.href).toBe('https://tienda.co/?wc-ajax=igr_wallet_redeem');
        expect(s.querySelector('[data-igr-wallet-msg]')?.textContent).toBe('Aplicamos $35.000 de tu saldo.');
        expect(triggered).toEqual(['update_checkout']);
    });
});
