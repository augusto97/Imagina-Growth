import type { Touch } from './types';
import { cut, getCookie, setCookie, store, uuid4 } from './util';

export const AID = '_igr_aid';
const SID = '_igr_sid';
const SRC = '_igr_src';
const SE = '_igr_se';
const DAY = 86400;
const CLICKS = ['fbclid', 'gclid', 'gbraid', 'wbraid', 'ttclid', 'msclkid'];
const UTMS = ['source', 'medium', 'campaign', 'content', 'term', 'id'];

function host(url: string): string {
    try {
        return new URL(url).hostname.replace(/^www\./, '');
    } catch {
        return '';
    }
}

/** El toque de esta visita: UTM, click IDs o un referrer externo. null = directo o navegación interna. */
export function currentTouch(href: string = location.href, referrer: string = document.referrer): Touch | null {
    const url = new URL(href);
    const p = url.searchParams;
    const t: Touch = {};
    const utm: Record<string, string> = {};
    const clicks: Record<string, string> = {};
    for (const k of UTMS) {
        const v = p.get('utm_' + k);
        if (v) utm[k] = cut(v, 200);
    }
    for (const k of CLICKS) {
        const v = p.get(k);
        if (v && /^[\w.\-~]{1,500}$/.test(v)) clicks[k] = v;
    }
    const external = !!referrer && host(referrer) !== '' && host(referrer) !== host(href);
    if (!Object.keys(utm).length && !Object.keys(clicks).length && !external) return null;
    if (Object.keys(utm).length) t.utm = utm;
    if (Object.keys(clicks).length) t.click_ids = clicks;
    if (external) t.referrer = cut(referrer, 300);
    t.landing = cut(url.origin + url.pathname, 300);
    t.at = Date.now();
    return t;
}

export interface Sources {
    f?: Touch;
    l?: Touch;
}

export function readSources(): Sources {
    try {
        const raw = getCookie(SRC);
        const v = raw ? (JSON.parse(raw) as Sources) : {};
        return v && typeof v === 'object' ? v : {};
    } catch {
        return {};
    }
}

/**
 * Actualiza `_igr_src`: el primer contacto no se sobrescribe en 90 días y el
 * último contacto es el último no directo (tracker/CLAUDE.md §4).
 */
export function updateSources(touch: Touch | null, domain?: string, nowMs: number = Date.now()): Sources {
    const s = readSources();
    const landing = { landing: cut(location.origin + location.pathname, 300), at: nowMs };
    if (!s.f || (s.f.at ?? 0) < nowMs - 90 * DAY * 1000) s.f = touch ?? landing;
    if (touch) s.l = touch;
    setCookie(SRC, JSON.stringify(s), 90 * DAY, domain);
    return s;
}

/** ID anónimo: cookie de 395 días con respaldo en localStorage; cada uno repara al otro. */
export function anonymousId(domain?: string): { id: string; created: boolean } {
    const valid = (v: string | null): v is string => !!v && /^[A-Za-z0-9-]{8,64}$/.test(v);
    const fromCookie = getCookie(AID);
    const fromStore = store('l', AID);
    const id = valid(fromCookie) ? fromCookie : valid(fromStore) ? fromStore : uuid4();
    const created = !valid(fromCookie) && !valid(fromStore);
    if (fromCookie !== id) setCookie(AID, id, 395 * DAY, domain);
    if (fromStore !== id) store('l', AID, id);
    return { id, created };
}

/**
 * Sesión de 30 min deslizantes. Empieza una nueva si cambia `utm_campaign` o
 * llega un click ID distinto (tracker/CLAUDE.md §4).
 */
export function session(touch: Touch | null, domain?: string): { id: string; entry: Touch | null } {
    let id = getCookie(SID);
    let entry: Touch | null = null;
    try {
        entry = JSON.parse(store('s', SE) ?? 'null') as Touch | null;
    } catch {
        entry = null;
    }
    const changed =
        !!touch && !!entry &&
        ((touch.utm?.campaign ?? '') !== (entry.utm?.campaign ?? '') ||
            CLICKS.some((k) => !!touch.click_ids?.[k] && touch.click_ids[k] !== entry?.click_ids?.[k]));
    if (!id || !/^[A-Za-z0-9_.-]{4,64}$/.test(id) || changed) {
        id = 's_' + uuid4().replace(/-/g, '').slice(0, 20);
        entry = touch;
        store('s', SE, JSON.stringify(entry));
    }
    setCookie(SID, id, 1800, domain);
    return { id, entry };
}

/** Renueva la sesión (30 min deslizantes) en cada evento. */
export function touchSession(id: string, domain?: string): void {
    setCookie(SID, id, 1800, domain);
}

/** Cookies de anuncios, solo con consentimiento de marketing. Crea `_fbc` desde `fbclid` (formato de Meta). */
export function adCookies(domain?: string): Record<string, string> {
    const out: Record<string, string> = {};
    const fbclid = new URL(location.href).searchParams.get('fbclid');
    let fbc = getCookie('_fbc');
    if (fbclid && /^[\w.\-~]{1,500}$/.test(fbclid) && (!fbc || !fbc.endsWith('.' + fbclid))) {
        fbc = 'fb.1.' + Date.now() + '.' + fbclid;
        setCookie('_fbc', fbc, 90 * DAY, domain);
    }
    const fbp = getCookie('_fbp');
    const ga = getCookie('_ga');
    const ttp = getCookie('_ttp');
    if (fbp && /^fb\.\d\.\d+\.[\w-]+$/.test(fbp)) out['fbp'] = fbp;
    if (fbc && /^fb\.\d\.\d+\.[\w.\-~]+$/.test(fbc)) out['fbc'] = cut(fbc, 500);
    const m = ga ? ga.match(/^GA\d\.\d+\.(\d+\.\d+)$/) : null;
    if (m && m[1]) out['ga_client_id'] = m[1];
    if (ttp && /^[\w.-]{6,128}$/.test(ttp)) out['ttp'] = ttp;
    return out;
}
