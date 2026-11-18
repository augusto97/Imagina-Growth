import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { api, ApiError, isApiError, toApiError } from './api';

function jsonResponse(status: number, body: unknown): Response {
    return new Response(body === null ? null : JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

describe('api client', () => {
    const fetchMock = vi.fn<typeof fetch>();

    beforeEach(() => {
        vi.stubGlobal('fetch', fetchMock);
        document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
    });

    afterEach(() => {
        fetchMock.mockReset();
        vi.unstubAllGlobals();
    });

    it('pide la cookie CSRF antes del primer POST y envía X-XSRF-TOKEN', async () => {
        fetchMock.mockImplementation((input) => {
            if (String(input) === '/sanctum/csrf-cookie') {
                document.cookie = 'XSRF-TOKEN=abc%3D123; path=/';
                return Promise.resolve(new Response(null, { status: 204 }));
            }
            return Promise.resolve(jsonResponse(200, { ok: true }));
        });

        await expect(api.post('/api/workspaces', { name: 'X' })).resolves.toEqual({ ok: true });

        expect(fetchMock.mock.calls[0]?.[0]).toBe('/sanctum/csrf-cookie');
        const init = fetchMock.mock.calls[1]?.[1];
        const headers = init?.headers as Record<string, string>;
        expect(headers['X-XSRF-TOKEN']).toBe('abc=123');
        expect(headers.Accept).toBe('application/json');
        expect(init?.body).toBe(JSON.stringify({ name: 'X' }));
        expect(init?.credentials).toBe('same-origin');
    });

    it('reintenta una vez con un token nuevo si el servidor responde 419', async () => {
        document.cookie = 'XSRF-TOKEN=viejo; path=/';
        let calls = 0;
        fetchMock.mockImplementation((input) => {
            if (String(input) === '/sanctum/csrf-cookie') {
                document.cookie = 'XSRF-TOKEN=nuevo; path=/';
                return Promise.resolve(new Response(null, { status: 204 }));
            }
            calls++;
            return Promise.resolve(calls === 1 ? jsonResponse(419, { message: 'CSRF token mismatch.' }) : new Response(null, { status: 204 }));
        });

        await expect(api.post('/auth/logout')).resolves.toBeNull();
        const lastHeaders = fetchMock.mock.calls.at(-1)?.[1]?.headers as Record<string, string>;
        expect(lastHeaders['X-XSRF-TOKEN']).toBe('nuevo');
    });

    it('lleva los errores 422 a fieldErrors', async () => {
        document.cookie = 'XSRF-TOKEN=x; path=/';
        fetchMock.mockResolvedValue(jsonResponse(422, { message: 'Datos inválidos', errors: { email: ['Ese correo ya existe.'], role: ['Rol inválido.'] } }));
        const error = await api.post('/api/w/ws_1/invitations', {}).catch((e: unknown) => e);
        expect(isApiError(error, 'validation')).toBe(true);
        expect((error as ApiError).fieldErrors).toEqual({ email: 'Ese correo ya existe.', role: 'Rol inválido.' });
    });

    it('distingue two_factor_required, la confirmación de contraseña y la sesión vencida', async () => {
        fetchMock.mockResolvedValueOnce(jsonResponse(403, { error: 'two_factor_required', message: 'Activa la verificación' }));
        await expect(api.get('/api/w/ws_1')).rejects.toMatchObject({ status: 403, code: 'two_factor_required' });

        fetchMock.mockResolvedValueOnce(jsonResponse(423, { message: 'Password confirmation required.' }));
        await expect(api.get('/auth/user/two-factor-qr-code')).rejects.toMatchObject({ code: 'password_confirmation_required' });

        fetchMock.mockResolvedValueOnce(jsonResponse(401, { message: 'Unauthenticated.' }));
        await expect(api.get('/api/me')).rejects.toMatchObject({ code: 'unauthenticated' });

        fetchMock.mockResolvedValueOnce(jsonResponse(404, { error: 'not_found' }));
        await expect(api.get('/api/w/ws_x')).rejects.toMatchObject({ code: 'not_found' });
    });

    it('convierte un fallo de red en ApiError network', async () => {
        fetchMock.mockRejectedValue(new TypeError('Failed to fetch'));
        await expect(api.get('/api/me')).rejects.toMatchObject({ status: 0, code: 'network' });
    });

    it('mapea códigos HTTP sin cuerpo', () => {
        expect(toApiError(429, null).code).toBe('rate_limited');
        expect(toApiError(500, 'Server Error').code).toBe('server');
        expect(toApiError(502, { message: 'El sitio no respondió' }).message).toBe('El sitio no respondió');
    });
});
