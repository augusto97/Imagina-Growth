import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import esCO from './es-CO.json';
import esMX from './es-MX.json';
import esVE from './es-VE.json';

type Tree = { [key: string]: string | Tree };

function flatten(tree: Tree, prefix = ''): string[] {
    return Object.entries(tree).flatMap(([k, v]) => (typeof v === 'string' ? [`${prefix}${k}`] : flatten(v, `${prefix}${k}.`)));
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

const base = new Set(flatten(esCO as Tree));
const root = join(__dirname, '..');

describe('traducciones', () => {
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

    it('es-MX y es-VE solo sobrescriben claves que existen en es-CO', () => {
        for (const keys of [flatten(esMX as Tree), flatten(esVE as Tree)]) {
            expect(keys.filter((k) => !base.has(k))).toEqual([]);
        }
    });

    it('incluye los valores dinámicos de roles, estados y entidades', () => {
        for (const key of ['roles.owner', 'roles.viewer', 'orderStatus.on-hold', 'orderStatus.completed', 'sites.status.degraded', 'sites.entities.orders', 'nav.flows', 'theme.system']) {
            expect(base.has(key)).toBe(true);
        }
    });
});
