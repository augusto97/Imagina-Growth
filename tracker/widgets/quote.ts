/*
 * Cotizador (igr-w-quote.js, M18): el slot `quote` con `{"calculatorId": "qc_…"}`.
 * Pide la definición pública (`GET /p/{clave}/quotes/{id}`), arma las preguntas y,
 * si el negocio lo pide, los datos de contacto con sus casillas sin marcar. El
 * precio lo calcula siempre el hub (`POST …/estimate`); el paquete no trae precios
 * cuando el precio se da a cambio del contacto.
 */
import { collapse, h, register, safeUrl, shadow, uid } from './lib/dom';
import type { ConsentText, Ctx } from './lib/types';

interface Opt {
    value: string;
    label: string;
}
interface QField {
    key: string;
    label: string;
    type: 'number' | 'select' | 'checkbox';
    required?: boolean;
    help?: string | null;
    unit?: string | null;
    min?: number;
    max?: number | null;
    step?: number;
    default?: number | null;
    options?: Opt[];
}
interface Def {
    id: string;
    name: string;
    definition: { fields?: QField[]; packages?: { key: string; label: string; description?: string | null }[]; note?: string | null };
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
    '.q{border:1px solid #e5e7eb;border-radius:12px;padding:18px}.f{margin:0 0 14px;border:0;padding:0}.l,legend{display:block;font-weight:600;font-size:14px;margin:0 0 4px;padding:0}' +
    '.hp{font-size:13px;color:#4b5563;margin:0 0 4px}.u{display:flex;gap:8px;align-items:center}.u input{max-width:160px}.o{display:flex;gap:8px;align-items:center;margin:4px 0;font-weight:400}' +
    '.ck{display:flex;gap:8px;align-items:flex-start;font-size:13px;margin:0 0 10px}.ck input{margin-top:3px}.r{background:#f3f4f6;border-radius:10px;padding:16px;margin:0 0 12px}' +
    '.big{font-size:26px;font-weight:700;margin:4px 0 8px}.sm{font-size:13px;color:#4b5563}.ln{list-style:none;margin:0 0 8px;padding:0;font-size:13px}.ln li{display:flex;justify-content:space-between;gap:8px}' +
    '.wa{display:inline-block;margin:0 8px 8px 0;padding:9px 16px;border-radius:8px;background:#128c3a;color:#fff;text-decoration:none;font-weight:600}';

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
    const fields = def.definition.fields ?? [];
    const packages = def.definition.packages ?? [];
    const errs: Record<string, HTMLElement> = {};
    const form = h('form', { class: 'q', novalidate: true });
    const err = (key: string): HTMLElement => (errs[key] = h('p', { class: 'e', id: uid('e'), 'aria-live': 'polite' }));
    form.append(h('h2', null, def.name));

    if (packages.length) {
        const e = err('answers.package');
        form.append(
            h('fieldset', { class: 'f' }, h('legend', null, 'Elige un paquete'), ...packages.map((p, i) => h('label', { class: 'o' }, h('input', { type: 'radio', name: 'package', value: p.key, checked: i === 0 }), h('span', null, p.label, p.description ? h('span', { class: 'hp' }, ' · ' + p.description) : null))), e),
        );
    }
    for (const f of fields) {
        const e = err('answers.' + f.key);
        const fid = uid('q');
        const help = f.help ? h('p', { class: 'hp' }, f.help) : null;
        if (f.type === 'number') {
            form.append(
                h('div', { class: 'f' }, h('label', { class: 'l', for: fid }, f.label), help,
                    h('div', { class: 'u' }, h('input', { id: fid, name: f.key, type: 'number', inputmode: 'decimal', min: f.min ?? 0, max: f.max ?? null, step: f.step ?? 'any', value: f.default ?? '', required: !!f.required, 'aria-describedby': e.id }), f.unit ? h('span', null, f.unit) : null), e),
            );
        } else if (f.type === 'select') {
            form.append(h('div', { class: 'f' }, h('label', { class: 'l', for: fid }, f.label), help, h('select', { id: fid, name: f.key, 'aria-describedby': e.id }, ...(f.options ?? []).map((o) => h('option', { value: o.value }, o.label))), e));
        } else {
            form.append(h('fieldset', { class: 'f' }, h('legend', null, f.label), help, ...(f.options ?? []).map((o) => h('label', { class: 'o' }, h('input', { type: 'checkbox', name: f.key, value: o.value }), o.label)), e));
        }
    }
    const cap = def.capture;
    if (cap.required) {
        form.append(h('p', { class: 'l' }, cap.title || '¿A dónde te enviamos tu cotización?'));
        for (const k of cap.fields) {
            const meta = LABELS[k];
            if (!meta) continue;
            const fid = uid('c');
            const e = err('fields.' + k);
            form.append(h('div', { class: 'f' }, h('label', { class: 'l', for: fid }, meta[0]), h('input', { id: fid, name: 'c_' + k, type: meta[1], autocomplete: meta[2], 'aria-describedby': e.id }), e));
        }
        for (const c of cap.consents ?? []) form.append(h('label', { class: 'ck' }, h('input', { type: 'checkbox', name: 'consent', value: c.channel }), h('span', null, c.label)));
    }
    const top = h('p', { class: 'e', role: 'alert' });
    const btn = h('button', { type: 'submit' }, cap.cta || 'Ver mi cotización');
    form.append(top, btn);

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
                let first: HTMLElement | null = null;
                for (const k in list) {
                    const el = errs[k];
                    if (el) {
                        el.textContent = String(list[k]?.[0] ?? '');
                        first = first ?? el;
                    }
                }
                top.textContent = r.status === 429 ? 'Espera un momento y vuelve a intentarlo.' : first ? 'Revisa los campos marcados.' : String((r.data as { message?: string }).message || 'No pudimos calcular tu cotización. Inténtalo más tarde.');
                const input = first ? (form.querySelector('[aria-describedby="' + first.id + '"]') as HTMLElement | null) : null;
                input?.focus();
            });
    });
    root.append(form);
}

function done(root: ShadowRoot, form: HTMLFormElement, def: Def, res: Result): void {
    const wa = safeUrl(res.whatsapp);
    const again = h('button', { type: 'button', class: 's' }, 'Cotizar de nuevo');
    const box = h(
        'div',
        { class: 'q', role: 'status', tabindex: -1 },
        h('h2', null, def.name),
        h('div', { class: 'r' }, h('p', { class: 'sm' }, 'Tu precio estimado'), h('p', { class: 'big' }, res.estimate?.text ?? ''),
            res.lines && res.lines.length ? h('ul', { class: 'ln' }, ...res.lines.map((l) => h('li', null, h('span', null, l.label)))) : null,
            res.note ? h('p', { class: 'sm' }, res.note) : null),
        res.message ? h('p', null, res.message) : null,
        wa ? h('a', { class: 'wa', href: wa, target: '_blank', rel: 'noopener' }, 'Hablar por WhatsApp') : null,
        again,
    );
    again.addEventListener('click', () => {
        box.replaceWith(form);
        (form.querySelector('input,select') as HTMLElement | null)?.focus();
    });
    form.replaceWith(box);
    box.focus();
}

register({ quote: (host, _w, x, ctx) => quote(host, x, ctx) });
