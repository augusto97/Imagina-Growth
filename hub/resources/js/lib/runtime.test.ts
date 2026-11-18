import { afterEach, describe, expect, it } from 'vitest';
import { realtimeConfigured } from './realtime';
import { reverbConfig, serverVersion } from './runtime';

afterEach(() => {
    delete window.igrRuntime;
});

describe('configuración del servidor', () => {
    it('toma Reverb de window.igrRuntime (el paquete de versión no trae la clave compilada)', () => {
        window.igrRuntime = { version: '0.3.0', reverb: { key: 'pub-key', host: 'growth.ejemplo.com', port: 443, scheme: 'https' } };
        expect(reverbConfig()).toEqual({ key: 'pub-key', host: 'growth.ejemplo.com', port: 443, scheme: 'https' });
        expect(realtimeConfigured()).toBe(true);
        expect(serverVersion()).toBe('0.3.0');
    });

    it('sin Reverb en el servidor (ni VITE_REVERB_APP_KEY) no hay tiempo real', () => {
        window.igrRuntime = { version: '0.3.0', reverb: null };
        expect(reverbConfig()).toBeNull();
        expect(realtimeConfigured()).toBe(false);
    });

    it('completa el host y el puerto si el servidor no los da', () => {
        window.igrRuntime = { reverb: { key: 'k', host: '', port: 0, scheme: 'http' } };
        expect(reverbConfig()).toEqual({ key: 'k', host: window.location.hostname, port: 80, scheme: 'http' });
        expect(serverVersion()).toBeNull();
    });
});
