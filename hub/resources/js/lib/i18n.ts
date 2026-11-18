import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';
import esCO from '@/locales/es-CO.json';
import esMX from '@/locales/es-MX.json';
import esVE from '@/locales/es-VE.json';

export const SUPPORTED_LOCALES = ['es-CO', 'es-MX', 'es-VE'] as const;
export type Locale = (typeof SUPPORTED_LOCALES)[number];

export function isLocale(value: unknown): value is Locale {
    return typeof value === 'string' && (SUPPORTED_LOCALES as readonly string[]).includes(value);
}

// es-CO es la base completa; es-MX y es-VE solo sobrescriben lo que cambia.
void i18n.use(initReactI18next).init({
    resources: {
        'es-CO': { translation: esCO },
        'es-MX': { translation: esMX },
        'es-VE': { translation: esVE },
    },
    lng: 'es-CO',
    fallbackLng: 'es-CO',
    interpolation: { escapeValue: false },
    returnNull: false,
});

export function setLocale(locale: string | null | undefined): void {
    const next = isLocale(locale) ? locale : 'es-CO';
    if (i18n.language !== next) {
        void i18n.changeLanguage(next);
        document.documentElement.lang = next;
    }
}

export default i18n;
