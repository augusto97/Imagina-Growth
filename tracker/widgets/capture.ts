/*
 * Captación (igr-w-capture.js, M12): popup (centro, esquina o pantalla completa),
 * formulario incrustado, barra, teaser, dos pasos y bienvenida de referido.
 * La ruleta (igr-w-wheel.js) y el quiz (igr-w-quiz.js) van aparte.
 */
import { h, overlayHost, register, shadow } from './lib/dom';
import { FORM_CSS, capture, frame, head, type Frame } from './lib/forms';
import type { Ctx, Live } from './lib/types';

const CSS =
    '.br{position:fixed;left:0;right:0;z-index:2147483000;background:var(--a);color:#fff;padding:10px 52px 10px 16px;display:flex;flex-wrap:wrap;gap:8px 16px;align-items:center;justify-content:center}' +
    '.br.top{top:0}.br.bottom{bottom:0}.br p{margin:0}.br .x{top:50%;transform:translateY(-50%)}.br form{display:flex;flex-wrap:wrap;gap:8px;align-items:center}.br .f{margin:0}.br .l{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}' +
    '.br button[type=submit],.br .go{background:#fff;color:var(--a);border-color:#fff;text-decoration:none;border-radius:8px;padding:9px 16px}.br .e{color:#fff}' +
    '.tz{position:fixed;bottom:16px;left:16px;z-index:2147482999;border-radius:999px;box-shadow:0 6px 20px rgba(0,0,0,.25)}.tz.right{left:auto;right:16px}';

/** Dos pasos: primero «¿Quieres…?» y después el formulario. */
function twoStep(f: Frame, live: Live, x: Ctx): void {
    const c = live.content;
    const keep = Array.from(f.box.children).filter((n) => n.classList.contains('x'));
    const yes = h('button', { type: 'button' }, c.cta || 'Sí, quiero');
    const no = h('button', { type: 'button', class: 's' }, c.no || 'No, gracias');
    f.box.replaceChildren(...keep, ...head(f, c.title, c.body, c.image ?? live.design.image), h('div', { class: 'ac' }, yes, no));
    yes.addEventListener('click', () => {
        capture(f, { ...live, content: { ...c, body: undefined, image: undefined, cta: c.steps?.[0]?.cta ?? (c.fields?.length ? 'Enviar' : c.cta) } }, x);
        (f.box.querySelector('input,select') as HTMLElement | null)?.focus();
    });
    no.addEventListener('click', () => (f.box.parentElement ? f.close() : f.box.replaceChildren()));
}

function overlay(layout?: string): (host: HTMLElement | null, live: Live | null, x: Ctx) => void {
    return (_host, live, x) => {
        if (!live) return;
        const f = frame(live, x, layout ?? live.design.layout ?? 'center', null, CSS);
        if (live.kind === 'two_step') twoStep(f, live, x);
        else capture(f, live, x);
    };
}

register({
    popup: overlay(),
    referral_welcome: overlay(),
    two_step: overlay('center'),
    /** Formulario incrustado en un slot (embedded_form o dos pasos). */
    form: (host, live, x) => {
        if (!host || !live) return;
        const f = frame(live, x, 'inline', host, CSS);
        if (live.kind === 'two_step') twoStep(f, live, x);
        else capture(f, live, x);
    },
    bar: (_host, live, x) => {
        if (!live) return;
        const f = frame(live, x, 'bar', null, CSS);
        const c = live.content;
        if ((c.fields ?? []).length || (c.steps ?? []).length) {
            capture(f, live, x, { compact: true });
            return;
        }
        const keep = Array.from(f.box.children);
        const go = c.url ? h('a', { class: 'go', href: c.url }, c.cta || 'Ver más') : null;
        go?.addEventListener('click', () => {
            x.mark(live, 'submit');
            x.track('widget.submitted', live);
            f.submitted = true;
        });
        f.box.replaceChildren(...keep, h('p', { id: f.box.getAttribute('data-title') ?? '' }, h('strong', null, c.title ?? ''), c.body ? ' ' + c.body : ''), go ?? '');
    },
    teaser: (_host, live, x) => {
        if (!live) return;
        const host = overlayHost('teaser');
        const root = shadow(host, FORM_CSS + CSS);
        const pos = live.design.position === 'right' ? ' right' : '';
        const tab = h('button', { type: 'button', class: 'tz' + pos, 'aria-haspopup': 'dialog' }, live.content.teaser || live.content.title || 'Ver oferta');
        root.append(tab);
        tab.addEventListener('click', () => {
            host.remove();
            capture(frame(live, x, live.design.layout ?? 'center', null, CSS), live, x);
        });
    },
});
