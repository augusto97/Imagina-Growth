/*
 * Parte del navegador de la prueba de la Fase 3 (infra/e2e/fase3.sh).
 *
 *   node fase3-browser.cjs popup            → Marta llega anónima, el popup le pide su WhatsApp, recibe el cupón,
 *                                             compra con él (correo con consentimiento) y crea su cuenta
 *   node fase3-browser.cjs resena URL FOTO   → abre el enlace del correo de reseña, 5 estrellas y una foto
 *   node fase3-browser.cjs recompensas       → inicia sesión y abre Mi cuenta → Mis recompensas: puntos y enlace para invitar
 *   node fase3-browser.cjs amigo URL         → Luis abre el enlace de referido, recibe su regalo en la bienvenida y compra
 *
 * Imprime en la última línea un JSON con lo que el guion necesita.
 */
'use strict';
const { chromium } = require('playwright');

const BASE = process.env.STORE_URL || 'http://127.0.0.1:8080';
const EMAIL = process.env.EMAIL || 'marta.e2e@example.com';
const PASS = process.env.PASS || 'Clave-Marta-2026!';
const PHONE = process.env.PHONE || '311' + String(Date.now()).slice(-7);
const SHOTS = process.env.SHOTS || '';
const [, , step, arg, arg2] = process.argv;

async function ready(page) {
    await page.waitForFunction(() => window.igr && window.igr.__igr === true, null, { timeout: 15000 });
}
async function flush(page) {
    await page.evaluate(() => window.igr.flush());
    await page.waitForTimeout(500);
}
async function shot(page, name) {
    if (SHOTS) await page.screenshot({ path: `${SHOTS}/${name}.png` });
}
async function acceptBanner(page) {
    const banner = page.locator('[data-igr-banner]');
    if (await banner.count()) await banner.getByRole('button', { name: 'Aceptar' }).click();
}
const address = (first, last, phone) => ({
    first_name: first,
    last_name: last,
    address_1: 'Calle 23 # 21-41',
    city: 'Manizales',
    state: 'CO-CAL',
    postcode: '170001',
    country: 'CO',
    phone,
});

/** Agrega un producto con stock y aplica el cupón por la Store API (como el carrito de bloques). */
async function cartWithCoupon(page, coupon, email) {
    return page.evaluate(async ({ coupon, email }) => {
        const products = await (await fetch('/wp-json/wc/store/v1/products?per_page=5&type=simple&stock_status=instock&orderby=price&order=desc')).json();
        const product = products[1] || products[0];
        const r = await fetch('/wp-json/wc/store/v1/cart', { credentials: 'same-origin' });
        const nonce = r.headers.get('Nonce');
        const post = (path, body) =>
            fetch('/wp-json/wc/store/v1/cart/' + path, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Nonce: nonce }, body: JSON.stringify(body) });
        const add = await post('add-item', { id: product.id, quantity: 1 });
        if (!add.ok) throw new Error('add-item ' + add.status + ' ' + (await add.text()));
        // Los cupones con el correo restringido (el regalo del amigo) piden el correo antes de aplicarse.
        const who = await post('update-customer', { billing_address: { email } });
        if (!who.ok) throw new Error('update-customer ' + who.status + ' ' + (await who.text()));
        const apply = await post('apply-coupon', { code: coupon });
        if (!apply.ok) throw new Error('apply-coupon ' + apply.status + ' ' + (await apply.text()));
        const cart = await apply.json();
        return { id: product.id, name: product.name, coupons: cart.coupons.map((c) => c.code), total: cart.totals.total_price };
    }, { coupon, email });
}

/** Paga el carrito con la tarjeta de prueba; con `password` crea la cuenta en el checkout. */
async function checkout(page, { email, address, consent, password }) {
    return page.evaluate(
        async ({ email, address, consent, password }) => {
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
                    ...(password ? { create_account: true, customer_password: password } : {}),
                }),
            });
            const body = await res.json();
            if (!res.ok) throw new Error('checkout ' + res.status + ' ' + JSON.stringify(body));
            return { orderId: String(body.order_id), redirect: body.payment_result.redirect_url };
        },
        { email, address, consent, password },
    );
}

/** Lee el cupón del mensaje de éxito del widget. */
async function couponFrom(dialog) {
    const status = dialog.getByRole('status');
    await status.waitFor({ timeout: 15000 });
    await page_wait(status);
    const text = (await status.innerText()).trim();
    const m = text.match(/\b([A-Z0-9]{3,}-[A-Z0-9]{4,})\b/);
    if (!m) throw new Error('Sin cupón en el mensaje: ' + text);
    return { code: m[1], text };
}
async function page_wait(locator) {
    for (let i = 0; i < 40; i++) {
        if (/[A-Z0-9]{3,}-[A-Z0-9]{4,}/.test(await locator.innerText())) return;
        await new Promise((r) => setTimeout(r, 250));
    }
}

(async () => {
    const browser = await chromium.launch();
    const mobile = step === 'resena';
    const ctx = await browser.newContext({ locale: 'es-CO', ...(mobile ? { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true } : { viewport: { width: 1280, height: 900 } }) });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));
    page.on('console', (m) => {
        if (m.type() === 'error' && !/favicon|Failed to load resource/.test(m.text())) errors.push(m.text());
    });
    const out = { step };

    if (step === 'popup') {
        // 1. Llega anónima al inicio: el popup sale de widgets.json y le pide su WhatsApp.
        await page.goto(BASE + '/?utm_source=instagram&utm_medium=social&utm_campaign=bienvenida');
        await ready(page);
        await acceptBanner(page);
        const dialog = page.locator('[data-igr-w="popup"]').getByRole('dialog');
        await dialog.waitFor({ timeout: 15000 });
        await page.waitForTimeout(600); // fin de la animación de entrada
        out.title = (await dialog.getByRole('heading').first().innerText()).trim();
        await shot(page, '1-popup');
        await dialog.getByLabel('Tu nombre').fill('Marta');
        await dialog.getByLabel('Tu WhatsApp').fill(PHONE);
        const box = dialog.getByRole('checkbox').first();
        out.consentUnchecked = !(await box.isChecked());
        await box.check();
        await dialog.getByRole('button', { name: 'Quiero mi cupón' }).click();
        out.coupon = await couponFrom(dialog);
        await shot(page, '2-popup-cupon');
        await flush(page);
        await dialog.getByRole('button', { name: /Cerrar/i }).first().click().catch(() => {});

        // 2. Compra con el cupón, acepta correos y crea su cuenta.
        out.cart = await cartWithCoupon(page, out.coupon.code, EMAIL);
        await page.goto(BASE + '/checkout/');
        await ready(page);
        out.order = await checkout(page, { email: EMAIL, address: address('Marta', 'Rincón', PHONE), consent: true, password: PASS });
        await page.goto(out.order.redirect);
        await ready(page);
        await flush(page);
        await shot(page, '3-pedido-marta');
        out.aid = (await ctx.cookies()).find((c) => c.name === '_igr_aid')?.value || null;
    } else if (step === 'resena') {
        // 3. Desde el celular abre el enlace del correo: 5 estrellas, un comentario y una foto.
        const response = await page.goto(arg);
        out.status = response ? response.status() : null;
        out.landed = page.url().replace(/\/r\/[^/?]+/, '/r/…');
        await page.locator('label[for="star-5"]').click();
        await page.fill('#title', 'Cubre con una sola mano');
        await page.fill('#body', 'La pintura rindió más de lo que esperaba y el color quedó igual al de la carta.');
        await page.setInputFiles('#media', arg2);
        await shot(page, '4-resena-formulario');
        await Promise.all([page.waitForURL(/gracias/, { timeout: 20000 }), page.click('#submit')]);
        out.thanks = (await page.locator('body').innerText()).slice(0, 300);
        out.google = await page.locator('a[href*="g.page"]').count();
        await shot(page, '5-resena-gracias');
    } else if (step === 'recompensas') {
        // 4. Inicia sesión y abre Mis recompensas: sus puntos y el enlace para invitar.
        await page.goto(BASE + '/wp-login.php');
        await page.fill('#user_login', EMAIL);
        await page.fill('#user_pass', PASS);
        await page.click('#wp-submit');
        await page.waitForURL((u) => !u.pathname.includes('wp-login'));
        await page.goto(BASE + '/my-account/recompensas/');
        await ready(page);
        const slot = page.locator('[data-igr-slot="loyalty"]');
        await slot.getByRole('heading').first().waitFor({ timeout: 15000 });
        await slot.locator('input[readonly]').first().waitFor({ timeout: 15000 });
        out.heading = (await slot.getByRole('heading').first().innerText()).trim();
        out.points = (await slot.getByText(/^[\d.]+$/).first().innerText().catch(() => '')).trim();
        out.link = await slot.locator('input[readonly]').first().inputValue();
        await shot(page, '6-mis-recompensas');
    } else if (step === 'amigo') {
        // 5. Luis abre el enlace de Marta: la bienvenida de referido le da su regalo y compra con él.
        await page.goto(arg);
        await ready(page);
        await acceptBanner(page);
        const dialog = page.locator('[data-igr-w="referral_welcome"]').getByRole('dialog');
        await dialog.waitFor({ timeout: 15000 });
        await page.waitForTimeout(600); // fin de la animación de entrada
        await shot(page, '7-bienvenida-referido');
        await dialog.getByLabel('Tu nombre').fill('Luis');
        await dialog.getByLabel('Tu correo').fill(EMAIL);
        await dialog.getByRole('checkbox').first().check();
        await dialog.getByRole('button', { name: 'Recibir mi regalo' }).click();
        out.coupon = await couponFrom(dialog);
        await shot(page, '8-regalo-amigo');
        await flush(page);
        await dialog.getByRole('button', { name: /Cerrar/i }).first().click().catch(() => {});
        out.src = (await ctx.cookies()).find((c) => c.name === '_igr_src')?.value || null;
        out.cart = await cartWithCoupon(page, out.coupon.code, EMAIL);
        await page.goto(BASE + '/checkout/');
        await ready(page);
        out.order = await checkout(page, { email: EMAIL, address: address('Luis', 'Ospina', PHONE), consent: false });
        await page.goto(out.order.redirect);
        await ready(page);
        await flush(page);
        await shot(page, '9-pedido-luis');
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
