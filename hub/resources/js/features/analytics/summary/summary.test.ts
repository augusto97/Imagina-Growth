import { describe, expect, it } from 'vitest';
import type { Ability } from '@/api/types';
import i18n from '@/lib/i18n';
import type { ServicesResults } from '@/features/services/types';
import type { SummaryData } from '../api';
import { channelsTop, funnelDrop, messagingRevenue, trendConclusion } from '../conclusions';
import { makeFormatters } from '../format';
import { resultsAudience, visibleDashboards, visibleGroups } from '../results';
import { leadSourceName, servicesDrop, servicesNarrative, storeNarrative } from './narrative';

const fmt = makeFormatters('es-CO', 'COP');
const t = i18n.getFixedT('es-CO');

function summary(patch: Partial<SummaryData> = {}): SummaryData {
    return {
        business_type: 'store',
        sales: { totals: { revenue: 143_435_100, orders: 273 }, compare: { revenue: 178_900_000, orders: 354 } },
        series: [],
        generated: { total: 1_764_900, orders: 3, store_total: 143_435_100, store_orders: 273, share_pct: 1.2, previous: { total: 0, orders: 0, share_pct: 0 }, change_pct: null },
        by_strategy: [{ key: 'abandoned_cart', strategy: 'abandoned_cart', bucket: 'other_flows', name: 'Recupera carritos abandonados', revenue: 1_764_900, orders: 3, previous: 0, conversions: null, messages: 0 }],
        by_channel: [{ channel: 'whatsapp', revenue: 1_764_900, orders: 3, previous: 0 }],
        insights: [],
        ...patch,
    };
}

describe('Resumen de Resultados: la frase que lo explica', () => {
    it('dice cuánto vendiste frente al periodo anterior, cuánto generó la plataforma y con qué', () => {
        const items = storeNarrative(summary(), fmt, (c) => t(`analytics.summary.channels.${c}`));
        expect(items.map((i) => i.key)).toEqual(['salesDown', 'generated', 'topVia']);
        const text = items.map((i) => t(`analytics.summary.say.${i.key}`, i.values)).join(' ');
        expect(text).toContain('menos que en el periodo anterior');
        expect(text).toContain('«Recupera carritos abandonados»');
        expect(text).toContain('WhatsApp');
        expect(text).not.toMatch(/analytics\./);
    });

    it('sin dinero generado invita a prender una estrategia y sin comparación no inventa el cambio', () => {
        const items = storeNarrative(summary({ sales: { totals: { revenue: 1000 }, compare: null }, generated: { ...summary().generated, total: 0, previous: null } }), fmt, (c) => c);
        expect(items.map((i) => i.key)).toEqual(['sales', 'generatedNone']);
    });

    it('servicios: contactos, ganados con la palabra de la vertical, lo que trajo la plataforma y dónde se caen', () => {
        const results = {
            kpis: { leads: { value: 46, previous: 25, change_pct: 84 } },
            won: { count: 1, value: '1230000.00' },
            attribution: { platform_value: '1230000.00', groups: [{ key: 'forms', label: 'Formularios', count: 1, value: '1230000.00' }] },
            leads: {
                funnel: {
                    stages: [
                        { stage: 'new', label: 'Nuevo', kind: 'open', leads: 46, rate: 1 },
                        { stage: 'attended', label: 'Asistió', kind: 'open', leads: 12, rate: 0.26 },
                        { stage: 'won', label: 'Paciente', kind: 'won', leads: 1, rate: 0.02 },
                        { stage: 'lost', label: 'No continuó', kind: 'lost', leads: 7, rate: 0.15 },
                    ],
                },
            },
        } as unknown as ServicesResults;
        const items = servicesNarrative(results, fmt);
        expect(items.map((i) => i.key)).toEqual(['leadsUp', 'won', 'platformVia', 'servicesDrop']);
        const won = items[1];
        expect(t(`analytics.summary.say.${won?.key}`, { ...won?.values, context: 'health' })).toContain('paciente');
        expect(servicesDrop(results.leads.funnel.stages, fmt)?.values).toMatchObject({ from: 'Asistió', to: 'Paciente' });
    });

    it('el nombre de una fuente de contactos usa el del servidor y si llega la clave cruda, el nuestro', () => {
        expect(leadSourceName({ channel: 'organic_search', label: 'búsqueda en Google' }, () => 'x')).toBe('Búsqueda en Google');
        expect(leadSourceName({ channel: 'paid_social', label: 'paid_social' }, () => 'anuncios en redes')).toBe('Anuncios en redes');
    });
});

describe('Tableros: la conclusión de cada gráfica', () => {
    it('una métrica frente a antes o, sin comparación, su mejor día', () => {
        expect(trendConclusion('Ventas', 80, 100, [], 'day', (v) => String(v), fmt)?.key).toBe('trendDown');
        expect(trendConclusion('Ventas', 100.2, 100, [], 'day', (v) => String(v), fmt)?.key).toBe('trendFlat');
        const best = trendConclusion('Ventas', 30, null, [{ date: '2026-11-02', value: 10 }, { date: '2026-11-09', value: 20 }], 'day', (v) => String(v), fmt);
        expect(best?.key).toBe('trendBest_day');
        expect(best?.values.amount).toBe('20');
        expect(trendConclusion('Ventas', 0, null, [], 'day', String, fmt)).toBeNull();
    });

    it('el paso del embudo donde más se pierden y el canal que más vende', () => {
        const steps = [
            { step: 'sessions', value: 1000, rate_from_previous: 1, rate_from_start: 1 },
            { step: 'product_views', value: 600, rate_from_previous: 0.6, rate_from_start: 0.6 },
            { step: 'add_to_carts', value: 60, rate_from_previous: 0.1, rate_from_start: 0.06 },
            { step: 'checkouts', value: 30, rate_from_previous: 0.5, rate_from_start: 0.03 },
        ] as const;
        expect(funnelDrop([...steps], (s) => s, fmt)?.values).toMatchObject({ from: 'product_views', to: 'add_to_carts' });
        const channels = [
            { channel: 'direct', revenue: 100 },
            { channel: 'organic_search', revenue: 300 },
        ] as Parameters<typeof channelsTop>[0];
        expect(channelsTop(channels, (c) => c, fmt)?.values.channel).toBe('organic_search');
    });

    it('cada conclusión tiene su texto', () => {
        const totals = { sent: 10, revenue: 0, roi: null } as Parameters<typeof messagingRevenue>[0];
        for (const key of ['trendUp', 'trendDown', 'trendFlat', 'trendBest_day', 'trendBest_week', 'trendBest_month', 'funnelDrop', 'channelsTop', messagingRevenue(totals, fmt)?.key ?? '']) {
            expect(i18n.exists(`analytics.conclusion.${key}`, { lng: 'es-CO' })).toBe(true);
        }
    });
});

describe('Resultados por tipo de negocio', () => {
    const base = { abilities: [] as Ability[], vertical: null };

    it('una clínica no ve los tableros de tienda y una tienda no ve el de contactos', () => {
        const services = resultsAudience({ ...base, business_type: 'services', capabilities: { store: false, services: true } });
        expect(services).toEqual({ store: false, services: true });
        expect(visibleDashboards(services)).toEqual(['services', 'messaging']);
        const store = resultsAudience({ ...base, business_type: 'store', capabilities: { store: true, services: false } });
        expect(visibleDashboards(store)).not.toContain('services');
        expect(visibleDashboards(store)[0]).toBe('overview');
        expect(visibleGroups(store).map((g) => g.key)).toEqual(['sales', 'customers', 'traffic', 'messages']);
    });

    it('sin ninguna señal se muestra todo', () => {
        const unknown = resultsAudience({ ...base, business_type: null, capabilities: { store: null, services: null } });
        expect(visibleDashboards(unknown)).toHaveLength(12);
    });
});
