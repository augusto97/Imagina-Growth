import { describe, expect, it } from 'vitest';
import { filtersToParams } from './api';
import { DEFAULT_FILTERS, hasActiveFilters, readFilters, writeFilters } from './filters';

describe('filtros de la lista de contactos', () => {
    it('ida y vuelta por la URL', () => {
        const filters = { ...DEFAULT_FILTERS, q: 'ana', stage: 'customer', consent: 'whatsapp' as const, buyers: '1' as const, sort: 'revenue' as const, page: 3 };
        expect(readFilters(writeFilters(filters))).toEqual(filters);
    });

    it('descarta valores que el hub no acepta', () => {
        const read = readFilters(new URLSearchParams('consent=fax&buyers=2&sort=random&page=-4'));
        expect(read.consent).toBe('');
        expect(read.buyers).toBe('');
        expect(read.sort).toBe('last_seen');
        expect(read.page).toBe(1);
    });

    it('la URL por defecto queda vacía y la consulta solo lleva lo que tiene valor', () => {
        expect(writeFilters(DEFAULT_FILTERS).toString()).toBe('');
        expect(filtersToParams({ ...DEFAULT_FILTERS, city: ' Medellín ', sort: 'orders' }).toString()).toBe('page=1&city=Medell%C3%ADn&sort=orders');
    });

    it('sabe si hay filtros activos (el orden no cuenta)', () => {
        expect(hasActiveFilters({ ...DEFAULT_FILTERS, sort: 'revenue' })).toBe(false);
        expect(hasActiveFilters({ ...DEFAULT_FILTERS, tag: 'vip' })).toBe(true);
    });
});
