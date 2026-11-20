import type { TFunction } from 'i18next';

/**
 * Nombre del departamento o estado a partir de su código ISO 3166-2 (`CO-DC` → «Bogotá D.C.»), con los textos de
 * locales/es-CO/regions.json. Si no se conoce, el código tal cual.
 */
export function regionName(t: TFunction, code: string | null | undefined): string {
    if (!code) {
        return '';
    }
    return t(`regions.${code}`, { defaultValue: code });
}
