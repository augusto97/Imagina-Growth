/*
 * Parte del panel de la prueba de la Fase 1 (infra/e2e/fase1.sh).
 *
 * Inicia sesión como dueña con 2FA en la SPA real, mide cada tablero con un año
 * de datos (criterio: < 1,5 s, sin caché) y recorre Inicio, Analítica, el perfil
 * 360 de la compradora, Segmentos y Consentimiento. Graba el recorrido en video
 * y deja capturas en OUT.
 *
 *   HUB=http://127.0.0.1:8010 WS=ws_… OWNER_EMAIL=… OWNER_PASS=… TOTP_SECRET=… EMAIL=… OUT=/carpeta node fase1-panel.cjs
 */
'use strict';
const crypto = require('node:crypto');
const { chromium } = require('playwright');

const { HUB, WS, OWNER_EMAIL, OWNER_PASS, TOTP_SECRET, EMAIL, OUT } = process.env;
const LIMIT_MS = 1500;
const DASHBOARDS = ['overview', 'funnel', 'channels', 'customers', 'products', 'searches', 'geography', 'coupons', 'messaging', 'profitability', 'services', 'hdyhau'];

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
const ymd = (d) => d.toISOString().slice(0, 10);

(async () => {
    const browser = await chromium.launch();
    const ctx = await browser.newContext({
        locale: 'es-CO',
        viewport: { width: 1440, height: 900 },
        recordVideo: OUT ? { dir: OUT, size: { width: 1440, height: 900 } } : undefined,
    });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));
    page.on('console', (m) => m.type() === 'error' && errors.push(m.text()));

    // 1. Acceso con contraseña y 2FA.
    await page.goto(HUB + '/login');
    await page.fill('input[type=email]', OWNER_EMAIL);
    await page.fill('input[type=password]', OWNER_PASS);
    await page.click('button[type=submit]');
    await page.waitForURL(/verificacion/, { timeout: 15000 });
    if (Date.now() % 30000 > 27000) await page.waitForTimeout(3500); // que el código no venza a mitad de camino
    await page.locator('input').first().fill(totp(TOTP_SECRET));
    await page.click('button[type=submit]');
    await page.waitForURL((u) => !u.pathname.startsWith('/verificacion') && !u.pathname.startsWith('/login'), { timeout: 15000 });

    // 2. Tableros con un año de datos, sin caché (cada consulta con un rango distinto).
    const to = new Date();
    const from = new Date(to.getTime() - 364 * 86400000);
    const results = [];
    for (const d of DASHBOARDS) {
        const r = await page.evaluate(
            async ({ ws, d, from, to }) => {
                const t0 = performance.now();
                const res = await fetch(`/api/w/${ws}/analytics/${d}?from=${from}&to=${to}&compare=previous`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                const body = await res.json().catch(() => ({}));
                return { status: res.status, ms: Math.round(performance.now() - t0), took: body.took_ms ?? null };
            },
            { ws: WS, d, from: ymd(from), to: ymd(to) },
        );
        results.push({ dashboard: d, ...r });
    }
    console.log('tablero          HTTP   ms (navegador)   ms (servidor)');
    for (const r of results) console.log(`${r.dashboard.padEnd(16)} ${String(r.status).padEnd(6)} ${String(r.ms).padStart(8)}   ${String(r.took ?? '—').padStart(12)}`);
    const slow = results.filter((r) => r.status !== 200 || r.ms >= LIMIT_MS);
    const max = Math.max(...results.map((r) => r.ms));
    console.log(slow.length === 0 ? `✓ Todos los tableros responden en menos de ${LIMIT_MS} ms (máximo ${max} ms)` : `✗ Fuera de criterio: ${JSON.stringify(slow)}`);

    // 3. Recorrido por las pantallas (capturas para el cierre de la fase).
    const shot = async (name) => OUT && page.screenshot({ path: `${OUT}/${name}.png`, fullPage: true });
    const visit = async (path, name) => {
        await page.goto(HUB + `/w/${WS}/${path}`);
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(600);
        await shot(name);
    };
    await visit('', 'inicio');
    await visit(`analitica?from=${ymd(from)}&to=${ymd(to)}`, 'analitica-resumen');
    await visit(`analitica/canales?from=${ymd(from)}&to=${ymd(to)}`, 'analitica-canales');
    await visit('contactos', 'contactos');

    // Perfil 360 de la compradora (búsqueda por correo, que usa el índice ciego).
    const found = await page.evaluate(
        async ({ ws, email }) => {
            const res = await fetch(`/api/w/${ws}/contacts?search=${encodeURIComponent(email)}`, { headers: { Accept: 'application/json' } });
            const body = await res.json();
            return (body.data || [])[0]?.id || null;
        },
        { ws: WS, email: EMAIL },
    );
    if (!found) throw new Error('No se encontró a la compradora en Contactos');
    const t0 = Date.now();
    await page.goto(HUB + `/w/${WS}/contactos/${found}`);
    await page.waitForLoadState('networkidle');
    const profileMs = Date.now() - t0;
    await page.waitForTimeout(600);
    await shot('perfil-360');
    console.log(`✓ Perfil 360 abierto en ${profileMs} ms (incluye la SPA)`);
    await visit('segmentos', 'segmentos');
    await visit('consentimiento', 'consentimiento');

    const real = errors.filter((e) => !/favicon|ResizeObserver/.test(e));
    if (real.length) console.log('Errores de consola:', real.slice(0, 5));
    await ctx.close();
    await browser.close();
    if (slow.length || real.length) process.exit(1);
})().catch((e) => {
    console.error(e);
    process.exit(1);
});
