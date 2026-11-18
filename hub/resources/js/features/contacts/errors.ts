import type { TFunction } from 'i18next';
import { isApiError } from '@/lib/api';

/** El primer error de validación, el mensaje del servidor o el genérico. */
export function errorMessage(error: unknown, t: TFunction): string {
    if (isApiError(error)) {
        const first = Object.values(error.fieldErrors)[0];
        if (first) {
            return first;
        }
        if (error.message !== '') {
            return error.message;
        }
        if (error.code === 'forbidden') {
            return t('contacts.errors.forbidden');
        }
    }
    return t('errors.generic');
}
