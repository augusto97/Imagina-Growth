/**
 * Cuánto ocupa un SMS (modulos.md M07/M08): la misma cuenta que el hub (`App\Domain\Messaging\Support\SmsText` y
 * `App\Domain\Templates\Support\SmsEstimate`), con los casos compartidos de `tests/fixtures/sms/cases.json`.
 *
 * - GSM-7 (3GPP TS 23.038): 160 en un mensaje, 153 por parte; `^ { } \ [ ~ ] | €` (y el salto de página) cuentan
 *   doble y nunca se parten entre partes.
 * - UCS-2 si hay una letra fuera de GSM-7: 70 en uno, 67 por parte; un emoji ocupa dos y no se parte.
 * - En GSM-7 están «é É ñ Ñ ü Ü ä ö à è ì ò ù ¿ ¡»; no «á í ó ú», las comillas tipográficas ni los emojis.
 */

export const GSM_SINGLE = 160;
export const GSM_PART = 153;
export const UCS_SINGLE = 70;
export const UCS_PART = 67;
/** Con «Permitir mensajes de 2 partes», el máximo; sin él, uno. */
export const MAX_PARTS_ALLOWED = 2;

const BASIC = "@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
const EXTENSION = '\f^{}\\[~]|€';

const TRANSLITERATION: Record<string, string> = {
    á: 'a', â: 'a', ã: 'a', Á: 'A', À: 'A', Â: 'A', Ã: 'A',
    ê: 'e', ë: 'e', Ê: 'E', Ë: 'E', È: 'E',
    í: 'i', î: 'i', ï: 'i', Í: 'I', Ì: 'I', Î: 'I', Ï: 'I',
    ó: 'o', ô: 'o', õ: 'o', Ó: 'O', Ò: 'O', Ô: 'O', Õ: 'O',
    ú: 'u', û: 'u', Ú: 'U', Ù: 'U', Û: 'U',
    ç: 'c', ý: 'y', ÿ: 'y', Ý: 'Y', ª: 'a', º: 'o', '°': 'o',
    '‘': "'", '’': "'", '‚': "'", '′': "'", '´': "'", '`': "'",
    '“': '"', '”': '"', '„': '"', '″': '"', '«': '"', '»': '"',
    '–': '-', '—': '-', '‐': '-', '‑': '-', '−': '-', '•': '-', '·': '-',
    '…': '...', '\t': ' ', ' ': ' ', ' ': ' ', ' ': ' ', ' ': ' ', '​': '',
};

export interface SmsMeasure {
    encoding: 'GSM-7' | 'UCS-2';
    length: number;
    segments: number;
    singleLimit: number;
    partLimit: number;
    /** Las letras que pasan el SMS a UCS-2 (hasta 10, sin repetir). */
    offenders: string[];
}

function parts(units: number[], single: number, part: number): number {
    const total = units.reduce((a, b) => a + b, 0);
    if (total === 0) return 0;
    if (total <= single) return 1;
    let count = 1;
    let used = 0;
    for (const u of units) {
        if (used + u > part) {
            count += 1;
            used = 0;
        }
        used += u;
    }
    return count;
}

export function measureSms(text: string): SmsMeasure {
    const chars = Array.from(text);
    const offenders: string[] = [];
    const septets: number[] = [];
    for (const c of chars) {
        if (BASIC.includes(c)) septets.push(1);
        else if (EXTENSION.includes(c)) septets.push(2);
        else if (!offenders.includes(c)) offenders.push(c);
    }
    if (offenders.length === 0) {
        return {
            encoding: 'GSM-7',
            length: septets.reduce((a, b) => a + b, 0),
            segments: parts(septets, GSM_SINGLE, GSM_PART),
            singleLimit: GSM_SINGLE,
            partLimit: GSM_PART,
            offenders: [],
        };
    }
    const units = chars.map((c) => ((c.codePointAt(0) ?? 0) > 0xffff ? 2 : 1));
    return {
        encoding: 'UCS-2',
        length: units.reduce((a, b) => a + b, 0),
        segments: parts(units, UCS_SINGLE, UCS_PART),
        singleLimit: UCS_SINGLE,
        partLimit: UCS_PART,
        offenders: offenders.slice(0, 10),
    };
}

/** Cambia lo que tiene equivalente en GSM-7 (tildes agudas, comillas tipográficas, rayas); la ñ, la é y la ü quedan. */
export function transliterateSms(text: string): string {
    return Array.from(text)
        .map((c) => TRANSLITERATION[c] ?? c)
        .join('');
}

export function maxParts(allowMultipart: boolean): number {
    return allowMultipart ? MAX_PARTS_ALLOWED : 1;
}

/** Lo que el hub manda en `sms_limits` de la plantilla. */
export interface SmsLimits {
    link_length: number;
    unsubscribe_length: number;
    store_name_length: number;
}

/** Largo máximo realista de cada variable (los mismos del hub). */
const LENGTHS: Record<string, number> = {
    'contacto.nombre': 20, 'contacto.apellido': 20, 'contacto.nombre_completo': 40, 'contacto.ciudad': 20,
    'contacto.pedidos': 4, 'contacto.puntos': 7, 'contacto.nivel': 12,
    'tienda.direccion': 50, 'tienda.contacto': 30,
    'carrito.subtotal': 14, 'carrito.total': 14, 'carrito.cantidad_items': 3,
    'cupon.codigo': 16, 'cupon.descuento': 8, 'cupon.vence': 28,
    'pedido.numero': 8, 'pedido.total': 14, 'pedido.metodo_pago': 20,
    'producto.nombre': 40, 'producto.precio': 14,
    'cita.servicio': 30, 'cita.fecha': 28, 'cita.hora': 8, 'cita.lugar': 20, 'cita.duracion': 10,
    'lead.servicio': 30, 'lead.etapa': 20, 'cotizacion.servicio': 30, 'cotizacion.valor': 14,
    'evento.nombre': 30, 'evento.fecha': 28, 'evento.hora': 8,
};
const SUFFIXES: Record<string, number> = { nombre: 40, precio: 14, total: 14, subtotal: 14, valor: 14, fecha: 28, hora: 8, cantidad: 3, codigo: 16 };
const DEFAULT_LENGTH = 20;
const MONEY = 14;
const DATE = 28;
const DATE_SHORT = 12;
const LOOP_TIMES = 3;

function valueLength(value: string, limits: SmsLimits): number {
    if (value === '') return 0;
    const quoted = /^(['"])([\s\S]*)\1$/.exec(value);
    if (quoted) return Array.from(quoted[2] ?? '').length;
    if (value.trim() !== '' && !Number.isNaN(Number(value))) return Array.from(value).length;
    const path = value.toLowerCase();
    if (path === 'enlaces.baja' || path === 'enlaces.preferencias') return limits.unsubscribe_length;
    const last = path.split('.').pop() ?? path;
    if (path.startsWith('enlaces.') || last.includes('url') || last.includes('enlace')) return limits.link_length;
    if (path === 'tienda.nombre') return limits.store_name_length;
    return LENGTHS[path] ?? SUFFIXES[last] ?? DEFAULT_LENGTH;
}

/** La variable y cada filtro, sin partir lo que está entre comillas. */
function splitFilters(expression: string): string[] {
    const out: string[] = [];
    let current = '';
    let quote: string | null = null;
    for (const c of Array.from(expression)) {
        if (quote !== null) {
            current += c;
            if (c === quote) quote = null;
        } else if (c === '"' || c === "'") {
            quote = c;
            current += c;
        } else if (c === '|') {
            out.push(current);
            current = '';
        } else {
            current += c;
        }
    }
    out.push(current);
    return out;
}

/** Largo máximo de lo que pinta `{{ … }}`. */
export function expressionLength(expression: string, limits: SmsLimits): number {
    const [head = '', ...filters] = splitFilters(expression);
    let length = valueLength(head.trim(), limits);
    for (const filter of filters) {
        const colon = filter.indexOf(':');
        const name = (colon === -1 ? filter : filter.slice(0, colon)).trim();
        const arg = colon === -1 ? null : filter.slice(colon + 1).trim();
        const argLength = arg !== null && arg !== '' ? valueLength(arg, limits) : 0;
        if (name === 'default') length = Math.max(length, argLength);
        else if (name === 'moneda' || name === 'money') length = MONEY;
        else if (name === 'fecha' || name === 'date') length = arg !== null && arg.includes('corta') ? DATE_SHORT : DATE;
        else if (name === 'append' || name === 'prepend') length += argLength;
        else if (name === 'truncate' && arg !== null && /^\d+$/.test(arg.replace(/^[\s'"]+|[\s'"]+$/g, ''))) length = Math.min(length, Number(arg.replace(/^[\s'"]+|[\s'"]+$/g, '')));
    }
    return length;
}

/** El texto del peor caso: variables y enlaces como «x» de su largo máximo. */
export function expandSms(body: string, limits: SmsLimits): string {
    let text = body.replace(/\{%-?\s*comment\s*-?%\}[\s\S]*?\{%-?\s*endcomment\s*-?%\}/g, '');
    text = text.replace(/\{%-?\s*for\s+[^%]*?-?%\}([\s\S]*?)\{%-?\s*endfor\s*-?%\}/g, (all: string, inner: string) => {
        const limit = /limit:\s*(\d+)/.exec(all);
        return inner.repeat(limit ? Number(limit[1]) : LOOP_TIMES);
    });
    text = text.replace(/\{%[\s\S]*?%\}/g, '');
    text = text.replace(/\{\{([\s\S]*?)\}\}/g, (_: string, expr: string) => 'x'.repeat(expressionLength(expr, limits)));
    // Los enlaces escritos tal cual salen rastreados; el hub no acorta su baja ni sus preferencias (no están escritas aquí).
    return text.replace(/https?:\/\/[^\s<>"')\]]+/gi, () => 'x'.repeat(limits.link_length));
}

export interface SmsEstimate extends SmsMeasure {
    hasVariables: boolean;
}

/** El peor caso de una plantilla con Liquid (sin límites del hub, el texto tal cual). */
export function estimateSms(body: string, limits: SmsLimits | null | undefined): SmsEstimate {
    const hasVariables = /\{\{|\{%/.test(body);
    return { ...measureSms(limits ? expandSms(body, limits) : body), hasVariables };
}

/** Si el SMS no cabe: cuánto sobra y frente a qué límite. */
export function smsOverflow(info: SmsMeasure, allowMultipart: boolean): { limit: number; over: number } | null {
    const max = maxParts(allowMultipart);
    if (info.segments <= max) return null;
    const limit = max === 1 ? info.singleLimit : max * info.partLimit;
    return { limit, over: Math.max(1, info.length - limit) };
}
