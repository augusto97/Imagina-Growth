/*
 * Encuesta «¿Cómo nos conociste?» en la página de gracias (docs/api-connector.md §8),
 * igr-w-survey.js. La carga el runtime de widgets si widgets.json trae la encuesta. Shadow DOM
 * para no heredar estilos del tema; accesible (fieldset, etiquetas, foco
 * visible, Esc para cerrar, contraste AA, sin animaciones forzadas).
 */
interface Option {
    key: string;
    label: string;
}

export interface SurveyWidget {
    id: string;
    kind: string;
    slot?: string;
    title?: string;
    question: string;
    options: Option[];
    allow_other?: boolean;
    other_label?: string;
    thanks?: string;
}

import { register } from './lib/dom';

interface WWindow {
    igrOrder?: { orderId?: string; token?: string };
    igr?: unknown[] & { push: (...c: unknown[]) => number };
}

const W = window as unknown as WWindow;

const CSS =
    ':host{display:block}.c{box-sizing:border-box;margin:24px 0;padding:18px 20px;border:1px solid #d1d5db;border-radius:12px;background:#fff;color:#111827;font:15px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif;max-width:560px}' +
    'h2{margin:0 0 4px;font-size:16px}fieldset{border:0;margin:0;padding:0}legend{margin:0 0 10px;color:#374151}' +
    '.o{display:flex;flex-wrap:wrap;gap:8px}label{display:inline-flex;align-items:center;gap:6px;border:1px solid #9ca3af;border-radius:999px;padding:6px 12px;cursor:pointer}' +
    'input[type=radio]{accent-color:#1d4ed8}input[type=text]{font:inherit;margin-top:10px;width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #6b7280;border-radius:8px}' +
    '.a{display:flex;gap:8px;margin-top:12px}button{font:inherit;padding:8px 14px;border-radius:8px;border:1px solid #1d4ed8;background:#1d4ed8;color:#fff;cursor:pointer}' +
    'button.s{background:transparent;color:#1f2937;border-color:#9ca3af}button:disabled{opacity:.6;cursor:default}' +
    ':focus-visible{outline:3px solid #2563eb;outline-offset:2px}.e{color:#b91c1c;margin:8px 0 0}' +
    '@media (prefers-reduced-motion:no-preference){.c{transition:opacity .2s}}';

function el<K extends keyof HTMLElementTagNameMap>(tag: K, attrs: Record<string, string> = {}, text?: string): HTMLElementTagNameMap[K] {
    const n = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) n.setAttribute(k, v);
    if (text !== undefined) n.textContent = text;
    return n;
}

function track(name: string, w: SurveyWidget): void {
    try {
        W.igr?.push(['track', name, { widget_id: w.id.slice(0, 64), variant: null, kind: 'hdyhau_survey' }]);
    } catch {
        /* sin tracker */
    }
}

/** URL de respuestas: …/p/{pk}/ → …/p/{pk}/surveys/{id}/answers. */
export function answersUrl(base: string, surveyId: string): string {
    return base.replace(/widgets\.json(\?.*)?$/, '') + 'surveys/' + encodeURIComponent(surveyId) + '/answers';
}

function anonymousId(): string | null {
    const m = document.cookie.match(/(?:^|; )_igr_aid=([^;]*)/);
    return m && m[1] ? decodeURIComponent(m[1]) : null;
}

export function render(slot: HTMLElement, w: SurveyWidget, order: { orderId?: string; token?: string }, base: string): void {
    const key = '_igr_sv_' + (order.orderId ?? '');
    try {
        if (localStorage.getItem(key)) return;
    } catch {
        /* sin almacenamiento */
    }
    const root = slot.shadowRoot ?? slot.attachShadow({ mode: 'open' });
    const card = el('section', { class: 'c', 'aria-labelledby': 'igr-t' });
    if (w.title) card.append(el('h2', { id: 'igr-t' }, w.title));
    const form = el('form', { novalidate: '' });
    const fs = el('fieldset');
    fs.append(el('legend', w.title ? {} : { id: 'igr-t' }, w.question));
    const opts = el('div', { class: 'o', role: 'radiogroup' });
    const all = w.options.slice(0, 12).concat(w.allow_other ? [{ key: '__other', label: w.other_label ?? 'Otro' }] : []);
    all.forEach((o, i) => {
        const id = 'igr-o' + i;
        const input = el('input', { type: 'radio', name: 'a', id, value: o.key });
        const label = el('label', { for: id });
        label.append(input, document.createTextNode(o.label));
        opts.append(label);
    });
    fs.append(opts);
    const other = el('input', { type: 'text', maxlength: '300', 'aria-label': w.other_label ?? 'Otro', hidden: '' });
    fs.append(other);
    const err = el('p', { class: 'e', role: 'alert', hidden: '' });
    const actions = el('div', { class: 'a' });
    const send = el('button', { type: 'submit' }, 'Enviar');
    const skip = el('button', { type: 'button', class: 's' }, 'No, gracias');
    actions.append(send, skip);
    form.append(fs, other, err, actions);
    card.append(form);
    root.append(el('style', {}, CSS), card);

    const close = (): void => {
        card.remove();
        try {
            localStorage.setItem(key, '1');
        } catch {
            /* sin almacenamiento */
        }
        track('widget.closed', w);
    };
    skip.addEventListener('click', close);
    card.addEventListener('keydown', (e) => {
        if ((e as KeyboardEvent).key === 'Escape') close();
    });
    opts.addEventListener('change', () => {
        const v = (root.querySelector('input[name=a]:checked') as HTMLInputElement | null)?.value;
        other.hidden = v !== '__other';
        if (!other.hidden) other.focus();
    });
    form.addEventListener('submit', (e) => {
        e.preventDefault();
        const v = (root.querySelector('input[name=a]:checked') as HTMLInputElement | null)?.value;
        if (!v) {
            err.hidden = false;
            err.textContent = 'Elige una opción.';
            return;
        }
        send.disabled = true;
        const body = JSON.stringify({
            order_token: order.token,
            answer: v === '__other' ? 'otro' : v,
            other: v === '__other' ? other.value.trim().slice(0, 300) || null : null,
            anonymous_id: anonymousId(),
        });
        fetch(answersUrl(base, w.id), { method: 'POST', body, credentials: 'omit', headers: { 'Content-Type': 'text/plain;charset=UTF-8' } })
            .then((r) => {
                if (!r.ok && r.status !== 409) throw new Error(String(r.status));
                card.replaceChildren(el('p', { role: 'status' }, w.thanks ?? '¡Gracias!'));
                try {
                    localStorage.setItem(key, '1');
                } catch {
                    /* sin almacenamiento */
                }
                track('widget.submitted', w);
            })
            .catch(() => {
                send.disabled = false;
                err.hidden = false;
                err.textContent = 'No pudimos enviar tu respuesta. Inténtalo de nuevo.';
            });
    });
    track('widget.shown', w);
}

register({
    thankyou: (slot, _w, x, ctx) => {
        const w = ctx['widget'] as SurveyWidget | undefined;
        const order = W.igrOrder;
        if (slot && w && w.kind === 'hdyhau_survey' && order?.token) render(slot, w, order, x.base);
    },
});
