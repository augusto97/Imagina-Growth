import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import i18n from '@/lib/i18n';
import texts from '@/locales/es-CO/insights.json';
import { LIST_STATUSES } from './api';
import { SEVERITIES, SeverityBadge } from './severity';

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

/** Detectores de App\Domain\Intelligence\Insights\InsightEngine::DETECTORS (Fase 1). */
const DETECTORS = ['conversion_drop', 'payment_method', 'low_stock_stars', 'valuable_at_risk', 'data_drift'];

describe('Hallazgos: textos', () => {
    it('tiene texto para cada severidad, estado, pestaña y detector', () => {
        const missing: string[] = [];
        const need = (key: string) => {
            if (!has(key)) missing.push(key);
        };
        SEVERITIES.forEach((s) => need(`insights.severity.${s}`));
        ['new', 'seen', 'dismissed', 'actioned', 'expired'].forEach((s) => need(`insights.status.${s}`));
        LIST_STATUSES.forEach((s) => {
            need(`insights.tabs.${s}`);
            need(`insights.empty.${s}`);
        });
        DETECTORS.forEach((d) => need(`insights.detector.${d}`));
        ['dismissed', 'actioned', 'seen'].forEach((s) => need(`insights.toast.${s}`));
        expect(missing).toEqual([]);
    });

    it('la severidad nunca va solo con color: lleva texto', () => {
        void i18n.changeLanguage('es-CO');
        render(
            <div>
                <SeverityBadge severity="critical" />
                <SeverityBadge severity="opportunity" />
                <SeverityBadge severity="algo-raro" />
            </div>,
        );
        expect(screen.getByText('Crítico')).toBeTruthy();
        expect(screen.getByText('Oportunidad')).toBeTruthy();
        expect(screen.getByText('Información')).toBeTruthy();
    });
});
