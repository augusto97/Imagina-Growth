import { describe, expect, it } from 'vitest';
import { filterQuery } from './api';
import { moveChannel, parseEmails, settingsPayload, settingsProblems, surveyCategory, surveyProblems, toggleChannel } from './logic';
import type { ReviewSettings, SurveySettings } from './types';

const base: ReviewSettings = {
    request: {
        enabled: true,
        trigger: 'completed',
        days: 7,
        category_days: [],
        channels: ['whatsapp', 'email'],
        email_template_id: null,
        whatsapp_template_id: 'tpl_1',
        sms_template_id: null,
        skip_reviewed: true,
        kind: 'marketing',
        reclassify_reason: null,
        link_days: 60,
    },
    moderation: { mode: 'manual' },
    google_review_url: null,
    publish_to_store: true,
    reply_author: null,
};

describe('ajustes de reseñas', () => {
    it('pide la plantilla de WhatsApp si el canal está activo', () => {
        expect(settingsProblems(base, undefined)).toEqual([]);
        expect(settingsProblems({ ...base, request: { ...base.request, whatsapp_template_id: null } }, undefined)).toContain('whatsappTemplate');
    });

    it('reclasificar exige justificación y el rol', () => {
        const utility = { ...base, request: { ...base.request, kind: 'utility' as const, reclassify_reason: 'corto' } };
        const options = { templates: { email: [], whatsapp: [], sms: [] }, categories: [], loyalty_active: false, rewards: { review: 0, media: 0 }, photos_supported: true, can_reclassify: false };
        expect(settingsProblems(utility, options)).toEqual(['reclassifyReason', 'reclassifyRole']);
    });

    it('valida la URL de Google y las categorías repetidas', () => {
        const s = { ...base, google_review_url: 'http://g.page/x', request: { ...base.request, category_days: [{ category_id: '12', days: 3 }, { category_id: '12', days: 5 }] } };
        expect(settingsProblems(s, undefined)).toEqual(['googleUrl', 'categories']);
    });

    it('limpia el borrador para la API', () => {
        const out = settingsPayload({ ...base, google_review_url: '  ', reply_author: ' Pinturas La 14 ', request: { ...base.request, reclassify_reason: 'ignorada' } });
        expect(out.google_review_url).toBeNull();
        expect(out.reply_author).toBe('Pinturas La 14');
        expect(out.request.reclassify_reason).toBeNull();
    });

    it('reordena y activa canales', () => {
        expect(moveChannel(['whatsapp', 'email'], 1, -1)).toEqual(['email', 'whatsapp']);
        expect(moveChannel(['whatsapp', 'email'], 0, -1)).toEqual(['whatsapp', 'email']);
        expect(toggleChannel(['whatsapp'], 'email')).toEqual(['whatsapp', 'email']);
        expect(toggleChannel(['whatsapp', 'email'], 'whatsapp')).toEqual(['email']);
    });

    it('arma la consulta de filtros sin vacíos', () => {
        expect(filterQuery({ status: 'pending', rating: '', q: '  ', page: 1 })).toBe('?status=pending');
        expect(filterQuery({ rating: 2, media: true, flagged: true, page: 3 })).toBe('?rating=2&media=1&flagged=1&page=3');
    });
});

describe('encuestas', () => {
    it('clasifica NPS y CSAT como el hub', () => {
        expect([0, 6, 7, 8, 9, 10].map((s) => surveyCategory('nps', s))).toEqual(['detractor', 'detractor', 'passive', 'passive', 'promoter', 'promoter']);
        expect([1, 2, 3, 4, 5].map((s) => surveyCategory('csat', s))).toEqual(['unsatisfied', 'unsatisfied', 'neutral', 'satisfied', 'satisfied']);
    });

    it('valida la encuesta activa', () => {
        const s: SurveySettings = {
            id: 'sv_1', kind: 'csat', status: 'active', question: '¿Qué tal?', comment_label: '', thanks: 'Gracias', scale: { min: 1, max: 5 },
            after: 'completed', days: 3, channels: ['email'], email_template_id: null, whatsapp_template_id: null, sms_template_id: null, alert_threshold: 2, alert_emails: ['malo'],
        };
        const { id: _i, kind: _k, scale: _s, ...draft } = s;
        expect(surveyProblems(draft, 'csat')).toEqual(['csatTemplate', 'alertEmails']);
        expect(parseEmails('A@x.co, b@y.co\nA@x.co')).toEqual(['a@x.co', 'b@y.co']);
    });
});
