import { existsSync, readdirSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { EAGER_TEXTS } from '@/lib/i18n';
import { ROUTE_TEXTS } from './routeTexts';

/*
 * Textos por pantalla (docs/rendimiento.md §7.1, 1): lee el código de cada carpeta de `features/` y de lo que importa,
 * junta las llaves de texto que usa (literales y prefijos de plantillas como `analytics.metric.${k}`) y calcula qué
 * módulos de `locales/es-CO/` necesita. Así `app/routeTexts.ts` no se queda corto cuando una pantalla empieza a usar
 * textos de otro módulo. Para regenerarlo: `UPDATE_ROUTE_TEXTS=1 npx vitest run resources/js/app/routeTexts.test.ts`.
 */

const root = resolve(__dirname, '..');
const locales = join(root, 'locales', 'es-CO');

type Json = string | number | boolean | null | Json[] | { [key: string]: Json };

function leaves(tree: Json, prefix = '', out: string[] = []): string[] {
    if (tree !== null && typeof tree === 'object') {
        for (const [k, v] of Object.entries(tree)) {
            leaves(v, `${prefix}${k}.`, out);
        }
    } else {
        out.push(prefix.slice(0, -1));
    }
    return out;
}

/** Hojas de cada módulo de es-CO (el archivo raíz no cuenta: siempre está). */
const moduleLeaves = new Map(
    readdirSync(locales)
        .filter((f) => f.endsWith('.json'))
        .map((f) => [f.replace(/\.json$/, ''), leaves(JSON.parse(readFileSync(join(locales, f), 'utf8')) as Json)] as const),
);
const rootLeaves = new Set(leaves(JSON.parse(readFileSync(join(root, 'locales', 'es-CO.json'), 'utf8')) as Json));
const PLURAL = /_(zero|one|two|few|many|other)$/;

function resolveImport(from: string, spec: string): string | null {
    let base: string;
    if (spec.startsWith('@/')) {
        base = join(root, spec.slice(2));
    } else if (spec.startsWith('.')) {
        base = resolve(dirname(from), spec);
    } else {
        return null;
    }
    for (const candidate of [base, `${base}.ts`, `${base}.tsx`, join(base, 'index.ts'), join(base, 'index.tsx')]) {
        if (existsSync(candidate) && statSync(candidate).isFile() && /\.(ts|tsx)$/.test(candidate)) {
            return candidate;
        }
    }
    return null;
}

/**
 * Los archivos que alcanza un conjunto de entradas (imports estáticos y, si se pide, también los perezosos). `stop`: lo
 * que ya trae el arranque, que no se recorre (sus textos ya están).
 */
function graph(entries: string[], withDynamic: boolean, stop: ReadonlySet<string> = new Set()): Set<string> {
    const seen = new Set<string>();
    const stack = [...entries];
    while (stack.length > 0) {
        const file = stack.pop() ?? '';
        if (seen.has(file) || stop.has(file)) {
            continue;
        }
        seen.add(file);
        const code = readFileSync(file, 'utf8');
        const specs = [
            ...code.matchAll(/(?:^|\n)\s*(?:import|export)\s[^'"]*?from\s+['"]([^'"]+)['"]/g),
            ...code.matchAll(/(?:^|\n)\s*import\s+['"]([^'"]+)['"]/g),
            ...(withDynamic ? code.matchAll(/import\(\s*['"]([^'"]+)['"]\s*\)/g) : []),
        ].map((m) => m[1] ?? '');
        for (const spec of specs) {
            const target = resolveImport(file, spec);
            if (target && !/\.test\.tsx?$/.test(target)) {
                stack.push(target);
            }
        }
    }
    return seen;
}

/** Llaves y prefijos de texto que aparecen en el código. */
function patterns(files: Iterable<string>): { keys: Set<string>; prefixes: Set<string> } {
    const keys = new Set<string>();
    const prefixes = new Set<string>();
    for (const file of files) {
        const code = readFileSync(file, 'utf8');
        for (const m of code.matchAll(/(['"`])([a-zA-Z][\w-]*(?:\.[\w-]+)+)\1/g)) {
            keys.add(m[2] ?? '');
        }
        for (const m of code.matchAll(/`([a-zA-Z][\w-]*(?:\.[\w-]+)*)\.\$\{/g)) {
            prefixes.add(m[1] ?? '');
        }
    }
    return { keys, prefixes };
}

/** Los módulos de textos que necesitan esas llaves (los que tienen la llave, su plural o algo debajo). */
function modulesFor({ keys, prefixes }: { keys: Set<string>; prefixes: Set<string> }): Set<string> {
    const out = new Set<string>();
    for (const [name, list] of moduleLeaves) {
        for (const leaf of list) {
            const stem = leaf.replace(PLURAL, '');
            if (keys.has(leaf) || keys.has(stem) || [...prefixes].some((p) => leaf.startsWith(`${p}.`)) || [...keys].some((k) => leaf.startsWith(`${k}.`) && !rootLeaves.has(k))) {
                out.add(name);
                break;
            }
        }
    }
    return out;
}

function sourceFiles(dir: string): string[] {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);
        if (statSync(path).isDirectory()) {
            return sourceFiles(path);
        }
        return /\.(ts|tsx)$/.test(name) && !/\.test\.tsx?$/.test(name) ? [path] : [];
    });
}

const features = readdirSync(join(root, 'features')).filter((f) => statSync(join(root, 'features', f)).isDirectory());
const routeFiles = features.map((f) => join(root, 'features', f, 'routes.tsx')).filter((p) => existsSync(p));

/** El marco de la app: lo que carga el arranque (sin las pantallas perezosas). */
const shellFiles = graph([join(root, 'main.tsx'), ...routeFiles], false);
const shell = modulesFor(patterns(shellFiles));

/** Cada carpeta de pantallas: sus archivos y lo que importan, menos lo que ya trae el arranque. */
const computed: Record<string, string[]> = Object.fromEntries(
    features
        .map((f) => [f, [...modulesFor(patterns(graph(sourceFiles(join(root, 'features', f)), true, shellFiles)))].filter((m) => !shell.has(m)).sort()] as const)
        .filter(([, list]) => list.length > 0)
        .sort(([a], [b]) => a.localeCompare(b)),
);

describe('textos por pantalla', () => {
    it('el arranque trae los módulos que usa el marco de la app (lib/i18n.ts, EAGER)', () => {
        expect([...shell].sort()).toEqual([...EAGER_TEXTS]);
    });

    it('el chunk de textos del arranque (vite.config.ts) lleva los mismos módulos y ninguno más', () => {
        const config = readFileSync(join(root, '..', '..', 'vite.config.ts'), 'utf8');
        // La parte final de la expresión del grupo `locale`: …[\\/](ui|sections|billing)\.json)$/
        const listed = /\(([a-z|-]+)\)\\\.json\)\$/.exec(config);
        expect(listed?.[1]?.split('|').sort()).toEqual([...EAGER_TEXTS]);
    });

    it('cada carpeta de pantallas declara los módulos de textos que usa (app/routeTexts.ts)', () => {
        if (process.env.UPDATE_ROUTE_TEXTS === '1') {
            const body = Object.entries(computed)
                .map(([f, list]) => `    ${/^[a-z]+$/.test(f) ? f : `'${f}'`}: [${list.map((m) => `'${m}'`).join(', ')}],`)
                .join('\n');
            const file = join(root, 'app', 'routeTexts.ts');
            const head = readFileSync(file, 'utf8').split('export const ROUTE_TEXTS')[0] ?? '';
            writeFileSync(file, `${head}export const ROUTE_TEXTS: Record<string, readonly string[]> = {\n${body}\n};\n`);
            return;
        }
        expect(ROUTE_TEXTS).toEqual(computed);
    });
});
