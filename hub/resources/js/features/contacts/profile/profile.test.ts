import { describe, expect, it } from 'vitest';
import type { ContactServices } from '../api';
import { timelineGroupsFor } from '../labels';
import { buildAttributes } from './DataTab';
import { currentLead, nextAppointment, purchaseOutlook, whatsappDigits } from './insights';
import { profileTabs } from './ProfilePage';
import { groupByDay } from './Timeline';

describe('el perfil en palabras', () => {
    const base = { stats: { orders_count: 3 } as never, churn: { band: null }, expected_next_order_at: null };

    it('lo que viene: sin compras, riesgo alto, la próxima compra o el riesgo que haya', () => {
        expect(purchaseOutlook({ ...base, stats: { orders_count: 0 } as never })).toEqual({ kind: 'noPurchases' });
        expect(purchaseOutlook({ ...base, churn: { band: 'high' }, expected_next_order_at: '2026-12-03T00:00:00Z' })).toEqual({ kind: 'risk', band: 'high' });
        expect(purchaseOutlook({ ...base, churn: { band: 'low' }, expected_next_order_at: '2026-12-03T00:00:00Z' })).toEqual({ kind: 'next', at: '2026-12-03T00:00:00Z' });
        expect(purchaseOutlook({ ...base, churn: { band: 'medium' } })).toEqual({ kind: 'risk', band: 'medium' });
        expect(purchaseOutlook(base)).toEqual({ kind: 'unknown' });
    });

    it('el proceso que importa es el abierto; la próxima cita es la activa que no ha pasado', () => {
        const lead = (id: string, kind: 'open' | 'won' | 'lost') => ({ id, stage_kind: kind }) as ContactServices['leads'][number];
        expect(currentLead({ leads: [lead('a', 'won'), lead('b', 'open')] })?.id).toBe('b');
        expect(currentLead({ leads: [lead('a', 'lost')] })?.id).toBe('a');
        expect(currentLead(undefined)).toBeNull();
        const now = new Date('2026-11-26T12:00:00Z');
        const ap = (id: string, status: string, at: string) => ({ id, status, starts_at: at }) as ContactServices['appointments'][number];
        const services = { appointments: [ap('x', 'cancelled', '2026-11-27T10:00:00Z'), ap('y', 'confirmed', '2026-11-28T10:00:00Z'), ap('z', 'completed', '2026-11-20T10:00:00Z')] };
        expect(nextAppointment(services, now)?.id).toBe('y');
    });

    it('el enlace de WhatsApp solo con el teléfono completo', () => {
        expect(whatsappDigits('+57 300 123 4567', true)).toBe('573001234567');
        expect(whatsappDigits('+57300****567', true)).toBeNull();
        expect(whatsappDigits('+573001234567', false)).toBeNull();
        expect(whatsappDigits(null, true)).toBeNull();
    });

    it('pestañas y actividad según el negocio: sin tienda no hay compras ni carrito', () => {
        expect(profileTabs({ store: false, services: true, loyalty: true, aside: true })).toEqual(['resumen', 'citas', 'mensajes', 'datos', 'identidades', 'privacidad']);
        expect(profileTabs({ store: true, services: false, loyalty: false, aside: false })).toEqual([
            'resumen',
            'pedidos',
            'productos',
            'mensajes',
            'actividad',
            'notas',
            'datos',
            'identidades',
            'privacidad',
        ]);
        expect(timelineGroupsFor(false, true)).not.toContain('carrito');
        expect(timelineGroupsFor(false, true)).toContain('servicios');
        expect(timelineGroupsFor(true, false)).not.toContain('servicios');
    });
});

describe('perfil 360', () => {
    it('conserva el tipo de los atributos que no se tocaron', () => {
        const { attributes, invalid } = buildAttributes([
            { key: 'puntos', value: '120', original: 120 },
            { key: 'talla', value: 'M', original: 'S' },
            { key: '', value: '', original: undefined },
            { key: 'nuevo', value: ' sí ', original: undefined },
        ]);
        expect(invalid).toEqual([]);
        expect(attributes).toEqual({ puntos: 120, talla: 'M', nuevo: 'sí' });
    });

    it('rechaza claves inválidas o repetidas', () => {
        expect(buildAttributes([{ key: 'Talla', value: 'M', original: undefined }]).invalid).toEqual(['Talla']);
        expect(
            buildAttributes([
                { key: 'a', value: '1', original: undefined },
                { key: 'a', value: '2', original: undefined },
            ]).invalid,
        ).toEqual(['a']);
    });

    it('agrupa la línea de tiempo por día en la zona del espacio de trabajo', () => {
        const ev = (id: string, at: string) => ({ id, event: 'page.viewed', occurred_at: at, source: null, channel: null, device: null, path: null, summary: {} });
        // 03:00 UTC del 18 todavía es el 17 en Bogotá (UTC-5).
        const days = groupByDay([ev('a', '2026-11-18T15:00:00.000Z'), ev('b', '2026-11-18T03:00:00.000Z'), ev('c', '2026-11-17T20:00:00.000Z')], 'America/Bogota');
        expect(days.map((d) => [d.day, d.events.map((e) => e.id)])).toEqual([
            ['2026-11-18', ['a']],
            ['2026-11-17', ['b', 'c']],
        ]);
    });
});
