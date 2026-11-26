/*
 * Cotizador (igr-w-quote.js, M18): el slot `quote` con `{"calculatorId": "qc_…"}`.
 * Pide la definición pública (`GET /p/{clave}/quotes/{id}`) y arma una pregunta por
 * paso con barra de progreso: opciones en tarjetas (con su emoji y, si los precios son
 * públicos, cuánto suman), números con − y + y su unidad, y al final los datos de
 * contacto con sus casillas sin marcar. El precio lo calcula siempre el hub
 * (`POST …/estimate`) y aparece con una animación sobria. Se carga solo en las páginas
 * que tienen el cotizador.
 */
import { collapse, h, money, register, safeUrl, shadow, uid } from './lib/dom';
import type { ConsentText, Ctx } from './lib/types';

interface Opt {
    value: string;
    label: string;
    icon?: string;
    description?: string | null;
    price?: number;
    multiplier?: number;
}
interface QField {
    key: string;
    label: string;
    type: 'number' | 'select' | 'checkbox';
    required?: boolean;
    help?: string | null;
    icon?: string;
    unit?: string | null;
    min?: number;
    max?: number | null;
    step?: number;
    default?: number | null;
    unit_price?: number;
    options?: Opt[];
}
interface Def {
    id: string;
    name: string;
    currency?: string;
    definition: {
        fields?: QField[];
        packages?: { key: string; label: string; description?: string | null; icon?: string; price?: number }[];
        intro?: string | null;
        note?: string | null;
    };
    capture: { required: boolean; fields: string[]; require: string; consents: ConsentText[]; title?: string; cta?: string };
}
interface Result {
    estimate?: { text?: string };
    lines?: { label: string; amount: string }[];
    note?: string | null;
    message?: string;
    whatsapp?: string | null;
    errors?: Record<string, string[]>;
}

const CSS =
    '.q{border:1px solid #e5e7eb;border-radius:16px;padding:20px}.hp,.sm,.p,.u,small{font-size:14px;color:#4b5563}.pg{display:flex;align-items:center;gap:8px;margin:8px 0 14px}' +
    '.tr{flex:1;height:6px;border-radius:9px;background:#e5e7eb}.fl{display:block;height:6px;border-radius:9px;background:var(--a);transition:width .3s}' +
    'fieldset{border:0;margin:0;padding:0;min-width:0}legend{font-weight:650;font-size:17px;padding:0;margin-bottom:6px}' +
    '.o{position:relative;display:flex;align-items:center;gap:12px;min-height:56px;margin:8px 0 0;padding:10px 12px;border:1.5px solid #e5e7eb;border-radius:12px;cursor:pointer}' +
    '.o:has(:checked){border-color:var(--a);background:#f5f7ff}.o:focus-within{outline:3px solid #2563eb}.o input{position:absolute;opacity:0}.i{font-size:22px}.t{flex:1}small{display:block}' +
    '.n,.nv{display:flex;gap:8px;margin-top:12px}.n button{width:48px;font-size:20px}.w{position:relative;flex:1}.w input{height:54px;font-size:22px;font-weight:650}' +
    '.u{position:absolute;right:14px;top:15px}.l{font-weight:600;margin:10px 0 4px}.ck{display:flex;gap:8px;font-size:13px;margin-top:10px}.nv button{flex:1;min-height:46px}' +
    '[hidden]{display:none!important}.r{text-align:center}.big{font-size:30px;font-weight:750;animation:rv .6s both}@keyframes rv{from{opacity:0;filter:blur(6px)}}' +
    '.ln{list-style:none;padding:0}.wa{display:block;margin:10px 0;padding:12px;border-radius:10px;background:#128c3a;color:#fff;text-decoration:none}';

const LABELS: Record<string, [string, string, string]> = {
    first_name: ['Nombre', 'text', 'given-name'],
    last_name: ['Apellido', 'text', 'family-name'],
    phone: ['WhatsApp', 'tel', 'tel'],
    email: ['Correo', 'email', 'email'],
};

function utm(): Record<string, string> {
    const out: Record<string, string> = {};
    new URLSearchParams(location.search).forEach((v, k) => {
        if (/^(utm_(source|medium|campaign)|gclid|fbclid|ttclid)$/.test(k)) out[k] = v.slice(0, 200);
    });
    return out;
}

/** «+ $30.000 · +25 %», o nada si la opción no cambia el precio (o los precios no son públicos). */
function tag(o: Opt, cur: string, plus: string): string {
    const m = Math.round(((o.multiplier ?? 1) - 1) * 100);
    return [o.price ? plus + money(o.price, cur) : '', m ? (m > 0 ? '+' : '−') + Math.abs(m) + ' %' : ''].filter(Boolean).join(' · ');
}

export function quote(host: HTMLElement | null, x: Ctx, ctx: Record<string, unknown>): void {
    const id = String(ctx['calculatorId'] ?? '');
    if (!host || !/^qc_[0-9A-Za-z]{26}$/.test(id)) return collapse(host);
    void x.get<{ ok?: boolean; calculator?: Def }>('quotes/' + id).then((r) => {
        const def = r.status === 200 ? r.data.calculator : undefined;
        if (!def) return collapse(host);
        render(shadow(host, CSS), def, x);
    });
}

function render(root: ShadowRoot, def: Def, x: Ctx): void {
    const cur = def.currency || 'COP';
    const fields = def.definition.fields ?? [];
    const packages = def.definition.packages ?? [];
    const cap = def.capture;
    const cta = cap.cta || 'Ver mi cotización';
    const errs: Record<string, HTMLElement> = {};
    const steps: HTMLFieldSetElement[] = [];
    const fill = h('span', { class: 'fl' });
    const count = h('span', { class: 'sm', 'aria-live': 'polite' });
    const progress = h('div', { class: 'pg' }, h('span', { class: 'tr' }, fill), count);
    const form = h('form', { class: 'q', novalidate: true }, h('h2', null, def.name), def.definition.intro ? h('p', { class: 'hp' }, def.definition.intro) : null, progress);
    const err = (key: string): HTMLElement => (errs[key] = h('p', { class: 'e', id: uid('e'), 'aria-live': 'polite' }));
    const step = (key: string, type: string, legend: string, icon: string | undefined, ...kids: (Node | null)[]): HTMLElement => {
        const e = err(key);
        const s = h('fieldset', { 'aria-describedby': e.id, 'data-t': type }, h('legend', null, icon ? icon + ' ' : '', legend), ...kids, e);
        steps.push(s);
        form.append(s);
        return e;
    };
    const cards = (name: string, multi: boolean, opts: Opt[], plus: string, first?: boolean): HTMLElement[] =>
        opts.map((o, i) => {
            const p = tag(o, cur, plus);
            return h(
                'label',
                { class: 'o' },
                h('input', { type: multi ? 'checkbox' : 'radio', name, value: o.value, checked: !!first && !i }),
                o.icon ? h('span', { class: 'i', 'aria-hidden': 'true' }, o.icon) : null,
                h('span', { class: 't' }, o.label, o.description ? h('small', null, o.description) : null),
                p ? h('span', { class: 'p' }, p) : null,
            );
        });

    if (packages.length) {
        step('answers.package', 'select', 'Elige un paquete', undefined, ...cards('package', false, packages.map((p) => ({ value: p.key, label: p.label, icon: p.icon, description: p.description, price: p.price })), '', true));
    }
    for (const f of fields) {
        const help = f.help ? h('p', { class: 'hp' }, f.help) : null;
        if (f.type === 'number') {
            const inp = h('input', { name: f.key, type: 'number', inputmode: 'decimal', min: f.min ?? 0, max: f.max ?? null, step: f.step ?? 'any', value: f.default ?? '', 'aria-label': f.label });
            const bump = (d: number): HTMLButtonElement => {
                const b = h('button', { type: 'button', class: 's', 'aria-label': d > 0 ? 'Más' : 'Menos' }, d > 0 ? '+' : '−');
                b.onclick = () => {
                    const n = Math.max((Number(inp.value.replace(',', '.')) || 0) + d * (f.step || 1), f.min ?? 0);
                    inp.value = String(Math.round((f.max != null ? Math.min(n, f.max) : n) * 100) / 100);
                };
                return b;
            };
            const per = f.unit_price ? h('p', { class: 'hp' }, money(f.unit_price, cur) + ' por ' + (f.unit || 'unidad')) : null;
            inp.setAttribute('aria-describedby', step('answers.' + f.key, 'number', f.label, f.icon, help, per, h('div', { class: 'n' }, bump(-1), h('span', { class: 'w' }, inp, f.unit ? h('span', { class: 'u' }, f.unit) : null), bump(1))).id);
        } else {
            step('answers.' + f.key, f.type, f.label, f.icon, help, ...cards(f.key, f.type === 'checkbox', f.options ?? [], '+ '));
        }
    }
    if (cap.required) {
        const kids: Node[] = [];
        for (const k of cap.fields) {
            const meta = LABELS[k];
            if (!meta) continue;
            const fid = uid('c');
            const e = err('fields.' + k);
            kids.push(h('label', { class: 'l', for: fid }, meta[0]), h('input', { id: fid, name: 'c_' + k, type: meta[1], autocomplete: meta[2], 'aria-describedby': e.id }), e);
        }
        for (const c of cap.consents ?? []) kids.push(h('label', { class: 'ck' }, h('input', { type: 'checkbox', name: 'consent', value: c.channel }), h('span', null, c.label)));
        step('contact', 'contact', cap.title || '¿A dónde te enviamos tu cotización?', undefined, ...kids);
    }
    const top = h('p', { class: 'e', role: 'alert' });
    const back = h('button', { type: 'button', class: 's' }, 'Atrás');
    const next = h('button', { type: 'button' }, 'Siguiente');
    const btn = h('button', { type: 'submit' }, cta);
    form.append(top, h('div', { class: 'nv' }, back, next, btn));
    progress.hidden = steps.length < 2;

    let at = 0;
    const show = (n: number, focus?: boolean): void => {
        at = Math.max(0, Math.min(n, steps.length - 1));
        steps.forEach((s, k) => (s.hidden = k !== at));
        fill.style.width = ((at + 1) / steps.length) * 100 + '%';
        count.textContent = 'Paso ' + (at + 1) + ' de ' + steps.length;
        back.hidden = !at;
        next.hidden = at === steps.length - 1;
        btn.hidden = !next.hidden;
        if (focus) steps[at]?.querySelector('input')?.focus();
    };
    // Lo que se puede revisar sin el hub: una opción elegida y un número dentro de sus límites.
    const check = (s: HTMLFieldSetElement): boolean => {
        const t = s.dataset['t'];
        const i = s.querySelector('input') as HTMLInputElement;
        const v = Number(i.value.replace(',', '.'));
        const msg =
            t === 'number'
                ? i.value.trim() === '' || isNaN(v) || v < Number(i.min) || (i.max !== '' && v > Number(i.max))
                    ? 'Escribe un número' + (i.max ? ' entre ' + i.min + ' y ' + i.max : '') + '.'
                    : ''
                : t === 'select' && !s.querySelector(':checked')
                  ? 'Elige una opción para seguir.'
                  : '';
        if (t !== 'contact') (s.lastChild as HTMLElement).textContent = msg;
        return !msg;
    };
    steps.forEach((s, k) =>
        s.addEventListener('change', (ev) => {
            if (s.dataset['t'] === 'select' && (ev.target as HTMLInputElement).type === 'radio' && k < steps.length - 1) setTimeout(() => at === k && check(s) && show(k + 1, true), 200);
        }),
    );
    next.onclick = () => check(steps[at] as HTMLFieldSetElement) && show(at + 1, true);
    back.onclick = () => show(at - 1, true);
    form.addEventListener('keydown', (ev) => {
        if (ev.key === 'Enter' && !next.hidden) {
            ev.preventDefault();
            next.click();
        }
    });
    show(0);

    form.addEventListener('submit', (ev) => {
        ev.preventDefault();
        const fd = new FormData(form);
        const answers: Record<string, unknown> = {};
        if (packages.length) answers['package'] = fd.get('package');
        for (const f of fields) {
            if (f.type === 'checkbox') answers[f.key] = fd.getAll(f.key);
            else {
                const v = String(fd.get(f.key) ?? '').trim();
                answers[f.key] = v === '' ? null : f.type === 'number' ? Number(v.replace(',', '.')) : v;
            }
        }
        const contact: Record<string, string> = {};
        for (const k of cap.fields) contact[k] = String(fd.get('c_' + k) ?? '').trim();
        const consents: Record<string, boolean> = {};
        for (const c of cap.consents ?? []) consents[c.channel] = fd.getAll('consent').indexOf(c.channel) >= 0;
        for (const k in errs) (errs[k] as HTMLElement).textContent = '';
        top.textContent = '';
        btn.disabled = true;
        void x
            .post<Result>('quotes/' + def.id + '/estimate', { anonymous_id: x.aid(), page_url: x.pageUrl(), answers, fields: cap.required ? contact : {}, consents, utm: utm() })
            .then((r) => {
                btn.disabled = false;
                if (r.status === 200 && r.data.estimate) return done(root, form, def, r.data);
                const list = r.data.errors ?? {};
                let bad = false;
                for (const k in list) {
                    const el = errs[k];
                    if (el) bad = !!(el.textContent = String(list[k]?.[0] ?? ''));
                }
                top.textContent = r.status === 429 ? 'Espera un momento y vuelve a intentarlo.' : bad ? 'Revisa los campos marcados.' : String((r.data as { message?: string }).message || 'No pudimos calcular tu cotización. Inténtalo más tarde.');
                // Vuelve al primer paso con un error, con el foco en su campo.
                const k = steps.findIndex((s) => Array.from(s.querySelectorAll('.e')).some((e) => e.textContent));
                if (k >= 0) show(k, true);
            });
    });
    root.append(form);
}

function done(root: ShadowRoot, form: HTMLFormElement, def: Def, res: Result): void {
    const wa = safeUrl(res.whatsapp);
    const again = h('button', { type: 'button', class: 's' }, 'Cotizar de nuevo');
    const box = h(
        'div',
        { class: 'q r', role: 'status', tabindex: -1 },
        h('p', { class: 'sm' }, 'Tu precio estimado'),
        h('p', { class: 'big' }, res.estimate?.text ?? ''),
        h('h2', null, def.name),
        res.lines && res.lines.length ? h('ul', { class: 'ln sm' }, ...res.lines.map((l) => h('li', null, l.label))) : null,
        res.message ? h('p', null, res.message) : null,
        res.note ? h('p', { class: 'sm' }, res.note) : null,
        wa ? h('a', { class: 'wa', href: wa, target: '_blank', rel: 'noopener' }, 'Hablar por WhatsApp') : null,
        again,
    );
    again.onclick = () => {
        box.replaceWith(form);
        form.querySelector<HTMLElement>('fieldset:not([hidden]) input')?.focus();
    };
    form.replaceWith(box);
    box.focus();
}

register({ quote: (host, _w, x, ctx) => quote(host, x, ctx) });
