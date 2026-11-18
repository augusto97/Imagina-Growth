// Valida los eventos del tracker contra hub/resources/schemas/events (el contrato compartido).
import Ajv2020 from 'ajv/dist/2020.js';
import addFormats from 'ajv-formats';
import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

const dir = join(__dirname, '../../hub/resources/schemas/events');
const ajv = new Ajv2020({ strict: false, allErrors: true });
addFormats(ajv);
for (const f of readdirSync(dir)) {
    if (f.endsWith('.json') && !f.includes('example')) ajv.addSchema(JSON.parse(readFileSync(join(dir, f), 'utf8')));
}
const BASE = 'https://growth.imagina.cloud/schemas/events/';

export function contractErrors(envelope: unknown): string[] {
    const out: string[] = [];
    const env = ajv.getSchema(BASE + '_envelope.json');
    if (env && !env(envelope)) out.push('sobre: ' + ajv.errorsText(env.errors));
    const e = envelope as { event?: string; properties?: unknown };
    const name = e.event ?? '';
    const props = ajv.getSchema(BASE + (name.startsWith('custom.') ? 'custom' : name) + '.json');
    if (!props) out.push('evento desconocido: ' + name);
    else if (!props(e.properties)) out.push(name + ': ' + ajv.errorsText(props.errors));
    return out;
}
