import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import i18n from '@/lib/i18n';
import texts from '@/locales/es-CO/measurement.json';
import { CREDENTIAL_FIELDS, type DeliveryStatus } from './api';
import { DeliveryBadge, ModeBadge } from './bits';
import { CTA_EVENT, destinationState, withCta } from './logic';

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

describe('Medición: textos de las claves dinámicas', () => {
    it('tiene nombre para cada credencial, plataforma, modo, estado y endpoint', () => {
        const missing: string[] = [];
        for (const [platform, fields] of Object.entries(CREDENTIAL_FIELDS)) {
            if (!has(`measurement.platform.${platform}`)) missing.push(`platform.${platform}`);
            for (const field of fields) {
                if (!has(`measurement.credential.${field.key}`)) missing.push(`credential.${field.key}`);
                if (!has(`measurement.form.groupHelp.${platform}_${field.group}`)) missing.push(`groupHelp.${platform}_${field.group}`);
            }
        }
        for (const mode of ['server_only', 'with_pixel', 'full']) {
            for (const part of ['short', 'title', 'help']) {
                if (!has(`measurement.mode.${mode}.${part}`)) missing.push(`mode.${mode}.${part}`);
            }
        }
        for (const s of ['delivered', 'retrying', 'failed', 'skipped']) if (!has(`measurement.status.${s}`)) missing.push(`status.${s}`);
        for (const e of ['meta_capi', 'ga4', 'google_data_manager', 'tiktok_events']) if (!has(`measurement.endpoint.${e}`)) missing.push(`endpoint.${e}`);
        for (const e of ['page.viewed', 'product.viewed', 'cart.item_added', 'checkout.started', 'order.paid', 'form.submitted', 'customer.registered']) {
            if (!has(`measurement.events.${e.replace('.', '_')}`)) missing.push(`events.${e}`);
        }
        for (const k of ['email', 'phone', 'external_id', 'click_id', 'browser_id', 'ip', 'name', 'city']) if (!has(`measurement.matchKey.${k}`)) missing.push(`matchKey.${k}`);
        expect(missing).toEqual([]);
    });

    it('pinta el estado de la entrega y el modo con su texto', () => {
        void i18n.changeLanguage('es-CO');
        const statuses: DeliveryStatus[] = ['delivered', 'failed'];
        render(
            <div>
                {statuses.map((s) => (
                    <DeliveryBadge key={s} status={s} />
                ))}
                <ModeBadge mode="full" />
            </div>,
        );
        expect(screen.getByText('Entregada')).toBeTruthy();
        expect(screen.getByText('Falló')).toBeTruthy();
        expect(screen.getByText('Completo')).toBeTruthy();
    });
});

describe('Anuncios y conversiones: estado en palabras y clics que valen', () => {
    const base = { status: 'active' as const, last_error: null, endpoints: { meta_capi: true }, test_event_code: null, last_delivered_at: null };

    it('el estado de cada plataforma, de lo que más urge a lo que menos', () => {
        expect(destinationState({ ...base, status: 'paused', last_error: 'x' })).toBe('paused');
        expect(destinationState({ ...base, last_error: 'Token vencido' })).toBe('error');
        expect(destinationState({ ...base, endpoints: { meta_capi: false } })).toBe('missing');
        expect(destinationState({ ...base, test_event_code: 'TEST1' })).toBe('test');
        expect(destinationState({ ...base, last_delivered_at: '2026-11-26T08:00:00Z' })).toBe('sending');
        expect(destinationState(base)).toBe('waiting');
        for (const state of ['paused', 'error', 'missing', 'test', 'sending', 'waiting']) {
            expect(has(`measurement.state.${state}.title`)).toBe(true);
        }
    });

    it('prender y apagar los clics que valen no toca los demás eventos', () => {
        expect(withCta(['order.paid'], true)).toEqual(['order.paid', CTA_EVENT]);
        expect(withCta(['order.paid', CTA_EVENT], true)).toEqual(['order.paid', CTA_EVENT]);
        expect(withCta([CTA_EVENT, 'form.submitted'], false)).toEqual(['form.submitted']);
        for (const platform of ['meta', 'google', 'tiktok']) {
            expect(has(`measurement.cta.help.${platform}`)).toBe(true);
        }
    });
});
