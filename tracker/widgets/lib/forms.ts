/*
 * Formularios de captación compartidos por igr-w-capture.js, igr-w-wheel.js e igr-w-quiz.js (M12):
 * contenedor (modal, esquina, barra o slot) accesible, campos con etiqueta, casillas
 * de consentimiento sin marcar con el texto y la versión del hub, envío y mensaje final.
 */
import { copyButton, h, loc, overlayHost, shadow, uid } from './dom';
import type { ConsentText, Ctx, Field, Live, Step, SubmitResult } from './types';

export const FORM_CSS =
    '.bd{position:fixed;inset:0;z-index:2147483000;background:rgba(17,24,39,.55);display:flex;align-items:center;justify-content:center;padding:16px}' +
    '.dl,.in{position:relative;background:var(--b);color:var(--t);border-radius:14px;padding:24px}' +
    '.dl{width:100%;max-width:440px;max-height:calc(100vh - 32px);overflow:auto;box-shadow:0 20px 50px rgba(0,0,0,.3)}.dl:focus{outline:0}' +
    '.fs{padding:0}.fs .dl{max-width:none;max-height:none;height:100%;border-radius:0;display:flex;flex-direction:column;justify-content:center;align-items:center}.fs .dl>*{width:100%;max-width:480px}' +
    '.cn{position:fixed;right:16px;bottom:16px;z-index:2147483000;width:calc(100% - 32px);max-width:360px}.cn .dl{padding:20px}' +
    '.in{border:1px solid #e5e7eb}.x{position:absolute;top:6px;right:6px;background:transparent;border:0;color:inherit;font-size:24px;line-height:1;padding:6px 10px}' +
    '.f{margin:0 0 12px}.l{display:block;font-weight:600;font-size:14px;margin-bottom:4px}.ck{display:flex;gap:8px;align-items:flex-start;font-size:13px;margin:0 0 10px}.ck input{margin-top:3px}' +
    '.ac{display:flex;flex-wrap:wrap;gap:8px;margin-top:4px}.ok{text-align:center}.cp{display:flex;gap:8px;align-items:center;justify-content:space-between;border:2px dashed var(--a);border-radius:8px;padding:8px 12px;margin:12px 0}' +
    '.cp strong{font-size:20px;letter-spacing:1px}.im{display:block;width:100%;height:auto;border-radius:8px;margin:0 0 12px}.sm{font-size:13px;color:#4b5563}' +
    '@media (prefers-reduced-motion:no-preference){.dl{animation:igri .2s ease-out}@keyframes igri{from{opacity:0;transform:translateY(8px)}}}';

export interface Frame {
    root: ShadowRoot;
    box: HTMLElement;
    close: () => void;
    submitted: boolean;
}

/** Contenedor del widget: modal (centro o pantalla completa), esquina, barra o incrustado en un slot. */
export function frame(live: Live, x: Ctx, layout: string, slot: HTMLElement | null, css = ''): Frame {
    const host = slot ?? overlayHost(live.kind);
    const root = shadow(host, FORM_CSS + css);
    const d = live.design;
    const vars = (d.bg ? '--b:' + d.bg + ';' : '') + (d.text ? '--t:' + d.text + ';' : '') + (d.accent ? '--a:' + d.accent + ';' : '');
    const title = uid('t');
    const prev = document.activeElement as HTMLElement | null;
    const modal = layout === 'center' || layout === 'fullscreen';
    let box: HTMLElement;
    const f: Frame = { root, box: h('div'), close: () => undefined, submitted: false };
    f.close = (): void => {
        if (slot) return;
        host.remove();
        x.mark(live, 'close');
        if (!f.submitted) x.track('widget.closed', live);
        try {
            prev?.focus();
        } catch {
            /* sin foco previo */
        }
    };
    const closer = (): HTMLButtonElement => {
        const b = h('button', { type: 'button', class: 'x', 'aria-label': 'Cerrar' }, '×');
        b.addEventListener('click', f.close);
        return b;
    };
    if (slot) {
        box = h('section', { class: 'in', style: vars, 'aria-labelledby': title });
        root.append(box);
    } else if (layout === 'bar') {
        box = h('div', { class: 'br ' + (d.position === 'bottom' ? 'bottom' : 'top'), role: 'region', 'aria-labelledby': title, style: vars });
        root.append(box);
        box.append(closer());
    } else {
        box = h('div', { class: 'dl', role: 'dialog', 'aria-modal': modal ? 'true' : 'false', 'aria-labelledby': title, tabindex: '-1', style: vars });
        const wrap = modal ? h('div', { class: 'bd' + (layout === 'fullscreen' ? ' fs' : '') }, box) : h('div', { class: 'cn' }, box);
        if (modal) wrap.addEventListener('click', (e) => e.target === wrap && f.close());
        root.append(wrap);
        box.append(closer());
        setTimeout(() => box.focus(), 0);
    }
    root.addEventListener('keydown', (ev) => {
        const e = ev as KeyboardEvent;
        if (e.key === 'Escape' && !slot) f.close();
        if (e.key !== 'Tab' || !modal) return;
        const els = Array.from(box.querySelectorAll<HTMLElement>('a[href],button:not([disabled]),input:not([disabled]),select:not([disabled])'));
        const first = els[0];
        const last = els[els.length - 1];
        const active = root.activeElement;
        if (e.shiftKey && (active === first || active === box)) {
            e.preventDefault();
            last?.focus();
        } else if (!e.shiftKey && active === last) {
            e.preventDefault();
            first?.focus();
        }
    });
    box.setAttribute('data-title', title);
    f.box = box;
    return f;
}

/** Imagen, título y texto del widget. */
export function head(f: Frame, title?: string, body?: string, image?: string): Node[] {
    const id = f.box.getAttribute('data-title') ?? uid('t');
    return [image ? h('img', { class: 'im', src: image, alt: '' }) : null, h('h2', { id }, title ?? ''), body ? h('p', null, body) : null].filter(Boolean) as Node[];
}

function fieldInput(fd: Field, id: string, err: string): HTMLElement {
    const type = fd.type ?? 'text';
    const common = { id, name: fd.key, required: !!fd.required, 'aria-required': fd.required ? 'true' : null, 'aria-describedby': err, placeholder: fd.placeholder ?? null };
    if (type === 'select') {
        return h('select', common, h('option', { value: '' }, 'Elige una opción'), ...(fd.options ?? []).map((o) => h('option', { value: o.key }, o.label)));
    }
    const auto: Record<string, string> = { email: 'email', tel: 'tel', phone: 'tel', first_name: 'given-name', last_name: 'family-name', name: 'name', birthday: 'bday' };
    return h('input', { ...common, type: type === 'phone' ? 'tel' : type, inputmode: type === 'tel' || type === 'phone' ? 'tel' : null, autocomplete: auto[fd.key] ?? auto[type] ?? null, maxlength: 200 });
}

/** Valida en el navegador; el hub vuelve a validar. */
function invalid(fd: Field, v: string): string | null {
    if (!v) return fd.required ? 'Este dato es obligatorio.' : null;
    const t = fd.type ?? '';
    if ((t === 'email' || fd.key === 'email') && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(v)) return 'Escribe un correo válido.';
    if ((t === 'tel' || t === 'phone' || fd.key === 'phone') && v.replace(/\D/g, '').length < 7) return 'Escribe un número válido.';
    return null;
}

function fmtDate(iso?: string | null): string {
    if (!iso) return '';
    const d = new Date(iso);
    return isNaN(d.getTime()) ? '' : d.toLocaleDateString(loc(), { day: 'numeric', month: 'long', year: 'numeric' });
}

/** Mensaje final: cupón con «Copiar», doble opt-in y productos del quiz. */
export function success(live: Live, x: Ctx, res: SubmitResult, products?: (res: SubmitResult) => Node | null): HTMLElement {
    const code = res.coupon?.code ?? '';
    const msg = res.message || (live.content.success || '¡Listo! Ya quedaste registrado.').replace(/\{\{\s*codigo\s*\}\}/g, code);
    const exp = fmtDate(res.coupon?.expires_at);
    return h(
        'div',
        { class: 'ok', role: 'status', tabindex: '-1' },
        res.prize ? h('h3', null, res.prize.label) : null,
        h('p', null, msg),
        code ? h('div', { class: 'cp' }, h('strong', null, code), copyButton(code)) : null,
        res.coupon?.description ? h('p', { class: 'sm' }, res.coupon.description) : null,
        exp ? h('p', { class: 'sm' }, 'Vence el ' + exp + '.') : null,
        res.double_opt_in ? h('p', { class: 'sm' }, 'Te enviamos un correo para confirmar tu suscripción.') : null,
        products ? products(res) : null,
    );
}

interface FormOpts {
    step: number;
    fields: Field[];
    consents: ConsentText[];
    cta?: string;
    answers?: Record<string, string>;
    compact?: boolean;
    done: (res: SubmitResult) => void;
}

/** Formulario: campos con etiqueta, casillas de consentimiento sin marcar y envío al hub. */
export function form(live: Live, x: Ctx, o: FormOpts): HTMLFormElement {
    const el = h('form', { novalidate: true });
    const inputs: { fd: Field; input: HTMLInputElement | HTMLSelectElement; err: HTMLElement }[] = [];
    for (const fd of o.fields) {
        const id = uid('f');
        const errId = id + 'e';
        const input = fieldInput(fd, id, errId) as HTMLInputElement | HTMLSelectElement;
        const err = h('span', { id: errId, class: 'e', hidden: true });
        inputs.push({ fd, input, err });
        el.append(h('div', { class: 'f' }, h('label', { for: id, class: 'l' }, fd.label ?? fd.key), input, err));
    }
    const boxes = o.consents.map((c) => {
        const cb = h('input', { type: 'checkbox', name: 'consent_' + c.channel, value: '1', 'data-version': c.version ?? null });
        el.append(h('label', { class: 'ck' }, cb, h('span', null, c.label)));
        return { c, cb };
    });
    const alert = h('p', { class: 'e', role: 'alert' });
    const btn = h('button', { type: 'submit' }, o.cta || 'Enviar');
    el.append(alert, h('div', { class: 'ac' }, btn));
    el.addEventListener('submit', (ev) => {
        ev.preventDefault();
        alert.textContent = '';
        const fields: Record<string, string> = {};
        let bad: HTMLElement | null = null;
        for (const { fd, input, err } of inputs) {
            const v = input.value.trim();
            const msg = invalid(fd, v);
            err.hidden = !msg;
            err.textContent = msg ?? '';
            input.setAttribute('aria-invalid', msg ? 'true' : 'false');
            if (msg && !bad) bad = input;
            if (v) fields[fd.key] = v.slice(0, 200);
        }
        if (bad) {
            bad.focus();
            return;
        }
        const consents: Record<string, boolean> = {};
        for (const { c, cb } of boxes) consents[c.channel] = cb.checked;
        btn.disabled = true;
        const body: Record<string, unknown> = { anonymous_id: x.aid(), variant: live.variant, page_url: x.pageUrl(), step: o.step, fields, consents };
        if (o.answers) body['answers'] = o.answers;
        const ref = x.ref();
        if (ref) body['referral_code'] = ref;
        void x.post<SubmitResult>('widgets/' + encodeURIComponent(live.id) + '/submit', body).then((r) => {
            btn.disabled = false;
            const d = r.data ?? {};
            if (r.status === 200 && d.ok !== false) {
                o.done(d);
                return;
            }
            if (r.status === 422 && d.errors) {
                for (const { fd, input, err } of inputs) {
                    const e = d.errors[fd.key] ?? d.errors['fields.' + fd.key];
                    if (e) {
                        err.hidden = false;
                        err.textContent = Array.isArray(e) ? String(e[0]) : String(e);
                        input.setAttribute('aria-invalid', 'true');
                    }
                }
            }
            const m = typeof d.error === 'object' ? d.error?.message : undefined;
            alert.textContent =
                d.message || m || (r.status === 409 ? 'Ya recibiste este beneficio.' : r.status === 422 ? 'Revisa los datos e inténtalo de nuevo.' : r.status === 429 ? 'Demasiados intentos. Espera un momento.' : 'No pudimos enviar tus datos. Inténtalo de nuevo.');
        });
    });
    return el;
}

/** Pasos del formulario (`content.steps` o uno solo con los campos del widget). */
export function steps(live: Live): Step[] {
    const c = live.content;
    const own = (c.steps ?? []).filter((s) => s && (s.fields?.length || s.consents?.length));
    return own.length ? own : [{ title: c.title, body: c.body, fields: c.fields ?? [], consents: c.consents ?? [], cta: c.cta }];
}

/** Captura con uno o varios pasos y el mensaje final. */
export function capture(f: Frame, live: Live, x: Ctx, opts: { into?: HTMLElement; compact?: boolean; answers?: Record<string, string>; products?: (res: SubmitResult) => Node | null; after?: (res: SubmitResult, show: () => void) => void } = {}): void {
    const all = steps(live);
    const into = opts.into ?? f.box;
    let got: SubmitResult = {};
    const keep = Array.from(into.children).filter((n) => n.classList.contains('x'));
    const render = (i: number): void => {
        const s = all[i] as Step;
        const nodes: Node[] = [];
        if (!opts.compact) nodes.push(...head(f, i === 0 ? live.content.title : s.title ?? live.content.title, i === 0 ? live.content.body : s.body, i === 0 ? live.content.image ?? live.design.image : undefined));
        else if (live.content.title) nodes.push(h('p', { id: f.box.getAttribute('data-title') ?? '' }, h('strong', null, live.content.title), live.content.body ? ' ' + live.content.body : ''));
        nodes.push(
            form(live, x, {
                step: i + 1,
                fields: s.fields ?? [],
                consents: s.consents ?? [],
                cta: s.cta ?? live.content.cta,
                answers: opts.answers,
                done: (res) => {
                    got = { ...got, ...res, coupon: res.coupon ?? got.coupon };
                    if (i + 1 < all.length) {
                        render(i + 1);
                        return;
                    }
                    f.submitted = true;
                    x.mark(live, 'submit');
                    x.track('widget.submitted', live);
                    const fin = (): void => {
                        // El título se queda: el diálogo conserva su nombre accesible.
                        const tid = f.box.getAttribute('data-title') ?? '';
                        const title = live.content.title ? (opts.compact ? h('p', { id: tid }, h('strong', null, live.content.title)) : h('h2', { id: tid }, live.content.title)) : null;
                        into.replaceChildren(...keep, ...(title ? [title] : []), success(live, x, got, opts.products));
                        (into.querySelector('[role=status]') as HTMLElement | null)?.focus?.();
                    };
                    if (opts.after) opts.after(got, fin);
                    else fin();
                },
            }),
        );
        into.replaceChildren(...keep, ...nodes);
    };
    render(0);
}

