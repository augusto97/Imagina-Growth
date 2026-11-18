/* Ayudas de la prueba de punta a punta: WP-CLI de la tienda y estado del hub simulado. */
import { execFileSync } from 'node:child_process';

export const HUB = 'http://127.0.0.1:8090';

const cli = (process.env['IGR_E2E_WP_CLI'] ?? 'wp').split(' ').filter(Boolean);

/** Corre WP-CLI contra la tienda (`IGR_HUB_URL` apunta al hub simulado). */
export function wp(...args: string[]): string {
    const [bin, ...pre] = cli;
    return execFileSync(bin as string, [...pre, `--path=${process.env['IGR_E2E_WP_DIR']}`, ...args], {
        encoding: 'utf8',
        env: { ...process.env, IGR_HUB_URL: HUB },
        stdio: ['ignore', 'pipe', 'pipe'],
    }).trim();
}

/** PHP dentro de WordPress. */
export function php(code: string): string {
    return wp('eval', code);
}

export interface BrowserEvent {
    event_id: string;
    event: string;
    identity: Record<string, string>;
    context: Record<string, unknown> & { page?: { url?: string; path?: string }; consent?: { analytics: boolean; marketing: boolean; source: string } };
    properties: Record<string, unknown>;
    source: { type: string };
}

export interface HubState {
    browser: BrowserEvent[];
    schema_errors: unknown[];
    identified: { anonymous_id: string | null; ref: string | null }[];
    bad_tokens: number;
    survey_answers: { survey_id: string; order_id: string; answer: string; anonymous_id: string | null }[];
    events: Record<string, number>;
    submits: { widget_id: string; kind: string; variant: string | null; step: number | null; anonymous_id: string | null; contact: string; fields: string[]; consents: Record<string, boolean>; answers: Record<string, string> | null; referral_code: string | null }[];
    watches: { product_id: string; kind: string; channel: string; consents: Record<string, boolean>; anonymous_id: string | null }[];
    redeems: { ref: string; reward_id: string; points: number }[];
    shares: { ref: string; channel: string }[];
    wallet_redeems: { request: { customer_ref: string; cart_total: string; currency: string; cart_token?: string }; coupon_status: number; code: string }[];
    public_requests: { path: string; query: Record<string, string> }[];
}

export async function hub(): Promise<HubState> {
    const r = await fetch(HUB + '/_state');
    return (await r.json()) as HubState;
}

export async function hubConfig(overrides: Record<string, unknown>): Promise<void> {
    await fetch(HUB + '/_config', { method: 'POST', body: JSON.stringify(overrides) });
}

/** Espera hasta que `pick` devuelva algo con el estado del hub. */
export async function waitHub<T>(pick: (s: HubState) => T | undefined | null | false, timeout = 15_000): Promise<T> {
    const end = Date.now() + timeout;
    for (;;) {
        const v = pick(await hub());
        if (v) return v;
        if (Date.now() > end) throw new Error('El hub simulado no recibió lo esperado a tiempo.');
        await new Promise((r) => setTimeout(r, 250));
    }
}

/** Ajustes de la pestaña «Datos» (Settings::save). */
export function settings(changes: Record<string, unknown>): void {
    php(`ImaginaGrowth\\Connector\\Settings::save(json_decode('${JSON.stringify(changes)}', true));`);
}

/** Pedido nuevo de invitado (pagado o no) y su URL de «gracias». */
export function newOrder(gateway = 'wompi', status = 'processing'): { id: string; url: string } {
    const out = php(
        `$p = wc_get_products(array('limit' => 1, 'status' => 'publish', 'type' => 'simple', 'stock_status' => 'instock'))[0];` +
            `$o = wc_create_order(); $o->add_product($p, 1); $o->set_billing_first_name('Prueba'); $o->set_billing_email('e2e-' . wp_generate_password(6, false) . '@example.com');` +
            `$o->set_billing_country('CO'); $o->set_payment_method('${gateway}'); $o->set_created_via('checkout'); $o->calculate_totals(false); $o->set_status('${status}'); $o->save();` +
            `echo $o->get_id() . ' ' . $o->get_checkout_order_received_url();`,
    );
    const [id, url] = out.split(' ');
    return { id: id as string, url: (url as string).replace(/^https?:\/\/[^/]+/, '') };
}
