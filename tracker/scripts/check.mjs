// CI del tracker (tracker/CLAUDE.md §9): tamaño gzip y nada de eval ni new Function.
import { readFileSync } from 'node:fs';
import { gzipSync } from 'node:zlib';

const dir = new URL('../../connector/assets/', import.meta.url).pathname;
const limits = { 'tracker.min.js': 6144, 'igr-pixels.js': 3072, 'igr-widgets.js': 6144, 'igr-banner.js': 2048 };
let failed = false;
for (const [file, limit] of Object.entries(limits)) {
    const code = readFileSync(dir + file);
    const size = gzipSync(code, { level: 9 }).length;
    const bad = /\beval\s*\(|new\s+Function\s*\(/.test(code.toString());
    const ok = size <= limit && !bad;
    failed ||= !ok;
    console.log(`${ok ? '✓' : '✗'} ${file}: ${size} B gzip (límite ${limit})${bad ? ' · usa eval o new Function' : ''}`);
}
process.exit(failed ? 1 : 0);
