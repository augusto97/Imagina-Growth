import { describe, expect, it } from 'vitest';
import esCO from '@/locales/es-CO/sections.json';
import entry from '@/locales/es-CO/section-entry.json';
import { isSummarySection, nextParams, SUMMARY_SECTIONS } from './api';
import { figureKey, formatFigure, sectionPrimary } from './SectionHomePage';

describe('página de entrada de una sección', () => {
    it('Configuración propone conectar la web mientras falte y después invitar al equipo', () => {
        expect(sectionPrimary('settings', { connections: { site: 'none', channels: [] } })).toEqual({ to: 'sitios?conectar=1', label: 'settings', ability: 'manageSites' });
        expect(sectionPrimary('settings', { connections: { site: 'pending', channels: [] } })?.to).toBe('sitios');
        expect(sectionPrimary('settings', { connections: { site: 'connected', channels: [] } })).toEqual({ to: 'equipo?invitar=1', label: 'settingsInvite', ability: 'manageMembers' });
        expect(sectionPrimary('home', {})).toBeNull();
    });

    it('cada acción principal tiene su texto', () => {
        const primary = esCO.sections.overview.primary as Record<string, string>;
        for (const key of ['customers', 'conversations', 'messages', 'onsite', 'results']) {
            expect(primary[sectionPrimary(key, {})?.label ?? '']).toBeTypeOf('string');
        }
        expect(primary.settingsInvite).toBeTypeOf('string');
    });

    it('las cifras se dan formato por unidad y el dinero se nombra por sección', () => {
        expect(formatFigure({ value: 1764900, unit: 'money' }, 'COP', 'es-CO')).toMatch(/1\.764\.900/);
        expect(formatFigure({ value: 1.4, unit: 'percent' }, 'COP', 'es-CO')).toMatch(/1,4/);
        expect(formatFigure({ value: 4299, unit: 'count' }, 'COP', 'es-CO')).toBe('4.299');
        expect(figureKey('messages', 'money')).toBe('sectionEntry.figures.money.messages');
        expect(figureKey('customers', 'people')).toBe('sectionEntry.figures.people');
    });

    it('cada cifra y cada «Empieza por aquí» que puede mandar el servidor tiene su texto', () => {
        const figures = entry.sectionEntry.figures as Record<string, unknown>;
        for (const key of ['people', 'customers', 'new_customers', 'at_risk', 'open_leads', 'today', 'appointments_week', 'won_month', 'waiting', 'open', 'unassigned',
            'active_flows', 'campaigns_month', 'sent_month', 'active_widgets', 'landing_pages', 'captured_month', 'reviews_month', 'money_month', 'sales_month', 'won_value', 'share',
            'open_insights', 'sites', 'channels', 'members']) {
            expect(figures[key], key).toBeTypeOf('object');
        }
        const money = figures.money as Record<string, unknown>;
        for (const section of ['conversations', 'messages', 'onsite']) {
            expect(money[section], section).toBeTypeOf('object');
        }
        const next = entry.sectionEntry.next as Record<string, Record<string, string>>;
        for (const kind of ['connect_site', 'finish_site', 'import_contacts', 'win_back', 'create_group', 'attend_today', 'first_lead', 'review_leads', 'connect_channel',
            'reply', 'assign', 'agent', 'first_automation', 'campaign', 'first_widget', 'widgets', 'first_landing', 'first_contact_form', 'landing_results', 'insights', 'dashboards', 'invite']) {
            expect(next[kind]?.action, kind).toBeTypeOf('string');
            expect(next[kind]?.why, kind).toBeTypeOf('string');
        }
    });

    it('solo las secciones con página de entrada piden sus cifras; los parámetros vacíos llegan como objeto', () => {
        expect(SUMMARY_SECTIONS).toEqual(['customers', 'conversations', 'messages', 'onsite', 'results', 'settings']);
        expect(isSummarySection('customers')).toBe(true);
        expect(isSummarySection('home')).toBe(false);
        expect(nextParams({ params: [] })).toEqual({});
        expect(nextParams({ params: { count: 3 } })).toEqual({ count: 3 });
    });
});
