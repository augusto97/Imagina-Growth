import type { WaTemplate, WaTemplateComponent, WaTemplateForm } from './api';

/* Reglas del editor de plantillas de WhatsApp: las mismas que valida el hub (WaTemplateBuilder). */

/** Palabras que delatan marketing en una plantilla de utilidad (cumplimiento.md §5.1). Igual que en el hub. */
export const MARKETING_WORDS = ['descuento', 'oferta', 'promo', 'promoción', 'cupón', 'cupon', 'gratis', 'rebaja', 'aprovecha', 'compra ya', 'compra ahora', 'black friday', 'liquidación', '%', 'últimas unidades', '2x1', 'novedades', 'lanzamiento'];

/** Variables {{n}} del texto, sin repetir y en orden. */
export function variables(text: string): number[] {
    const found = new Set<number>();
    for (const m of text.matchAll(/\{\{\s*(\d+)\s*\}\}/g)) {
        found.add(Number(m[1]));
    }
    return [...found].sort((a, b) => a - b);
}

/** ¿Las variables van seguidas desde {{1}}? */
export function variablesInOrder(text: string): boolean {
    return variables(text).every((n, i) => n === i + 1);
}

export function marketingWords(category: WaTemplate['category'] | undefined, form: Pick<WaTemplateForm, 'header' | 'body' | 'footer'>): string[] {
    if (category === 'MARKETING' || category === undefined) return [];
    const text = `${form.header.format === 'TEXT' ? form.header.text : ''} ${form.body.text} ${form.footer.text}`.toLocaleLowerCase('es');
    return MARKETING_WORDS.filter((w) => text.includes(w));
}

/** Pone los ejemplos en las variables para la vista previa. */
export function fill(text: string, examples: string[]): string {
    return text.replace(/\{\{\s*(\d+)\s*\}\}/g, (_, n: string) => examples[Number(n) - 1] || `{{${n}}}`);
}

/** Nombre válido para Meta: minúsculas, números y guion bajo. */
export function slugName(value: string): string {
    return value
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '')
        .slice(0, 512);
}

export function emptyForm(account: string): WaTemplateForm {
    return {
        account,
        name: '',
        language: 'es',
        category: 'MARKETING',
        header: { format: 'NONE', text: '', example: '' },
        body: { text: '', examples: [] },
        footer: { text: '' },
        buttons: [],
    };
}

/** De los componentes de Meta al formulario (para editar una plantilla existente). */
export function formFromTemplate(t: WaTemplate, linksBase: string): WaTemplateForm {
    const by = (type: WaTemplateComponent['type']) => t.components.find((c) => c.type === type);
    const header = by('HEADER');
    const body = by('BODY');
    const bodyExamples = (body?.example?.['body_text'] as string[][] | undefined)?.[0] ?? [];
    return {
        header: {
            format: header?.format ?? 'NONE',
            text: header?.format === 'TEXT' ? (header.text ?? '') : '',
            example: header?.format === 'TEXT' ? String((header.example?.['header_text'] as string[] | undefined)?.[0] ?? '') : String((header?.example?.['header_handle'] as string[] | undefined)?.[0] ?? ''),
        },
        body: { text: body?.text ?? '', examples: bodyExamples.map(String) },
        footer: { text: by('FOOTER')?.text ?? '' },
        buttons: (by('BUTTONS')?.buttons ?? []).map((b) => ({
            type: b.type,
            text: b.text ?? '',
            url: b.type === 'URL' && !(b.url ?? '').startsWith(`${linksBase}/c/`) ? (b.url ?? '') : '',
            tracked: b.type === 'URL' && (b.url ?? '').startsWith(`${linksBase}/c/`),
            phone: b.phone_number ?? '',
            example: typeof b.example === 'string' ? b.example : '',
        })),
    };
}

/** Errores del formulario antes de enviarlo a Meta (claves de i18n). */
export function formErrors(form: WaTemplateForm, creating: boolean): Record<string, string> {
    const errors: Record<string, string> = {};
    if (creating && !/^[a-z0-9_]+$/.test(form.name ?? '')) errors['name'] = 'channels.wa.errors.name';
    if (form.body.text.trim() === '') errors['body.text'] = 'channels.wa.errors.bodyRequired';
    else if (!variablesInOrder(form.body.text)) errors['body.text'] = 'channels.wa.errors.order';
    const missing = variables(form.body.text).some((n) => (form.body.examples[n - 1] ?? '').trim() === '');
    if (missing) errors['body.examples'] = 'channels.wa.errors.examples';
    if (form.header.format === 'TEXT' && form.header.text.length > 60) errors['header.text'] = 'channels.wa.errors.headerLong';
    if (form.footer.text.length > 60) errors['footer.text'] = 'channels.wa.errors.footerLong';
    if (form.buttons.filter((b) => b.type === 'URL').length > 2) errors['buttons'] = 'channels.wa.errors.tooManyUrls';
    form.buttons.forEach((b, i) => {
        if (b.type !== 'COPY_CODE' && b.text.trim() === '') errors[`buttons.${i}.text`] = 'channels.wa.errors.buttonText';
        if (b.type === 'URL' && !b.tracked && !/^https?:\/\//.test(b.url)) errors[`buttons.${i}.url`] = 'channels.wa.errors.buttonUrl';
    });
    return errors;
}
