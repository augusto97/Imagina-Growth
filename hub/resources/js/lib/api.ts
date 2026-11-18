/**
 * Cliente HTTP de la SPA. Sesión de Sanctum (cookies), CSRF con XSRF-TOKEN y
 * errores normalizados para la interfaz (ver hub/routes/api.php y Fortify).
 */

export type ApiErrorCode =
    | 'unauthenticated'
    | 'forbidden'
    | 'not_found'
    | 'validation'
    | 'two_factor_required'
    | 'password_confirmation_required'
    | 'csrf'
    | 'rate_limited'
    | 'server'
    | 'network'
    | (string & {});

export class ApiError extends Error {
    readonly status: number;
    readonly code: ApiErrorCode;
    readonly fieldErrors: Record<string, string>;
    readonly body: unknown;

    constructor(status: number, code: ApiErrorCode, message: string, fieldErrors: Record<string, string> = {}, body: unknown = null) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.code = code;
        this.fieldErrors = fieldErrors;
        this.body = body;
    }
}

export function isApiError(error: unknown, code?: ApiErrorCode): error is ApiError {
    return error instanceof ApiError && (code === undefined || error.code === code);
}

type Method = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';

export interface RequestOptions {
    method?: Method;
    body?: unknown;
    signal?: AbortSignal;
}

function readCookie(name: string): string | null {
    const match = document.cookie.split('; ').find((row) => row.startsWith(`${name}=`));
    return match ? decodeURIComponent(match.slice(name.length + 1)) : null;
}

let csrfPromise: Promise<void> | null = null;

export async function ensureCsrf(force = false): Promise<void> {
    if (!force && readCookie('XSRF-TOKEN')) {
        return;
    }
    csrfPromise ??= fetch('/sanctum/csrf-cookie', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(() => undefined)
        .finally(() => {
            csrfPromise = null;
        });
    await csrfPromise;
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/** Convierte una respuesta de error de Laravel en ApiError. */
export function toApiError(status: number, body: unknown): ApiError {
    const data = isRecord(body) ? body : {};
    const message = typeof data.message === 'string' ? data.message : '';
    const explicit = typeof data.error === 'string' ? data.error : null;

    if (status === 422) {
        const fieldErrors: Record<string, string> = {};
        if (isRecord(data.errors)) {
            for (const [field, messages] of Object.entries(data.errors)) {
                if (Array.isArray(messages) && typeof messages[0] === 'string') {
                    fieldErrors[field] = messages[0];
                } else if (typeof messages === 'string') {
                    fieldErrors[field] = messages;
                }
            }
        }
        return new ApiError(status, explicit ?? 'validation', message, fieldErrors, body);
    }

    const code: ApiErrorCode =
        explicit ??
        (status === 401
            ? 'unauthenticated'
            : status === 403
              ? 'forbidden'
              : status === 404
                ? 'not_found'
                : status === 419
                  ? 'csrf'
                  : status === 423
                    ? 'password_confirmation_required'
                    : status === 429
                      ? 'rate_limited'
                      : 'server');

    return new ApiError(status, code, message, {}, body);
}

async function send(path: string, options: RequestOptions): Promise<Response> {
    const method = options.method ?? 'GET';
    if (method !== 'GET') {
        await ensureCsrf();
    }
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };
    const xsrf = readCookie('XSRF-TOKEN');
    if (xsrf) {
        headers['X-XSRF-TOKEN'] = xsrf;
    }
    let body: string | undefined;
    if (options.body !== undefined) {
        headers['Content-Type'] = 'application/json';
        body = JSON.stringify(options.body);
    }

    return fetch(path, { method, headers, body, credentials: 'same-origin', signal: options.signal });
}

export async function apiRequest<T>(path: string, options: RequestOptions = {}): Promise<T> {
    let response: Response;
    try {
        response = await send(path, options);
        if (response.status === 419) {
            // El token CSRF venció (sesión nueva): se pide otro y se reintenta una vez.
            await ensureCsrf(true);
            response = await send(path, options);
        }
    } catch (error) {
        if (error instanceof DOMException && error.name === 'AbortError') {
            throw error;
        }
        throw new ApiError(0, 'network', '', {}, null);
    }

    const text = response.status === 204 ? '' : await response.text();
    let parsed: unknown = null;
    if (text !== '') {
        try {
            parsed = JSON.parse(text);
        } catch {
            parsed = text;
        }
    }

    if (!response.ok) {
        throw toApiError(response.status, parsed);
    }

    return parsed as T;
}

export const api = {
    get: <T>(path: string, signal?: AbortSignal) => apiRequest<T>(path, { signal }),
    post: <T>(path: string, body?: unknown) => apiRequest<T>(path, { method: 'POST', body }),
    put: <T>(path: string, body?: unknown) => apiRequest<T>(path, { method: 'PUT', body }),
    patch: <T>(path: string, body?: unknown) => apiRequest<T>(path, { method: 'PATCH', body }),
    delete: <T>(path: string) => apiRequest<T>(path, { method: 'DELETE' }),
};
