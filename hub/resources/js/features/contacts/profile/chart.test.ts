import { describe, expect, it } from 'vitest';
import { fillMonths, monthLabel, trimLeadingEmpty } from './chart';

describe('gráfico de compras por mes', () => {
    const end = new Date(Date.UTC(2026, 10, 18));

    it('completa 24 meses terminando en el mes actual', () => {
        const bars = fillMonths([{ month: '2026-09', orders: 2, revenue: '150000.00' }], end);
        expect(bars).toHaveLength(24);
        expect(bars[0]?.month).toBe('2024-12');
        expect(bars[23]?.month).toBe('2026-11');
        expect(bars.find((b) => b.month === '2026-09')).toEqual({ month: '2026-09', orders: 2, revenue: 150000 });
        expect(bars.find((b) => b.month === '2026-10')?.orders).toBe(0);
    });

    it('recorta los meses vacíos del comienzo pero deja al menos seis', () => {
        const bars = fillMonths([{ month: '2026-10', orders: 1, revenue: '10' }], end);
        expect(trimLeadingEmpty(bars).map((b) => b.month)).toEqual(['2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11']);
        const early = fillMonths([{ month: '2025-01', orders: 1, revenue: '10' }], end);
        expect(trimLeadingEmpty(early)[0]?.month).toBe('2025-01');
    });

    it('etiqueta el mes en español', () => {
        expect(monthLabel('2026-11', 'es-CO')).toBe('nov');
        expect(monthLabel('2026-01', 'es-CO', true)).toMatch(/ene.*2026/);
    });
});
