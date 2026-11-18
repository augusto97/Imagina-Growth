/*
 * Parte del navegador de la prueba de la Fase 2 (infra/e2e/fase2.sh).
 *
 *   node fase2-browser.cjs compra     → primera compra con consentimiento de correo (crea la clienta)
 *   node fase2-browser.cjs abandona   → la misma clienta (mismas cookies) arma un carrito, entra al checkout y se va
 *   node fase2-browser.cjs recupera URL → abre el enlace del correo en OTRO dispositivo (sin cookies), el sitio
 *                                         restaura el carrito y paga con tarjeta
 *
 * Las cookies de la clienta se guardan en STATE (storageState de Playwright) entre «compra» y «abandona».
 * Imprime en la última línea un JSON con lo que el guion necesita.
 */
'use strict';
const fs = require('node:fs');
const { chromium } = require('playwright');

const BASE = process.env.STORE_URL || 'http://127.0.0.1:8080';
const EMAIL = process.env.EMAIL || 'marta.e2e@example.com';
const PHONE = process.env.PHONE || '311' + String(Date.now()).slice(-7);
const STATE = process.env.STATE || '/tmp/igr-fase2-state.json';
const SHOTS = process.env.SHOTS || '';
const [, , step, arg] = process.argv;

async function ready(page) {
    await page.waitForFunction(() => window.igr && window.igr.__igr === true, null, { timeout: 15000 });
}
async function flush(page) {
    await page.evaluate(() => window.igr.flush());
    await page.waitForTimeout(400);
}
const address = (phone) => ({
    first_name: 'Marta',
    last_name: 'Rincón',
    address_1: 'Calle 23 # 21-41',
    city: 'Manizales',
    state: 'CO-CAL',
    postcode: '170001',
    country: 'CO',
    phone,
});

/** Agrega un producto con stock por la Store API (como el carrito de bloques). */
async function addToCart(page, qty) {
    return page.evaluate(async (qty) => {
        const products = await (await fetch('/wp-json/wc/store/v1/products?per_page=5&type=simple&stock_status=instock&orderby=price&order=asc')).json();
        const product = products[2] || products[0];
        const r = await fetch('/wp-json/wc/store/v1/cart', { credentials: 'same-origin' });
        const nonce = r.headers.get('Nonce');
        const add = await fetch('/wp-json/wc/store/v1/cart/add-item', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Nonce: nonce },
            body: JSON.stringify({ id: product.id, quantity: qty }),
        });
        if (!add.ok) throw new Error('add-item ' + add.status + ' ' + (await add.text()));
        return { id: product.id, name: product.name, url: product.permalink };
    }, qty);
}

/** Paga el carrito actual con la tarjeta de prueba (Store API), con o sin consentimiento de correo. */
async function checkout(page, consent) {
    return page.evaluate(
        async ({ email, address, consent }) => {
            const r = await fetch('/wp-json/wc/store/v1/cart', { credentials: 'same-origin' });
            const nonce = r.headers.get('Nonce');
            const res = await fetch('/wp-json/wc/store/v1/checkout', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Nonce: nonce },
                body: JSON.stringify({
                    billing_address: { ...address, email },
                    shipping_address: address,
                    payment_method: 'igr_e2e_card',
                    additional_fields: consent ? { 'imagina-growth/marketing-email': true } : {},
                }),
            });
            const body = await res.json();
            if (!res.ok) throw new Error('checkout ' + res.status + ' ' + JSON.stringify(body));
            return { orderId: String(body.order_id), redirect: body.payment_result.redirect_url };
        },
        { email: EMAIL, address: address(PHONE), consent },
    );
}

(async () => {
    const browser = await chromium.launch();
    const fresh = step === 'recupera';
    const ctx = await browser.newContext({ locale: 'es-CO', viewport: { width: 1280, height: 900 }, ...(fresh || step === 'compra' ? {} : { storageState: STATE }) });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));
    const out = { step, email: EMAIL };

    if (step === 'compra') {
        await page.goto(BASE + '/?utm_source=google&utm_medium=organic');
        await ready(page);
        const banner = page.locator('[data-igr-banner]');
        if (await banner.count()) await banner.getByRole('button', { name: 'Aceptar' }).click();
        out.product = await addToCart(page, 1);
        await page.goto(BASE + '/checkout/');
        await ready(page);
        out.order = await checkout(page, true);
        await page.goto(out.order.redirect);
        await ready(page);
        await flush(page);
        out.aid = (await ctx.cookies()).find((c) => c.name === '_igr_aid')?.value || null;
        await ctx.storageState({ path: STATE });
    } else if (step === 'abandona') {
        await page.goto(BASE + '/');
        await ready(page);
        out.product = await addToCart(page, 2);
        await page.goto(BASE + '/cart/');
        await ready(page);
        await page.goto(BASE + '/checkout/');
        await ready(page);
        await flush(page);
        if (SHOTS) await page.screenshot({ path: SHOTS + '/checkout-abandonado.png' });
        out.aid = (await ctx.cookies()).find((c) => c.name === '_igr_aid')?.value || null;
    } else if (step === 'recupera') {
        // Otro dispositivo: abre el enlace del correo; el hub registra el clic y la tienda arma el carrito.
        const response = await page.goto(arg);
        await ready(page);
        out.landed = page.url();
        out.status = response ? response.status() : null;
        out.cart = await page.evaluate(async () => {
            const c = await (await fetch('/wp-json/wc/store/v1/cart', { credentials: 'same-origin' })).json();
            return { items: c.items.map((i) => ({ name: i.name, qty: i.quantity })), coupons: c.coupons.map((x) => x.code) };
        });
        if (SHOTS) await page.screenshot({ path: SHOTS + '/carrito-restaurado.png', fullPage: true });
        await flush(page);
        out.order = await checkout(page, false);
        await page.goto(out.order.redirect);
        await ready(page);
        await flush(page);
        if (SHOTS) await page.screenshot({ path: SHOTS + '/pedido-recuperado.png', fullPage: true });
        out.aid = (await ctx.cookies()).find((c) => c.name === '_igr_aid')?.value || null;
    } else {
        throw new Error('Paso desconocido: ' + step);
    }

    out.errors = errors;
    await browser.close();
    console.log(JSON.stringify(out));
})().catch((e) => {
    console.error(e);
    process.exit(1);
});
