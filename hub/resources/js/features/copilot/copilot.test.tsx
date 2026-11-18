import { describe, expect, it } from 'vitest';
import { ApiError } from '@/lib/api';
import es from '@/locales/es-CO/copilot.json';
import { copilotError } from './CopilotDialog';
import { triggerLabel } from './ProposalViews';

describe('copiloto', () => {
    it('describe el disparador propuesto con claves que existen en español', () => {
        expect(triggerLabel({ type: 'segment', system_key: 'rfm_at_risk' })).toEqual(['copilot.trigger.segment', { segment: 'rfm_at_risk', on: 'entered' }]);
        expect(triggerLabel({ type: 'event', event: 'order.paid' })).toEqual(['copilot.trigger.event', { event: 'order.paid' }]);
        expect(triggerLabel({ type: 'threshold' })[0]).toBe('copilot.trigger.other');
        for (const key of ['event', 'segment', 'date', 'other'] as const) {
            expect(es.copilot.trigger[key]).toBeTruthy();
        }
    });

    it('muestra el mensaje del hub (IA no disponible o propuesta inválida con su detalle)', () => {
        expect(copilotError(new ApiError(503, 'unknown', 'La IA no está configurada.'), 'genérico')).toBe('La IA no está configurada.');
        expect(copilotError(new ApiError(422, 'validation', 'No hay propuesta válida.', { segment: 'El segmento no existe.' }), 'genérico')).toBe('No hay propuesta válida. El segmento no existe.');
        expect(copilotError(new Error('x'), 'genérico')).toBe('genérico');
    });
});
