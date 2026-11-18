import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import i18n from '@/lib/i18n';
import texts from '@/locales/es-CO/insights.json';
import { CATEGORIES, LIST_STATUSES } from './api';
import { isOneClick } from './components';
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

/** Los 23 detectores de App\Domain\Intelligence\Insights\DetectorCatalog, en orden. */
const DETECTORS = [
    'conversion_drop', 'payment_method', 'viewed_not_bought', 'cart_abandonment_products', 'search_no_results', 'low_stock_stars', 'slow_pages',
    'valuable_at_risk', 'first_purchase_stalled', 'concentration', 'channel_ltv', 'entry_product', 'city_potential', 'discount_dependency',
    'essential_flow_inactive', 'whatsapp_quality', 'email_reputation', 'fatigue', 'conversions_failing', 'data_drift',
    'leads_unanswered', 'form_low_conversion', 'lead_source_close',
];
/** Supuestos editables de config/igr-insights.php. */
const ASSUMPTIONS = ['reactivation_rate', 'payment_recovery_rate', 'cart_recovery_rate', 'second_purchase_rate', 'product_fix_share', 'cart_fix_share', 'search_capture_rate', 'city_fix_share'];

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
        CATEGORIES.forEach((c) => need(`insights.category.${c}`));
        ASSUMPTIONS.forEach((a) => need(`insights.assumption.${a}`));
        ['flow_activated', 'flow_draft', 'already_active', 'exists', 'campaign_created', 'link'].forEach((r) => need(`insights.actionResult.${r}`));
        ['ok_one', 'ok_other', 'skipped', 'failed', 'disabled'].forEach((r) => need(`insights.settings.run.${r}`));
        ['dismissed', 'actioned', 'seen'].forEach((s) => need(`insights.toast.${s}`));
        expect(missing).toEqual([]);
    });

    it('«Hacer ahora» con 1 clic solo para flujos y campañas de hallazgos abiertos', () => {
        expect(isOneClick({ status: 'new', action: { label: 'x', kind: 'activate_flow' } })).toBe(true);
        expect(isOneClick({ status: 'seen', action: { label: 'x', kind: 'create_campaign' } })).toBe(true);
        expect(isOneClick({ status: 'actioned', action: { label: 'x', kind: 'activate_flow' } })).toBe(false);
        expect(isOneClick({ status: 'new', action: { label: 'x', to: 'analitica' } })).toBe(false);
        expect(isOneClick({ status: 'new', action: null })).toBe(false);
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
