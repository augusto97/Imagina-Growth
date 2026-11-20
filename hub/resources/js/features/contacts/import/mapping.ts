import type { ConsentChannel, ImportConsent, ImportField, LegalBasis } from '../api';

/* Reglas del asistente de importación (ImportController::store las vuelve a validar en el servidor). */

export const STANDARD_FIELDS = [
    'email',
    'phone',
    'first_name',
    'last_name',
    'city',
    'region_code',
    'country',
    'birthday',
    'renewal_at',
    'tags',
    'consent_email',
    'consent_whatsapp',
    'consent_sms',
] as const;

const CUSTOM_KEY = /^[a-z0-9_]{1,40}$/;

/** «Código postal» → `codigo_postal`, la clave de un atributo personalizado. */
export function customKey(raw: string): string {
    return raw
        .normalize('NFD')
        .replace(/\p{Mn}/gu, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '')
        .slice(0, 40);
}

export function isCustom(field: string): field is `custom:${string}` {
    return field.startsWith('custom:');
}

export type MappingProblem = { code: 'needsIdentifier' } | { code: 'duplicate'; field: string; headers: string[] } | { code: 'invalidCustom'; header: string };

/** Problemas del mapeo: falta correo o teléfono, un campo asignado dos veces o una clave personalizada inválida. */
export function mappingProblems(mapping: Readonly<Record<string, ImportField>>): MappingProblem[] {
    const problems: MappingProblem[] = [];
    const fields = Object.values(mapping);
    if (!fields.includes('email') && !fields.includes('phone')) {
        problems.push({ code: 'needsIdentifier' });
    }
    const byField = new Map<string, string[]>();
    for (const [header, field] of Object.entries(mapping)) {
        if (field === 'ignore') {
            continue;
        }
        if (isCustom(field) && !CUSTOM_KEY.test(field.slice('custom:'.length))) {
            problems.push({ code: 'invalidCustom', header });
            continue;
        }
        byField.set(field, [...(byField.get(field) ?? []), header]);
    }
    for (const [field, headers] of byField) {
        // Varias columnas de etiquetas se pueden sumar; el resto de campos no.
        if (headers.length > 1 && field !== 'tags') {
            problems.push({ code: 'duplicate', field, headers });
        }
    }
    return problems;
}

/** Mapeo inicial: lo sugerido por el hub, sin repetir un campo en dos columnas. */
export function initialMapping(headers: readonly string[], suggested: Readonly<Record<string, ImportField>>): Record<string, ImportField> {
    const used = new Set<string>();
    const out: Record<string, ImportField> = {};
    for (const header of headers) {
        const field = suggested[header] ?? 'ignore';
        if (field !== 'ignore' && field !== 'tags' && used.has(field)) {
            out[header] = 'ignore';
            continue;
        }
        used.add(field);
        out[header] = field;
    }
    return out;
}

export interface ConsentDraft {
    mode: 'declared' | 'none' | null;
    channels: ConsentChannel[];
    source: string;
    date: string;
    legalBasis?: LegalBasis | '';
}

export type ConsentProblem = 'chooseMode' | 'channels' | 'source' | 'legalBasis' | 'date' | 'futureDate';

/** La declaración de consentimiento es obligatoria (cumplimiento.md §3.1): o se declara completa o se elige «sin consentimiento». */
export function consentProblems(draft: ConsentDraft, today: string): ConsentProblem[] {
    if (draft.mode === null) {
        return ['chooseMode'];
    }
    if (draft.mode === 'none') {
        return [];
    }
    const problems: ConsentProblem[] = [];
    if (draft.channels.length === 0) {
        problems.push('channels');
    }
    if (draft.source.trim().length < 10) {
        problems.push('source');
    }
    if (!draft.legalBasis) {
        problems.push('legalBasis');
    }
    if (!/^\d{4}-\d{2}-\d{2}$/.test(draft.date)) {
        problems.push('date');
    } else if (draft.date > today) {
        problems.push('futureDate');
    }
    return problems;
}

export function consentPayload(draft: ConsentDraft): ImportConsent | null {
    return draft.mode === 'declared'
        ? { channels: draft.channels, source: draft.source.trim(), date: draft.date, ...(draft.legalBasis ? { legal_basis: draft.legalBasis } : {}) }
        : null;
}

/** Columnas de consentimiento por fila del mapeo (necesitan la declaración: solo cuenta el «sí» de cada fila). */
export function perRowConsentFields(mapping: Readonly<Record<string, ImportField>>): ImportField[] {
    return Object.values(mapping).filter((f) => f === 'consent_email' || f === 'consent_whatsapp' || f === 'consent_sms');
}
