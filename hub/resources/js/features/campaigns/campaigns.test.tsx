import { describe, expect, it } from 'vitest';
import { funnelSteps } from './CampaignDetailPage';
import { groupByMonth, monthOf } from './CampaignsPage';
import { audienceToForm, formToAudience, STEPS } from './CampaignWizardPage';
import { statusVariant } from './labels';
import { isoToWall, wallToIso } from './time';
import type { CampaignReport, CampaignSummary } from './types';

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
        expect(STEPS).toEqual(['channel', 'audience', 'content', 'schedule', 'review']);
    });
});

describe('calendario y reporte', () => {
    const summary = (id: string, send_at: string | null, started_at: string | null = null): CampaignSummary => ({
        id, name: id, channel: 'email', status: 'scheduled', send_mode: 'scheduled', send_at, started_at, finished_at: null, holdout_pct: 0,
        is_ab: false, winner_variant: null, recipients_estimate: null, cost_estimate_usd: null, created_at: null, updated_at: null,
    });

    it('agrupa fechas y campañas por mes en la zona del espacio de trabajo', () => {
        // 1 de diciembre a las 02:00 UTC todavía es 30 de noviembre en Bogotá.
        expect(monthOf('2026-12-01T02:00:00Z', 'America/Bogota')).toBe(11);
        expect(monthOf('2026-09-19', 'America/Bogota')).toBe(9);
        const months = groupByMonth(
            [{ key: 'amor', name: 'Amor y Amistad', date: '2026-09-19', ends: '2026-09-19', starts_communicating: '2026-09-05', ideas: [] }],
            [summary('a', '2026-11-27T15:00:00Z'), summary('b', null, '2026-12-01T02:00:00Z'), summary('c', null)],
            'America/Bogota',
        );
        expect(months).toHaveLength(12);
        expect(months[8]?.events.map((e) => e.key)).toEqual(['amor']);
        expect(months[10]?.campaigns.map((c) => c.id)).toEqual(['a', 'b']);
    });

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
