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

/*
 * Textos por módulo (docs/rendimiento.md §7.1, 1). El arranque trae solo `es-<país>.json` (menú, comunes, errores) y
 * los módulos que usa el marco de la app (EAGER: los componentes base, las secciones del menú y el aviso de
 * facturación). Los demás `locales/<locale>/<módulo>.json` se piden con la pantalla que los usa: cada ruta los declara
 * en `app/routeTexts.ts` (una prueba los recalcula leyendo el código) y `withTexts` los espera junto con el chunk de la
 * pantalla. Si el espacio de trabajo es de México o Venezuela, con cada módulo llega también lo que cambia en ese país.
 */
const EAGER_CO = import.meta.glob<Tree>(['../locales/es-CO/ui.json', '../locales/es-CO/sections.json', '../locales/es-CO/billing.json'], {
    eager: true,
    import: 'default',
});
const EAGER_MX = import.meta.glob<Tree>(['../locales/es-MX/ui.json', '../locales/es-MX/sections.json', '../locales/es-MX/billing.json'], {
    eager: true,
    import: 'default',
});
const EAGER_VE = import.meta.glob<Tree>(['../locales/es-VE/ui.json', '../locales/es-VE/sections.json', '../locales/es-VE/billing.json'], {
    eager: true,
    import: 'default',
});

const LAZY: Record<Locale, Record<string, () => Promise<Tree>>> = {
    'es-CO': import.meta.glob<Tree>('../locales/es-CO/*.json', { import: 'default' }),
    'es-MX': import.meta.glob<Tree>('../locales/es-MX/*.json', { import: 'default' }),
    'es-VE': import.meta.glob<Tree>('../locales/es-VE/*.json', { import: 'default' }),
};

const moduleName = (path: string): string => path.replace(/^.*\//, '').replace(/\.json$/, '');

/** Los módulos que llegan con el arranque. */
export const EAGER_TEXTS: readonly string[] = Object.keys(EAGER_CO).map(moduleName).sort();

/** Todos los módulos de textos que existen (es-CO es la base completa). */
export const ALL_TEXTS: readonly string[] = Object.keys(LAZY['es-CO']).map(moduleName).sort();

// es-CO es la base completa; es-MX y es-VE solo sobrescriben lo que cambia.
void i18n.use(initReactI18next).init({
    resources: {
        'es-CO': { translation: mergeLocale(esCO as Tree, EAGER_CO) },
        'es-MX': { translation: mergeLocale(esMX as Tree, EAGER_MX) },
        'es-VE': { translation: mergeLocale(esVE as Tree, EAGER_VE) },
    },
    lng: 'es-CO',
    fallbackLng: 'es-CO',
    interpolation: { escapeValue: false },
    returnNull: false,
});

/** Lo ya pedido por idioma y módulo (la promesa: dos pantallas que piden el mismo módulo esperan la misma descarga). */
const requested = new Map<string, Promise<void>>();
for (const name of EAGER_TEXTS) {
    for (const locale of SUPPORTED_LOCALES) {
        requested.set(`${locale}/${name}`, Promise.resolve());
    }
}
/** Los módulos que alguna pantalla ya pidió: al cambiar de idioma se piden también en el nuevo. */
const wanted = new Set<string>(EAGER_TEXTS);

function loadOne(locale: Locale, name: string): Promise<void> {
    const key = `${locale}/${name}`;
    const existing = requested.get(key);
    if (existing) {
        return existing;
    }
    const load = LAZY[locale][`../locales/${locale}/${name}.json`];
    // es-MX y es-VE solo tienen los módulos que cambian: los demás caen a es-CO.
    const promise = load
        ? load().then(
              (tree) => {
                  i18n.addResourceBundle(locale, 'translation', tree, true, true);
              },
              (error: unknown) => {
                  requested.delete(key);
                  throw error;
              },
          )
        : Promise.resolve();
    requested.set(key, promise);
    return promise;
}

/** El idioma al que se está cambiando (sus textos se piden ya, aunque i18next todavía no haya cambiado). */
let target: Locale | null = null;

function currentLocale(): Locale {
    return target ?? (isLocale(i18n.language) ? i18n.language : 'es-CO');
}

/** Pide los textos de estos módulos (es-CO y el idioma actual) y resuelve cuando ya se pueden usar. */
export function loadTexts(names: readonly string[]): Promise<void> {
    const locale = currentLocale();
    const jobs: Promise<void>[] = [];
    for (const name of names) {
        wanted.add(name);
        jobs.push(loadOne('es-CO', name));
        if (locale !== 'es-CO') {
            jobs.push(loadOne(locale, name));
        }
    }
    return Promise.all(jobs).then(() => undefined);
}

/** Todos los textos de todos los idiomas (pruebas y herramientas). */
export function loadAllTexts(): Promise<void> {
    return Promise.all(SUPPORTED_LOCALES.flatMap((locale) => ALL_TEXTS.map((name) => loadOne(locale, name)))).then(() => undefined);
}

/** La ruta perezosa espera su pantalla y sus textos a la vez (React Router no pinta hasta que `lazy` resuelve). */
export function withTexts<T>(names: readonly string[], load: () => Promise<T>): () => Promise<T> {
    return async () => {
        const [route] = await Promise.all([load(), loadTexts(names)]);
        return route;
    };
}

export function setLocale(locale: string | null | undefined): void {
    const next = isLocale(locale) ? locale : 'es-CO';
    if (currentLocale() === next) {
        return;
    }
    target = next;
    // Lo que cambia en el país llega antes de cambiar de idioma, así no se ve un instante el texto de Colombia.
    const overrides = next === 'es-CO' ? Promise.resolve() : Promise.all([...wanted].map((name) => loadOne(next, name)));
    void overrides
        .catch(() => undefined)
        .then(() => {
            if (target !== next) {
                return;
            }
            void i18n.changeLanguage(next);
            document.documentElement.lang = next;
            target = null;
        });
}

export default i18n;
