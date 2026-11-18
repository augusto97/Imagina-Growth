import type { Channel, ReviewSettings, ReviewSettingsResponse, SurveyCategory, SurveyKind, SurveySettings } from './types';

/* Lógica pura de la pantalla de reseñas (se prueba con Vitest). */

export const CHANNELS: Channel[] = ['whatsapp', 'email', 'sms'];

/** Problemas del borrador de ajustes antes de guardar (claves de i18n en reviews.settings.problems). */
export function settingsProblems(s: ReviewSettings, options: ReviewSettingsResponse['options'] | undefined): string[] {
    const out: string[] = [];
    const r = s.request;
    if (r.channels.length === 0) out.push('noChannel');
    if (r.enabled && r.channels.includes('whatsapp') && !r.whatsapp_template_id) out.push('whatsappTemplate');
    if (r.enabled && r.channels.includes('sms') && !r.sms_template_id) out.push('smsTemplate');
    if (r.kind === 'utility' && (r.reclassify_reason ?? '').trim().length < 10) out.push('reclassifyReason');
    if (s.google_review_url && !/^https:\/\/\S+$/.test(s.google_review_url.trim())) out.push('googleUrl');
    if (r.days < 0 || r.days > 120 || r.category_days.some((c) => c.days < 0 || c.days > 120)) out.push('days');
    if (new Set(r.category_days.map((c) => c.category_id)).size !== r.category_days.length || r.category_days.some((c) => c.category_id === '')) out.push('categories');
    if (options && r.kind !== 'marketing' && !options.can_reclassify) out.push('reclassifyRole');
    return out;
}

/** Normaliza el borrador para la API (textos recortados, vacíos como null). */
export function settingsPayload(s: ReviewSettings): ReviewSettings {
    const blank = (v: string | null) => (v === null || v.trim() === '' ? null : v.trim());
    return {
        ...s,
        request: {
            ...s.request,
            reclassify_reason: s.request.kind === 'utility' ? blank(s.request.reclassify_reason) : null,
            email_template_id: blank(s.request.email_template_id),
            whatsapp_template_id: blank(s.request.whatsapp_template_id),
            sms_template_id: blank(s.request.sms_template_id),
        },
        google_review_url: blank(s.google_review_url),
        reply_author: blank(s.reply_author),
    };
}

/** Cambia un canal de lugar en el orden de preferencia. */
export function moveChannel(channels: Channel[], index: number, delta: -1 | 1): Channel[] {
    const target = index + delta;
    if (target < 0 || target >= channels.length) return channels;
    const next = [...channels];
    const a = next[index];
    const b = next[target];
    if (a === undefined || b === undefined) return channels;
    next[index] = b;
    next[target] = a;
    return next;
}

/** Activa o quita un canal conservando el orden. */
export function toggleChannel(channels: Channel[], channel: Channel): Channel[] {
    return channels.includes(channel) ? channels.filter((c) => c !== channel) : [...channels, channel];
}

/** Categoría de una nota (igual que NpsSurvey::category en el hub). */
export function surveyCategory(kind: SurveyKind, score: number): SurveyCategory {
    if (kind === 'nps') return score >= 9 ? 'promoter' : score >= 7 ? 'passive' : 'detractor';
    return score >= 4 ? 'satisfied' : score === 3 ? 'neutral' : 'unsatisfied';
}

/** Orden fijo de las categorías en la leyenda y los colores (el color sigue a la categoría). */
export const CATEGORY_ORDER: Record<SurveyKind, SurveyCategory[]> = {
    nps: ['promoter', 'passive', 'detractor'],
    csat: ['satisfied', 'neutral', 'unsatisfied'],
};

/** Borrador del formulario de la encuesta (lo que se envía a la API). */
export type SurveyDraft = Omit<SurveySettings, 'id' | 'kind' | 'scale'>;

export function surveyDraft(s: SurveySettings): SurveyDraft {
    return {
        status: s.status,
        question: s.question,
        comment_label: s.comment_label,
        thanks: s.thanks,
        after: s.after,
        days: s.days,
        channels: [...s.channels],
        email_template_id: s.email_template_id,
        whatsapp_template_id: s.whatsapp_template_id,
        sms_template_id: s.sms_template_id,
        alert_threshold: s.alert_threshold,
        alert_emails: [...s.alert_emails],
    };
}

export function surveyProblems(d: SurveyDraft, kind: SurveyKind): string[] {
    const out: string[] = [];
    if (d.question.trim() === '') out.push('question');
    if (d.thanks.trim() === '') out.push('thanks');
    if (d.channels.length === 0) out.push('noChannel');
    if (d.status === 'active' && d.channels.includes('whatsapp') && !d.whatsapp_template_id) out.push('whatsappTemplate');
    if (d.status === 'active' && kind === 'csat' && d.channels.includes('email') && !d.email_template_id) out.push('csatTemplate');
    if (d.alert_emails.some((e) => !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e))) out.push('alertEmails');
    return out;
}

/** Lista de correos escrita con comas, espacios o saltos de línea. */
export function parseEmails(text: string): string[] {
    return [...new Set(text.split(/[\s,;]+/).map((e) => e.trim().toLowerCase()).filter((e) => e !== ''))];
}
