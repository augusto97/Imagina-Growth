// Compila el tracker y sus paquetes bajo demanda en connector/assets (tracker/CLAUDE.md).
import { build } from 'esbuild';
import { readFileSync } from 'node:fs';

const pkg = JSON.parse(readFileSync(new URL('../package.json', import.meta.url), 'utf8'));
const out = new URL('../../connector/assets/', import.meta.url).pathname;
const common = {
    bundle: true,
    minify: true,
    format: 'iife',
    target: 'es2018',
    legalComments: 'none',
    charset: 'utf8',
    define: { __VERSION__: JSON.stringify(pkg.version) },
    logLevel: 'warning',
};

await build({ ...common, entryPoints: ['src/index.ts'], outfile: out + 'tracker.min.js' });
await build({ ...common, entryPoints: ['src/pixels.ts'], outfile: out + 'igr-pixels.js' });
await build({ ...common, entryPoints: ['widgets/survey.ts'], outfile: out + 'igr-widgets.js' });
await build({ ...common, entryPoints: ['src/banner.ts'], outfile: out + 'igr-banner.js' });
console.log('Compilado en ' + out);
