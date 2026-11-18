/*
 * Comercio en el sitio (igr-w-commerce.js, M16 y M13): recomendaciones, «avísame»
 * (vuelve a tener stock o baja de precio), «Gana N puntos» y el banner por segmento.
 * Cada slot ya trae su espacio reservado; si no hay nada que mostrar, se recoge.
 */
import { CARDS_CSS, collapse, h, overlayHost, productCard, register, safeUrl, shadow, uid } from './lib/dom';
import type { ConsentText, Ctx, Product } from './lib/types';

const CSS =
    CARDS_CSS +
    '.bs{display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end}.bs .f{flex:1 1 220px}.l{display:block;font-weight:600;font-size:14px;margin-bottom:4px}' +
    '.ck{display:flex;gap:8px;align-items:flex-start;font-size:13px;margin:8px 0 0}.ck input{margin-top:3px}.ok{color:#047857;font-weight:600}' +
    '.pts{margin:6px 0;color:#92400e;font-weight:600}.pts:before{content:"★ ";color:#b45309}' +
    '.bn{display:flex;flex-wrap:wrap;gap:8px 16px;align-items:center;justify-content:center;padding:12px 16px;border-radius:10px;background:var(--a);color:#fff}.bn p{margin:0}' +
    '.bn a{color:inherit;font-weight:700}.bn.vip{background:#111827}.bn.warning{background:#b45309}.bn.info{background:#1e40af}.bn.success{background:#047857}' +
    '.top{position:fixed;top:0;left:0;right:0;z-index:2147482999;border-radius:0;padding-right:52px}.x{position:absolute;right:8px;top:50%;transform:translateY(-50%);background:transparent;border:0;color:inherit;font-size:22px;padding:4px 10px}';

const TITLES: Record<string, string> = {
    para_ti: 'Recomendados para ti',
    bought_together: 'Se compran juntos con frecuencia',
    recently_viewed: 'Vistos recientemente',
    best_sellers_category: 'Lo más vendido',
    trending: 'En tendencia',
    complete_cart: 'Completa tu compra',
};
/** Tipos que dependen de la persona (van con `aid`, caché privada). */
const PERSONAL = ['para_ti', 'recently_viewed', 'complete_cart'];

function str(v: unknown): string | undefined {
    return v === undefined || v === null || v === '' ? undefined : String(v);
}

export function reco(host: HTMLElement | null, x: Ctx, ctx: Record<string, unknown>): void {
    if (!host) return;
    const kind = TITLES[String(ctx['kind'])] ? String(ctx['kind']) : 'trending';
    const limit = Math.min(12, Math.max(1, Number(ctx['limit']) || 4));
    const cart = Array.isArray(ctx['cart']) ? (ctx['cart'] as unknown[]).map(String).join(',') : undefined;
    void x
        .get<{ products?: Product[] }>('reco', { kind, product_id: str(ctx['productId']), category_id: str(ctx['categoryId']), cart, aid: PERSONAL.indexOf(kind) >= 0 ? x.aid() : undefined, limit })
        .then((r) => {
            const ps = r.status === 200 && Array.isArray(r.data.products) ? r.data.products.filter((p) => p && p.url && p.name) : [];
            if (!ps.length) return collapse(host);
            const id = uid('r');
            shadow(host, CSS).append(h('section', { 'aria-labelledby': id }, h('h2', { id }, str(ctx['title']) ?? TITLES[kind]), h('ul', { class: 'pg' }, ...ps.slice(0, limit).map((p) => productCard(p, x.currency)))));
        });
}

/** «Avísame»: un campo (correo o WhatsApp) y, si el hub manda el texto, la casilla de marketing sin marcar. */
export function bis(host: HTMLElement | null, x: Ctx, ctx: Record<string, unknown>): void {
    const pid = str(ctx['productId']);
    if (!host || !pid) return;
    const kind = ctx['kind'] === 'price_drop' ? 'price_drop' : 'back_in_stock';
    const root = shadow(host, CSS);
    const fid = uid('w');
    const open = h('button', { type: 'button', 'aria-expanded': 'false', 'aria-controls': fid }, kind === 'price_drop' ? 'Avísame si baja de precio' : 'Avísame cuando vuelva');
    const inId = uid('i');
    const input = h('input', { id: inId, type: 'text', inputmode: 'email', autocomplete: 'email', required: true, 'aria-required': 'true', maxlength: 200 });
    const texts: Record<string, ConsentText> = {};
    for (const c of x.consents) texts[c.channel] = c;
    const cb = h('input', { type: 'checkbox' });
    const cbText = h('span');
    const ck = h('label', { class: 'ck', hidden: true }, cb, cbText);
    const channel = (): string => (input.value.indexOf('@') >= 0 ? 'email' : 'whatsapp');
    const sync = (): void => {
        const t = texts[channel()];
        ck.hidden = !t;
        cbText.textContent = t ? t.label : '';
    };
    input.addEventListener('input', sync);
    sync();
    const alert = h('p', { class: 'e', role: 'alert' });
    const send = h('button', { type: 'submit' }, 'Avisarme');
    const form = h('form', { id: fid, hidden: true, novalidate: true }, h('div', { class: 'bs' }, h('div', { class: 'f' }, h('label', { class: 'l', for: inId }, 'Tu correo o WhatsApp'), input), send), ck, alert);
    open.addEventListener('click', () => {
        form.hidden = false;
        open.hidden = true;
        open.setAttribute('aria-expanded', 'true');
        input.focus();
    });
    form.addEventListener('submit', (e) => {
        e.preventDefault();
        const v = input.value.trim();
        const ch = channel();
        if (ch === 'email' ? !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(v) : v.replace(/\D/g, '').length < 7) {
            alert.textContent = 'Escribe un correo o un WhatsApp válido.';
            input.setAttribute('aria-invalid', 'true');
            input.focus();
            return;
        }
        input.setAttribute('aria-invalid', 'false');
        send.disabled = true;
        const t = texts[ch];
        const body: Record<string, unknown> = { anonymous_id: x.aid(), product_id: pid, kind, consents: t ? { [ch]: cb.checked } : {}, consent_version: t?.version };
        body[ch === 'email' ? 'email' : 'phone'] = v.slice(0, 200);
        void x.post<{ ok?: boolean; message?: string }>('watch', body).then((r) => {
            send.disabled = false;
            if (r.status === 200) form.replaceWith(h('p', { class: 'ok', role: 'status' }, r.data.message || 'Te avisaremos.'));
            else alert.textContent = r.data.message || (r.status === 429 ? 'Demasiados intentos. Espera un momento.' : 'No pudimos guardar tu aviso. Inténtalo de nuevo.');
        });
    });
    root.append(open, form);
}

export function points(host: HTMLElement | null, x: Ctx, ctx: Record<string, unknown>): void {
    if (!host) return;
    void x.get<{ points?: number; label?: string }>('points', { product_id: str(ctx['productId']), price: str(ctx['price']), cart_total: str(ctx['cartTotal']) }).then((r) => {
        const n = Number(r.data.points);
        if (r.status !== 200 || !(n > 0)) return collapse(host);
        shadow(host, CSS).append(h('p', { class: 'pts' }, r.data.label || 'Gana ' + n + ' puntos'));
    });
}

export function banner(host: HTMLElement | null, x: Ctx, ctx: Record<string, unknown>): void {
    if (!host) return;
    const placement = ctx['placement'] === 'account' ? 'account' : 'top';
    void x.get<{ banner?: { text?: string; cta?: string; url?: string; style?: string } | null }>('banner', { placement, aid: x.aid(), token: x.token() }).then((r) => {
        const b = r.status === 200 ? r.data.banner : null;
        if (!b || !b.text) return collapse(host);
        const style = ['vip', 'warning', 'info', 'success'].indexOf(String(b.style)) >= 0 ? ' ' + String(b.style) : '';
        const url = safeUrl(b.url);
        const box = h('div', { class: 'bn' + style + (placement === 'top' ? ' top' : ''), role: 'region', 'aria-label': 'Aviso' }, h('p', null, b.text), url ? h('a', { href: url }, b.cta || 'Ver más') : null);
        if (placement === 'top') {
            // Arriba va flotante (sin empujar la página) y se puede cerrar.
            collapse(host);
            const close = h('button', { type: 'button', class: 'x', 'aria-label': 'Cerrar' }, '×');
            const o = overlayHost('banner');
            close.addEventListener('click', () => o.remove());
            box.append(close);
            shadow(o, CSS).append(box);
        } else shadow(host, CSS).append(box);
    });
}

register({
    reco: (host, _w, x, ctx) => reco(host, x, ctx),
    bis: (host, _w, x, ctx) => bis(host, x, ctx),
    points: (host, _w, x, ctx) => points(host, x, ctx),
    banner: (host, _w, x, ctx) => banner(host, x, ctx),
});
