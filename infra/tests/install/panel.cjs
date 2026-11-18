/*
 * El administrador de la plataforma actualiza desde el panel (infra/tests/install/e2e-update.sh).
 *
 *   node panel.cjs <versión>
 *
 * Entra con 2FA, abre /plataforma → «Actualizaciones», pulsa «Buscar ahora», «Instalar <versión>», confirma con
 * su contraseña y espera el resultado que muestra el panel (instalada, falló o volvió a la anterior). Mientras
 * espera, el panel puede no responder unos segundos (reinicio): se reintenta.
 *
 * Variables: BASE (https://growth.e2e.test), MAP_IP (IP del servidor de prueba), ADMIN_EMAIL, ADMIN_PASS,
 * TOTP_SECRET, WS (ws_…), SHOTS (carpeta de capturas, opcional). Imprime una línea JSON al final.
 */
'use strict';
const crypto = require('node:crypto');
const { chromium } = require('playwright');

const env = process.env;
const target = process.argv[2];
const host = new URL(env.BASE).hostname;

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
const shot = (page, name) => (env.SHOTS ? page.screenshot({ path: `${env.SHOTS}/${name}.png`, fullPage: true }).catch(() => null) : null);

async function login(page) {
    await page.goto(env.BASE + '/login');
    await page.fill('input[type=email]', env.ADMIN_EMAIL);
    await page.fill('input[type=password]', env.ADMIN_PASS);
    await page.click('button[type=submit]');
    await page.waitForURL(/verificacion/, { timeout: 30000 });
    const left = (u) => !u.pathname.startsWith('/verificacion') && !u.pathname.startsWith('/login');
    for (let attempt = 0; ; attempt++) {
        if (Date.now() % 30000 > 27000) await page.waitForTimeout(3500);
        await page.locator('input').first().fill(totp(env.TOTP_SECRET));
        await page.click('button[type=submit]');
        try {
            await page.waitForURL(left, { timeout: 10000 });
            return;
        } catch (e) {
            if (attempt > 0) throw e; // el mismo código no vale dos veces: se espera la siguiente ventana
            await page.waitForTimeout(30000 - (Date.now() % 30000) + 500);
        }
    }
}

async function openUpdates(page) {
    await page.goto(`${env.BASE}/w/${env.WS}/plataforma`);
    await page.getByRole('tab', { name: 'Actualizaciones' }).click();
}

(async () => {
    const browser = await chromium.launch({ args: [`--host-resolver-rules=MAP ${host} ${env.MAP_IP}, MAP in.${host} ${env.MAP_IP}`] });
    const context = await browser.newContext({ ignoreHTTPSErrors: true, locale: 'es-CO', viewport: { width: 1440, height: 1000 } });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e.message).slice(0, 160)));
    await login(page);
    await openUpdates(page);
    await page.getByRole('button', { name: 'Buscar ahora' }).click();
    const install = page.getByRole('button', { name: `Instalar ${target}` });
    await install.waitFor({ timeout: 60000 });
    await shot(page, `panel-${target}-1-disponible`);
    await install.click();
    await page.getByLabel('Tu contraseña').fill(env.ADMIN_PASS);
    await page.getByRole('button', { name: 'Instalar', exact: true }).click();
    await page.getByText(`Pedido enviado`).first().waitFor({ timeout: 30000 });
    await shot(page, `panel-${target}-2-pedido`);

    // El resultado final que muestra el panel (el estado lo escribe growth-update en shared/state).
    const finals = {
        succeeded: `La versión ${target} quedó instalada.`,
        rolled_back: `La versión ${target} falló y se volvió a la versión anterior`,
        failed: `No se instaló la versión ${target}`,
    };
    const deadline = Date.now() + 15 * 60 * 1000;
    let state = null;
    while (!state && Date.now() < deadline) {
        await page.waitForTimeout(5000);
        try {
            for (const [key, text] of Object.entries(finals)) {
                if (await page.getByText(text).first().isVisible().catch(() => false)) {
                    state = key;
                    break;
                }
            }
            if (!state && Math.random() < 0.3) await openUpdates(page); // el reinicio pudo cortar el sondeo
        } catch {
            await page.waitForTimeout(3000); // el panel se está reiniciando
        }
    }
    await shot(page, `panel-${target}-3-resultado`);
    await browser.close();
    console.log(JSON.stringify({ target, state, errors }));
})().catch((e) => {
    console.error(e);
    process.exit(1);
});
