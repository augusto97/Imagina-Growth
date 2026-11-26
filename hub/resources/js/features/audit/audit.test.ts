import { describe, expect, it } from 'vitest';
import i18n from '@/lib/i18n';
import { actionLabel, changedFields, dayOf } from './logic';

const t = i18n.getFixedT('es-CO');

describe('registro de actividad en palabras', () => {
    it('usa el texto propio, el verbo con la cosa o la acción legible', () => {
        expect(actionLabel(t, 'workspace.created')).toBe('Creó el espacio de trabajo');
        expect(actionLabel(t, 'flow.published')).toBe('Publicó una automatización');
        expect(actionLabel(t, 'measurement.destination_created')).toBe('Conectó un destino de anuncios');
        expect(actionLabel(t, 'loyalty.tiers_saved')).toBe('Otro cambio en el programa de puntos (tiers saved)');
        expect(actionLabel(t, 'misterio.algo')).toBe('Otro cambio en misterio (algo)');
    });

    it('nombra los campos que cambiaron (con antes y después, los del después)', () => {
        expect(changedFields(t, { currency: 'MXN', timezone: 'America/Mexico_City' })).toBe('Moneda, Zona horaria');
        expect(changedFields(t, { before: { a: 1 }, after: { enabled: true, mode: 'auto' } })).toBe('Encendido, Modo');
        expect(changedFields(t, null)).toBe('');
        expect(dayOf('2026-11-18T04:30:00Z', 'America/Bogota')).toBe('2026-11-17');
    });
});
