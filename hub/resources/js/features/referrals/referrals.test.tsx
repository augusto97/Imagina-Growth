import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import i18n from '@/lib/i18n';
import texts from '@/locales/es-CO/referrals.json';
import { ConversionStatusBadge, isoDay, ReferralStatusBadge, SignalBadges } from './labels';
import type { Referral } from './types';

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

const referral = (over: Partial<Referral>): Referral => ({
    id: 'rf_1', status: 'ordered', review_status: null, rejected_reason: null, code: 'ANA7K2', referrer: null, friend: null, signals: [], order: null,
    order_amount_ws: null, friend_coupon: true, reward: null, reward_cost_ws: null, ordered_at: null, qualifies_at: null, rewarded_at: null, created_at: null, ...over,
});

describe('Referidos: textos de las claves dinámicas', () => {
    it('tiene texto para cada estado, señal, motivo, recompensa y opción', () => {
        const missing: string[] = [];
        const need = (key: string) => {
            if (!has(`referrals.${key}`)) missing.push(key);
        };
        ['clicked', 'signed_up', 'ordered', 'qualified', 'rewarded', 'rejected'].forEach((s) => need(`status.${s}`));
        ['same_person', 'same_device', 'same_ip', 'same_address'].forEach((s) => {
            need(`signals.${s}`);
            need(`signals.help.${s}`);
        });
        ['order_cancelled', 'order_failed', 'order_refunded', 'below_minimum', 'monthly_limit', 'manual_review'].forEach((r) => need(`rejectedReason.${r}`));
        ['loyalty_inactive', 'coupon_failed', 'unknown'].forEach((e) => need(`rewardError.${e}`));
        ['pending', 'approved', 'paid', 'void'].forEach((s) => need(`conversions.status.${s}`));
        ['resumen', 'referidos', 'afiliados', 'comisiones', 'programa'].forEach((tab) => need(`tabs.${tab}`));
        ['coupon', 'link'].forEach((v) => need(`via.${v}`));
        ['draft', 'active', 'paused'].forEach((s) => need(`program.statuses.${s}`));
        ['percent', 'fixed_cart', 'free_shipping'].forEach((k) => need(`program.kinds.${k}`));
        ['wallet', 'points', 'coupon'].forEach((k) => need(`program.rewardTypes.${k}`));
        ['influencer', 'affiliate', 'partner'].forEach((k) => need(`affiliates.kinds.${k}`));
        ['active', 'paused', 'archived'].forEach((s) => need(`affiliates.statuses.${s}`));
        ['week', 'month'].forEach((b) => need(`report.period.${b}`));
        ['no_email', 'other'].forEach((r) => need(`portal.notSent.${r}`));
        expect(missing).toEqual([]);
    });

    it('muestra el estado del referido, la revisión y las señales con texto (nunca solo color)', () => {
        void i18n.changeLanguage('es-CO');
        render(
            <div>
                <ReferralStatusBadge referral={referral({ status: 'signed_up', review_status: 'pending_review' })} />
                <SignalBadges signals={['same_device', 'same_ip']} />
                <SignalBadges signals={[]} />
                <ConversionStatusBadge status="approved" />
            </div>,
        );
        expect(screen.getByText('Se registró')).toBeTruthy();
        expect(screen.getByText('En revisión')).toBeTruthy();
        expect(screen.getByText('Mismo dispositivo')).toBeTruthy();
        expect(screen.getByText('Misma conexión')).toBeTruthy();
        expect(screen.getByText('Sin señales')).toBeTruthy();
        expect(screen.getByText('Aprobada')).toBeTruthy();
    });

    it('arma la fecha del reporte en la hora local', () => {
        expect(isoDay(new Date(2026, 0, 5, 23, 30))).toBe('2026-01-05');
    });
});
