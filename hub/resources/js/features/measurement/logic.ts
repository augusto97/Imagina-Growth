import type { Destination } from './api';

/*
 * Lógica pura de Anuncios y conversiones (probada con Vitest): el estado de cada destino en palabras y el interruptor de
 * los «clics que valen» (cta.clicked → Contact, apagado por defecto; modulos.md M06).
 */

/** El evento de los clics en WhatsApp, llamar o escribir. */
export const CTA_EVENT = 'cta.clicked';

export type DestinationState = 'paused' | 'error' | 'missing' | 'test' | 'sending' | 'waiting';

/** El estado de un destino, de lo que más urge a lo que menos: pausado, con error, sin datos, en prueba, enviando o esperando. */
export function destinationState(d: Pick<Destination, 'status' | 'last_error' | 'endpoints' | 'test_event_code' | 'last_delivered_at'>): DestinationState {
    if (d.status === 'paused') {
        return 'paused';
    }
    if (d.last_error) {
        return 'error';
    }
    if (!Object.values(d.endpoints).some(Boolean)) {
        return 'missing';
    }
    if (d.test_event_code) {
        return 'test';
    }
    return d.last_delivered_at ? 'sending' : 'waiting';
}

/** Los eventos con los clics que valen prendidos o apagados (sin repetir ni tocar los demás). */
export function withCta(events: readonly string[], on: boolean): string[] {
    const rest = events.filter((e) => e !== CTA_EVENT);
    return on ? [...rest, CTA_EVENT] : rest;
}
