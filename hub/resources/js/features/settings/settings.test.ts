import { describe, expect, it } from 'vitest';
import type { VerticalProfile } from '@/features/verticals/types';
import { DEFAULT_HOURS, hoursFromSettings, verticalsFor } from './logic';

const profile = (key: VerticalProfile['key'], business_type: VerticalProfile['business_type']) => ({ key, business_type }) as VerticalProfile;

describe('Tu negocio', () => {
    it('lee el horario de atención guardado o usa el de siempre', () => {
        expect(hoursFromSettings({})).toEqual({ hours: DEFAULT_HOURS, defined: false });
        expect(hoursFromSettings(null).defined).toBe(false);
        expect(hoursFromSettings({ business_hours: { days: [6, 1, 1, 9], start: '09:00', end: '13:00' } })).toEqual({ hours: { days: [1, 6], start: '09:00', end: '13:00' }, defined: true });
        expect(hoursFromSettings({ business_hours: { days: [2], start: 'mañana' } }).hours).toEqual({ days: [2], start: '08:00', end: '18:00' });
    });

    it('ofrece las actividades que van con el tipo de negocio', () => {
        const all = [profile('store', 'store'), profile('health', 'services'), profile('b2b', 'services')];
        expect(verticalsFor(all, 'store').map((p) => p.key)).toEqual(['store']);
        expect(verticalsFor(all, 'services').map((p) => p.key)).toEqual(['health', 'b2b']);
        expect(verticalsFor(all, 'both')).toHaveLength(3);
    });
});
