import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import i18n from '@/lib/i18n';

// es-CO es la base completa; es-MX y es-VE solo sobrescriben lo que cambia, con las mismas llaves, en el archivo
// que se llama igual que el de es-CO (es-MX.json ↔ es-CO.json, es-MX/<módulo>.json ↔ es-CO/<módulo>.json).

type Json = string | number | boolean | null | Json[] | { [key: string]: Json };

const here = __dirname;
const BASE = 'es-CO';
const OVERRIDES = ['es-MX', 'es-VE'] as const;
const ROOT_FILE = '(raíz)';
const PLURAL = /_(zero|one|two|few|many|other)$/;

const parseErrors: string[] = [];

function readJson(path: string): Json | undefined {
    try {
        return JSON.parse(readFileSync(path, 'utf8')) as Json;
    } catch (error) {
        parseErrors.push(`${path.replace(here, '')}: ${(error as Error).message}`);
        return undefined;
    }
}

/** Archivos de un idioma por nombre: el raíz (`es-XX.json`) y cada módulo (`es-XX/<módulo>.json`). */
function localeFiles(locale: string): Map<string, Json> {
    const files = new Map<string, Json>();
    const rootJson = readJson(join(here, `${locale}.json`));
    if (rootJson !== undefined) {
        files.set(ROOT_FILE, rootJson);
    }
    const dir = join(here, locale);
    if (existsSync(dir)) {
        for (const name of readdirSync(dir).filter((f) => f.endsWith('.json')).sort()) {
            const tree = readJson(join(dir, name));
            if (tree !== undefined) {
                files.set(name, tree);
            }
        }
    }
    return files;
}

/** Hojas del árbol (`a.b.c` → valor); los arreglos cuentan por índice. */
function leaves(tree: Json, prefix = ''): Map<string, Json> {
    const out = new Map<string, Json>();
    if (tree !== null && typeof tree === 'object') {
        for (const [k, v] of Object.entries(tree)) {
            for (const [key, value] of leaves(v, `${prefix}${k}.`)) {
                out.set(key, value);
            }
        }
    } else {
        out.set(prefix.slice(0, -1), tree);
    }
    return out;
}

/** Arreglos del árbol con su largo: i18next los toma enteros, así que una sobrescritura debe traerlos completos. */
function arrays(tree: Json, prefix = ''): Map<string, number> {
    const out = new Map<string, number>();
    if (tree !== null && typeof tree === 'object') {
        if (Array.isArray(tree)) {
            out.set(prefix.slice(0, -1), tree.length);
        }
        for (const [k, v] of Object.entries(tree)) {
            for (const [key, length] of arrays(v, `${prefix}${k}.`)) {
                out.set(key, length);
            }
        }
    }
    return out;
}

/** Marcadores de interpolación ({{count}}, {{ enlaces.baja }}…), sin repetir y ordenados. */
function markers(text: string): string[] {
    return [...new Set([...text.matchAll(/\{\{\s*([^}]+?)\s*\}\}/g)].map((m) => m[1] ?? ''))].sort();
}

function sourceFiles(dir: string): string[] {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);
        if (statSync(path).isDirectory()) {
            return sourceFiles(path);
        }
        return /\.(ts|tsx)$/.test(name) && !/\.test\./.test(name) ? [path] : [];
    });
}

const files = new Map([BASE, ...OVERRIDES].map((locale) => [locale, localeFiles(locale)] as const));
const baseFiles = files.get(BASE) ?? new Map<string, Json>();
const baseLeaves = new Map([...baseFiles].map(([name, tree]) => [name, leaves(tree)] as const));
const base = new Set([...baseLeaves.values()].flatMap((l) => [...l.keys()]));
const root = join(here, '..');

/** Cada sobrescritura con el valor de es-CO en el archivo del mismo nombre (undefined si es huérfana). */
function overrides(): { locale: string; file: string; key: string; value: Json; base: Json | undefined }[] {
    return OVERRIDES.flatMap((locale) =>
        [...(files.get(locale) ?? new Map<string, Json>())].flatMap(([file, tree]) =>
            [...leaves(tree)].map(([key, value]) => ({ locale, file, key, value, base: baseLeaves.get(file)?.get(key) })),
        ),
    );
}

const where = (o: { locale: string; file: string; key: string }) => `${o.locale} ${o.file}: ${o.key}`;

describe('traducciones', () => {
    it('los JSON de cada idioma son válidos y solo tienen textos', () => {
        expect(parseErrors).toEqual([]);
        const invalid: string[] = [];
        for (const [locale, byFile] of files) {
            for (const [file, tree] of byFile) {
                for (const [key, value] of leaves(tree)) {
                    // es-CO tiene algunos textos vacíos a propósito; una sobrescritura vacía borraría el de la base.
                    if (typeof value !== 'string' || (locale !== BASE && value.trim() === '')) {
                        invalid.push(`${locale} ${file}: ${key}`);
                    }
                }
            }
        }
        expect(invalid).toEqual([]);
    });

    it('es-CO tiene todas las claves fijas que usa el código', () => {
        const missing: string[] = [];
        for (const file of sourceFiles(root)) {
            const code = readFileSync(file, 'utf8');
            for (const match of code.matchAll(/\bt\(\s*'([a-zA-Z0-9_.]+)'/g)) {
                const key = match[1] ?? '';
                // Las claves con plural (count) existen como key_one / key_other.
                if (!base.has(key) && !base.has(`${key}_one`)) {
                    missing.push(`${key} (${file.replace(root, '')})`);
                }
            }
        }
        expect(missing).toEqual([]);
    });

    it('es-MX y es-VE solo sobrescriben claves que existen en es-CO, en el archivo del mismo nombre', () => {
        const orphans = overrides().filter((o) => o.base === undefined).map(where);
        expect(orphans).toEqual([]);

        const partialArrays: string[] = [];
        for (const locale of OVERRIDES) {
            for (const [file, tree] of files.get(locale) ?? new Map<string, Json>()) {
                const baseArrays = arrays(baseFiles.get(file) ?? {});
                for (const [key, length] of arrays(tree)) {
                    if (baseArrays.get(key) !== length) {
                        partialArrays.push(`${locale} ${file}: ${key}`);
                    }
                }
            }
        }
        expect(partialArrays).toEqual([]);
    });

    it('las sobrescrituras cambian el texto y conservan los marcadores de es-CO', () => {
        const same: string[] = [];
        const wrongMarkers: string[] = [];
        for (const o of overrides()) {
            if (typeof o.value !== 'string' || typeof o.base !== 'string') {
                continue;
            }
            if (o.value === o.base) {
                same.push(where(o));
            }
            if (markers(o.value).join(' ') !== markers(o.base).join(' ')) {
                wrongMarkers.push(`${where(o)} → ${markers(o.value).join(', ') || '—'} (es-CO: ${markers(o.base).join(', ') || '—'})`);
            }
        }
        expect(same).toEqual([]);
        expect(wrongMarkers).toEqual([]);
    });

    it('una sobrescritura con plural trae todas las formas que tiene es-CO', () => {
        const incomplete: string[] = [];
        for (const locale of OVERRIDES) {
            for (const [file, tree] of files.get(locale) ?? new Map<string, Json>()) {
                const keys = new Set(leaves(tree).keys());
                const baseKeys = [...(baseLeaves.get(file)?.keys() ?? [])];
                for (const key of keys) {
                    if (!PLURAL.test(key)) {
                        continue;
                    }
                    const stem = key.replace(PLURAL, '');
                    for (const form of baseKeys.filter((k) => PLURAL.test(k) && k.replace(PLURAL, '') === stem)) {
                        if (!keys.has(form)) {
                            incomplete.push(`${locale} ${file}: ${form}`);
                        }
                    }
                }
            }
        }
        expect(incomplete).toEqual([]);
    });

    it('la app sirve cada sobrescritura y cae a es-CO en lo que no cambia', () => {
        const lost = overrides()
            .filter((o) => typeof o.value === 'string' && !/\.\d+$/.test(o.key))
            .filter((o) => i18n.getResource(o.locale, 'translation', o.key) !== o.value)
            .map(where);
        expect(lost).toEqual([]);

        const co = i18n.getFixedT('es-CO');
        const mx = i18n.getFixedT('es-MX');
        const ve = i18n.getFixedT('es-VE');
        expect(mx('analytics.geo.region')).toBe('Estado');
        expect(mx('consent.policy.title')).toBe('Aviso de privacidad');
        expect(ve('consent.policy.title')).toBe(co('consent.policy.title'));
        expect(ve('home.codPending', { count: 2 })).toBe('2 pedidos de pago contra entrega por confirmar');
        expect(mx('analytics.title')).toBe(co('analytics.title'));
        expect(mx('orderStatus.completed')).toBe(co('orderStatus.completed'));
    });

    it('incluye los valores dinámicos de roles, estados y entidades', () => {
        for (const key of ['roles.owner', 'roles.viewer', 'orderStatus.on-hold', 'orderStatus.completed', 'sites.status.degraded', 'sites.entities.orders', 'nav.flows', 'theme.system']) {
            expect(base.has(key)).toBe(true);
        }
    });
});
