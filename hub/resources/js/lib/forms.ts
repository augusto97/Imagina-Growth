import type { FieldValues, Path, UseFormSetError } from 'react-hook-form';
import { isApiError } from './api';

/**
 * Lleva los errores 422 de Laravel a los campos del formulario. Devuelve el
 * mensaje general si el error no corresponde a ningún campo conocido.
 */
export function applyServerErrors<T extends FieldValues>(error: unknown, setError: UseFormSetError<T>, fields: readonly Path<T>[], fallback: string): string | null {
    if (isApiError(error, 'validation') || (isApiError(error) && Object.keys(error.fieldErrors).length > 0)) {
        let matched = false;
        for (const [field, message] of Object.entries(error.fieldErrors)) {
            if ((fields as readonly string[]).includes(field)) {
                setError(field as Path<T>, { type: 'server', message });
                matched = true;
            }
        }
        if (matched) {
            return null;
        }
        return Object.values(error.fieldErrors)[0] ?? (error.message !== '' ? error.message : fallback);
    }
    if (isApiError(error) && error.message !== '') {
        return error.message;
    }
    return fallback;
}

/** Solo rutas internas como destino de ?next= (evita redirecciones abiertas). */
export function safeNext(next: string | null | undefined, fallback = '/'): string {
    if (!next || !next.startsWith('/') || next.startsWith('//')) {
        return fallback;
    }
    return next;
}
