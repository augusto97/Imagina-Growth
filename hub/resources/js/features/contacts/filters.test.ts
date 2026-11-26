import { describe, expect, it } from 'vitest';
import { filtersToParams, selectionParams } from './api';
import { DEFAULT_FILTERS, activeFilterCount, hasActiveFilters, quickActive, quickFilters, readFilters, toggleQuick, writeFilters } from './filters';

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
        expect(hasActiveFilters({ ...DEFAULT_FILTERS, segment: 'sg_1' })).toBe(true);
        expect(activeFilterCount({ ...DEFAULT_FILTERS, q: 'ana', city: 'Cali', temperature: 'hot' })).toBe(2);
    });

    it('lee el grupo y la temperatura, y descarta una temperatura inventada', () => {
        expect(readFilters(new URLSearchParams('segment=sg_9&temperature=hot'))).toMatchObject({ segment: 'sg_9', temperature: 'hot' });
        expect(readFilters(new URLSearchParams('temperature=tibia')).temperature).toBe('');
        expect(filtersToParams({ ...DEFAULT_FILTERS, segment: 'sg_9' }, true).toString()).toBe('page=1&segment=sg_9&services=1');
    });

    it('filtros rápidos según el negocio: prender, apagar y «Todos»', () => {
        expect(quickFilters(true, false)).toEqual(['all', 'buyers', 'nonBuyers', 'whatsapp', 'atRisk']);
        expect(quickFilters(false, true)).toEqual(['all', 'hot', 'warm', 'whatsapp', 'email']);
        const hot = toggleQuick({ ...DEFAULT_FILTERS, q: 'ana', page: 4 }, 'hot');
        expect(hot).toMatchObject({ temperature: 'hot', q: 'ana', page: 1 });
        expect(quickActive(hot, 'hot')).toBe(true);
        expect(quickActive(hot, 'all')).toBe(false);
        expect(toggleQuick(hot, 'hot').temperature).toBe('');
        expect(toggleQuick({ ...hot, city: 'Cali' }, 'all')).toEqual({ ...DEFAULT_FILTERS, q: 'ana' });
    });

    it('las acciones masivas mandan las elegidas o los filtros, sin página ni orden', () => {
        expect(selectionParams({ ids: ['ct_1', 'ct_2'] }).toString()).toBe('ids%5B%5D=ct_1&ids%5B%5D=ct_2');
        expect(selectionParams({ all: true, filters: { ...DEFAULT_FILTERS, consent: 'whatsapp', sort: 'revenue', page: 3 } }).toString()).toBe('consent=whatsapp&all=1');
    });
});
