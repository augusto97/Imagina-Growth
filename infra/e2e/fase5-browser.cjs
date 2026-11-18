/*
 * Navegador de la prueba de la Fase 5 (infra/e2e/fase5.sh).
 *
 *   node fase5-browser.cjs visitor → una persona llega desde un anuncio de Google al sitio corporativo (sin WooCommerce),
 *                                     mira los servicios y deja sus datos en el formulario de Contact Form 7.
 *   node fase5-browser.cjs book    → la misma persona (sus cookies) vuelve y agenda su valoración con el botón de reservas
 *                                     (página pública del hub, con su visitante y el anuncio en el enlace).
 *   node fase5-browser.cjs quote   → otra persona usa el cotizador incrustado en el sitio y deja sus datos.
 *   node fase5-browser.cjs owner   → el dueño de la clínica entra con 2FA: tablero de leads, marca el lead como ganado
 *                                     con su valor (API del panel), la agenda y el perfil.
 *   node fase5-browser.cjs agency  → la dueña de la agencia entra con 2FA: el panel con todos sus clientes, genera el
 *                                     reporte mensual de la clínica y lo descarga en PDF.
 *
 * Variables: HUB, CORP, WS_CLINIC, NAME, EMAIL, PHONE, LEAD, VALUE, MONTH, OWNER_EMAIL, OWNER_PASS, AGENCY_EMAIL,
 *            AGENCY_PASS, TOTP_SECRET, SHOTS, GCLID. Imprime una línea JSON al final.
 */
'use strict';
const crypto = require('node:crypto');
const { chromium } = require('playwright');

const env = process.env;
const shot = (page, name) => (env.SHOTS ? page.screenshot({ path: `${env.SHOTS}/${name}.png`, fullPage: true }) : null);

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

async function login(page, email, password) {
    await page.goto(env.HUB + '/login');
    await page.fill('input[type=email]', email);
    await page.fill('input[type=password]', password);
    await page.click('button[type=submit]');
    await page.waitForURL(/verificacion/, { timeout: 15000 });
    const left = (u) => !u.pathname.startsWith('/verificacion') && !u.pathname.startsWith('/login');
    for (let attempt = 0; ; attempt++) {
        if (Date.now() % 30000 > 27000) await page.waitForTimeout(3500);
        await page.locator('input').first().fill(totp(env.TOTP_SECRET));
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
            const type = res.headers.get('content-type') || '';
            if (type.includes('application/pdf')) {
                const buf = new Uint8Array(await res.arrayBuffer());
                return { status: res.status, type, bytes: buf.length, magic: String.fromCharCode(...buf.slice(0, 5)) };
            }
            return { status: res.status, type, body: await res.json().catch(() => null) };
        },
        { method, path, body },
    );
}

function watch(page, errors) {
    page.on('pageerror', (e) => errors.push(String(e.message).slice(0, 160)));
}

async function visitor() {
    const browser = await chromium.launch();
    const context = await browser.newContext({ locale: 'es-CO', viewport: { width: 1280, height: 900 } });
    const page = await context.newPage();
    const errors = [];
    watch(page, errors);
    const ad = `utm_source=google&utm_medium=cpc&utm_campaign=ortodoncia-manizales&gclid=${env.GCLID}`;

    // 1. Llega desde el anuncio y mira los servicios.
    await page.goto(`${env.CORP}/servicios/?${ad}`);
    await page.waitForTimeout(1500);
    await page.goto(`${env.CORP}/valoracion/`);
    await page.waitForSelector('form.wpcf7-form');

    // 2. Formulario de Contact Form 7, con autorización de datos y novedades por WhatsApp y correo.
    await page.fill('input[name=your-name]', env.NAME);
    await page.fill('input[name=your-email]', env.EMAIL);
    await page.fill('input[name=your-phone]', env.PHONE);
    await page.selectOption('select[name=servicio]', 'Ortodoncia');
    await page.selectOption('select[name=presupuesto]', '2 a 8 millones');
    await page.fill('textarea[name=your-message]', 'Quiero saber cuánto cuesta la ortodoncia invisible y si tienen financiación.');
    await page.check('input[name=acepta-datos]');
    await page.check('input[name="whatsapp-novedades[]"]');
    await page.check('input[name="correo-novedades[]"]');
    await shot(page, '01-formulario');
    await page.click('input[type=submit], button[type=submit]');
    await page.waitForSelector('form.wpcf7-form.sent, form.wpcf7-form.failed, form.wpcf7-form.invalid', { timeout: 20000 });
    const formState = await page.getAttribute('form.wpcf7-form', 'data-status');
    await shot(page, '02-formulario-enviado');
    await context.storageState({ path: `${env.SHOTS}/visitante.json` }); // las cookies del sitio para la reserva
    await browser.close();
    return { form: formState, errors };
}

async function book() {
    const browser = await chromium.launch();
    const context = await browser.newContext({ locale: 'es-CO', viewport: { width: 1280, height: 900 }, storageState: `${env.SHOTS}/visitante.json` });
    const page = await context.newPage();
    const errors = [];
    watch(page, errors);

    // 3. Vuelve al sitio y agenda su valoración: el botón lleva a la página de reservas del hub.
    await page.goto(`${env.CORP}/agenda/`);
    const button = page.locator('a.igr-booking').first();
    await button.waitFor({ timeout: 10000 });
    const bookingHref = await button.getAttribute('href');
    await button.click();
    await page.waitForURL(/\/reservar/, { timeout: 15000 });
    const landing = new URL(page.url()).searchParams;
    await page.waitForSelector('label.slot input[name=start]', { timeout: 15000 });
    const start = await page.locator('label.slot input[name=start]').first().getAttribute('value');
    await page.locator('label.slot').first().click();
    await page.fill('#name', env.NAME);
    await page.fill('#phone', env.PHONE);
    await page.fill('#email', env.EMAIL);
    const consent = page.locator('input[name="consent[whatsapp]"]');
    if (await consent.count()) await consent.check();
    await shot(page, '03-reserva');
    await page.getByRole('button', { name: 'Agendar cita' }).click();
    await page.waitForSelector('text=¡Listo! Tu cita quedó agendada.', { timeout: 15000 });
    const manageUrl = page.url();
    await shot(page, '04-cita-agendada');

    await browser.close();
    return { start, booked: true, manage: manageUrl.includes('/cita/'), carried: { aid: landing.has('aid'), gclid: landing.get('gclid') === env.GCLID }, errors };
}

async function quote() {
    const browser = await chromium.launch();
    const context = await browser.newContext({ locale: 'es-CO', viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
    const page = await context.newPage();
    const errors = [];
    watch(page, errors);
    await page.goto(`${env.CORP}/cotiza/?utm_source=facebook&utm_medium=paid_social&utm_campaign=sonrisa`);
    const heading = page.getByRole('heading', { name: 'Diseño de sonrisa' });
    await heading.waitFor({ timeout: 20000 });
    await page.getByLabel('Número de dientes').fill('8');
    const material = page.getByLabel('Material');
    if ((await material.evaluate((el) => el.tagName)) === 'SELECT') {
        await material.selectOption('porcelana');
    } else {
        await page.getByLabel('Porcelana').check();
    }
    await page.getByLabel('Nombre').fill(env.NAME);
    await page.getByRole('textbox', { name: 'WhatsApp', exact: true }).fill(env.PHONE);
    await page.getByLabel('Correo').fill(env.EMAIL);
    await shot(page, '05-cotizador');
    await page.getByRole('button', { name: 'Ver mi cotización' }).click();
    const status = page.locator('[role=status]').filter({ hasText: '$' }).first();
    await status.waitFor({ timeout: 15000 });
    const result = (await status.innerText()).replace(/\s+/g, ' ').trim();
    await shot(page, '06-cotizacion');
    await browser.close();
    return { result: result.slice(0, 200), errors };
}

async function owner() {
    const browser = await chromium.launch();
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    const errors = [];
    watch(page, errors);
    await login(page, env.OWNER_EMAIL, env.OWNER_PASS);
    const w = `/api/w/${env.WS_CLINIC}`;
    await page.goto(`${env.HUB}/w/${env.WS_CLINIC}/leads`);
    await page.waitForLoadState('networkidle');
    await shot(page, '07-leads-tablero');
    const board = await api(page, 'GET', `${w}/leads/board`);
    const won = await api(page, 'POST', `${w}/leads/${env.LEAD}/stage`, { stage: 'won', value: Number(env.VALUE) });
    const kpis = await api(page, 'GET', `${w}/leads/kpis`);
    await page.goto(`${env.HUB}/w/${env.WS_CLINIC}/leads?lead=${env.LEAD}`);
    await page.waitForLoadState('networkidle');
    await shot(page, '08-lead-ganado');
    await page.goto(`${env.HUB}/w/${env.WS_CLINIC}/agenda`);
    await page.waitForLoadState('networkidle');
    await shot(page, '09-agenda');
    await browser.close();
    return {
        board: board.status,
        won: { status: won.status, stage: won.body?.lead?.stage ?? won.body?.data?.stage ?? null },
        kpis: { status: kpis.status, leads: kpis.body?.leads ?? null, won: kpis.body?.won ?? null },
        errors,
    };
}

async function agency() {
    const browser = await chromium.launch();
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    const errors = [];
    watch(page, errors);
    await login(page, env.AGENCY_EMAIL, env.AGENCY_PASS);
    const w = `/api/w/${env.WS_CLINIC}`;
    const overview = await api(page, 'GET', `${w}/agency/overview`);
    await page.goto(`${env.HUB}/w/${env.WS_CLINIC}/agencia`);
    await page.waitForLoadState('networkidle');
    await shot(page, '10-agencia-clientes');
    const generated = await api(page, 'POST', `${w}/agency/reports/${env.WS_CLINIC}/generate`, { month: env.MONTH, send: false });
    const reportId = generated.body?.report?.id;
    const pdf = reportId ? await api(page, 'GET', `${w}/agency/reports/${env.WS_CLINIC}/${reportId}/pdf`) : null;
    await page.goto(`${env.HUB}/w/${env.WS_CLINIC}/agencia?tab=reportes`);
    await page.waitForLoadState('networkidle');
    await shot(page, '11-agencia-reportes');
    await browser.close();
    const clients = (overview.body?.clients ?? []).map((c) => ({
        name: c.name,
        health: c.health?.status ?? null,
        money: c.money?.total ?? null,
        top: c.money?.top_module ?? null,
    }));
    return { overview: overview.status, clients, report: { status: generated.status, id: reportId ?? null }, pdf, errors };
}

const modes = { visitor, book, quote, owner, agency };
const mode = process.argv[2];
if (!modes[mode]) {
    console.error('Modo desconocido: ' + mode);
    process.exit(2);
}
modes[mode]()
    .then((r) => console.log(JSON.stringify(r)))
    .catch((e) => {
        console.error(e);
        process.exit(1);
    });
