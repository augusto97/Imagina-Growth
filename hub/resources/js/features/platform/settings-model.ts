import type { SettingField, SettingValue } from './settings-api';

/* Lógica de la pantalla Plataforma → Ajustes (F17), sin React: qué sección abrir, qué se envía al guardar y cómo se muestra un valor. */

/** Secciones de los ajustes guardados (las mismas del registro del hub) y las tres herramientas que reemplazan comandos. */
export const SETTING_SECTIONS = ['mail', 'ses', 'meta', 'google', 'tiktok', 'ai', 'security', 'push', 'billing', 'monitor', 'retention', 'fx'] as const;
export const TOOL_SECTIONS = ['admins', 'holidays', 'wa_rates'] as const;
export type SectionKey = (typeof SETTING_SECTIONS)[number] | (typeof TOOL_SECTIONS)[number];

/** Grupos del menú de la pantalla, en el orden en que los recorre quien instala. */
export const SECTION_GROUPS: { key: string; sections: SectionKey[] }[] = [
    { key: 'email', sections: ['mail', 'ses'] },
    { key: 'apps', sections: ['meta', 'google', 'tiktok', 'ai'] },
    { key: 'access', sections: ['security', 'admins', 'push'] },
    { key: 'business', sections: ['billing', 'wa_rates', 'holidays', 'fx'] },
    { key: 'operation', sections: ['monitor', 'retention'] },
];

export function isSettingSection(key: string): key is (typeof SETTING_SECTIONS)[number] {
    return (SETTING_SECTIONS as readonly string[]).includes(key);
}

/** La sección que pide la dirección (`?seccion=`), o el correo si no existe. */
export function sectionFromParam(raw: string | null): SectionKey {
    const all: readonly string[] = [...SETTING_SECTIONS, ...TOOL_SECTIONS];
    return raw !== null && all.includes(raw) ? (raw as SectionKey) : 'mail';
}

/** Lo que la persona escribió en un campo y todavía no guardó. `null` = vaciar (vuelve al valor del servidor). */
export type Draft = Record<string, string | boolean | null>;

/** El texto que muestra un campo que no es secreto antes de editarlo. */
export function initialText(field: SettingField): string {
    return field.value === null ? '' : String(field.value);
}

/** El estado de una casilla (sí o no): lo del panel o, si no hay, lo del servidor. */
export function effectiveBool(field: SettingField, draft: Draft): boolean {
    const pending = draft[field.key];
    if (typeof pending === 'boolean') return pending;
    if (pending === null) return field.fallback === true;
    if (typeof field.value === 'boolean') return field.value;
    return field.fallback === true;
}

/**
 * Lo que se envía al hub: solo lo que cambió. Vacío (o null) quita el valor del panel; los números van como números.
 */
export function buildPayload(fields: SettingField[], draft: Draft): Record<string, SettingValue> {
    const out: Record<string, SettingValue> = {};
    for (const field of fields) {
        if (!(field.key in draft)) continue;
        const raw = draft[field.key];
        if (raw === null || raw === undefined) {
            out[field.key] = null;
            continue;
        }
        if (typeof raw === 'boolean') {
            out[field.key] = raw;
            continue;
        }
        const text = field.type === 'secret' ? raw : raw.trim();
        if (text === '') {
            out[field.key] = null;
        } else if (field.type === 'int' || field.type === 'number') {
            const n = Number(text.replace(',', '.'));
            out[field.key] = Number.isFinite(n) ? n : text;
        } else {
            out[field.key] = text;
        }
    }
    return out;
}

/** Quita del borrador lo que no cambia nada (lo mismo que ya está guardado). */
export function pruneDraft(fields: SettingField[], draft: Draft): Draft {
    const out: Draft = {};
    for (const field of fields) {
        if (!(field.key in draft)) continue;
        const raw = draft[field.key];
        if (raw === undefined) continue;
        if (field.type === 'secret') {
            // Un secreto vacío no cambia nada salvo que se haya pedido quitarlo (null).
            if (raw === null ? field.source === 'panel' : raw !== '') out[field.key] = raw;
            continue;
        }
        const current = field.value;
        if (raw === null) {
            if (current !== null) out[field.key] = null;
        } else if (typeof raw === 'boolean') {
            if (raw !== current) out[field.key] = raw;
        } else if (raw.trim() !== initialText(field)) {
            out[field.key] = raw;
        }
    }
    return out;
}

/** Un token al azar (hexadecimal) para pegarlo en otra parte antes de guardarlo: el hub nunca lo devuelve después. */
export function randomToken(bytes = 24, source: Pick<Crypto, 'getRandomValues'> = crypto): string {
    const buf = new Uint8Array(bytes);
    source.getRandomValues(buf);
    return Array.from(buf, (b) => b.toString(16).padStart(2, '0')).join('');
}

/** Cómo se lee un valor del servidor en el panel (sí/no, números con miles, texto tal cual). */
export function displayValue(value: SettingValue, yes: string, no: string, locale: string): string {
    if (value === null || value === '') return '';
    if (typeof value === 'boolean') return value ? yes : no;
    if (typeof value === 'number') return new Intl.NumberFormat(locale).format(value);
    return value;
}

/** Errores del hub por campo (`values.<nombre>`) → por nombre. */
export function fieldErrors(errors: Record<string, string>): Record<string, string> {
    const out: Record<string, string> = {};
    for (const [key, message] of Object.entries(errors)) {
        if (key.startsWith('values.')) out[key.slice(7)] = message;
    }
    return out;
}

/** Una tarifa escrita por la persona («0,0125» o «0.0125»), o null si no es un número de 0 a 1. */
export function parseWaRate(input: string): number | null {
    const clean = input.trim().replace(',', '.');
    if (clean === '') return null;
    const n = Number(clean);
    return Number.isFinite(n) && n >= 0 && n <= 1 ? n : null;
}
