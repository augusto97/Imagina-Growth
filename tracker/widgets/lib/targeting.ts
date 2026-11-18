/*
 * Reglas de muestra de los widgets (api-connector.md §8.3), sin DOM para probarlas solas:
 * página, tipo de página, dispositivo, UTM, referido, valor del carrito, frecuencia,
 * datos del visitante y variante determinista por anonymous_id.
 */
import type { Targeting, Variant, Visitor, WidgetCfg } from './types';

const DAY = 86400000;

/** Widgets que tapan la página: solo uno a la vez. */
export const MODAL = ['popup', 'spin_wheel', 'two_step', 'quiz', 'referral_welcome'];
/** Widgets flotantes que maneja el runtime (los demás van en slots). */
export const OVERLAY = MODAL.concat(['bar', 'teaser', 'wa_widget']);

export interface Env {
    pageType: string;
    path: string;
    device: 'mobile' | 'tablet' | 'desktop';
    /** UTM de esta URL y del último contacto, en minúsculas. */
    utm: Record<string, string>[];
    ref: string | null;
    cart: number;
}

/** `*` comodín; compara sin importar la barra final. */
export function glob(pattern: string, path: string): boolean {
    const norm = (s: string): string => (s.length > 1 ? s.replace(/\/+$/, '') : s);
    const re = new RegExp('^' + norm(pattern).split('*').map((p) => p.replace(/[.+?^${}()|[\]\\]/g, '\\$&')).join('.*') + '$');
    return re.test(norm(path)) || re.test(path);
}

export function device(width: number, coarse: boolean): Env['device'] {
    return width < 768 ? 'mobile' : coarse && width <= 1024 ? 'tablet' : 'desktop';
}

/** FNV-1a de 32 bits. */
export function hash(s: string): number {
    let x = 0x811c9dc5;
    for (let i = 0; i < s.length; i++) {
        x ^= s.charCodeAt(i);
        x = Math.imul(x, 0x01000193) >>> 0;
    }
    return x >>> 0;
}

/**
 * Variante por peso. Con `seed` (anonymous_id + widget) siempre la misma; sin
 * consentimiento, la ya elegida en esta sesión (`stored`) o una al azar.
 */
export function pickVariant(w: WidgetCfg, seed: string | null, stored?: string, rnd: () => number = Math.random): Variant | null {
    const vs = (w.variants ?? []).filter((v) => v && typeof v.key === 'string');
    if (!vs.length) return null;
    const keep = stored ? vs.find((v) => v.key === stored) : undefined;
    if (keep) return keep;
    let weights = vs.map((v) => Math.max(0, Number(v.weight ?? 1) || 0));
    let total = weights.reduce((a, b) => a + b, 0);
    if (!total) {
        weights = vs.map(() => 1);
        total = vs.length;
    }
    let n = (seed ? (hash(seed) % 10000) / 10000 : rnd()) * total;
    for (let i = 0; i < vs.length; i++) {
        n -= weights[i] ?? 0;
        if (n < 0) return vs[i] as Variant;
    }
    return vs[vs.length - 1] as Variant;
}

/** Vistas (`v`), última vista (`t`), cierre (`c`), envío (`s`) y variante (`k`). */
export interface Freq {
    v?: number;
    t?: number;
    c?: number;
    s?: number;
    k?: string;
}

/**
 * Frecuencia: quien ya envió no lo vuelve a ver; `max_views` en total;
 * `cooldown_days` desde la última vista. Sin regla, un cierre lo oculta un día.
 * El widget de WhatsApp no tiene frecuencia.
 */
export function freqAllows(f: Freq | undefined, t: Targeting | undefined, kind: string, now: number): boolean {
    if (kind === 'wa_widget' || !f) return true;
    if (f.s) return false;
    const fq = t?.frequency;
    if (fq?.max_views && (f.v ?? 0) >= fq.max_views) return false;
    if (fq && fq.cooldown_days != null) return !(f.t && fq.cooldown_days > 0 && now - f.t < fq.cooldown_days * DAY);
    return !(f.c && now - f.c < DAY);
}

/** ¿Hace falta `visitor.json` para decidir? */
export function needsVisitor(w: WidgetCfg): boolean {
    const t = w.targeting ?? {};
    return !!((t.countries && t.countries.length) || (t.segments && t.segments.length) || t.hide_for_subscribers || (t.new_visitors !== null && t.new_visitors !== undefined) || w.kind === 'referral_welcome');
}

/** Reglas que se resuelven en el navegador. */
export function staticMatch(w: WidgetCfg, env: Env): boolean {
    const t = w.targeting ?? {};
    if (env.pageType === 'checkout') return false;
    if (t.page_types && t.page_types.length && t.page_types.indexOf(env.pageType) < 0) return false;
    const inc = t.pages?.include ?? [];
    if (inc.length && !inc.some((p) => glob(p, env.path))) return false;
    if ((t.pages?.exclude ?? []).some((p) => glob(p, env.path))) return false;
    if (t.devices && t.devices.length && t.devices.indexOf(env.device) < 0 && !(env.device === 'tablet' && t.devices.indexOf('desktop') >= 0)) return false;
    if (t.utm) {
        for (const k in t.utm) {
            const want = String(t.utm[k]).toLowerCase();
            if (!env.utm.some((u) => u[k] === want)) return false;
        }
    }
    if ((t.referral === true || w.kind === 'referral_welcome') && !env.ref) return false;
    if (t.cart_value_min != null && Number(t.cart_value_min) > 0 && env.cart < Number(t.cart_value_min)) return false;
    return true;
}

/** Reglas que necesitan `visitor.json`. Sin datos se cierran las que piden país, segmento o visitante recurrente. */
export function visitorMatch(w: WidgetCfg, v: Visitor): boolean {
    const t = w.targeting ?? {};
    if (t.countries && t.countries.length && !(v.country && t.countries.some((c) => c.toUpperCase() === String(v.country).toUpperCase()))) return false;
    if (t.segments && t.segments.length && !(v.segments ?? []).some((s) => t.segments?.indexOf(s) !== -1)) return false;
    if (t.new_visitors === true && v.returning !== false) return false;
    if (t.new_visitors === false && v.returning !== true) return false;
    if (t.hide_for_subscribers) {
        const subs = v.subscribed ?? {};
        const channels = (w.content?.consents ?? []).map((c) => c.channel);
        if ((channels.length ? channels : Object.keys(subs)).some((c) => subs[c])) return false;
    }
    if (w.kind === 'referral_welcome' && v.known) return false;
    return true;
}
