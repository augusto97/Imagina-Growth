import { describe, expect, it } from 'vitest';
import { funnelSteps } from './CampaignDetailPage';
import { defaultChannel } from './CampaignsPage';
import { audienceToForm, formToAudience, STEPS, stepFromParam } from './CampaignWizardPage';
import { describeWindow, hourLabel } from './sendWindow';
import { statusVariant } from './labels';
import { isoToWall, wallToIso } from './time';
import i18n from '@/lib/i18n';
import type { CampaignReport, SendWindow } from './types';

describe('hora de la campaña en la zona del espacio de trabajo', () => {
    it('convierte la hora de reloj de Bogotá y de Ciudad de México a UTC y de vuelta', () => {
        expect(wallToIso('2026-11-24T10:00', 'America/Bogota')).toBe('2026-11-24T15:00:00.000Z');
        expect(wallToIso('2026-11-24T10:00', 'America/Mexico_City')).toBe('2026-11-24T16:00:00.000Z');
        expect(isoToWall('2026-11-24T15:00:00Z', 'America/Bogota')).toBe('2026-11-24T10:00');
        expect(isoToWall(null, 'America/Bogota')).toBe('');
        expect(wallToIso('mañana', 'America/Bogota')).toBeNull();
    });
});

describe('audiencia del paso 2', () => {
    it('ida y vuelta entre el formulario y las reglas de la API', () => {
        const form = { all: false, include: ['sg_1'], exclude: ['sg_2'], excludeBuyersDays: 7, excludeMessagedDays: 3, onlyConsent: true, onlyNeverPurchased: false };
        const audience = formToAudience(form, 'whatsapp');
        expect(audience).toEqual({
            all_contacts: false,
            segments_include: ['sg_1'],
            segments_exclude: ['sg_2'],
            rules_include: [{ type: 'has_consent', channel: 'whatsapp' }],
            rules_exclude: [
                { type: 'purchased_within_days', days: 7 },
                { type: 'messaged_within_days', days: 3 },
            ],
        });
        expect(audienceToForm(audience)).toEqual(form);
        expect(formToAudience({ ...form, all: true }, 'email').segments_include).toEqual([]);
        expect(audienceToForm(null).include).toEqual([]);
        // Cuatro pasos: a quién → qué → cuándo → revisar; el enlace de antes (`?paso=channel`) abre «¿Qué les dices?».
        expect(STEPS).toEqual(['audience', 'content', 'schedule', 'review']);
        expect(stepFromParam('channel')).toBe('content');
        expect(stepFromParam(null)).toBe('audience');
        expect(stepFromParam('review')).toBe('review');
    });

    it('una campaña nueva empieza por el primer canal conectado', () => {
        expect(defaultChannel(['email', 'whatsapp'])).toBe('whatsapp');
        expect(defaultChannel(['sms'])).toBe('sms');
        expect(defaultChannel([])).toBe('email');
        expect(defaultChannel(undefined)).toBe('email');
    });
});

describe('la ventana legal en palabras', () => {
    const t = i18n.getFixedT('es-CO');
    const base: SendWindow = {
        country: 'CO', timezone: 'America/Bogota', requested_at: '2026-12-05T21:00:00Z', sends_at: '2026-12-07T12:00:00Z', moved: true, reason: 'outside_hours',
        requested_window: ['08:00', '15:00'], skipped: [{ date: '2026-12-06', reason: 'sunday', holiday: null }], holiday: null, per_contact: false,
        windows: { '1': ['07:00', '19:00'], '6': ['08:00', '15:00'] },
    };

    it('dice cuándo sale y por qué se mueve (fuera de horario el sábado y el domingo no se envía)', () => {
        const s = describeWindow(t, base, 'es-CO', 'scheduled');
        expect(s.moved).toBe(true);
        expect(t(s.titleKey, s.titleVars)).toMatch(/^Se enviará el lunes, 7 de diciembre a las 7:00/);
        expect(s.because[0]).toContain('sábado');
        expect(s.because[0]).toContain('de 8:00 a 15:00');
        expect(s.because[0]).toContain('domingo');
    });

    it('nombra el festivo y no explica nada cuando no se mueve', () => {
        const holiday = describeWindow(t, { ...base, reason: 'holiday', holiday: 'Inmaculada Concepción', skipped: [], requested_at: '2026-12-08T14:00:00Z', sends_at: '2026-12-09T12:00:00Z' }, 'es-CO', 'scheduled');
        expect(holiday.because[0]).toContain('es festivo (Inmaculada Concepción)');
        const ok = describeWindow(t, { ...base, moved: false, reason: null, skipped: [], sends_at: base.requested_at }, 'es-CO', 'now');
        expect(ok.titleKey).toBe('campaigns.window.nowOk');
        expect(ok.because).toEqual([]);
        expect(hourLabel('07:00')).toBe('7:00');
        expect(hourLabel('19:00')).toBe('19:00');
    });
});

describe('reporte', () => {
    it('el embudo usa leídos en WhatsApp y abiertos en correo, como proporción de los enviados', () => {
        const report = { delivery: { queued: 10, sent: 10, delivered: 8, read: 5, opened: 4, opened_excludes_mpp: true, clicked: 2, unsubscribed: 0, failed: 0, click_rate: 0.2 } } as CampaignReport;
        expect(funnelSteps(report, 'whatsapp').map((s) => [s.key, s.ratio])).toEqual([['sent', 1], ['delivered', 0.8], ['read', 0.5], ['clicked', 0.2]]);
        expect(funnelSteps(report, 'email')[2]).toEqual({ key: 'opened', value: 4, ratio: 0.4 });
        expect(funnelSteps({ delivery: { ...report.delivery, sent: 0 } } as CampaignReport, 'sms')[1]?.ratio).toBe(0);
    });

    it('cada estado tiene su variante de insignia (con texto, no solo color)', () => {
        expect(statusVariant('sent')).toBe('success');
        expect(statusVariant('sending')).toBe('warning');
        expect(statusVariant('draft')).toBe('outline');
    });
});
