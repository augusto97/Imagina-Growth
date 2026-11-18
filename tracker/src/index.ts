/*
 * Imagina Growth: script de seguimiento del navegador (tracker/CLAUDE.md).
 * Sin dependencias, ≤ 6 KB gzip, nunca lanza errores hacia la página.
 */
import { CONSENT_EVENTS, detectConsent } from './consent';
import { adCookies, anonymousId, currentTouch, readSources, refCode, session, touchSession, updateSources } from './ids';
import { enqueue, flush, initTransport, retryPending } from './transport';
import type { Config, Consent, Envelope, OrderData, PageData, Props, Touch } from './types';
import { cut, idle, shortToken, store, uuid7 } from './util';

declare const __VERSION__: string;

type Cmd = [string, ...unknown[]];
type Listener = (e: Envelope) => void;

interface IgrWindow {
    igrConfig?: Config;
    igrPage?: PageData;
    igrUser?: { ref?: string; token?: string };
    igrOrder?: OrderData;
    igr?: Cmd[] & { __igr?: boolean; on?: (fn: Listener) => void; consent?: () => Consent; flush?: () => void };
    __igrWidgets?: unknown;
}

const W = window as unknown as IgrWindow;
const PAGE_TYPES = ['home', 'product', 'category', 'cart', 'checkout', 'thankyou', 'search', 'account', 'blog', 'landing', 'other'];

let cfg: Config;
let consent: Consent;
let aid = '';
let sid = '';
let link = '';
let entry: Touch | null = null;
let refc: string | null = null;
const subs: Listener[] = [];
const recent: Envelope[] = [];
let pixelsLoaded = false;
let self: unknown;

function page(): PageData {
    return W.igrPage ?? {};
}

function pageType(): string {
    const t = page().type ?? 'other';
    return PAGE_TYPES.indexOf(t) >= 0 ? t : 'other';
}

function touchCtx(t: Touch | undefined): Record<string, unknown> | undefined {
    if (!t) return undefined;
    const o: Record<string, unknown> = {};
    if (t.utm) o['utm'] = t.utm;
    if (t.click_ids) o['click_ids'] = t.click_ids;
    if (t.referrer) o['referrer'] = t.referrer;
    if (t.landing) o['landing'] = t.landing;
    if (t.referral_code) o['referral_code'] = t.referral_code;
    if (t.at) o['at'] = new Date(t.at).toISOString();
    return o;
}

function context(): Record<string, unknown> {
    const url = new URL(location.href);
    url.searchParams.delete('_igr');
    const ctx: Record<string, unknown> = {
        page: { url: cut(consent.analytics ? url.href : url.origin + url.pathname, 2048), path: cut(url.pathname, 2048), title: cut(document.title, 500) },
        page_type: pageType(),
        consent,
    };
    const lang = (document.documentElement.lang || '').replace('_', '-');
    if (/^[a-z]{2}([-_][A-Za-z]{2})?$/.test(lang)) ctx['locale'] = lang;
    const cur = cfg.currency;
    if (cur && /^[A-Z]{3}$/.test(cur)) ctx['currency'] = cur;
    // El código del referidor no identifica al visitante: viaja en los eventos del aterrizaje aun sin consentimiento.
    if (refc) ctx['referral_code'] = refc;
    if (!consent.analytics) return ctx;
    const ref = document.referrer;
    if (ref) (ctx['page'] as Record<string, string>)['referrer'] = cut(ref, 2048);
    if (entry?.utm) ctx['utm'] = entry.utm;
    if (entry?.click_ids) ctx['click_ids'] = entry.click_ids;
    const src = readSources();
    const f = touchCtx(src.f);
    const l = touchCtx(src.l);
    if (f) ctx['first_touch'] = f;
    if (l) ctx['last_touch'] = l;
    if (consent.marketing) {
        const ads = adCookies(cfg.cookieDomain);
        if (Object.keys(ads).length) ctx['ad_cookies'] = ads;
    }
    return ctx;
}

/** Construye el sobre (eventos.md §2, schema_version 4) y lo encola. */
function track(name: string, props: Props = {}): Envelope | undefined {
    // Una instancia reemplazada (script cargado dos veces) no envía nada.
    // Modo estricto sin consentimiento de analítica: solo page.viewed agregado, sin identificadores.
    if (W.igr !== self || (!consent.analytics && name !== 'page.viewed')) return undefined;
    const identity: Record<string, string> = {};
    if (consent.analytics) {
        identity['anonymous_id'] = aid;
        identity['session_id'] = sid;
        if (link) identity['link_token'] = link;
        touchSession(sid, cfg.cookieDomain);
    }
    const e: Envelope = {
        schema_version: 4,
        event_id: uuid7(),
        event: name,
        occurred_at: new Date().toISOString(),
        source: { type: 'browser', platform: cfg.platform ?? 'woocommerce', site_id: cfg.siteId, sdk: 'tracker/' + __VERSION__ },
        identity,
        context: context(),
        properties: props,
    };
    recent.push(e);
    if (recent.length > 20) recent.shift();
    for (const fn of subs) safe(() => fn(e))();
    enqueue(e);
    return e;
}

function safe<A extends unknown[]>(fn: (...a: A) => void): (...a: A) => void {
    return (...a: A) => {
        try {
            fn(...a);
        } catch (err) {
            // 1% de los errores se reportan para depurar sin inundar el hub.
            if (Math.random() < 0.01) {
                try {
                    track('custom.tracker_error', { message: cut(err instanceof Error ? err.message : err, 200) });
                } catch {
                    /* nunca hacia la página */
                }
            }
        }
    };
}

function pageView(): void {
    const p = page();
    const t = pageType();
    const props: Props = { page_type: t };
    if (p.objectId != null) props['object_id'] = String(p.objectId);
    if (p.objectType) props['object_type'] = p.objectType;
    track('page.viewed', props);
    const pr = p.product;
    if (t === 'product' && pr && pr.price) {
        const prop: Props = { product_id: String(pr.id), name: cut(pr.name, 300), price: pr.price, currency: pr.currency ?? cfg.currency, in_stock: pr.inStock !== false };
        if (pr.variationId) prop['variation_id'] = String(pr.variationId);
        if (pr.sku) prop['sku'] = cut(pr.sku, 100);
        if (pr.categories) prop['category_ids'] = pr.categories.map(String);
        track('product.viewed', prop);
    }
    if (t === 'category' && p.objectId != null) track('category.viewed', { category_id: String(p.objectId), name: cut(p.name, 200) });
    const q = p.search?.query ?? new URL(location.href).searchParams.get('s');
    if (q) {
        const sp: Props = { query: cut(q, 300), engine: p.search?.engine ?? (t === 'search' ? 'wp' : 'wc') };
        if (p.search?.results != null) sp['results_count'] = p.search.results;
        track('search.performed', sp);
    }
}

/** ≥ 30 s visible o ≥ 50% de scroll, una vez por página. */
function engagement(): void {
    let acc = 0;
    let since = document.visibilityState === 'visible' ? Date.now() : 0;
    let maxScroll = 0;
    let done = false;
    let tid: ReturnType<typeof setTimeout> | undefined;
    const secs = (): number => Math.floor((acc + (since ? Date.now() - since : 0)) / 1000);
    const check = safe(() => {
        if (done || (secs() < 30 && maxScroll < 50)) return;
        done = true;
        track('page.engaged', { seconds_visible: secs(), max_scroll_pct: maxScroll });
    });
    const arm = (): void => {
        if (tid) clearTimeout(tid);
        tid = setTimeout(check, Math.max(0, 30000 - acc));
    };
    if (since) arm();
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            since = Date.now();
            arm();
        } else if (since) {
            acc += Date.now() - since;
            since = 0;
        }
    });
    let ticking = false;
    window.addEventListener(
        'scroll',
        () => {
            if (ticking) return;
            ticking = true;
            setTimeout(
                safe(() => {
                    ticking = false;
                    const max = document.documentElement.scrollHeight - innerHeight;
                    const pct = max > 0 ? Math.min(100, Math.round((scrollY / max) * 100)) : 0;
                    if (pct > maxScroll) maxScroll = pct;
                    check();
                }),
                200,
            );
        },
        { passive: true },
    );
}

/** Teléfono de un enlace de WhatsApp, o null si no lo es. */
export function waNumber(href: string): string | null {
    const m = href.match(/^(?:https?:\/\/(?:wa\.me|(?:api|web)\.whatsapp\.com)|whatsapp:)\/?\/?(?:send\/?)?(\d*)/i);
    if (!m) return null;
    if (m[1]) return m[1];
    try {
        return (new URL(href).searchParams.get('phone') ?? '').replace(/\D/g, '');
    } catch {
        return '';
    }
}

/**
 * Agrega el token de atribución al texto prellenado (eventos.md §6.3) si el
 * número está entre los conectados. Devuelve el token o null.
 */
export function addWaRef(a: HTMLAnchorElement | { href: string }, conf: Config['waRef']): string | null {
    const num = waNumber(a.href);
    if (!conf?.enabled || !num || !(conf.numbers ?? []).some((n) => n.replace(/\D/g, '') === num)) return null;
    const url = new URL(a.href);
    const token = shortToken(5);
    const text = url.searchParams.get('text') ?? '';
    url.searchParams.set('text', (conf.format ?? '{text} (ref: {token})').replace('{text}', text).replace('{token}', token).trim());
    a.href = url.href;
    return token;
}

/** Clasifica un clic: WhatsApp, teléfono, correo, descarga o `data-igr-cta`. */
export function classify(el: Element): { kind: string; target: string; label: string; anchor?: HTMLAnchorElement } | null {
    const custom = el.closest('[data-igr-cta]');
    const a = el.closest('a[href]') as HTMLAnchorElement | null;
    const label = cut(((custom ?? a)?.textContent ?? '').replace(/\s+/g, ' ').trim() || (custom ?? a)?.getAttribute('aria-label'), 300);
    if (a) {
        const href = a.getAttribute('href') ?? '';
        if (waNumber(a.href) !== null) return { kind: 'whatsapp', target: cut(a.href, 2048), label, anchor: a };
        if (/^tel:/i.test(href)) return { kind: 'phone', target: cut(href, 2048), label };
        if (/^mailto:/i.test(href)) return { kind: 'email', target: cut(href.split('?')[0], 2048), label };
        if (/\.(pdf|docx?|xlsx?|pptx?|csv|zip|rar)([?#]|$)/i.test(href)) return { kind: 'download', target: cut(a.href, 2048), label };
    }
    if (custom) return { kind: 'custom', target: cut(custom.getAttribute('data-igr-cta') || (a ? a.href : location.pathname), 2048), label };
    return null;
}

function clicks(): void {
    document.addEventListener(
        'click',
        safe((ev: Event) => {
            const t = ev.target;
            if (!(t instanceof Element)) return;
            const c = classify(t);
            if (!c) return;
            const props: Props = { kind: c.kind, target: c.target, label: c.label };
            if (c.anchor && c.kind === 'whatsapp') {
                const ref = addWaRef(c.anchor, cfg.waRef);
                if (ref) props['wa_ref'] = ref;
            }
            track('cta.clicked', props);
            flush(); // antes de navegar
        }),
        true,
    );
}

/** Renueva `_igr_aid` desde el servidor como mucho cada 6 días (Safari ITP). */
function ping(force: boolean): void {
    if (!cfg.pingUrl) return;
    const last = Number(store('l', '_igr_pt') ?? 0);
    if (!force && Date.now() - last < 6 * 86400000) return;
    store('l', '_igr_pt', String(Date.now()));
    fetch(cfg.pingUrl, { credentials: 'same-origin', cache: 'no-store' }).catch(() => store('l', '_igr_pt', null));
}

function loadScript(src: string): void {
    const s = document.createElement('script');
    s.async = true;
    s.src = src;
    document.head.appendChild(s);
}

/**
 * Widgets (api-connector.md §8.3): `widgets.json` (caché de CDN, igual para todos) en
 * toda página que no sea el checkout; el paquete de widgets se carga solo si trae
 * alguno o si la página tiene un slot.
 */
function widgets(): void {
    const t = pageType();
    if (!cfg.widgetsUrl || !cfg.widgetsJs || t === 'checkout') return;
    fetch(cfg.widgetsUrl + '?page_type=' + t + '&path=' + encodeURIComponent(location.pathname), { credentials: 'omit' })
        .then((r) => (r.ok ? r.json() : null))
        .catch(() => null)
        .then((data: { widgets?: unknown[] } | null) => {
            if ((data && data.widgets && data.widgets.length) || document.querySelector('[data-igr-slot]')) {
                W.__igrWidgets = data || { widgets: [] };
                loadScript(cfg.widgetsJs as string);
            }
        });
}

/** Modo «completo» (api-connector.md §5.1): píxeles solo con consentimiento de marketing. */
function pixels(): void {
    const px = cfg.pixels;
    if (pixelsLoaded || !consent.marketing || !cfg.pixelsJs || !px || !(px.meta || px.tiktok || px.google)) return;
    pixelsLoaded = true;
    loadScript(cfg.pixelsJs);
}

/** Una vez por sesión del navegador y por cliente (cada página firma un token nuevo para el mismo `ref`). */
function identify(token: unknown, key?: string): void {
    if (!consent.analytics || typeof token !== 'string' || !/^[A-Za-z0-9_-]+\.[0-9a-f]{64}$/.test(token)) return;
    const k = key || token;
    if (store('s', '_igr_idf') === k) return;
    store('s', '_igr_idf', k);
    track('identify', { method: 'login', token });
}

function startIds(): void {
    const t = currentTouch();
    updateSources(t, cfg.cookieDomain);
    const a = anonymousId(cfg.cookieDomain);
    aid = a.id;
    const s = session(t, cfg.cookieDomain);
    sid = s.id;
    entry = s.entry;
    ping(a.created);
}

function applyConsent(next: Consent): void {
    const had = consent.analytics;
    consent = next;
    if (consent.analytics && !had) {
        startIds();
        retryPending();
    }
    pixels();
}

function command(c: unknown): void {
    if (!Array.isArray(c) || typeof c[0] !== 'string') return;
    const [name, a, b] = c as Cmd;
    if (name === 'track' && typeof a === 'string') track(a, (b as Props) ?? {});
    else if (name === 'page') pageView();
    else if (name === 'identify') identify((a as { token?: unknown } | undefined)?.token);
    else if (name === 'consent' && a && typeof a === 'object') {
        const v = a as Partial<Consent>;
        applyConsent({ analytics: !!v.analytics, marketing: !!v.marketing, source: cut(v.source ?? 'api', 40) });
    } else if (name === 'flush') flush();
}

function main(): void {
    const c = W.igrConfig;
    if (!c || !c.siteKey || !c.siteId || !c.endpoint) return;
    const q = (W.igr = W.igr ?? ([] as unknown as NonNullable<IgrWindow['igr']>));
    if (q.__igr) return;
    q.__igr = true;
    self = q;
    cfg = c;
    consent = detectConsent(c.consent ?? { analytics: c.consentMode !== 'strict', marketing: false, source: 'none' });
    initTransport(c.endpoint, c.siteKey, () => consent.analytics);

    refc = refCode();
    // `_igr` (enlace de un mensaje): vincula la visita y se quita de la URL para que nadie lo comparta.
    const url = new URL(location.href);
    const lt = url.searchParams.get('_igr');
    if (lt !== null) {
        if (/^[\w.-]{4,128}$/.test(lt)) link = lt;
        url.searchParams.delete('_igr');
        try {
            history.replaceState(history.state, '', url.href);
        } catch {
            /* sin History API */
        }
    }

    if (consent.analytics) {
        startIds();
        retryPending();
    }
    const pre = q.splice(0, q.length);
    for (const cmd of pre) if (Array.isArray(cmd) && cmd[0] === 'consent') safe(command)(cmd);
    pageView();
    if (link) track('identify', { method: 'link_token' }); // el enlace viaja en identity.link_token
    identify(W.igrUser?.token, W.igrUser?.ref);
    for (const cmd of pre) if (!Array.isArray(cmd) || cmd[0] !== 'consent') safe(command)(cmd);
    q.push = (...cmds: Cmd[]): number => {
        for (const cmd of cmds) safe(command)(cmd);
        return 0;
    };
    q.on = (fn: Listener): void => {
        subs.push(fn);
        for (const e of recent) safe(() => fn(e))();
    };
    q.consent = (): Consent => consent;
    q.flush = flush;

    const refresh = safe(() => applyConsent(detectConsent(consent)));
    for (const ev of CONSENT_EVENTS) (ev.indexOf('Cookiebot') === 0 ? window : document).addEventListener(ev, refresh);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') flush();
    });
    addEventListener('pagehide', flush);

    idle(
        safe(() => {
            clicks();
            engagement();
            widgets();
            pixels();
        }),
    );
}

safe(main)();
