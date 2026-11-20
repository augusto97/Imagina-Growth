import { describe, expect, it } from 'vitest';
import i18n from '@/lib/i18n';
import type { ConnectionAlert } from './api';
import { alertBody, alertTitle, isUrgent, reconnectAction } from './alerts';

const t = i18n.t.bind(i18n);

const base: ConnectionAlert = {
    kind: 'connection',
    id: 'oac_1',
    provider: 'meta',
    subject: 'workspace',
    state: 'expires_7d',
    name: 'Pinturas La 14 SAS',
    expires_at: '2026-11-30T14:00:00Z',
    message: null,
};

describe('aviso de conexiones por vencer o caídas', () => {
    it('«Reconectar» abre el consentimiento de Meta, Google o TikTok y lleva a Conexiones o a la agenda en lo demás', () => {
        expect(reconnectAction(base)).toEqual({ kind: 'oauth', provider: 'meta' });
        expect(reconnectAction({ ...base, provider: 'google' })).toEqual({ kind: 'oauth', provider: 'google' });
        expect(reconnectAction({ ...base, provider: 'google', subject: 'calendar' })).toEqual({ kind: 'link', path: 'agenda?pestana=personas' });
        expect(reconnectAction({ ...base, provider: 'meta_whatsapp', subject: 'whatsapp' })).toEqual({ kind: 'link', path: 'conexiones' });
        expect(reconnectAction({ ...base, kind: 'channel', provider: 'meta', subject: 'messenger', state: 'disconnected' })).toEqual({ kind: 'link', path: 'conexiones' });
    });

    it('dice qué vence y cuándo, en palabras del dueño', () => {
        expect(alertTitle(t, base, 'es-CO', 'America/Bogota')).toBe('Meta (Facebook e Instagram) vence el 30 de nov de 2026');
        expect(alertTitle(t, { ...base, state: 'expires_1d' })).toBe('Meta (Facebook e Instagram) vence mañana');
        expect(alertTitle(t, { ...base, provider: 'google', subject: 'calendar', state: 'expired' })).toBe('Hay que volver a conectar Google Calendar de la agenda');
        const number: ConnectionAlert = {
            ...base,
            kind: 'channel',
            provider: 'meta_whatsapp',
            subject: 'whatsapp',
            state: 'disconnected',
            name: '+57 300 0000000',
            message: 'Meta desconectó este número.',
        };
        expect(alertTitle(t, number)).toBe('Tu WhatsApp +57 300 0000000 se desconectó');
        expect(alertBody(t, number)).toBe('Meta desconectó este número.');
        expect(alertBody(t, base)).toContain('Vuelve a conectarlo antes');
        expect(isUrgent(number)).toBe(true);
        expect(isUrgent(base)).toBe(false);
    });
});
