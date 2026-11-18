/*
 * Reseñas (igr-w-reviews.js, M15): lista con resumen, carrusel, galería de fotos y
 * la insignia de estrellas (las insignias de una página van en una sola petición).
 * Imágenes con carga diferida; los enlaces del hub solo si son http(s).
 */
import { STARS_CSS, collapse, h, loc, register, safeUrl, shadow, stars, uid } from './lib/dom';
import type { Ctx } from './lib/types';

interface Media {
    url: string;
    type?: 'image' | 'video';
    thumb?: string | null;
}
interface Review {
    id: string;
    rating: number;
    title?: string | null;
    body?: string | null;
    author?: string | null;
    verified?: boolean;
    media?: Media[];
    created_at?: string;
    reply?: string | { body?: string } | null;
}
interface Page {
    summary?: { avg?: number; count?: number; distribution?: Record<string, number> };
    reviews?: Review[];
    next_page?: number | null;
}

const CSS =
    STARS_CSS +
    '.sum{display:flex;flex-wrap:wrap;gap:16px 32px;align-items:center;margin:0 0 16px}.big{font-size:32px;font-weight:700;line-height:1}' +
    '.ds{display:grid;grid-template-columns:auto 160px auto;gap:4px 8px;align-items:center;font-size:13px}.bar{height:8px;border-radius:4px;background:#e5e7eb;overflow:hidden}.bar i{display:block;height:100%;background:#b45309}' +
    '.rl{list-style:none;margin:0;padding:0}.rv{border-top:1px solid #e5e7eb;padding:14px 0}.rv h3{margin:6px 0 4px}.mt{font-size:13px;color:#4b5563}.vf{color:#047857;font-weight:600}' +
    '.md{display:flex;flex-wrap:wrap;gap:8px;margin:8px 0}.md a,.md button{display:block;width:72px;height:72px;padding:0;border:0;border-radius:8px;overflow:hidden;background:#f3f4f6}.md img{width:100%;height:100%;object-fit:cover}' +
    '.vd{display:flex;align-items:center;justify-content:center;width:100%;height:100%;color:#111827;font-size:13px;text-decoration:none}' +
    '.rp{margin:8px 0 0;padding:8px 12px;border-left:3px solid #9ca3af;background:#f9fafb;font-size:14px}' +
    '.cr{display:flex;gap:12px;overflow-x:auto;scroll-snap-type:x mandatory;list-style:none;margin:0;padding:0 0 8px}.cr>li{flex:0 0 min(300px,85%);scroll-snap-align:start;border:1px solid #e5e7eb;border-radius:12px;padding:14px}' +
    '.nv{display:flex;gap:8px;justify-content:flex-end;margin-top:8px}.gl{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:8px;list-style:none;margin:0;padding:0}' +
    '.gl button{display:block;width:100%;aspect-ratio:1;padding:0;border:0;border-radius:8px;overflow:hidden;background:#f3f4f6}.gl img{width:100%;height:100%;object-fit:cover}' +
    '.lb{position:fixed;inset:0;z-index:2147483000;background:rgba(0,0,0,.8);display:flex;align-items:center;justify-content:center;padding:16px}' +
    '.lb>div{background:#fff;border-radius:12px;max-width:640px;width:100%;max-height:90vh;overflow:auto;padding:16px;position:relative}.lb img,.lb video{width:100%;height:auto;border-radius:8px}' +
    '.x{position:absolute;top:6px;right:6px;background:transparent;border:0;color:#111827;font-size:24px;padding:4px 10px}.bd{display:inline-flex;gap:6px;align-items:center;font-size:14px}';

function date(iso?: string): string {
    const d = iso ? new Date(iso) : null;
    return d && !isNaN(d.getTime()) ? d.toLocaleDateString(loc(), { day: 'numeric', month: 'short', year: 'numeric' }) : '';
}

function replyText(r: Review['reply']): string {
    return typeof r === 'string' ? r : r && typeof r === 'object' ? String(r.body ?? '') : '';
}

function mediaThumbs(r: Review, open?: (m: Media) => void): HTMLElement | null {
    const ms = (r.media ?? []).filter((m) => safeUrl(m.url)).slice(0, 6);
    if (!ms.length) return null;
    return h(
        'div',
        { class: 'md' },
        ...ms.map((m, i) => {
            const thumb = safeUrl(m.thumb) ?? (m.type === 'video' ? null : safeUrl(m.url));
            const inner = thumb ? h('img', { src: thumb, alt: (m.type === 'video' ? 'Video ' : 'Foto ') + (i + 1) + ' de la reseña', loading: 'lazy', decoding: 'async', width: 72, height: 72 }) : h('span', { class: 'vd' }, '▶ Video');
            if (open) {
                const b = h('button', { type: 'button', 'aria-label': 'Ver ' + (m.type === 'video' ? 'video' : 'foto') + ' ' + (i + 1) }, inner);
                b.addEventListener('click', () => open(m));
                return b;
            }
            return h('a', { href: safeUrl(m.url), target: '_blank', rel: 'noopener' }, inner);
        }),
    );
}

export function reviewItem(r: Review, tag: 'li' | 'div' = 'li', open?: (m: Media) => void): HTMLElement {
    const reply = replyText(r.reply);
    return h(
        tag,
        { class: 'rv' },
        stars(Number(r.rating) || 0),
        r.title ? h('h3', null, r.title) : null,
        h('p', { class: 'mt' }, (r.author || 'Cliente') + (r.created_at ? ' · ' + date(r.created_at) : ''), r.verified ? h('span', { class: 'vf' }, ' · ✓ Compra verificada') : null),
        r.body ? h('p', null, r.body) : null,
        mediaThumbs(r, open),
        reply ? h('p', { class: 'rp' }, h('strong', null, 'Respuesta de la tienda: '), reply) : null,
    );
}

function lightbox(root: ShadowRoot, m: Media, r?: Review): void {
    const prev = root.activeElement as HTMLElement | null;
    const close = h('button', { type: 'button', class: 'x', 'aria-label': 'Cerrar' }, '×');
    const url = safeUrl(m.url) ?? '';
    const media = m.type === 'video' ? h('video', { src: url, controls: true, preload: 'metadata', playsinline: true }) : h('img', { src: url, alt: 'Foto de la reseña' });
    const box = h('div', { role: 'dialog', 'aria-modal': 'true', 'aria-label': 'Reseña con foto', tabindex: '-1' }, close, media, r ? reviewItem(r, 'div') : null);
    const lb = h('div', { class: 'lb' }, box);
    const done = (): void => {
        lb.remove();
        prev?.focus();
    };
    close.addEventListener('click', done);
    lb.addEventListener('click', (e) => e.target === lb && done());
    lb.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') done();
        if (e.key === 'Tab') {
            e.preventDefault();
            close.focus();
        }
    });
    root.append(lb);
    close.focus();
}

function summary(s: NonNullable<Page['summary']>): HTMLElement {
    const count = Number(s.count) || 0;
    const dist = s.distribution ?? {};
    const rows: Node[] = [];
    for (let n = 5; n >= 1; n--) {
        const c = Number(dist[String(n)]) || 0;
        rows.push(h('span', null, n + ' ★'), h('span', { class: 'bar', 'aria-hidden': 'true' }, h('i', { style: 'width:' + (count ? Math.round((c / count) * 100) : 0) + '%' })), h('span', null, String(c)));
    }
    return h('div', { class: 'sum' }, h('div', null, h('p', { class: 'big' }, (Number(s.avg) || 0).toFixed(1).replace('.', ',')), stars(Number(s.avg) || 0, count)), h('div', { class: 'ds', role: 'group', 'aria-label': 'Reseñas por estrellas' }, ...rows));
}

/** Insignias: todas las de la página en una petición. */
const pending: { id: string; host: HTMLElement }[] = [];
let timer: ReturnType<typeof setTimeout> | 0 = 0;
function badge(host: HTMLElement, x: Ctx, pid: string | null): void {
    const paint = (avg: number, count: number): void => {
        if (!count) return collapse(host);
        shadow(host, CSS).append(h('span', { class: 'bd' }, stars(avg, count)));
    };
    if (!pid) {
        void x.get<Page>('reviews', { page: 1, per_page: 1 }).then((r) => paint(Number(r.data.summary?.avg) || 0, Number(r.data.summary?.count) || 0));
        return;
    }
    pending.push({ id: pid, host });
    if (timer) return;
    timer = setTimeout(() => {
        const batch = pending.splice(0, pending.length);
        timer = 0;
        const ids = Array.from(new Set(batch.map((b) => b.id))).slice(0, 100);
        void x.get<Record<string, { avg?: number; count?: number }>>('reviews/badges', { product_ids: ids.join(',') }).then((r) => {
            for (const b of batch) {
                const v = r.status === 200 ? r.data[b.id] : undefined;
                if (v) {
                    shadow(b.host, CSS).append(h('span', { class: 'bd' }, stars(Number(v.avg) || 0, Number(v.count) || 0)));
                    if (!Number(v.count)) collapse(b.host);
                } else collapse(b.host);
            }
        });
    }, 0);
}

export function reviews(host: HTMLElement | null, x: Ctx, ctx: Record<string, unknown>): void {
    if (!host) return;
    const pid = ctx['productId'] != null && ctx['productId'] !== '' ? String(ctx['productId']) : null;
    const layout = ['carousel', 'gallery', 'list', 'badge'].indexOf(String(ctx['layout'])) >= 0 ? String(ctx['layout']) : 'list';
    if (layout === 'badge') return badge(host, x, pid);
    const per = layout === 'gallery' ? 12 : layout === 'carousel' ? 10 : Math.min(20, Math.max(1, Number(ctx['perPage']) || 5));
    const q = (page: number): Record<string, unknown> => ({ product_id: pid ?? undefined, page, per_page: per, with_media: layout === 'gallery' ? 1 : 0, sort: ctx['sort'] === 'rating' ? 'rating' : 'recent' });
    void x.get<Page>('reviews', q(1)).then((r) => {
        const list = r.status === 200 && Array.isArray(r.data.reviews) ? r.data.reviews : [];
        const root = shadow(host, CSS);
        const id = uid('v');
        const sec = h('section', { 'aria-labelledby': id }, h('h2', { id }, ctx['title'] ? String(ctx['title']) : layout === 'gallery' ? 'Fotos de nuestros clientes' : 'Opiniones de clientes'));
        if (!list.length) {
            if (layout !== 'list') return collapse(host);
            sec.append(h('p', null, pid ? 'Este producto aún no tiene reseñas.' : 'Aún no hay reseñas.'));
            root.append(sec);
            return;
        }
        if (layout === 'gallery') {
            const items: HTMLElement[] = [];
            for (const rv of list) {
                for (const m of rv.media ?? []) {
                    const thumb = safeUrl(m.thumb) ?? (m.type !== 'video' ? safeUrl(m.url) : null);
                    if (!safeUrl(m.url) || !thumb) continue;
                    const b = h('button', { type: 'button', 'aria-label': 'Ver la reseña de ' + (rv.author || 'un cliente') }, h('img', { src: thumb, alt: '', loading: 'lazy', decoding: 'async' }));
                    b.addEventListener('click', () => lightbox(root, m, rv));
                    items.push(h('li', null, b));
                }
            }
            if (!items.length) return collapse(host);
            sec.append(h('ul', { class: 'gl' }, ...items.slice(0, 24)));
        } else if (layout === 'carousel') {
            const track = h('ul', { class: 'cr', tabindex: '0', 'aria-label': 'Reseñas' }, ...list.map((rv) => reviewItem(rv)));
            const move = (dir: number): void => track.scrollBy({ left: dir * (track.clientWidth * 0.8), behavior: 'smooth' });
            const prev = h('button', { type: 'button', class: 's', 'aria-label': 'Reseñas anteriores' }, '‹');
            const next = h('button', { type: 'button', class: 's', 'aria-label': 'Más reseñas' }, '›');
            prev.addEventListener('click', () => move(-1));
            next.addEventListener('click', () => move(1));
            sec.setAttribute('aria-roledescription', 'carrusel');
            if (r.data.summary) sec.append(summary(r.data.summary));
            sec.append(track, h('div', { class: 'nv' }, prev, next));
        } else {
            if (r.data.summary) sec.append(summary(r.data.summary));
            const ul = h('ul', { class: 'rl' }, ...list.map((rv) => reviewItem(rv, 'li', (m) => lightbox(root, m, rv))));
            sec.append(ul);
            let next = r.data.next_page ?? null;
            if (next) {
                const more = h('button', { type: 'button', class: 's' }, 'Ver más reseñas');
                more.addEventListener('click', () => {
                    if (!next) return;
                    more.disabled = true;
                    void x.get<Page>('reviews', q(next)).then((p) => {
                        more.disabled = false;
                        for (const rv of p.data.reviews ?? []) ul.append(reviewItem(rv, 'li', (m) => lightbox(root, m, rv)));
                        next = p.status === 200 ? p.data.next_page ?? null : null;
                        if (!next) more.remove();
                    });
                });
                sec.append(more);
            }
        }
        root.append(sec);
    });
}

register({ reviews: (host, _w, x, ctx) => reviews(host, x, ctx) });
