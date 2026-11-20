import { describe, expect, it } from 'vitest';
import { applyQuickReply, composerMode, draftFor, formatDuration, formatLeft, keepDraft, matchQuickReplies, parseLabels, quickReplyQuery, windowLeft } from './lib';

const envio = {
    id: 'qr_1',
    shortcut: 'envio',
    title: 'Envíos',
    body: 'Enviamos a todo el país en 2 a 4 días.',
};
const replies = [
    envio,
    {
        id: 'qr_2',
        shortcut: 'pago',
        title: 'Medios de pago',
        body: 'Recibimos PSE, Nequi y contraentrega.',
    },
];

describe('bandeja', () => {
    it('cuenta lo que le queda a la ventana de 24 h', () => {
        const now = new Date('2026-11-17T15:00:00Z');
        expect(windowLeft('2026-11-18T14:30:00Z', now)).toBe(23.5 * 3600_000);
        expect(windowLeft('2026-11-17T14:00:00Z', now)).toBe(0);
        expect(windowLeft(null, now)).toBe(0);
        expect(formatLeft(23.5 * 3600_000)).toEqual({ hours: 23, minutes: 30 });
    });

    it('formatea duraciones cortas y largas', () => {
        expect(formatDuration(45)).toBe('45 s');
        expect(formatDuration(120)).toBe('2 min');
        expect(formatDuration(5400)).toBe('1 h 30 min');
        expect(formatDuration(3 * 86400)).toBe('3 d');
        expect(formatDuration(null)).toBe('—');
    });

    it('respuestas rápidas con /atajo al final del texto', () => {
        expect(quickReplyQuery('Hola /env')).toBe('env');
        expect(quickReplyQuery('/')).toBe('');
        expect(quickReplyQuery('precio 1/2')).toBeNull();
        expect(matchQuickReplies(replies, 'env').map((r) => r.id)).toEqual(['qr_1']);
        expect(matchQuickReplies(replies, 'medios').map((r) => r.id)).toEqual(['qr_2']);
        expect(applyQuickReply('Claro. /env', envio)).toBe('Claro. Enviamos a todo el país en 2 a 4 días.');
    });

    it('etiquetas sin vacías ni repetidas', () => {
        expect(parseLabels(' vip, mayorista,,vip ')).toEqual(['vip', 'mayorista']);
    });
});

describe('composerMode (Messenger e Instagram, Fase 5)', () => {
    const now = new Date('2026-11-17T15:00:00Z');
    const inWindow = '2026-11-17T20:00:00Z';
    const past = '2026-11-16T15:00:00Z';

    it('dentro de la ventana se escribe libre en los tres canales', () => {
        for (const channel of ['whatsapp', 'messenger', 'instagram'] as const) {
            expect(composerMode({ channel, window_state: 'open', window_expires_at: inWindow }, now)).toBe('free');
        }
    });

    it('fuera de la ventana: plantilla en WhatsApp, solo una persona en Messenger y nada en Instagram', () => {
        expect(composerMode({ channel: 'whatsapp', window_state: 'closed', window_expires_at: past }, now)).toBe('template');
        expect(composerMode({ channel: 'messenger', window_state: 'human_agent', window_expires_at: past }, now)).toBe('human');
        expect(composerMode({ channel: 'messenger', window_state: 'closed', window_expires_at: past }, now)).toBe('none');
        expect(composerMode({ channel: 'instagram', window_state: 'closed', window_expires_at: past }, now)).toBe('none');
    });

    it('si la ventana vence con la pantalla abierta, cambia sin esperar al servidor', () => {
        expect(composerMode({ channel: 'messenger', window_state: 'open', window_expires_at: past }, now)).toBe('human');
        expect(composerMode({ channel: 'instagram', window_state: 'open', window_expires_at: past }, now)).toBe('none');
    });
});

describe('borradores del editor (celular: volver a la lista no pierde lo escrito)', () => {
    it('guardan el texto por conversación y olvidan los vacíos', () => {
        expect(draftFor('cnv_1')).toBe('');
        keepDraft('cnv_1', 'Hola, ¿te sirve la talla M?');
        keepDraft('cnv_2', 'Otro');
        expect(draftFor('cnv_1')).toBe('Hola, ¿te sirve la talla M?');
        expect(draftFor('cnv_2')).toBe('Otro');
        keepDraft('cnv_1', '   ');
        expect(draftFor('cnv_1')).toBe('');
        expect(draftFor('cnv_2')).toBe('Otro');
    });
});
