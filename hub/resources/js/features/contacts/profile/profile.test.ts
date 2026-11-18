import { describe, expect, it } from 'vitest';
import { buildAttributes } from './DataTab';
import { groupByDay } from './Timeline';

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
