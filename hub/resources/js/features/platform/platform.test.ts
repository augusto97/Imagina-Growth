import { describe, expect, it } from 'vitest';
import { draftTotal, lagState, platformInvoicePdfUrl } from './api';

describe('panel de la plataforma', () => {
    it('calcula el total del borrador como el servidor', () => {
        expect(
            draftTotal([
                { concept: 'Plan', quantity: 2, unit_price: '599000' },
                { concept: 'Paquete', quantity: 1, unit_price: '99000.5' },
            ]),
        ).toBe(1297000.5);
        expect(draftTotal([])).toBe(0);
    });

    it('clasifica el retraso de ingesta', () => {
        expect(lagState(0)).toBe('ok');
        expect(lagState(60)).toBe('warning');
        expect(lagState(300)).toBe('critical');
    });

    it('arma la URL del PDF de una factura', () => {
        expect(platformInvoicePdfUrl('inv_1')).toBe('/api/platform/invoices/inv_1/pdf');
    });
});
