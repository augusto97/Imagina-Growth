import { describe, expect, it } from 'vitest';
import type { BackupsPayload } from './backups-api';
import { errorKey, finishedRequest, formatBytes, formFromPayload, generatePassphrase, jobTone, settingsInput, shouldPoll, userFromHost } from './backups-model';

const payload = (over: Partial<BackupsPayload> = {}): BackupsPayload => ({
    answered: true,
    updated_at: null,
    configured: true,
    managed: true,
    manual_remote: null,
    destination: { host: 'u123456.your-storagebox.de', port: 23, user: 'u123456', path: 'imagina-growth', auth: 'key', keep_days: 30 },
    secrets: { password: false, encryption: true },
    heartbeats: { mariadb: true, clickhouse: false, verify: false },
    timers_enabled: true,
    ssh_key: null,
    host_key: null,
    last_request: null,
    last_test: null,
    jobs: { mariadb: null, clickhouse: null, verify: null },
    schedule: {},
    pending: null,
    busy: false,
    ...over,
});
const job = (state: 'queued' | 'running' | 'succeeded' | 'failed') => ({
    state,
    started_at: null,
    finished_at: null,
    name: null,
    size_bytes: null,
    code: null,
    message: null,
    last_success: null,
});

describe('copias de seguridad', () => {
    it('consulta en vivo solo mientras algo está en marcha', () => {
        expect(shouldPoll(payload())).toBe(false);
        expect(shouldPoll(payload({ pending: { id: 'bkp_1', action: 'test', requested_at: null, stale: false } }))).toBe(true);
        // Un pedido que nadie tomó en minutos no se queda consultando cada 2 s.
        expect(shouldPoll(payload({ pending: { id: 'bkp_1', action: 'test', requested_at: null, stale: true } }))).toBe(false);
        expect(shouldPoll(payload({ last_request: { id: 'bkp_1', action: 'test', requested_by: null, state: 'running', code: null, message: null, started_at: null, finished_at: null } }))).toBe(true);
        expect(shouldPoll(payload({ jobs: { mariadb: job('succeeded'), clickhouse: job('queued'), verify: null } }))).toBe(true);
    });

    it('elige el tono de cada copia', () => {
        expect(jobTone(job('succeeded'))).toBe('ok');
        expect(jobTone(job('failed'))).toBe('critical');
        expect(jobTone(job('running'))).toBe('active');
        expect(jobTone(null)).toBe('neutral');
    });

    it('explica los códigos conocidos y deja pasar el mensaje de los demás', () => {
        expect(errorKey('auth_failed')).toBe('platform.backups.errors.auth_failed');
        expect(errorKey('algo_nuevo')).toBeNull();
        expect(errorKey(null)).toBeNull();
    });

    it('muestra tamaños legibles', () => {
        expect(formatBytes(512)).toBe('512 B');
        expect(formatBytes(52428800)).toBe('50 MB');
        expect(formatBytes(1610612736)).toBe('1,5 GB');
        expect(formatBytes(null)).toBe('—');
    });

    it('sugiere el usuario a partir del servidor del Storage Box', () => {
        expect(userFromHost('U123456.your-storagebox.de ')).toBe('u123456');
        expect(userFromHost('u123456-sub2.your-storagebox.de')).toBe('u123456-sub2');
        expect(userFromHost('backup.example.com')).toBeNull();
    });

    it('genera frases de cifrado al azar, largas y sin caracteres confusos', () => {
        const a = generatePassphrase();
        const b = generatePassphrase();
        expect(a).toMatch(/^([a-km-zA-HJ-NP-Z2-9]{5}-){5}[a-km-zA-HJ-NP-Z2-9]{5}$/);
        expect(a).not.toBe(b);
        // Los bytes que sesgarían el reparto se descartan.
        let calls = 0;
        const mixed = (n: number) => new Uint8Array(n).fill(calls++ === 0 ? 250 : 0);
        expect(generatePassphrase(mixed)).toBe('aaaaa-aaaaa-aaaaa-aaaaa-aaaaa-aaaaa');
    });

    it('arma el formulario con lo guardado, sin secretos', () => {
        const form = formFromPayload(payload());
        expect(form).toMatchObject({ host: 'u123456.your-storagebox.de', port: '23', user: 'u123456', auth: 'key', keep_days: '30', password: '', encryption_password: '' });
        expect(formFromPayload(undefined)).toMatchObject({ port: '23', path: 'imagina-growth', auth: 'key', keep_days: '30' });
    });

    it('manda solo lo que cambia: heartbeats escritos o quitados y secretos escritos', () => {
        const form = {
            ...formFromPayload(payload()),
            host: ' u1.your-storagebox.de ',
            path: '/copias/',
            password: 'no-va-con-llave',
            heartbeats: { mariadb: ' https://uptime.betterstack.com/api/v1/heartbeat/abcdefgh ', clickhouse: '', verify: 'https://x' },
            clear_heartbeats: ['verify' as const],
        };
        expect(settingsInput(form, 'clave')).toEqual({
            host: 'u1.your-storagebox.de',
            port: 23,
            user: 'u123456',
            path: 'copias',
            auth: 'key',
            keep_days: 30,
            heartbeats: { mariadb: 'https://uptime.betterstack.com/api/v1/heartbeat/abcdefgh' },
            clear_heartbeats: ['verify'],
            current_password: 'clave',
        });
        const withSecrets = settingsInput({ ...form, auth: 'password', encryption_password: '  frase  ' }, 'clave');
        expect(withSecrets.password).toBe('no-va-con-llave');
        expect(withSecrets.encryption_password).toBe('  frase  ');
    });

    it('avisa el resultado del pedido que se mandó, una vez terminado', () => {
        const last = { id: 'bkp_1', action: 'test' as const, requested_by: null, state: 'failed' as const, code: 'auth_failed', message: null, started_at: null, finished_at: null };
        const done = payload({ last_request: last });
        expect(finishedRequest(done, 'bkp_1')?.code).toBe('auth_failed');
        expect(finishedRequest(done, 'bkp_2')).toBeNull();
        expect(finishedRequest(payload({ last_request: { ...last, state: 'running' } }), 'bkp_1')).toBeNull();
    });
});
