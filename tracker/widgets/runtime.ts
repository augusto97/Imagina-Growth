/*
 * Runtime de widgets (igr-widgets.js, docs/api-connector.md §8.3). Lo carga el tracker
 * cuando `widgets.json` trae widgets o la página tiene slots. Decide qué mostrar
 * (página, dispositivo, UTM, referido, carrito, frecuencia, visitante y variante),
 * arma los disparadores y descarga el paquete de cada tipo solo cuando hace falta.
 * Nunca corre en el checkout y nunca lanza errores hacia la página.
 */
import { MODAL, OVERLAY, device, freqAllows, needsVisitor, pickVariant, staticMatch, visitorMatch, type Env, type Freq } from './lib/targeting';
import type { ConsentText, Ctx, Live, Renderer, Res, Visitor, WidgetCfg } from './lib/types';

interface RW {
    igrConfig?: { widgetsUrl?: string; widgetsJs?: string; currency?: string; waRef?: { enabled?: boolean; format?: string; widgets?: boolean } };
    igrPage?: { type?: string };
    igrUser?: { token?: string };
    igr?: { push: (...c: unknown[]) => number; consent?: () => { analytics?: boolean }; flush?: () => void };
    __igrWidgets?: { widgets?: WidgetCfg[]; consents?: ConsentText[] };
    __igrW?: { r: Record<string, Renderer>; x?: Ctx; started?: boolean };
}

const W = window as unknown as RW;
const FREQ = '_igr_wf';

/** Paquete que trae el renderizador de cada tipo de widget o slot. */
export const CHUNKS: Record<string, string> = {
    popup: 'capture',
    bar: 'capture',
    teaser: 'capture',
    two_step: 'capture',
    spin_wheel: 'wheel',
    quiz: 'quiz',
    referral_welcome: 'capture',
    form: 'capture',
    wa_widget: 'wa',
    reco: 'commerce',
    bis: 'commerce',
    points: 'commerce',
    banner: 'commerce',
    reviews: 'reviews',
    loyalty: 'loyalty',
    thankyou: 'survey',
    quote: 'quote',
};

function cookie(name: string): string | null {
    const m = document.cookie.match('(?:^|; )' + name + '=([^;]*)');
    try {
        return m ? decodeURIComponent(m[1] ?? '') : null;
    } catch {
        return null;
    }
}

function reg(): NonNullable<RW['__igrW']> {
    return (W.__igrW = W.__igrW ?? { r: {} });
}

function consented(): boolean {
    try {
        return !!W.igr?.consent?.().analytics;
    } catch {
        return false;
    }
}

/** Frecuencia en localStorage con consentimiento de analítica; si no, solo en esta sesión. */
function freqStore(): Storage | null {
    try {
        return consented() ? localStorage : sessionStorage;
    } catch {
        return null;
    }
}
function freqAll(): Record<string, Freq> {
    try {
        const v = JSON.parse(freqStore()?.getItem(FREQ) ?? '{}') as Record<string, Freq>;
        return v && typeof v === 'object' ? v : {};
    } catch {
        return {};
    }
}

function touches(): { utm?: Record<string, string>; referral_code?: string }[] {
    try {
        const s = JSON.parse(cookie('_igr_src') ?? '{}') as Record<string, { utm?: Record<string, string>; referral_code?: string }>;
        return [s['l'], s['f']].filter(Boolean) as { utm?: Record<string, string>; referral_code?: string }[];
    } catch {
        return [];
    }
}

function urlRef(): string | null {
    const r = new URL(location.href).searchParams.get('ref');
    return r && /^[A-Za-z0-9_-]{3,32}$/.test(r) ? r : null;
}

export function env(): Env {
    const url = new URL(location.href);
    const now: Record<string, string> = {};
    url.searchParams.forEach((v, k) => {
        if (k.indexOf('utm_') === 0) now[k.slice(4)] = v.toLowerCase();
    });
    const lower = (u?: Record<string, string>): Record<string, string> => {
        const o: Record<string, string> = {};
        for (const k in u ?? {}) o[k] = String(u?.[k]).toLowerCase();
        return o;
    };
    let coarse = false;
    try {
        coarse = matchMedia('(pointer:coarse)').matches;
    } catch {
        /* sin matchMedia */
    }
    return {
        pageType: W.igrPage?.type ?? 'other',
        path: location.pathname,
        device: device(innerWidth, coarse),
        utm: [now].concat(touches().map((t) => lower(t.utm))),
        ref: urlRef() ?? touches().map((t) => t.referral_code).filter(Boolean)[0] ?? null,
        cart: Number(cookie('_igr_ct')) || 0,
    };
}

/** Contexto que se comparte con los paquetes. */
export function makeCtx(base: string): Ctx {
    const cfg = W.igrConfig ?? {};
    const data = W.__igrWidgets ?? {};
    const qs = (q: Record<string, unknown> = {}): string => {
        const p: string[] = [];
        for (const k in q) {
            const v = q[k];
            if (v !== undefined && v !== null && v !== '') p.push(encodeURIComponent(k) + '=' + encodeURIComponent(String(v)));
        }
        return p.length ? '?' + p.join('&') : '';
    };
    const parse = <T>(r: Response): Promise<Res<T>> =>
        r
            .json()
            .catch(() => null)
            .then((d) => ({ status: r.status, data: (d ?? {}) as T }));
    const fail = <T>(): Res<T> => ({ status: 0, data: {} as T });
    const x: Ctx = {
        base,
        currency: cfg.currency ?? 'COP',
        pageType: W.igrPage?.type ?? 'other',
        aid: () => (consented() ? cookie('_igr_aid') : null),
        token: () => W.igrUser?.token,
        ref: () => env().ref,
        track: (name, w) => {
            try {
                W.igr?.push(['track', name, { widget_id: w.id.slice(0, 64), variant: w.variant ? w.variant.slice(0, 32) : null, kind: w.kind.slice(0, 40) }]);
            } catch {
                /* sin tracker */
            }
        },
        mark: (w, what) => {
            const all = freqAll();
            const f: Freq = all[w.id] ?? {};
            const now = Date.now();
            if (what === 'view') {
                f.v = (f.v ?? 0) + 1;
                f.t = now;
                if (w.variant) f.k = w.variant;
            } else if (what === 'close') f.c = now;
            else f.s = now;
            all[w.id] = f;
            try {
                freqStore()?.setItem(FREQ, JSON.stringify(all));
            } catch {
                /* sin almacenamiento */
            }
            if (what === 'close' && MODAL.indexOf(w.kind) >= 0) busy = false;
        },
        get: <T>(path: string, q?: Record<string, unknown>) => fetch(base + path + qs(q), { credentials: 'omit' }).then((r) => parse<T>(r), () => fail<T>()),
        post: <T>(path: string, body: Record<string, unknown>) =>
            fetch(base + path, { method: 'POST', body: JSON.stringify(body), credentials: 'omit', headers: { 'Content-Type': 'text/plain;charset=UTF-8' } }).then((r) => parse<T>(r), () => fail<T>()),
        pageUrl: () => (consented() ? location.href : location.origin + location.pathname),
        widget: (id) => (data.widgets ?? []).find((w) => w && w.id === id),
        live: (w): Live => {
            const aid = x.aid();
            const v = pickVariant(w, aid ? aid + ':' + w.id : null, freqAll()[w.id]?.k);
            return { id: w.id, kind: w.kind, variant: v ? v.key : null, content: { ...(w.content ?? {}), ...(v?.content ?? {}) }, design: { ...(w.design ?? {}), ...(v?.design ?? {}) }, raw: w };
        },
        consents: Array.isArray(data.consents) ? data.consents : [],
        waRef: cfg.waRef,
        flush: () => {
            try {
                W.igr?.push(['flush']);
            } catch {
                /* sin tracker */
            }
        },
    };
    return x;
}

let x: Ctx;
let busy = false;
const shown: Record<string, boolean> = {};
const loading: Record<string, Promise<void>> = {};

function chunkUrl(name: string): string {
    const js = W.igrConfig?.widgetsJs ?? '';
    return js.replace(/igr-widgets\.js/, 'igr-w-' + name + '.js');
}

/** Descarga el paquete del tipo (una vez) si su renderizador no está registrado. */
export function need(kind: string): Promise<Renderer | undefined> {
    const have = reg().r[kind];
    if (have) return Promise.resolve(have);
    const name = CHUNKS[kind];
    if (!name) return Promise.resolve(undefined);
    loading[name] =
        loading[name] ??
        new Promise<void>((ok) => {
            const s = document.createElement('script');
            s.async = true;
            s.src = chunkUrl(name);
            s.onload = s.onerror = () => ok();
            document.head.appendChild(s);
        });
    return loading[name].then(() => reg().r[kind]);
}

function run(kind: string, host: HTMLElement | null, live: Live | null, ctx: Record<string, unknown>): Promise<boolean> {
    return need(kind).then((fn) => {
        if (!fn) return false;
        try {
            fn(host, live, x, ctx);
            return true;
        } catch {
            return false;
        }
    });
}

/** Muestra un widget flotante (o al hacer clic en `[data-igr-open]`). */
export function show(w: WidgetCfg, forced = false): void {
    const modal = MODAL.indexOf(w.kind) >= 0;
    if ((shown[w.id] && !forced) || (modal && busy)) return;
    shown[w.id] = true;
    if (modal) busy = true;
    const live = x.live(w);
    void run(w.kind, null, live, {}).then((ok) => {
        if (ok) {
            x.mark(live, 'view');
            x.track('widget.shown', live);
        } else if (modal) busy = false;
    });
}

let visitorP: Promise<Visitor> | undefined;
function visitor(): Promise<Visitor> {
    const aid = x.aid();
    visitorP = visitorP ?? x.get<Visitor>('visitor.json', aid ? { aid } : {}).then((r) => (r.status === 200 && r.data ? r.data : {}));
    return visitorP;
}

/** Disparadores: tiempo, scroll, intención de salida (escritorio) o de volver atrás (celular), inmediato o clic. */
function arm(w: WidgetCfg, fire: () => void): void {
    const tr = w.targeting?.trigger ?? {};
    const val = Number(tr.value) || 0;
    let once = false;
    const go = (): void => {
        if (!once) {
            once = true;
            fire();
        }
    };
    if (tr.type === 'click') return;
    if (tr.type === 'time') setTimeout(go, val * 1000);
    else if (tr.type === 'scroll') {
        const onScroll = (): void => {
            const max = document.documentElement.scrollHeight - innerHeight;
            if (max <= 0 || (scrollY / max) * 100 >= (val || 50)) {
                removeEventListener('scroll', onScroll);
                go();
            }
        };
        addEventListener('scroll', onScroll, { passive: true });
        onScroll();
    } else if (tr.type === 'exit_intent') {
        const start = Date.now();
        if (env().device === 'desktop') {
            document.addEventListener('mouseout', (e) => {
                if (!e.relatedTarget && e.clientY <= 0 && Date.now() - start > 3000) go();
            });
        } else {
            // Celular: subir rápido después de haber bajado (camino a la barra de direcciones o a «atrás»).
            let maxY = 0;
            let lastY = scrollY;
            let lastT = Date.now();
            addEventListener(
                'scroll',
                () => {
                    const y = scrollY;
                    const t = Date.now();
                    maxY = Math.max(maxY, y);
                    if (t - start > 3000 && maxY > innerHeight * 0.5 && lastY - y > innerHeight * 0.2 && t - lastT < 250) go();
                    if (t - lastT > 100) {
                        lastY = y;
                        lastT = t;
                    }
                },
                { passive: true },
            );
        }
    } else setTimeout(go, 0);
}

/** Evalúa un widget flotante y, si aplica, arma su disparador. */
export function consider(w: WidgetCfg, e: Env = env()): void {
    if (!staticMatch(w, e) || !freqAllows(freqAll()[w.id], w.targeting, w.kind, Date.now())) return;
    if (!needsVisitor(w)) arm(w, () => show(w));
    else
        void visitor().then((v) => {
            if (visitorMatch(w, v)) arm(w, () => show(w));
        });
}

function slots(): void {
    const list = W.__igrWidgets?.widgets ?? [];
    document.querySelectorAll<HTMLElement>('[data-igr-slot]').forEach((slot) => {
        if (slot.getAttribute('data-igr-done')) return;
        slot.setAttribute('data-igr-done', '1');
        const kind = slot.getAttribute('data-igr-slot') ?? '';
        let ctx: Record<string, unknown> = {};
        try {
            ctx = JSON.parse(slot.getAttribute('data-igr-ctx') || '{}') as Record<string, unknown>;
        } catch {
            ctx = {};
        }
        if (kind === 'thankyou') {
            const sv = list.find((w) => w && w.kind === 'hdyhau_survey' && (w.slot ?? 'thankyou') === 'thankyou');
            if (sv) void run('thankyou', slot, null, { widget: sv });
            return;
        }
        if (kind === 'form' || kind === 'quiz') {
            const w = x.widget(String(ctx['widgetId'] ?? ''));
            if (!w) {
                slot.style.minHeight = '0';
                slot.hidden = true;
                return;
            }
            const live = x.live(w);
            // El slot `form` puede traer una ruleta (igr-w-wheel.js) o un formulario (igr-w-capture.js).
            const rk = kind === 'quiz' || w.kind === 'quiz' ? 'quiz' : w.kind === 'spin_wheel' ? 'spin_wheel' : 'form';
            void run(rk, slot, live, ctx).then((ok) => {
                if (ok) {
                    x.mark(live, 'view');
                    x.track('widget.shown', live);
                }
            });
            return;
        }
        if (CHUNKS[kind] && kind !== 'wallet') void run(kind, slot, null, ctx);
    });
}

export function start(): void {
    const cfg = W.igrConfig;
    const r = reg();
    if (r.started || !cfg?.widgetsUrl || (W.igrPage?.type ?? '') === 'checkout') return;
    r.started = true;
    x = r.x = makeCtx(cfg.widgetsUrl.replace(/widgets\.json(\?.*)?$/, ''));
    slots();
    const e = env();
    for (const w of W.__igrWidgets?.widgets ?? []) {
        if (w && typeof w.id === 'string' && !w.slot && OVERLAY.indexOf(w.kind) >= 0) consider(w, e);
    }
    document.addEventListener('click', (ev) => {
        const t = ev.target instanceof Element ? ev.target.closest('[data-igr-open]') : null;
        const w = t ? x.widget(t.getAttribute('data-igr-open') ?? '') : undefined;
        if (w && OVERLAY.indexOf(w.kind) >= 0) {
            ev.preventDefault();
            show(w, true);
        }
    });
}

try {
    start();
} catch {
    /* nunca hacia la página */
}
