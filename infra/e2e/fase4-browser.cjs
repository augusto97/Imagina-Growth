/*
 * Navegador de la prueba de la Fase 4 (infra/e2e/fase4.sh).
 *
 *   node fase4-browser.cjs panel   → la dueña entra con 2FA: Predicciones (qué tan confiable es cada modelo),
 *                                     «Pregúntale a tus datos» por el API del panel, Informes y la Bandeja con
 *                                     la conversación del agente y el panel del contacto.
 *   node fase4-browser.cjs link URL PRODUCTO → un cliente abre el enlace de carrito o de pago que mandó el chat y
 *                                     se comprueba que el producto está ahí para pagar.
 *
 * Variables: HUB, WS_DEMO, WS_SHOP, OWNER_EMAIL, OWNER_PASS, TOTP_SECRET, SHOTS, EMAIL (para el pago de invitado).
 * Imprime una línea JSON al final.
 */
'use strict';
const crypto = require('node:crypto');
const { chromium } = require('playwright');

const { HUB, WS_DEMO, WS_SHOP, OWNER_EMAIL, OWNER_PASS, TOTP_SECRET, SHOTS, EMAIL } = process.env;

function totp(secret) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const c of secret) bits += alphabet.indexOf(c).toString(2).padStart(5, '0');
    const key = Buffer.from(bits.match(/.{8}/g).map((b) => parseInt(b, 2)));
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
    const h = crypto.createHmac('sha1', key).update(counter).digest();
    const o = h[h.length - 1] & 0xf;
    return String((h.readUInt32BE(o) & 0x7fffffff) % 1000000).padStart(6, '0');
}
const shot = (page, name) => (SHOTS ? page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: true }) : null);

async function login(page) {
    await page.goto(HUB + '/login');
    await page.fill('input[type=email]', OWNER_EMAIL);
    await page.fill('input[type=password]', OWNER_PASS);
    await page.click('button[type=submit]');
    await page.waitForURL(/verificacion/, { timeout: 15000 });
    const left = (u) => !u.pathname.startsWith('/verificacion') && !u.pathname.startsWith('/login');
    for (let attempt = 0; ; attempt++) {
        if (Date.now() % 30000 > 27000) await page.waitForTimeout(3500);
        await page.locator('input').first().fill(totp(TOTP_SECRET));
        await page.click('button[type=submit]');
        try {
            await page.waitForURL(left, { timeout: 8000 });
            return;
        } catch (e) {
            if (attempt > 0) throw e; // Fortify no acepta el mismo código dos veces: se espera la siguiente ventana.
            await page.waitForTimeout(30000 - (Date.now() % 30000) + 500);
        }
    }
}

async function api(page, method, path, body) {
    return page.evaluate(
        async ({ method, path, body }) => {
            const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
            const res = await fetch(path, {
                method,
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf },
                body: body ? JSON.stringify(body) : undefined,
            });
            return { status: res.status, body: await res.json().catch(() => null) };
        },
        { method, path, body },
    );
}

async function panel() {
    const browser = await chromium.launch();
    const ctx = await browser.newContext({ locale: 'es-CO', viewport: { width: 1440, height: 900 } });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));
    page.on('console', (m) => m.type() === 'error' && !/Failed to load resource/.test(m.text()) && errors.push(m.text()));
    await login(page);

    // 1. Predicciones: «qué tan confiable es» cada modelo, medido contra la reserva de 90 días.
    await page.goto(`${HUB}/w/${WS_DEMO}/inteligencia/predicciones`);
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(1500);
    const predText = await page.locator('main').innerText();
    await shot(page, '1-predicciones');
    const reliability = (predText.match(/[^\n]*(predijo|confiable|Confiabilidad|Alta|Media|Baja)[^\n]*/gi) || []).slice(0, 8);

    // 2. «Pregúntale a tus datos» por el API del panel (sesión de la dueña).
    const ask = await api(page, 'POST', `/api/w/${WS_DEMO}/ai/ask`, { question: '¿Cuánto vendí en los últimos 30 días?' });
    await page.goto(`${HUB}/w/${WS_DEMO}/inteligencia/preguntale`);
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(800);
    await shot(page, '2-preguntale');

    // 3. Informes del analista semanal.
    await page.goto(`${HUB}/w/${WS_DEMO}/inteligencia/informes`);
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(800);
    await shot(page, '3-informes');

    // 4. Bandeja: la conversación del agente con el panel del contacto.
    await page.goto(`${HUB}/w/${WS_SHOP}/bandeja`);
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(1500);
    const first = page.locator('[data-conversation-id], [role=listitem], li button, a[href*="bandeja/"]').first();
    if ((await first.count()) > 0) {
        await first.click().catch(() => {});
        await page.waitForTimeout(1500);
    }
    const inboxText = await page.locator('body').innerText();
    await shot(page, '4-bandeja');
    await page.goto(`${HUB}/w/${WS_SHOP}/bandeja/agente`);
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(1200);
    await shot(page, '5-agente-revision');

    await browser.close();
    console.log(JSON.stringify({
        reliability,
        ask: { status: ask.status, answer: ask.body?.answer ?? ask.body?.query?.answer ?? null, source: ask.body?.status ?? null },
        inboxHasPrice: /\$\s?\d/.test(inboxText),
        errors,
    }));
}

async function link(url, product) {
    const browser = await chromium.launch();
    const ctx = await browser.newContext({ locale: 'es-CO', viewport: { width: 390, height: 844 }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();
    await page.goto(url, { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    // Pedido de invitado: WooCommerce pide confirmar el correo de facturación antes de mostrar el pago.
    const verify = page.locator('form.woocommerce-verify-email input[type=email], input#email');
    if (EMAIL && (await verify.count()) > 0 && !(await page.locator('#order_review, .shop_table').count())) {
        await verify.first().fill(EMAIL);
        await page.locator('form button[type=submit], form input[type=submit]').first().click();
        await page.waitForLoadState('networkidle').catch(() => {});
    }
    await page.waitForTimeout(1500);
    const text = await page.locator('body').innerText();
    const finalUrl = page.url();
    const name = /order-pay/.test(finalUrl) ? 'pago' : 'carrito';
    await shot(page, `6-${name}`);
    await browser.close();
    console.log(JSON.stringify({ finalUrl, hasProduct: text.toLowerCase().includes(String(product).toLowerCase()), page: name }));
}

(async () => {
    const [cmd, ...args] = process.argv.slice(2);
    if (cmd === 'panel') return panel();
    if (cmd === 'link') return link(args[0], args[1]);
    throw new Error('Subcomando desconocido: ' + cmd);
})().catch((e) => {
    console.error(e);
    process.exit(1);
});
