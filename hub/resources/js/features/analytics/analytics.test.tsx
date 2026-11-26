import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import i18n from '@/lib/i18n';
import texts from '@/locales/es-CO/analytics.json';
import { ATTRIBUTION_MODELS, DASHBOARDS, RFM_SEGMENTS } from './api';
import { slugFromPath, DASHBOARD_PATHS } from './paths';
import { StatTile } from './components/StatTile';
import { csvFilename, toCsv } from './csv';
import { delta, makeFormatters } from './format';
import { apiQuery, CHANNELS, COMPARE_MODES, DEVICES, presetRange, RANGE_PRESETS, readParams } from './params';

type Tree = { [key: string]: string | Tree };

function has(path: string): boolean {
    let node: string | Tree = texts;
    for (const part of path.split('.')) {
        if (typeof node !== 'object' || !(part in node)) {
            return false;
        }
        node = node[part] as string | Tree;
    }
    return typeof node === 'string';
}

/** Métricas del catálogo del hub (App\Analytics\Semantic\Catalog::METRICS). */
const CATALOG_METRICS = [
    'revenue', 'orders', 'aov', 'new_customers', 'returning_customers', 'cod_pending_orders', 'cod_pending_revenue', 'refunded', 'discount',
    'net_revenue', 'sessions', 'users', 'product_views', 'add_to_carts', 'checkouts', 'purchase_sessions', 'conversion_rate', 'revenue_per_visitor',
];

describe('Analítica: textos de las claves dinámicas', () => {
    it('tiene nombre y descripción para cada tablero, canal, dispositivo, rango, métrica, modelo y segmento RFM', () => {
        const missing: string[] = [];
        const need = (key: string) => {
            if (!has(key)) missing.push(key);
        };
        DASHBOARDS.forEach((d) => {
            need(`analytics.dashboards.${d}`);
            need(`analytics.descriptions.${d}`);
        });
        CHANNELS.forEach((c) => need(`analytics.channel.${c}`));
        DEVICES.forEach((d) => need(`analytics.device.${d}`));
        RANGE_PRESETS.forEach((r) => need(`analytics.range.${r}`));
        COMPARE_MODES.forEach((c) => need(`analytics.compare.${c}`));
        CATALOG_METRICS.forEach((m) => need(`analytics.metric.${m}`));
        ATTRIBUTION_MODELS.forEach((m) => {
            need(`analytics.channels.model.${m}`);
            need(`analytics.channels.modelHelp.${m}`);
        });
        RFM_SEGMENTS.forEach((s) => {
            need(`analytics.rfm.${s}.name`);
            need(`analytics.rfm.${s}.description`);
        });
        ['sessions', 'product_views', 'add_to_carts', 'checkouts', 'purchases'].forEach((s) => need(`analytics.funnel.step.${s}`));
        ['meta', 'google', 'tiktok'].forEach((p) => need(`analytics.platform.${p}`));
        expect(missing).toEqual([]);
    });
});

describe('Analítica: rutas', () => {
    it('cada tablero tiene ruta en español y se resuelve de vuelta', () => {
        for (const d of DASHBOARDS) {
            expect(slugFromPath(DASHBOARD_PATHS[d])).toBe(d);
        }
        expect(slugFromPath(undefined)).toBe('overview');
        expect(slugFromPath('no-existe')).toBeNull();
    });
});

describe('Analítica: rango de fechas en la zona del negocio', () => {
    // 2026-11-18 03:00 UTC = 17 de noviembre, 22:00 en Bogotá.
    const now = new Date('2026-11-18T03:00:00Z');

    it('calcula los presets con el «hoy» de Bogotá', () => {
        expect(presetRange('30d', 'America/Bogota', now)).toEqual({ from: '2026-10-19', to: '2026-11-17' });
        expect(presetRange('7d', 'America/Bogota', now)).toEqual({ from: '2026-11-11', to: '2026-11-17' });
        expect(presetRange('month', 'America/Bogota', now)).toEqual({ from: '2026-11-01', to: '2026-11-17' });
        expect(presetRange('last_month', 'America/Bogota', now)).toEqual({ from: '2026-10-01', to: '2026-10-31' });
    });

    it('lee la URL y arma la consulta de la API', () => {
        const p = readParams(new URLSearchParams('range=custom&from=2026-01-01&to=2026-01-31&compare=year&channel=paid_meta&device=watch'), 'America/Bogota', now);
        expect(p).toMatchObject({ range: 'custom', from: '2026-01-01', to: '2026-01-31', compare: 'year', channel: 'paid_meta', device: '' });
        expect(apiQuery(p)).toBe('from=2026-01-01&to=2026-01-31&compare=year&channel=paid_meta');
        // Un rango personalizado inválido vuelve a los últimos 30 días.
        expect(readParams(new URLSearchParams('range=custom&from=2026-02-01&to=2026-01-01'), 'America/Bogota', now).range).toBe('30d');
    });
});

describe('Analítica: CSV para Excel en español', () => {
    it('usa punto y coma, coma decimal, BOM y escapa comillas', () => {
        const csv = toCsv(
            [
                { label: 'Canal', value: (r: { c: string; v: number }) => r.c },
                { label: 'Ventas', value: (r: { c: string; v: number }) => r.v },
            ],
            [
                { c: 'Meta "Ads"; pago', v: 1234.5 },
                { c: 'Directo', v: 900 },
            ],
        );
        expect(csv.charCodeAt(0)).toBe(0xfeff);
        expect(csv.slice(1)).toBe('Canal;Ventas\r\n"Meta ""Ads""; pago";1234,5\r\nDirecto;900\r\n');
        expect(csvFilename(['Canales', 'Ventas por canal', '2026-10-01'])).toBe('canales-ventas-por-canal-2026-10-01.csv');
    });
});

describe('Analítica: cifras y comparación', () => {
    it('calcula el cambio relativo y en puntos para tasas', () => {
        expect(delta(120, 100, 'money')).toBeCloseTo(0.2);
        expect(delta(0.03, 0.02, 'ratio')).toBeCloseTo(0.01);
        expect(delta(10, 0, 'count')).toBeNull();
        expect(delta(10, null, 'count')).toBeNull();
    });

    it('pinta la cifra en COP sin decimales y el cambio con flecha y texto', () => {
        void i18n.changeLanguage('es-CO');
        const fmt = makeFormatters('es-CO', 'COP');
        render(<StatTile label="Ventas" value={1250000} previous={1000000} compareMode="previous" unit="money" fmt={fmt} />);
        expect(screen.getByText(/1\.250\.000/)).toBeTruthy();
        expect(screen.getByText(/^\+25\s?%$/)).toBeTruthy();
        expect(screen.getByText('vs. periodo anterior')).toBeTruthy();
    });
});
