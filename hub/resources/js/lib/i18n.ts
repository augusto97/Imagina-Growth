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

type Tree = { [key: string]: string | Tree };

/** Fusiona en profundidad los textos de cada módulo (locales/<locale>/<módulo>.json) sobre el archivo base. */
export function mergeLocale(base: Tree, modules: Record<string, Tree>): Tree {
    const merge = (target: Tree, source: Tree): Tree => {
        for (const [key, value] of Object.entries(source)) {
            const current = target[key];
            target[key] = typeof value === 'object' && typeof current === 'object' ? merge({ ...current }, value) : value;
        }
        return target;
    };
    return Object.values(modules).reduce<Tree>((acc, mod) => merge(acc, mod), { ...base });
}

const modules = {
    'es-CO': import.meta.glob<Tree>('../locales/es-CO/*.json', { eager: true, import: 'default' }),
    'es-MX': import.meta.glob<Tree>('../locales/es-MX/*.json', { eager: true, import: 'default' }),
    'es-VE': import.meta.glob<Tree>('../locales/es-VE/*.json', { eager: true, import: 'default' }),
};

// es-CO es la base completa; es-MX y es-VE solo sobrescriben lo que cambia.
void i18n.use(initReactI18next).init({
    resources: {
        'es-CO': { translation: mergeLocale(esCO as Tree, modules['es-CO']) },
        'es-MX': { translation: mergeLocale(esMX as Tree, modules['es-MX']) },
        'es-VE': { translation: mergeLocale(esVE as Tree, modules['es-VE']) },
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
