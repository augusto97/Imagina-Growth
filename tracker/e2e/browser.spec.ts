/*
 * Lado del navegador de punta a punta (tracker/CLAUDE.md §9): tienda real + hub simulado.
 * El hub simulado valida cada evento contra hub/resources/schemas/events (v2).
 */
import { expect, test, type BrowserContext, type Page } from '@playwright/test';
import { hub, hubConfig, newOrder, php, settings, waitHub, type BrowserEvent } from './store';

test.describe.configure({ mode: 'serial' });

/** Espera a que el tracker arranque y envía la cola. */
async function ready(page: Page): Promise<void> {
    await page.waitForFunction(() => (window as unknown as { igr?: { __igr?: boolean } }).igr?.__igr === true);
}
async function flush(page: Page): Promise<void> {
    await page.evaluate(() => (window as unknown as { igr: { flush: () => void } }).igr.flush());
}
async function cookie(ctx: BrowserContext, name: string): Promise<string | undefined> {
    return (await ctx.cookies()).find((c) => c.name === name)?.value;
}
const byPage = (path: string, extra: (e: BrowserEvent) => boolean = () => true) => (s: { browser: BrowserEvent[] }) =>
    s.browser.find((e) => e.event === 'page.viewed' && e.context.page?.path === path && extra(e));

test.afterAll(async () => {
    const s = await hub();
    expect(s.schema_errors, 'ningún evento fuera del contrato').toEqual([]);
});

test('page.viewed llega al hub con identificador propio (modo informado, sin banner)', async ({ page, context }) => {
    await page.goto('/');
    await ready(page);
    await flush(page);
    const aid = await cookie(context, '_igr_aid');
    expect(aid).toMatch(/^[0-9a-f-]{36}$/);
    const ev = await waitHub(byPage('/', (e) => e.identity['anonymous_id'] === aid));
    expect(ev.source.type).toBe('browser');
    expect(ev.context.consent).toEqual({ analytics: true, marketing: false, source: 'none' });
    expect(ev.identity['session_id']).toBeTruthy();
    expect(ev.properties['page_type']).toBe('home');
    expect(JSON.stringify(ev)).not.toMatch(/@|"email"|"phone"/);

    // El ping renueva la cookie desde el servidor sin caché.
    const ping = await page.request.get('/?igr_ping=1', { headers: { Cookie: '_igr_aid=' + aid } });
    expect(ping.status()).toBe(204);
    expect(ping.headers()['cache-control']).toContain('no-store');
    expect(ping.headers()['set-cookie']).toContain('_igr_aid=' + aid);
    expect(ping.headers()['set-cookie']).toMatch(/SameSite=Lax/i);
});

test('el HTML no cambia entre visitantes (cacheable)', async ({ browser }) => {
    const a = await (await browser.newContext()).request.get('/');
    const b = await (await browser.newContext({ extraHTTPHeaders: { Cookie: '_igr_aid=c3b0f4a2-1111-4b2b-9d3e-aaaaaaaaaaaa; cmplz_marketing=allow' } })).request.get('/');
    const conf = (html: string): string => html.match(/window\.igrConfig=.*?;window\.igrPage=.*?;/)?.[0] ?? '';
    const ca = conf(await a.text());
    expect(ca).toContain('"siteKey":"pk_');
    expect(conf(await b.text())).toBe(ca);
    expect(await a.text()).not.toContain('igrUser');
});

test('_igr se quita de la URL y vincula la visita al enlace', async ({ page }) => {
    await page.goto('/?_igr=lnk_K7Q2abc&utm_source=whatsapp&utm_medium=flow&utm_campaign=carrito');
    await ready(page);
    expect(page.url()).not.toContain('_igr=');
    expect(page.url()).toContain('utm_source=whatsapp');
    await flush(page);
    const ev = await waitHub(byPage('/', (e) => e.identity['link_token'] === 'lnk_K7Q2abc'));
    expect(ev.context.page?.url).not.toContain('_igr');
    expect(ev.context['utm']).toEqual({ source: 'whatsapp', medium: 'flow', campaign: 'carrito' });
    const idf = await waitHub((s) => s.browser.find((e) => e.event === 'identify' && e.identity['link_token'] === 'lnk_K7Q2abc'));
    expect(idf.properties).toEqual({ method: 'link_token' });
});

test('clic a WhatsApp: token en el mensaje y cta.clicked antes de salir', async ({ page }) => {
    const id = php(
        `echo wp_insert_post(array('post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Escríbenos', 'post_content' => '<!-- wp:paragraph --><p><a id="wa" href="https://wa.me/573001234567?text=Hola%2C%20quiero%20cotizar">WhatsApp</a> <a id="otro" href="https://wa.me/573009999999?text=Hola">Otro número</a></p><!-- /wp:paragraph -->'));`,
    );
    let opened = '';
    await page.route('https://wa.me/**', (route) => {
        opened = route.request().url();
        return route.fulfill({ status: 200, contentType: 'text/html', body: '<p>WhatsApp</p>' });
    });
    await page.goto('/?page_id=' + id);
    await ready(page);
    await page.waitForTimeout(300); // los clics se enganchan en requestIdleCallback
    await page.click('#wa');
    await page.waitForURL(/wa\.me/);
    const text = new URL(opened).searchParams.get('text') ?? '';
    const m = text.match(/^Hola, quiero cotizar \(ref: ([A-Z0-9]{4,6})\)$/);
    expect(m, text).not.toBeNull();
    const cta = await waitHub((s) => s.browser.find((e) => e.event === 'cta.clicked' && e.properties['wa_ref'] === m?.[1]));
    expect(cta.properties['kind']).toBe('whatsapp');

    // Un número que no está entre los conectados no se toca.
    await page.goto('/?page_id=' + id);
    await ready(page);
    await page.waitForTimeout(300);
    await page.click('#otro');
    await page.waitForURL(/wa\.me/);
    expect(new URL(opened).searchParams.get('text')).toBe('Hola');
    php(`wp_delete_post(${id}, true);`);
});

test('banner propio: sin aceptar no hay cookies; al aceptar se identifica', async ({ browser }) => {
    settings({ banner: true });
    try {
        const ctx = await browser.newContext();
        const page = await ctx.newPage();
        await page.goto('/');
        await ready(page);
        const banner = page.locator('[data-igr-banner]');
        await expect(banner.getByRole('region')).toBeVisible();
        await flush(page);
        const agg = await waitHub(byPage('/', (e) => e.context.page?.url === 'http://127.0.0.1:8080/' && e.context.consent?.source === 'igr-banner' && !e.identity['anonymous_id']));
        expect(agg.context.consent).toEqual({ analytics: false, marketing: false, source: 'igr-banner' });
        expect(Object.keys(agg.identity)).toEqual([]); // el estado del hub simulado guarda {} como []
        await banner.getByRole('button', { name: 'Solo las necesarias' }).click();
        await expect(banner).toHaveCount(0);
        expect(await cookie(ctx, '_igr_consent')).toBe('a0m0');
        expect(await cookie(ctx, '_igr_aid')).toBeUndefined();
        expect(await page.evaluate(() => Object.keys(localStorage).filter((k) => k.startsWith('_igr')))).toEqual([]);
        await page.reload();
        await ready(page);
        await expect(page.locator('[data-igr-banner]')).toHaveCount(0);
        expect(await cookie(ctx, '_igr_aid')).toBeUndefined();
        await ctx.close();

        const ctx2 = await browser.newContext();
        const p2 = await ctx2.newPage();
        await p2.goto('/');
        await ready(p2);
        await p2.locator('[data-igr-banner]').getByRole('button', { name: 'Aceptar' }).click();
        expect(await cookie(ctx2, '_igr_consent')).toBe('a1m1');
        const aid = await cookie(ctx2, '_igr_aid');
        expect(aid).toMatch(/^[0-9a-f-]{36}$/);
        await p2.goto('/?s=vinilo');
        await ready(p2);
        await flush(p2);
        const ev = await waitHub((s) => s.browser.find((e) => e.event === 'search.performed' && e.identity['anonymous_id'] === aid));
        expect(ev.context.consent).toEqual({ analytics: true, marketing: true, source: 'igr-banner' });
        await ctx2.close();
    } finally {
        settings({ banner: false });
    }
});

test('modo estricto elegido en el panel: el sitio sin banner muestra el propio y no guarda identificador hasta aceptar', async ({ browser }) => {
    await hubConfig({ consent: { mode: 'strict', privacy_policy_url: null, texts: [] } });
    php('ImaginaGrowth\\Connector\\Connection\\RemoteConfig::refresh();');
    try {
        const ctx = await browser.newContext();
        const page = await ctx.newPage();
        await page.goto('/');
        await ready(page);
        expect(await page.evaluate(() => (window as unknown as { igrConfig: { consentMode: string } }).igrConfig.consentMode)).toBe('strict');
        const banner = page.locator('[data-igr-banner]');
        await expect(banner.getByRole('region')).toBeVisible();
        expect(await cookie(ctx, '_igr_aid')).toBeUndefined();
        await banner.getByRole('button', { name: 'Aceptar' }).click();
        expect(await cookie(ctx, '_igr_aid')).toMatch(/^[0-9a-f-]{36}$/);
        await ctx.close();
    } finally {
        await hubConfig({ consent: null });
        php('ImaginaGrowth\\Connector\\Connection\\RemoteConfig::refresh();');
    }
});

test('encuesta «¿cómo nos conociste?» en la página de gracias', async ({ page }) => {
    const order = newOrder();
    await page.goto(order.url);
    await ready(page);
    const slot = page.locator('[data-igr-slot="thankyou"]');
    await expect(slot.getByText('¿Cómo nos conociste?')).toBeVisible();
    await slot.getByLabel('Instagram').check();
    await slot.getByRole('button', { name: 'Enviar' }).click();
    await expect(slot.getByRole('status')).toHaveText('¡Gracias por contarnos!');
    const answer = await waitHub((s) => s.survey_answers.find((a) => a.order_id === order.id));
    expect(answer.answer).toBe('instagram');
    expect(answer.anonymous_id).toBeTruthy();
    const s = await hub();
    expect(s.bad_tokens).toBe(0);

    // Ya respondió: no se vuelve a mostrar.
    await page.reload();
    await ready(page);
    await page.waitForTimeout(800);
    await expect(slot.getByText('¿Cómo nos conociste?')).toHaveCount(0);
});

test('identify con igrUser en Mi cuenta', async ({ page }) => {
    const login = php(
        `$u = get_user_by('login', 'e2e-cliente'); if (!$u) { $id = wc_create_new_customer('e2e-cliente@example.com', 'e2e-cliente', 'Clave-e2e-123'); } else { $id = $u->ID; wp_set_password('Clave-e2e-123', $id); } echo $id;`,
    );
    await page.goto('/wp-login.php');
    await page.fill('#user_login', 'e2e-cliente');
    await page.fill('#user_pass', 'Clave-e2e-123');
    await page.click('#wp-submit');
    await page.goto(php(`echo wc_get_page_permalink('myaccount');`).replace(/^https?:\/\/[^/]+/, ''));
    await ready(page);
    const token = await page.evaluate(() => (window as unknown as { igrUser?: { token: string } }).igrUser?.token);
    expect(token).toMatch(/^[A-Za-z0-9_-]+\.[0-9a-f]{64}$/);
    await flush(page);
    const aid = await cookie(page.context(), '_igr_aid');
    const linked = await waitHub((s) => s.identified.find((i) => i.ref === 'wc:' + login && i.anonymous_id === aid));
    expect(linked).toBeTruthy();
    // Otra página con sesión: token nuevo, mismo cliente → no se repite identify.
    await page.reload();
    await ready(page);
    await flush(page);
    await page.waitForTimeout(500);
    expect((await hub()).identified.filter((i) => i.ref === 'wc:' + login)).toHaveLength(1);
    php(`wp_delete_user(${login});`);
});

test('compra: el píxel de Meta usa igrOrder.eventId (deduplicación con el servidor)', async ({ browser }) => {
    await hubConfig({ measurement: { mode: 'full', pixels: { meta: '1234567890' } } });
    php('ImaginaGrowth\\Connector\\Connection\\RemoteConfig::refresh();');
    settings({ banner: true });
    try {
        const ctx = await browser.newContext();
        await ctx.addCookies([{ name: '_igr_consent', value: 'a1m1', url: 'http://127.0.0.1:8080' }]);
        const hits: URL[] = [];
        // fbevents.js simulado: reenvía cada llamada como lo haría Meta (/tr con eid).
        await ctx.route('https://connect.facebook.net/**', (route) =>
            route.fulfill({
                contentType: 'application/javascript',
                body:
                    '(function(){var f=window.fbq,id="";function send(a){if(a[0]==="init"){id=a[1];return}if(a[0]!=="track")return;var o=a[3]||{},d=a[2]||{};' +
                    'new Image().src="https://www.facebook.com/tr/?id="+id+"&ev="+encodeURIComponent(a[1])+"&eid="+encodeURIComponent(o.eventID||"")+"&cd[value]="+(d.value||"")+"&cd[currency]="+(d.currency||"")}' +
                    'f.callMethod=function(){send([].slice.call(arguments))};(f.queue||[]).forEach(send);f.queue=[]})();',
            }),
        );
        await ctx.route('https://www.facebook.com/tr/**', (route) => {
            hits.push(new URL(route.request().url()));
            return route.fulfill({ status: 200, contentType: 'image/gif', body: '' });
        });
        await ctx.route(/analytics\.tiktok\.com|googletagmanager\.com/, (route) => route.abort());

        const paid = newOrder('wompi', 'processing');
        const page = await ctx.newPage();
        await page.goto(paid.url);
        await ready(page);
        const igrOrder = await page.evaluate(() => (window as unknown as { igrOrder: { eventId: string; value: string; currency: string; paid: boolean } }).igrOrder);
        expect(igrOrder.paid).toBe(true);
        await expect.poll(() => hits.find((h) => h.searchParams.get('ev') === 'Purchase')?.searchParams.get('eid'), { timeout: 10_000 }).toBe(igrOrder.eventId);
        const purchase = hits.find((h) => h.searchParams.get('ev') === 'Purchase') as URL;
        expect(purchase.searchParams.get('id')).toBe('1234567890');
        expect(Number(purchase.searchParams.get('cd[value]'))).toBe(Number(igrOrder.value));
        expect(purchase.searchParams.get('cd[currency]')).toBe('COP');
        // El event_id que espera el servidor para order.paid (UUIDv5 determinista).
        expect(igrOrder.eventId).toBe(php(`echo ImaginaGrowth\\Connector\\Support\\Uuid::event(ImaginaGrowth\\Connector\\Connection\\Connection::site_id(), 'order:${paid.id}:paid');`));

        // PageView con el mismo event_id que el page.viewed que recibió el hub.
        await flush(page);
        const pv = hits.find((h) => h.searchParams.get('ev') === 'PageView');
        expect(pv).toBeTruthy();
        await waitHub((s) => s.browser.find((e) => e.event === 'page.viewed' && e.event_id === pv?.searchParams.get('eid')));

        // Recargar no repite la compra.
        await page.reload();
        await ready(page);
        await page.waitForTimeout(1000);
        expect(hits.filter((h) => h.searchParams.get('ev') === 'Purchase')).toHaveLength(1);

        // Contraentrega sin pagar: el píxel no dispara Purchase (lo envía el servidor al confirmar).
        const cod = newOrder('cod', 'processing');
        await page.goto(cod.url);
        await ready(page);
        await page.waitForTimeout(1500);
        expect(hits.filter((h) => h.searchParams.get('ev') === 'Purchase')).toHaveLength(1);
        await ctx.close();
    } finally {
        settings({ banner: false });
        await hubConfig({ measurement: { mode: 'server_only', pixels: {} } });
        php('ImaginaGrowth\\Connector\\Connection\\RemoteConfig::refresh();');
    }
});

test('sin consentimiento de marketing no se cargan píxeles', async ({ page }) => {
    await hubConfig({ measurement: { mode: 'full', pixels: { meta: '1234567890' } } });
    php('ImaginaGrowth\\Connector\\Connection\\RemoteConfig::refresh();');
    try {
        const requested: string[] = [];
        await page.route(/facebook\.(net|com)/, (route) => {
            requested.push(route.request().url());
            return route.abort();
        });
        await page.goto('/');
        await ready(page);
        await page.waitForTimeout(1000);
        expect(await page.evaluate(() => (window as unknown as { igrConfig: { pixels?: unknown } }).igrConfig.pixels)).toEqual({ meta: '1234567890' });
        expect(requested).toEqual([]);
        expect(await page.evaluate(() => typeof (window as unknown as { fbq?: unknown }).fbq)).toBe('undefined');
    } finally {
        await hubConfig({ measurement: { mode: 'server_only', pixels: {} } });
        php('ImaginaGrowth\\Connector\\Connection\\RemoteConfig::refresh();');
    }
});
