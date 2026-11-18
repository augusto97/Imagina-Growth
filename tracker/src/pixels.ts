/*
 * Píxeles del modo «completo» (docs/api-connector.md §5.1). Lo carga el
 * tracker solo con consentimiento de marketing. Cada evento estándar usa como
 * eventID el event_id de Imagina Growth: la conversión desde el servidor usa el
 * mismo y la plataforma deduplica. La compra usa igrOrder.eventId.
 */
import type { Config, Envelope, OrderData } from './types';

type Fn = ((...a: unknown[]) => void) & Record<string, unknown>;
interface PxWindow {
    igrConfig?: Config;
    igrOrder?: OrderData;
    igr?: { on?: (fn: (e: Envelope) => void) => void; consent?: () => { marketing: boolean } };
    fbq?: Fn;
    _fbq?: Fn;
    ttq?: Fn & unknown[];
    dataLayer?: unknown[];
    gtag?: (...a: unknown[]) => void;
    TiktokAnalyticsObject?: string;
}

const W = window as unknown as PxWindow;
const px = W.igrConfig?.pixels ?? {};

function load(src: string): void {
    const s = document.createElement('script');
    s.async = true;
    s.src = src;
    document.head.appendChild(s);
}

if (px.meta) {
    if (!W.fbq) {
        const f = function (...a: unknown[]) {
            const cm = f['callMethod'] as ((...x: unknown[]) => void) | undefined;
            if (cm) cm.apply(f, a);
            else (f['queue'] as unknown[]).push(a);
        } as Fn;
        f['push'] = f;
        f['loaded'] = true;
        f['version'] = '2.0';
        f['queue'] = [];
        W.fbq = W._fbq = f;
        load('https://connect.facebook.net/en_US/fbevents.js');
    }
    W.fbq('init', px.meta);
}

if (px.tiktok) {
    // Cargador oficial de TikTok, reducido: deja los métodos en cola hasta que llega la librería.
    W.TiktokAnalyticsObject = 'ttq';
    const t = (W.ttq = W.ttq ?? ([] as unknown as Fn & unknown[]));
    const methods = ['page', 'track', 'identify', 'instances', 'debug', 'on', 'off', 'once', 'ready', 'alias', 'group', 'enableCookie', 'disableCookie', 'holdConsent', 'revokeConsent', 'grantConsent'];
    for (const m of methods) t[m] = (...a: unknown[]) => t.push([m, ...a]);
    const id = px.tiktok;
    const lib = 'https://analytics.tiktok.com/i18n/pixel/events.js';
    t['_i'] = { [id]: Object.assign([], { _u: lib }) };
    t['_t'] = { [id]: Date.now() };
    t['_o'] = { [id]: {} };
    load(lib + '?sdkid=' + encodeURIComponent(id) + '&lib=ttq');
}

if (px.google) {
    W.dataLayer = W.dataLayer ?? [];
    W.gtag = function (...a: unknown[]) {
        // gtag.js espera el objeto arguments, no un arreglo.
        // eslint-disable-next-line prefer-rest-params
        (W.dataLayer as unknown[]).push(arguments);
        void a;
    };
    W.gtag('js', new Date());
    W.gtag('config', px.google, { send_page_view: false });
    load('https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(px.google));
}

const allowed = (): boolean => !W.igr?.consent || W.igr.consent().marketing;
const call = (fn: string, ...a: unknown[]): void => {
    const t = W.ttq as unknown as Record<string, (...x: unknown[]) => void> | undefined;
    if (px.tiktok && t && t[fn]) t[fn](...a);
};

/** Evento de Imagina Growth → evento estándar de cada plataforma, con el mismo ID. */
export function fire(e: Envelope): void {
    if (!allowed()) return;
    const p = e.properties as Record<string, unknown>;
    const id = e.event_id;
    if (e.event === 'page.viewed') {
        if (px.meta) W.fbq?.('track', 'PageView', {}, { eventID: id });
        call('page');
        if (px.google) W.gtag?.('event', 'page_view');
    } else if (e.event === 'product.viewed') {
        const value = Number(p['price']);
        const currency = p['currency'];
        if (px.meta) W.fbq?.('track', 'ViewContent', { content_ids: [p['product_id']], content_type: 'product', value, currency }, { eventID: id });
        call('track', 'ViewContent', { contents: [{ content_id: p['product_id'], content_type: 'product', price: value }], value, currency }, { event_id: id });
        if (px.google) W.gtag?.('event', 'view_item', { currency, value, items: [{ item_id: p['product_id'], item_name: p['name'], price: value }] });
    } else if (e.event === 'search.performed') {
        if (px.meta) W.fbq?.('track', 'Search', { search_string: p['query'] }, { eventID: id });
        call('track', 'Search', { query: p['query'] }, { event_id: id });
        if (px.google) W.gtag?.('event', 'search', { search_term: p['query'] });
    } else if (e.event === 'cta.clicked' && p['kind'] === 'whatsapp') {
        if (px.meta) W.fbq?.('track', 'Contact', {}, { eventID: id });
        call('track', 'Contact', {}, { event_id: id });
    }
}

/**
 * Compra en la página de gracias con igrOrder.eventId (eventos.md §7). Solo si
 * el pedido ya está pagado: una compra PSE o contraentrega que se confirma
 * después la envía el servidor, y así no se cuenta dos veces.
 */
export function purchase(o: OrderData | undefined): void {
    if (!o || !o.eventId || o.paid === false || !allowed()) return;
    try {
        if (sessionStorage.getItem('_igr_px_' + o.eventId)) return;
        sessionStorage.setItem('_igr_px_' + o.eventId, '1');
    } catch {
        /* sin almacenamiento: la plataforma deduplica por eventID */
    }
    const value = Number(o.value);
    const currency = o.currency;
    if (px.meta) W.fbq?.('track', 'Purchase', { value, currency }, { eventID: o.eventId });
    call('track', 'CompletePayment', { value, currency }, { event_id: o.eventId });
    if (px.google) W.gtag?.('event', 'purchase', { transaction_id: o.orderId, value, currency });
}

try {
    W.igr?.on?.(fire);
    purchase(W.igrOrder);
} catch {
    /* nunca hacia la página */
}
