import { describe, expect, it } from 'vitest';
import channels from '@/locales/es-CO/channels.json';
import { UNOFFICIAL_PROVIDERS } from './api';
import { numberStatusKey, usableNumber } from './dialogs/ImaginaWhatsDialog';

describe('Imagina Whats', () => {
    it('es WhatsApp no oficial, como Zender', () => {
        expect(UNOFFICIAL_PROVIDERS).toEqual(['zender', 'imagina_whats']);
        expect(channels.channels.provider.imagina_whats).toContain('Imagina Whats');
    });

    it('solo se elige un número conectado y habilitado, y cada estado tiene su texto', () => {
        expect(usableNumber({ id: 1, phone: '+573105550000', name: 'Ventas', status: 'connected', enabled: true })).toBe(true);
        expect(usableNumber({ id: 2, phone: null, name: null, status: 'connected', enabled: false })).toBe(false);
        expect(usableNumber({ id: 3, phone: null, name: null, status: 'logged_out', enabled: true })).toBe(false);
        const texts = channels.channels.imaginaWhats.status as Record<string, string>;
        for (const status of ['connected', 'disconnected', 'logged_out', 'banned', 'registering', 'pending_pair', 'raro']) {
            expect(texts[numberStatusKey(status)], status).toBeTypeOf('string');
        }
        expect(numberStatusKey('pending_pair')).toBe('pairing');
        expect(numberStatusKey('raro')).toBe('other');
    });
});
