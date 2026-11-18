import { describe, expect, it } from 'vitest';
import { formatDate, formatDateTime, formatMoney, formatNumber, formatRelative } from './format';

const SPACES = new RegExp('[' + String.fromCharCode(160, 8239) + ']', 'g');
const nbsp = (s: string) => s.replace(SPACES, ' ');

describe('formatos', () => {
    it('muestra COP sin decimales y MXN con dos', () => {
        expect(nbsp(formatMoney('1532000000.00', 'COP', 'es-CO'))).toBe('$ 1.532.000.000');
        expect(nbsp(formatMoney('282000.50', 'COP', 'es-CO'))).toBe('$ 282.001');
        expect(nbsp(formatMoney('1234.5', 'MXN', 'es-MX'))).toBe('$1,234.50');
        expect(nbsp(formatMoney(null, 'USD', 'es-CO'))).toContain('0,00');
    });

    it('formatea números y fechas en español', () => {
        expect(formatNumber(10000, 'es-CO')).toBe('10.000');
        expect(formatDate('2024-11-18', 'es-CO')).toMatch(/18 de nov\.? de 2024/);
        expect(formatDate(null)).toBe('—');
        expect(formatDateTime('2026-11-17T23:05:00Z', 'es-CO', 'America/Bogota')).toMatch(/17 de nov\.? de 2026.*6:05/);
    });

    it('expresa tiempos relativos', () => {
        const now = new Date('2026-11-18T12:00:00Z');
        expect(formatRelative('2026-11-18T11:55:00Z', 'es-CO', now)).toBe('hace 5 minutos');
        expect(formatRelative('2026-11-15T12:00:00Z', 'es-CO', now)).toBe('hace 3 días');
        expect(formatRelative(undefined)).toBe('—');
        // Fechas sin zona de la base: son UTC, no la hora local del navegador.
        expect(formatRelative('2026-11-18 11:00:00', 'es-CO', now)).toBe('hace 1 hora');
    });
});
