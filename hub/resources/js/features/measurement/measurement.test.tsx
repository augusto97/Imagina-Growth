import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import i18n from '@/lib/i18n';
import texts from '@/locales/es-CO/measurement.json';
import { CREDENTIAL_FIELDS, type DeliveryStatus } from './api';
import { DeliveryBadge, ModeBadge } from './bits';

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
