import type { TFunction } from 'i18next';

/* Registro de actividad en palabras (probado con Vitest). */

/**
 * «flow.published» → «Publicó una automatización»: primero el texto propio de la acción (`audit.actions`), si no, el
 * verbo con la cosa (`activity.verbs` + `activity.entities`) y, si tampoco, la acción legible («Cambió: plan asignado»).
 */
export function actionLabel(t: TFunction, action: string): string {
    const own = t(`activity.actions.${action}`, { defaultValue: t(`audit.actions.${action}`, { defaultValue: '' }) });
    if (own !== '') return own;
    const dot = action.indexOf('.');
    const entityKey = dot > 0 ? action.slice(0, dot) : action;
    const verbKey = dot > 0 ? action.slice(dot + 1) : '';
    const entity = t(`activity.entities.${entityKey}`, { defaultValue: '' });
    const verb = verbKey !== '' ? t(`activity.verbs.${verbKey}`, { entity: entity !== '' ? entity : entityKey.replace(/_/g, ' '), defaultValue: '' }) : '';
    if (verb !== '' && entity !== '') return verb;
    return t('activity.generic', { action: (verbKey !== '' ? verbKey : entityKey).replace(/_/g, ' '), entity: entity !== '' ? entity : entityKey.replace(/_/g, ' ') });
}

/** Los campos que cambió, con su nombre («Moneda, Zona horaria»); con antes y después, los del después. */
export function changedFields(t: TFunction, changes: Record<string, unknown> | null): string {
    if (!changes) return '';
    const after = changes.after;
    const source = after !== null && typeof after === 'object' && !Array.isArray(after) ? (after as Record<string, unknown>) : changes;
    return Object.keys(source)
        .filter((k) => k !== 'before' && k !== 'updated_at')
        .map((k) => t(`activity.fields.${k}`, { defaultValue: k.replace(/_/g, ' ') }))
        .join(', ');
}

/** El día (AAAA-MM-DD en la zona del negocio) para separar el registro por días. */
export function dayOf(iso: string | null, timeZone?: string): string {
    if (!iso) return '';
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? '' : new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(d);
}
