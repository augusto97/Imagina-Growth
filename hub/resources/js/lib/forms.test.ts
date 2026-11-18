import { describe, expect, it } from 'vitest';
import { safeNext } from './forms';

describe('safeNext', () => {
    it('solo acepta rutas internas', () => {
        expect(safeNext('/connect?site=https%3A%2F%2Ftienda.co')).toBe('/connect?site=https%3A%2F%2Ftienda.co');
        expect(safeNext('https://evil.co')).toBe('/');
        expect(safeNext('//evil.co/x')).toBe('/');
        expect(safeNext(null, '/onboarding')).toBe('/onboarding');
    });
});
