/*
 * Fase 3 en la tienda (api-connector.md §5.5, §7 y §8.3): widgets de captación, slots,
 * Mis recompensas, saldo en el checkout de bloques y referidos, contra la tienda real y
 * el hub simulado (connector/tests/fixtures/mock-hub.php).
 */
import { expect, test, type BrowserContext, type Page } from '@playwright/test';
import { hub, hubConfig, php, waitHub } from './store';

test.describe.configure({ mode: 'serial' });

const CONSENT_WA = 'Quiero recibir ofertas por WhatsApp. Puedo darme de baja cuando quiera.';
const path = (url: string): string => url.replace(/^https?:\/\/[^/]+/, '');

async function ready(page: Page): Promise<void> {
    await page.waitForFunction(() => (window as unknown as { igr?: { __igr?: boolean } }).igr?.__igr === true);
}
async function flush(page: Page): Promise<void> {
    await page.evaluate(() => (window as unknown as { igr: { flush: () => void } }).igr.flush());
}
async function cookie(ctx: BrowserContext, name: string): Promise<string | undefined> {
    return (await ctx.cookies()).find((c) => c.name === name)?.value;
}
async function widgets(list: unknown[]): Promise<void> {
    await hubConfig({ widgets: list });
}
function login(user: string): string {
    return php(
        `$u = get_user_by('login', '${user}'); if (!$u) { $id = wc_create_new_customer('${user}@example.com', '${user}', 'Clave-e2e-123'); } else { $id = $u->ID; wp_set_password('Clave-e2e-123', $id); } echo $id;`,
    );
}
async function signIn(page: Page, user: string): Promise<void> {
    await page.goto('/wp-login.php');
    await page.fill('#user_login', user);
    await page.fill('#user_pass', 'Clave-e2e-123');
    await page.click('#wp-submit');
    await page.waitForURL((u) => !u.pathname.includes('wp-login'));
}

const popup = {
    id: 'wg_01JF0POPUP0000000000000001',
    kind: 'popup',
    slot: null,
    variants: [{ key: 'a', weight: 100, design: { layout: 'center' }, content: {} }],
    targeting: { trigger: { type: 'immediate' }, page_types: ['home'], frequency: { max_views: 3, cooldown_days: 0 } },
    content: {
        title: '10% en tu primera compra',
        body: 'Déjanos tu WhatsApp y te enviamos el cupón.',
        fields: [{ key: 'phone', type: 'tel', required: true, label: 'Tu WhatsApp' }],
        consents: [{ channel: 'whatsapp', label: CONSENT_WA, version: 'v3' }],
        cta: 'Quiero mi cupón',
        success: '¡Listo! Tu cupón es {{codigo}}',
    },
};

let product: { id: string; url: string; name: string };
const pages: number[] = [];

test.beforeAll(() => {
    product = JSON.parse(
        php(
            `$p = wc_get_products(array('limit' => 1, 'status' => 'publish', 'type' => 'simple', 'stock_status' => 'instock', 'orderby' => 'ID', 'order' => 'ASC'))[0];` +
                `echo wp_json_encode(array('id' => (string) $p->get_id(), 'url' => get_permalink($p->get_id()), 'name' => $p->get_name()));`,
        ),
    ) as { id: string; url: string; name: string };
});

test.afterAll(async () => {
    for (const id of pages) php(`wp_delete_post(${id}, true);`);
    await hubConfig({ widgets: null, reco: null });
    const s = await hub();
    expect(s.schema_errors, 'ningún evento fuera del contrato').toEqual([]);
});

test('popup con casillas sin marcar: envía al hub, muestra el cupón y no vuelve a salir', async ({ page, context }) => {
    await widgets([popup]);
    await page.goto('/');
    await ready(page);
    const host = page.locator('[data-igr-w="popup"]');
    const dialog = host.getByRole('dialog', { name: '10% en tu primera compra' });
    await expect(dialog).toBeVisible();
    await expect(dialog).toHaveAttribute('aria-modal', 'true');
    const consent = dialog.getByRole('checkbox', { name: CONSENT_WA });
    await expect(consent).not.toBeChecked();
    // Sin datos: error accesible y nada viaja al hub.
    await dialog.getByRole('button', { name: 'Quiero mi cupón' }).click();
    await expect(dialog.getByLabel('Tu WhatsApp')).toHaveAttribute('aria-invalid', 'true');
    await dialog.getByLabel('Tu WhatsApp').fill('300 123 4567');
    await consent.check();
    await dialog.getByRole('button', { name: 'Quiero mi cupón' }).click();
    await expect(dialog.getByRole('status')).toContainText(/¡Listo! Tu cupón es BIENV-[A-F0-9]{6}/);
    const aid = await cookie(context, '_igr_aid');
    const sub = await waitHub((s) => s.submits.find((x) => x.widget_id === popup.id));
    expect(sub).toMatchObject({ kind: 'popup', variant: 'a', step: 1, anonymous_id: aid, consents: { whatsapp: true }, fields: ['phone'], referral_code: null });

    // Esc cierra y el foco vuelve a la página; no causó desplazamientos de diseño.
    await page.keyboard.press('Escape');
    await expect(host).toHaveCount(0);
    const cls = await page.evaluate(
        () =>
            new Promise<number>((done) => {
                let sum = 0;
                new PerformanceObserver((l) => {
                    for (const e of l.getEntries() as unknown as { value: number; hadRecentInput: boolean }[]) if (!e.hadRecentInput) sum += e.value;
                }).observe({ type: 'layout-shift', buffered: true });
                setTimeout(() => done(sum), 300);
            }),
    );
    expect(cls).toBeLessThan(0.01);
    await flush(page);
    const shown = await waitHub((s) => s.browser.find((e) => e.event === 'widget.submitted' && e.properties['widget_id'] === popup.id));
    expect(shown.properties).toEqual({ widget_id: popup.id, variant: 'a', kind: 'popup' });

    // Ya envió: no vuelve a salir.
    await page.reload();
    await ready(page);
    await page.waitForTimeout(800);
    await expect(page.locator('[data-igr-w="popup"]')).toHaveCount(0);
});

test('quiz incrustado: ramas, respuestas al hub y productos del resultado', async ({ page }) => {
    const quiz = {
        id: 'wg_01JF0QUIZ00000000000000001',
        kind: 'quiz',
        slot: 'quiz',
        content: { title: '¿Qué pintura necesitas?' },
        quiz: {
            questions: [
                { id: 'q1', text: '¿Dónde vas a pintar?', options: [{ key: 'int', label: 'Interior', next: 'q2' }, { key: 'ext', label: 'Exterior', next: 'q3' }] },
                { id: 'q2', text: '¿Qué acabado prefieres?', options: [{ key: 'mate', label: 'Mate' }] },
                { id: 'q3', text: '¿Cómo es el clima?', options: [{ key: 'lluvia', label: 'Lluvioso' }, { key: 'sol', label: 'Soleado' }] },
            ],
        },
    };
    await widgets([quiz]);
    await hubConfig({ reco: [{ id: product.id, name: product.name, price: '150000.00', regular_price: '150000.00', sale_price: null, currency: 'COP', image: null, url: product.url, rating_avg: 4.8, rating_count: 23 }] });
    const id = Number(php(`echo wp_insert_post(array('post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Encuentra tu pintura', 'post_content' => '[igr_quiz id="${quiz.id}"]'));`));
    pages.push(id);
    await page.goto('/?page_id=' + id);
    await ready(page);
    const slot = page.locator('[data-igr-slot="quiz"]');
    await expect(slot).toHaveAttribute('style', /min-height:360px/);
    await expect(slot.getByRole('heading', { name: '¿Dónde vas a pintar?' })).toBeVisible();
    await slot.getByRole('button', { name: 'Exterior' }).click();
    await expect(slot.getByRole('heading', { name: '¿Cómo es el clima?' })).toBeVisible();
    await slot.getByRole('button', { name: 'Lluvioso' }).click();
    await expect(slot.getByRole('status')).toContainText('Esto es lo que te recomendamos');
    await expect(slot.getByRole('link', { name: product.name })).toHaveAttribute('href', product.url);
    const sub = await waitHub((s) => s.submits.find((x) => x.widget_id === quiz.id));
    expect(sub.answers).toEqual({ q1: 'ext', q3: 'lluvia' });
});

test('slots en la tienda: recomendaciones, reseñas, puntos y «avísame cuando vuelva»', async ({ page }) => {
    await widgets([]);
    const id = Number(
        php(`echo wp_insert_post(array('post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Ofertas', 'post_content' => '[igr_reco type="trending" limit="4" title="Te puede gustar"] [igr_reviews layout="list" product_id="${product.id}"]'));`),
    );
    pages.push(id);
    await page.goto('/?page_id=' + id);
    await ready(page);
    const reco = page.locator('[data-igr-slot="reco"]');
    await expect(reco.getByRole('heading', { name: 'Te puede gustar' })).toBeVisible();
    await expect(reco.getByRole('link', { name: product.name })).toBeVisible();
    const reviews = page.locator('[data-igr-slot="reviews"]');
    await expect(reviews.getByText('Excelente cubrimiento')).toBeVisible();
    await expect(reviews.getByText('✓ Compra verificada')).toBeVisible();
    await expect(reviews.getByRole('img', { name: /4,8 de 5 estrellas, 23 reseñas/ })).toBeVisible();
    await expect(reviews.locator('img[loading="lazy"]').first()).toBeAttached();
    const s = await hub();
    expect(s.public_requests.find((r) => r.path === 'reco')?.query).toMatchObject({ kind: 'trending', limit: '4' });
    expect(s.public_requests.find((r) => r.path === 'reviews')?.query).toMatchObject({ product_id: product.id, page: '1' });

    // Ficha con stock: «Gana N puntos» (la fidelización está activa en el hub).
    await page.goto(path(product.url));
    await ready(page);
    await expect(page.locator('[data-igr-slot="points"]').getByText(/^Gana \d+ Puntos Prueba$/)).toBeVisible();

    // Agotado: «Avísame cuando vuelva» con la casilla de marketing sin marcar.
    const before = php(`$p = wc_get_product(${product.id}); echo wp_json_encode(array($p->get_manage_stock(), $p->get_stock_quantity(), $p->get_stock_status()));`);
    php(`$p = wc_get_product(${product.id}); $p->set_manage_stock(false); $p->set_stock_status('outofstock'); $p->save();`);
    try {
        await page.goto(path(product.url));
        await ready(page);
        const bis = page.locator('[data-igr-slot="bis"]');
        await bis.getByRole('button', { name: 'Avísame cuando vuelva' }).click();
        await bis.getByLabel('Tu correo o WhatsApp').fill('e2e-avisame@example.com');
        const box = bis.getByRole('checkbox', { name: /ofertas por correo/ });
        await expect(box).not.toBeChecked();
        await bis.getByRole('button', { name: 'Avisarme' }).click();
        await expect(bis.getByRole('status')).toHaveText('Te avisaremos cuando vuelva.');
        const w = await waitHub((st) => st.watches.find((x) => x.product_id === product.id));
        expect(w).toMatchObject({ kind: 'back_in_stock', channel: 'email', consents: { email: false } });
    } finally {
        php(`$b = json_decode('${before}', true); $p = wc_get_product(${product.id}); $p->set_manage_stock($b[0]); if ($b[0]) { $p->set_stock_quantity($b[1]); } $p->set_stock_status($b[2]); $p->save();`);
    }
});

test('Mis recompensas con el token: puntos, nivel, canje y enlace para invitar', async ({ page }) => {
    const user = login('e2e-recompensas');
    try {
        await signIn(page, 'e2e-recompensas');
        await page.goto(path(php(`echo wc_get_account_endpoint_url('recompensas');`)));
        await ready(page);
        await expect(page.getByRole('link', { name: 'Mis recompensas' }).first()).toBeVisible();
        const slot = page.locator('[data-igr-slot="loyalty"]');
        await expect(slot.getByRole('heading', { name: 'Mis Puntos Prueba' })).toBeVisible();
        await expect(slot.getByText('1.532', { exact: true })).toBeVisible();
        await expect(slot.getByRole('progressbar', { name: 'Avance hacia Oro' })).toHaveAttribute('aria-valuenow', '64');
        await expect(page.locator('[data-igr-slot="banner"]').getByText('Eres cliente Plata: envío gratis este mes')).toBeVisible();
        const kit = slot.getByRole('listitem').filter({ hasText: 'Kit de brochas' });
        await expect(kit.getByRole('button', { name: 'Canjear' })).toBeDisabled();
        await slot.getByRole('listitem').filter({ hasText: '$20.000 de descuento' }).getByRole('button', { name: 'Canjear' }).click();
        await expect(slot.getByRole('status')).toContainText(/PUNTOS-[A-F0-9]{6}/);
        await expect(slot.getByText('1.032', { exact: true })).toBeVisible();
        const red = await waitHub((s) => s.redeems.find((r) => r.ref === 'wc:' + user));
        expect(red.reward_id).toBe('rw_01JF0REWARD000000000001');

        await page.context().route('https://wa.me/**', (route) => route.fulfill({ status: 200, contentType: 'text/html', body: '<p>WhatsApp</p>' }));
        const share = slot.getByRole('link', { name: 'Compartir por WhatsApp' });
        expect(decodeURIComponent((await share.getAttribute('href')) ?? '')).toContain('http://127.0.0.1:8080/?ref=REF');
        const [popupPage] = await Promise.all([page.waitForEvent('popup'), share.click()]);
        await popupPage.close();
        await waitHub((s) => s.shares.find((x) => x.ref === 'wc:' + user && x.channel === 'whatsapp'));
    } finally {
        php(`wp_delete_user(${user});`);
    }
});

test('saldo a favor en el checkout de bloques: el plugin llama al hub (firmado) y aplica el cupón', async ({ page }) => {
    const user = login('e2e-saldo');
    try {
        await signIn(page, 'e2e-saldo');
        await page.goto('/?add-to-cart=' + product.id);
        await page.goto(path(php(`echo wc_get_checkout_url();`)));
        await ready(page);
        const box = page.locator('[data-igr-slot="wallet"]');
        await expect(box.getByText(/Tienes \$\s?35\.000 de saldo a favor\./)).toBeVisible();
        await box.getByRole('button', { name: 'Usar saldo' }).click();
        await expect(box.getByRole('status')).toContainText('Aplicamos');
        const red = await waitHub((s) => s.wallet_redeems.find((r) => r.request.customer_ref === 'wc:' + user));
        expect(red.coupon_status).toBe(200);
        expect(red.request.currency).toBe('COP');
        const cart = await page.evaluate(async () => (await (await fetch('/wp-json/wc/store/v1/cart')).json()) as { coupons: { code: string }[] });
        expect(cart.coupons.map((c) => c.code)).toContain(red.code.toLowerCase());
        // Ningún widget ni widgets.json en el checkout.
        expect((await hub()).public_requests.filter((r) => r.path === 'visitor.json')).toEqual([]);
        await expect(page.locator('igr-w')).toHaveCount(0);
    } finally {
        php(`global $wpdb; $wpdb->delete($wpdb->prefix . 'woocommerce_sessions', array('session_key' => '${user}')); wp_delete_user(${user});`);
    }
});

test('aterrizaje con ?ref=: código en _igr_src y en el evento, y bienvenida de referido', async ({ page, context }) => {
    await widgets([
        {
            id: 'wg_01JF0REFWELCOME0000000001',
            kind: 'referral_welcome',
            slot: null,
            targeting: { trigger: { type: 'immediate' } },
            content: { title: 'Tu amigo te regaló 10%', fields: [{ key: 'email', type: 'email', required: true, label: 'Tu correo' }], consents: [], cta: 'Quiero mi regalo' },
        },
    ]);
    await page.goto('/?ref=ANA7K2&utm_source=whatsapp');
    await ready(page);
    await flush(page);
    const pv = await waitHub((s) => s.browser.find((e) => e.event === 'page.viewed' && e.context['referral_code'] === 'ANA7K2'));
    expect((pv.context['last_touch'] as Record<string, unknown>)['referral_code']).toBe('ANA7K2');
    expect(JSON.parse(decodeURIComponent((await cookie(context, '_igr_src')) ?? '{}')).l.referral_code).toBe('ANA7K2');
    expect(page.url()).toContain('ref=ANA7K2');
    const dialog = page.locator('[data-igr-w="referral_welcome"]').getByRole('dialog', { name: 'Tu amigo te regaló 10%' });
    await expect(dialog).toBeVisible();
    await dialog.getByLabel('Tu correo').fill('e2e-amigo@example.com');
    await dialog.getByRole('button', { name: 'Quiero mi regalo' }).click();
    await expect(dialog.getByRole('status')).toBeVisible();
    const sub = await waitHub((s) => s.submits.find((x) => x.kind === 'referral_welcome'));
    expect(sub.referral_code).toBe('ANA7K2');
});

test('botón de WhatsApp: referencia corta en el mensaje y cta.clicked antes de abrir', async ({ page, context }) => {
    await widgets([{ id: 'wg_01JF0WHATSAPP00000000001', kind: 'wa_widget', slot: null, wa: { number: '573001234567', text: 'Hola, quiero información', position: 'right' }, content: { cta: 'Escríbenos' } }]);
    let opened = '';
    await context.route('https://wa.me/**', (route) => {
        opened = route.request().url();
        return route.fulfill({ status: 200, contentType: 'text/html', body: '<p>WhatsApp</p>' });
    });
    await page.goto('/');
    await ready(page);
    const btn = page.locator('[data-igr-w="wa"]').getByRole('link', { name: 'Escríbenos' });
    await expect(btn).toBeVisible();
    const [wa] = await Promise.all([page.waitForEvent('popup'), btn.click()]);
    await wa.waitForLoadState();
    const text = new URL(opened || wa.url()).searchParams.get('text') ?? '';
    const m = text.match(/^Hola, quiero información \(ref: ([A-Z2-9]{5})\)$/);
    expect(m, text).not.toBeNull();
    const cta = await waitHub((s) => s.browser.find((e) => e.event === 'cta.clicked' && e.properties['wa_ref'] === m?.[1]));
    expect(cta.properties['kind']).toBe('whatsapp');
    await wa.close();
});

test('nunca hay widgets en el checkout', async ({ page }) => {
    await widgets([{ ...popup, id: 'wg_01JF0POPUPCHECKOUT00000001', targeting: { trigger: { type: 'immediate' } } }]);
    await page.goto('/?add-to-cart=' + product.id);
    const seen: string[] = [];
    page.on('request', (r) => {
        if (r.url().includes('/widgets.json') || r.url().includes('igr-widgets.js')) seen.push(r.url());
    });
    await page.goto(path(php(`echo wc_get_checkout_url();`)));
    await ready(page);
    await page.waitForTimeout(1000);
    expect(seen).toEqual([]);
    await expect(page.locator('igr-w')).toHaveCount(0);
});
