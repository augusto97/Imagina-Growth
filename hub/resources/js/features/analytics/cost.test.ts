import { describe, expect, it } from 'vitest';
import { parseRate } from '@/features/platform/fx-api';
import { formatCost, formatCostPerSale, formatRoi, formatUsd } from './cost';

const nbsp = (s: string) => s.replace(/\s/g, ' ');

describe('costo y retorno de los mensajes', () => {
    it('muestra el costo en la moneda del espacio de trabajo y, sin tasa, en dólares con los centavos que hacen falta', () => {
        expect(nbsp(formatCost({ cost: 614.75, cost_usd: 0.15 }, 'COP', 'es-CO'))).toBe('$ 615');
        expect(nbsp(formatCost({ cost: null, cost_usd: 0.1501 }, 'COP', 'es-CO'))).toContain('0,1501');
        expect(nbsp(formatUsd(12.5, 'es-CO'))).toContain('12,50');
    });

    it('el retorno se lee como veces y no se inventa sin costo', () => {
        expect(formatRoi(2785.19, 'es-CO')).toBe('2.785×');
        expect(formatRoi(3.46, 'es-CO')).toBe('3,5×');
        expect(formatRoi(null, 'es-CO')).toBe('—');
        expect(formatCostPerSale({ cost_per_sale: null }, 'COP', 'es-CO')).toBe('—');
    });

    it('entiende la tasa escrita con coma decimal o punto', () => {
        expect(parseRate('3.950,5')).toBe(3950.5);
        expect(parseRate('3950.5')).toBe(3950.5);
        expect(parseRate(' 925,25 ')).toBe(925.25);
        expect(parseRate('0')).toBeNull();
        expect(parseRate('abc')).toBeNull();
        expect(parseRate('')).toBeNull();
    });
});
