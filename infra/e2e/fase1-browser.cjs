/*
 * Parte del navegador de la prueba de la Fase 1 (infra/e2e/fase1.sh).
 *
 * Una visitante llega desde un anuncio de Facebook, acepta el banner, mira un
 * producto, busca, agrega al carrito y paga con tarjeta (Store API, la misma que
 * usa el checkout de bloques). En la página de gracias el píxel de Meta dispara
 * Purchase con el event_id de igrOrder y ella responde «¿cómo nos conociste?».
 *
 *   STORE_URL=http://127.0.0.1:8080 EMAIL=laura@example.com node fase1-browser.cjs
 *
 * Imprime en la última línea un JSON con lo que el guion necesita comprobar en el hub.
 */
'use strict';
const { chromium } = require('playwright');

const BASE = process.env.STORE_URL || 'http://127.0.0.1:8080';
const EMAIL = process.env.EMAIL || 'laura.gomez.e2e@example.com';
// Un celular distinto en cada corrida: con el mismo, la identidad (correctamente) la uniría a la compradora anterior.
const PHONE = process.env.PHONE || '310' + String(Date.now()).slice(-7);
const SHOTS = process.env.SHOTS || '';

/**
 * fbevents.js simulado: como el real, deja _fbp (navegador) y _fbc (si llegó con fbclid)
 * en el dominio del sitio y reenvía cada llamada a /tr con su eid.
 */
const FBEVENTS =
    '(function(){var now=Date.now();if(!/(^|; )_fbp=/.test(document.cookie)){document.cookie="_fbp=fb.1."+now+"."+Math.floor(Math.random()*1e10)+";path=/;max-age=7776000;SameSite=Lax"}' +
    'var m=location.search.match(/[?&]fbclid=([^&]+)/);if(m){document.cookie="_fbc=fb.1."+now+"."+m[1]+";path=/;max-age=7776000;SameSite=Lax"}' +
    'var f=window.fbq,id="";function send(a){if(a[0]==="init"){id=a[1];return}if(a[0]!=="track")return;var o=a[3]||{},d=a[2]||{};' +
    'new Image().src="https://www.facebook.com/tr/?id="+id+"&ev="+encodeURIComponent(a[1])+"&eid="+encodeURIComponent(o.eventID||"")+"&cd[value]="+(d.value||"")+"&cd[currency]="+(d.currency||"")}' +
    'f.callMethod=function(){send([].slice.call(arguments))};(f.queue||[]).forEach(send);f.queue=[]})();';

async function ready(page) {
    await page.waitForFunction(() => window.igr && window.igr.__igr === true, null, { timeout: 15000 });
}
async function flush(page) {
    await page.evaluate(() => window.igr.flush());
    await page.waitForTimeout(400);
}
async function waitFor(fn, what, timeout = 15000) {
    const end = Date.now() + timeout;
    for (;;) {
        const v = await fn();
        if (v) return v;
        if (Date.now() > end) throw new Error('No llegó a tiempo: ' + what);
        await new Promise((r) => setTimeout(r, 250));
    }
}

(async () => {
    const browser = await chromium.launch();
    const ctx = await browser.newContext({ locale: 'es-CO', viewport: { width: 1280, height: 900 } });
    const hits = [];
    await ctx.route('https://connect.facebook.net/**', (route) => route.fulfill({ contentType: 'application/javascript', body: FBEVENTS }));
    await ctx.route('https://www.facebook.com/tr/**', (route) => {
        hits.push(new URL(route.request().url()));
        return route.fulfill({ status: 200, contentType: 'image/gif', body: '' });
    });
    await ctx.route(/analytics\.tiktok\.com|googletagmanager\.com|google-analytics\.com/, (route) => route.abort());
    const errors = [];
    const page = await ctx.newPage();
    page.on('pageerror', (e) => errors.push(String(e)));

    // 1. Llega desde un anuncio de Facebook y acepta el banner.
    await page.goto(BASE + '/?utm_source=facebook&utm_medium=paid_social&utm_campaign=e2e-fase1&fbclid=IwAR0e2eFase1Click');
    await ready(page);
    const banner = page.locator('[data-igr-banner]');
    await banner.getByRole('button', { name: 'Aceptar' }).click();
    const cookies = async () => Object.fromEntries((await ctx.cookies()).map((c) => [c.name, c.value]));
    const aid = await waitFor(async () => (await cookies())['_igr_aid'], 'cookie _igr_aid');
    await flush(page);

    // 2. Mira un producto y busca.
    const product = await page.evaluate(async () => {
        const r = await fetch('/wp-json/wc/store/v1/products?per_page=1&type=simple&stock_status=instock&orderby=price&order=desc');
        const [p] = await r.json();
        return { id: p.id, url: p.permalink, name: p.name };
    });
    await page.goto(product.url);
    await ready(page);
    await flush(page);
    await page.goto(BASE + '/?s=vinilo');
    await ready(page);
    await flush(page);

    // 3. Carrito y checkout (Store API, como el checkout de bloques), con WhatsApp y correo aceptados.
    await page.evaluate(async (id) => {
        const r = await fetch('/wp-json/wc/store/v1/cart', { credentials: 'same-origin' });
        const nonce = r.headers.get('Nonce');
        const add = await fetch('/wp-json/wc/store/v1/cart/add-item', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Nonce: nonce },
            body: JSON.stringify({ id, quantity: 2 }),
        });
        if (!add.ok) throw new Error('add-item ' + add.status + ' ' + (await add.text()));
    }, product.id);
    await page.goto(BASE + '/checkout/');
    await ready(page);
    await flush(page);
    const checkout = await page.evaluate(async ({ email, phone }) => {
        const r = await fetch('/wp-json/wc/store/v1/cart', { credentials: 'same-origin' });
        const nonce = r.headers.get('Nonce');
        const address = {
            first_name: 'Laura',
            last_name: 'Gómez',
            address_1: 'Carrera 7 # 45-10',
            city: 'Manizales',
            state: 'CO-CAL',
            postcode: '170001',
            country: 'CO',
            phone,
        };
        const res = await fetch('/wp-json/wc/store/v1/checkout', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Nonce: nonce },
            body: JSON.stringify({
                billing_address: { ...address, email },
                shipping_address: address,
                payment_method: 'igr_e2e_card',
                additional_fields: { 'imagina-growth/marketing-whatsapp': true, 'imagina-growth/marketing-email': true },
            }),
        });
        const body = await res.json();
        if (!res.ok) throw new Error('checkout ' + res.status + ' ' + JSON.stringify(body));
        return { orderId: String(body.order_id), redirect: body.payment_result.redirect_url, status: body.status };
    }, { email: EMAIL, phone: PHONE });

    // 4. Página de gracias: píxel de compra con el event_id de igrOrder y encuesta.
    await page.goto(checkout.redirect);
    await ready(page);
    const igrOrder = await page.evaluate(() => window.igrOrder);
    if (!igrOrder || !igrOrder.paid) throw new Error('igrOrder no llegó pagado: ' + JSON.stringify(igrOrder));
    const purchase = await waitFor(() => hits.find((h) => h.searchParams.get('ev') === 'Purchase'), 'Purchase del píxel');
    const slot = page.locator('[data-igr-slot="thankyou"]');
    await slot.getByText('¿Cómo nos conociste?').waitFor({ timeout: 15000 });
    await slot.getByLabel('Instagram').check();
    await slot.getByRole('button', { name: 'Enviar' }).click();
    await slot.getByRole('status').filter({ hasText: '¡Gracias por contarnos!' }).waitFor({ timeout: 10000 });
    if (SHOTS) await page.screenshot({ path: SHOTS + '/gracias.png', fullPage: true });
    await flush(page);
    await page.reload();
    await ready(page);
    await page.waitForTimeout(1000);
    await flush(page);
    const purchases = hits.filter((h) => h.searchParams.get('ev') === 'Purchase').length;

    const result = {
        aid,
        fbc: (await cookies())['_fbc'] || null,
        fbp: (await cookies())['_fbp'] || null,
        email: EMAIL,
        product,
        order: checkout,
        igrOrder,
        pixel: {
            purchaseEventId: purchase.searchParams.get('eid'),
            purchaseValue: purchase.searchParams.get('cd[value]'),
            purchaseCurrency: purchase.searchParams.get('cd[currency]'),
            purchases,
            pageViewEventIds: hits.filter((h) => h.searchParams.get('ev') === 'PageView').map((h) => h.searchParams.get('eid')),
        },
        errors,
    };
    await browser.close();
    console.log(JSON.stringify(result));
})().catch((e) => {
    console.error(e);
    process.exit(1);
});
