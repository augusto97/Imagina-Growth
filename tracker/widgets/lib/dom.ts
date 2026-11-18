/* Ayudas de DOM compartidas por los paquetes de widgets (se copian en cada uno al compilar). */
import type { Renderer } from './types';

type Kid = Node | string | null | undefined | false;

/** Crea un elemento con atributos (true = atributo vacío; null/false = sin atributo) e hijos. */
export function h<K extends keyof HTMLElementTagNameMap>(tag: K, attrs?: Record<string, string | number | boolean | null | undefined> | null, ...kids: Kid[]): HTMLElementTagNameMap[K] {
    const n = document.createElement(tag);
    if (attrs) {
        for (const k in attrs) {
            const v = attrs[k];
            if (v === true) n.setAttribute(k, '');
            else if (v != null && v !== false) n.setAttribute(k, String(v));
        }
    }
    for (const c of kids) if (c != null && c !== false) n.append(c);
    return n;
}

let seq = 0;
/** ID único para enlazar etiquetas y descripciones. */
export const uid = (p = ''): string => 'igr' + p + ++seq;

/**
 * Estilos base dentro del Shadow DOM: aíslan el widget del tema pero heredan su
 * fuente. Contraste AA, foco visible y sin animaciones si se pide menos movimiento.
 */
export const BASE =
    ':host{all:initial;display:block;font-family:inherit;font-size:15px;line-height:1.5;color:#111827;--a:#1d4ed8;--b:#fff;--t:#111827}' +
    '*{box-sizing:border-box}h2,h3,p{margin:0 0 8px}h2{font-size:20px;line-height:1.25}h3{font-size:16px}' +
    'button,input,select{font:inherit}button{cursor:pointer;border-radius:8px;padding:9px 16px;border:1px solid var(--a);background:var(--a);color:#fff}' +
    'button.s{background:transparent;color:inherit;border-color:#9ca3af}button:disabled{opacity:.6;cursor:default}' +
    'input,select{width:100%;padding:9px 11px;border:1px solid #6b7280;border-radius:8px;background:#fff;color:#111827}' +
    'input[type=checkbox],input[type=radio]{width:auto;accent-color:var(--a)}label{display:block}' +
    'a{color:var(--a)}:focus-visible{outline:3px solid #2563eb;outline-offset:2px}.e{color:#b91c1c;font-size:14px}' +
    '.sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}' +
    '@media (prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}}';

/** Shadow DOM del host con los estilos base y los del widget. */
export function shadow(host: HTMLElement, css: string): ShadowRoot {
    const root = host.shadowRoot ?? host.attachShadow({ mode: 'open' });
    root.replaceChildren(h('style', null, BASE + css));
    return root;
}

/** Host para un widget flotante (`<igr-w>` para no heredar reglas de `div` del tema). */
export function overlayHost(kind: string): HTMLElement {
    const e = document.createElement('igr-w');
    e.setAttribute('data-igr-w', kind);
    document.body.appendChild(e);
    return e;
}

/** Quita el espacio reservado de un slot que no tiene nada que mostrar. */
export function collapse(slot: HTMLElement | null): void {
    if (slot) {
        slot.style.minHeight = '0';
        slot.hidden = true;
    }
}

/** Los widgets hablan español: el idioma de la página si es español; si no, es-CO. */
export function loc(): string {
    const l = document.documentElement.lang || '';
    return /^es([-_]|$)/i.test(l) ? l.replace('_', '-') : 'es-CO';
}

/** Monto en la moneda de la tienda, sin decimales si no los tiene («$ 150.000»). */
export function money(v: string | number | null | undefined, currency: string): string {
    const n = Number(v);
    if (v == null || v === '' || !isFinite(n)) return '';
    try {
        return new Intl.NumberFormat(loc(), { style: 'currency', currency: currency || 'COP', minimumFractionDigits: n % 1 ? 2 : 0, maximumFractionDigits: 2 }).format(n);
    } catch {
        return String(v);
    }
}

/** Estrellas con texto accesible («4,8 de 5 estrellas»). */
export function stars(avg: number, count?: number | null): HTMLElement {
    const pct = Math.max(0, Math.min(100, (avg / 5) * 100));
    const label = avg.toFixed(1).replace('.', ',') + ' de 5 estrellas' + (count != null ? ', ' + count + (count === 1 ? ' reseña' : ' reseñas') : '');
    return h(
        'span',
        { class: 'st', role: 'img', 'aria-label': label },
        h('span', { class: 'sb', 'aria-hidden': 'true' }, '★★★★★', h('span', { class: 'sf', style: 'width:' + pct + '%' }, '★★★★★')),
        count != null ? h('span', { class: 'sc', 'aria-hidden': 'true' }, ' (' + count + ')') : null,
    );
}
export const STARS_CSS = '.st{display:inline-flex;align-items:center;gap:2px}.sb{position:relative;color:#d1d5db;letter-spacing:1px}.sf{position:absolute;inset:0 auto 0 0;overflow:hidden;color:#b45309;white-space:nowrap}.sc{color:#4b5563;font-size:13px}';

/** Registra los renderizadores de un paquete en el runtime. */
export function register(map: Record<string, Renderer>): void {
    const w = window as unknown as { __igrW?: { r: Record<string, Renderer> } };
    const reg = (w.__igrW = w.__igrW ?? { r: {} });
    for (const k in map) reg.r[k] = map[k] as Renderer;
}

/** Botón «Copiar» para un cupón o un enlace. */
export function copyButton(text: string, label = 'Copiar'): HTMLButtonElement {
    const b = h('button', { type: 'button', class: 's' }, label);
    b.addEventListener('click', () => {
        const done = (): void => {
            b.textContent = '¡Copiado!';
            setTimeout(() => (b.textContent = label), 2000);
        };
        try {
            void navigator.clipboard.writeText(text).then(done, () => undefined);
        } catch {
            /* sin portapapeles */
        }
    });
    return b;
}

/** Solo URLs http(s) en enlaces que vienen del hub. */
export function safeUrl(u: unknown): string | null {
    return typeof u === 'string' && /^https?:\/\//i.test(u) ? u : null;
}

/** Tarjeta de producto (recomendaciones y quiz). */
export function productCard(p: { name: string; url: string; image?: string | null; price?: string; regular_price?: string | null; sale_price?: string | null; currency?: string; rating_avg?: number | null; rating_count?: number | null }, currency: string): HTMLElement {
    const cur = p.currency || currency;
    const sale = p.sale_price && p.regular_price && Number(p.sale_price) < Number(p.regular_price);
    return h(
        'li',
        { class: 'pc' },
        h(
            'a',
            { href: safeUrl(p.url) ?? '#' },
            safeUrl(p.image) ? h('img', { src: safeUrl(p.image), alt: '', loading: 'lazy', decoding: 'async', width: 300, height: 300 }) : h('span', { class: 'pi' }),
            h('span', { class: 'pn' }, p.name),
        ),
        p.rating_avg ? stars(p.rating_avg, p.rating_count) : null,
        h(
            'span',
            { class: 'pp' },
            sale ? h('del', null, h('span', { class: 'sr' }, 'Antes '), money(p.regular_price, cur)) : null,
            sale ? ' ' : null,
            sale ? h('ins', null, h('span', { class: 'sr' }, 'Ahora '), money(p.sale_price, cur)) : money(p.price, cur),
        ),
    );
}
export const CARDS_CSS =
    '.pg{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:16px}' +
    '.pc a{display:block;color:inherit;text-decoration:none}.pc img,.pi{display:block;width:100%;height:auto;aspect-ratio:1;object-fit:cover;border-radius:8px;background:#f3f4f6}' +
    '.pn{display:block;margin:8px 0 2px;font-weight:600}.pp{display:block}.pp del{color:#6b7280;font-size:13px}.pp ins{text-decoration:none;font-weight:600;color:#b91c1c}' +
    STARS_CSS;
