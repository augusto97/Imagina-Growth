import type { BuiltinFieldKey, ConsentChannel, LandingContent, LandingField, LandingFieldType, LandingPageDetail, LandingPagePatch, LandingWhatsApp } from './types';

/*
 * Lógica pura del editor de páginas de captura (probada en capture.test.ts): el borrador, lo que cambia para el PATCH,
 * los campos, dónde va cada casilla de consentimiento y el color del texto del botón. Sigue las reglas de
 * `LandingSchema` y de la página pública (resources/views/capture/page.blade.php) para que la vista previa no mienta.
 */

export const BUILTIN_KEYS: readonly BuiltinFieldKey[] = ['name', 'email', 'phone', 'message', 'service'];
export const BUILTIN_TYPES: Record<BuiltinFieldKey, LandingFieldType> = { name: 'text', email: 'email', phone: 'tel', message: 'textarea', service: 'select' };
export const CUSTOM_TYPES = ['text', 'textarea', 'select', 'checkbox'] as const;
export type CustomFieldType = (typeof CUSTOM_TYPES)[number];
export const CHANNELS: readonly ConsentChannel[] = ['whatsapp', 'email', 'sms'];
export const DEFAULT_ACCENT = '#2563eb';

/** Lo que se edita en la pantalla (el PATCH manda solo lo que cambió). */
export interface LandingDraft {
    name: string;
    slug: string;
    content: Omit<LandingContent, 'logo_src' | 'image_src'>;
    fields: LandingField[];
    consent_channels: ConsentChannel[];
    indexable: boolean;
    site_id: string | null;
}

export function draftFrom(page: LandingPageDetail): LandingDraft {
    const content = { ...page.content, whatsapp: { ...page.content.whatsapp } };
    delete content.logo_src;
    delete content.image_src;
    return {
        name: page.name,
        slug: page.slug,
        content,
        fields: page.fields.map((f) => ({ ...f, options: f.options ? [...f.options] : undefined })),
        consent_channels: [...page.consent_channels],
        indexable: page.settings.indexable,
        site_id: page.site_id,
    };
}

const same = (a: unknown, b: unknown) => JSON.stringify(a) === JSON.stringify(b);

/** Solo lo que cambió frente a lo guardado (vacío: no hay nada que guardar). Las imágenes subidas no van aquí. */
export function patchFrom(draft: LandingDraft, saved: LandingDraft): LandingPagePatch {
    const patch: LandingPagePatch = {};
    if (draft.name.trim() !== saved.name) patch.name = draft.name.trim();
    if (draft.slug !== saved.slug) patch.slug = draft.slug.trim().toLowerCase();
    const content: Record<string, unknown> = {};
    const keys = ['title', 'body', 'button', 'success_title', 'success_message', 'redirect_url', 'color', 'logo_url', 'image_url'] as const;
    for (const key of keys) {
        if (!same(draft.content[key], saved.content[key])) {
            const value = draft.content[key];
            content[key] = typeof value === 'string' && key !== 'title' && value.trim() === '' ? null : value;
        }
    }
    if (!same(draft.content.whatsapp, saved.content.whatsapp)) content.whatsapp = draft.content.whatsapp;
    if (Object.keys(content).length > 0) patch.content = content;
    if (!same(draft.fields, saved.fields)) patch.fields = draft.fields.map(cleanField);
    if (!same(draft.consent_channels, saved.consent_channels)) patch.consent_channels = draft.consent_channels;
    if (draft.indexable !== saved.indexable) patch.settings = { indexable: draft.indexable };
    if (draft.site_id !== saved.site_id) patch.site_id = draft.site_id;
    return patch;
}

/** Quita lo vacío de un campo antes de guardarlo (las opciones solo van en las listas). */
export function cleanField(field: LandingField): LandingField {
    const out: LandingField = { key: field.key, label: field.label.trim(), required: field.required ?? false };
    if (isCustom(field.key) && field.type) out.type = field.type;
    if (field.placeholder && field.placeholder.trim() !== '') out.placeholder = field.placeholder.trim();
    if (fieldType(field) === 'select') out.options = (field.options ?? []).map((o) => o.trim()).filter((o) => o !== '');
    return out;
}

export function isCustom(key: string): key is `custom_${string}` {
    return key.startsWith('custom_');
}

/** El tipo con el que se pinta un campo: el de fábrica para los propios del producto, el elegido para los propios. */
export function fieldType(field: Pick<LandingField, 'key' | 'type'>): LandingFieldType {
    if (isCustom(field.key)) return field.type ?? 'text';
    return BUILTIN_TYPES[field.key];
}

/** Los campos propios del producto que todavía no están en el formulario. */
export function missingBuiltins(fields: readonly LandingField[]): BuiltinFieldKey[] {
    const used = new Set(fields.map((f) => f.key));
    return BUILTIN_KEYS.filter((k) => !used.has(k));
}

/** Una clave libre para un campo propio a partir de su nombre («¿Tienes seguro?» → custom_tienes_seguro). */
export function customKey(label: string, fields: readonly LandingField[]): `custom_${string}` {
    const slug =
        label
            .normalize('NFD')
            .replace(/[̀-ͯ]/g, '')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '_')
            .replace(/^_+|_+$/g, '')
            .slice(0, 24) || 'campo';
    const used = new Set(fields.map((f) => f.key));
    let key: `custom_${string}` = `custom_${slug}`;
    for (let i = 2; used.has(key); i++) key = `custom_${slug}_${i}`;
    return key;
}

export function moveField(fields: readonly LandingField[], index: number, step: -1 | 1): LandingField[] {
    const target = index + step;
    if (target < 0 || target >= fields.length) return [...fields];
    const out = [...fields];
    const [item] = out.splice(index, 1);
    if (item) out.splice(target, 0, item);
    return out;
}

/** El campo que necesita cada canal: correo para el correo; teléfono (WhatsApp) para WhatsApp y SMS. */
export function consentField(channel: ConsentChannel): 'email' | 'phone' {
    return channel === 'email' ? 'email' : 'phone';
}

/** Los canales que pueden llevar casilla con los campos de hoy. */
export function possibleChannels(fields: readonly LandingField[]): ConsentChannel[] {
    const keys = new Set(fields.map((f) => f.key));
    return CHANNELS.filter((c) => keys.has(consentField(c)));
}

/** Quita las casillas de los canales que se quedaron sin su campo (al borrar el correo o el teléfono). */
export function keepPossible(channels: readonly ConsentChannel[], fields: readonly LandingField[]): ConsentChannel[] {
    const possible = new Set(possibleChannels(fields));
    return channels.filter((c) => possible.has(c));
}

/** Las casillas que van justo debajo de un campo (como en la página pública). */
export function consentsAfter(field: Pick<LandingField, 'key'>, channels: readonly ConsentChannel[]): ConsentChannel[] {
    return channels.filter((c) => consentField(c) === field.key);
}

export type FieldsProblem = 'needs_contact' | 'too_many' | 'empty_label' | 'empty_options' | null;

/** Lo que impide guardar los campos (el hub lo rechazaría): sin correo ni WhatsApp, demasiados, sin nombre o una lista sin opciones. */
export function fieldsProblem(fields: readonly LandingField[], max: number): FieldsProblem {
    if (!fields.some((f) => f.key === 'email' || f.key === 'phone')) return 'needs_contact';
    if (fields.length > max) return 'too_many';
    if (fields.some((f) => f.label.trim() === '')) return 'empty_label';
    if (fields.some((f) => fieldType(f) === 'select' && (f.options ?? []).every((o) => o.trim() === ''))) return 'empty_options';
    return null;
}

/** Los párrafos del texto (separados por una línea en blanco), como los pinta la página pública. */
export function paragraphs(body: string | null | undefined): string[] {
    return (body ?? '')
        .split(/\r?\n\s*\r?\n/)
        .map((p) => p.trim())
        .filter((p) => p !== '');
}

export function isHexColor(value: string | null | undefined): value is string {
    return typeof value === 'string' && /^#[0-9a-fA-F]{6}$/.test(value);
}

/** Texto legible sobre el color de la marca: blanco o casi negro, el de más contraste (igual que la página pública). */
export function readableOn(hex: string): '#ffffff' | '#0f172a' {
    if (!isHexColor(hex)) return '#ffffff';
    const channel = (h: string) => {
        const c = parseInt(h, 16) / 255;
        return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    };
    const l = 0.2126 * channel(hex.slice(1, 3)) + 0.7152 * channel(hex.slice(3, 5)) + 0.0722 * channel(hex.slice(5, 7));
    const white = 1.05 / (l + 0.05);
    const dark = (l + 0.05) / 0.0589;
    return white >= dark ? '#ffffff' : '#0f172a';
}

/** Lo que dice el chat de WhatsApp al abrirse: el mensaje de alta y el código de atribución. */
export function waMessage(message: string | null | undefined, code: string | null | undefined): string {
    const text = (message ?? '').trim();
    return code ? `${text}${text === '' ? '' : ' '}(ref: ${code})` : text;
}

/** Una URL https válida (la redirección y las imágenes externas solo aceptan https). */
export function isHttpsUrl(value: string | null | undefined): boolean {
    if (!value) return false;
    try {
        return new URL(value).protocol === 'https:';
    } catch {
        return false;
    }
}

/** Dirección de la página: minúsculas, números y guiones, de 3 a 80 (como `LandingSchema::slugProblem`). */
export function slugValid(slug: string): boolean {
    return /^[a-z0-9](?:[a-z0-9-]{1,78})[a-z0-9]$/.test(slug) && !slug.includes('--');
}

export function slugify(text: string): string {
    return text
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 70);
}

/** Una conversión (envíos ÷ visitas) o null sin visitas. */
export function conversion(submissions: number, views: number): number | null {
    return views > 0 ? submissions / views : null;
}

export function emptyWhatsApp(): LandingWhatsApp {
    return { enabled: false, label: null, message: null };
}

/**
 * Lo guardado cambió en el servidor (se subió una imagen, se publicó, se guardó): el borrador toma lo nuevo en todo lo
 * que la persona no tocó y conserva lo que estaba editando.
 */
export function rebase(draft: LandingDraft, prev: LandingDraft, next: LandingDraft): LandingDraft {
    const pick = <T>(mine: T, before: T, after: T): T => (same(mine, before) ? after : mine);
    const content: Record<string, unknown> = { ...next.content };
    for (const key of Object.keys(next.content) as (keyof LandingDraft['content'])[]) {
        content[key] = pick(draft.content[key], prev.content[key], next.content[key]);
    }
    return {
        name: pick(draft.name, prev.name, next.name),
        slug: pick(draft.slug, prev.slug, next.slug),
        content: content as LandingDraft['content'],
        fields: pick(draft.fields, prev.fields, next.fields),
        consent_channels: pick(draft.consent_channels, prev.consent_channels, next.consent_channels),
        indexable: pick(draft.indexable, prev.indexable, next.indexable),
        site_id: pick(draft.site_id, prev.site_id, next.site_id),
    };
}
