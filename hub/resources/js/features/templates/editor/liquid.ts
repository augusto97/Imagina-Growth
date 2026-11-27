/*
 * El Liquid en el lienzo del editor de correo (modulos.md M08).
 *
 * El MJML guardado lleva Liquid crudo (`{{ tienda.nombre }}`, `{% if … %}`): el hub lo renderiza al enviar
 * (sandbox, hub/CLAUDE.md §8). En el lienzo, en cambio, cada expresión que está en el texto se convierte en una
 * ficha legible («Nombre del negocio», «Si hay logo») que al exportar vuelve a ser el Liquid de siempre: así el
 * MJML y el HTML guardados no cambian. Lo de los atributos (`href`, `src`) se queda como está.
 *
 * Todo es puro y con pruebas; `EmailCanvas` lo usa como pre-procesador del parser de GrapesJS.
 */

/** El atributo que marca una ficha de Liquid en el lienzo. */
export const LIQUID_ATTR = 'data-igr-liquid';

export type LiquidKind = 'var' | 'logic' | 'end';

export interface LiquidChip {
    kind: LiquidKind;
    label: string;
}

/** `t` de i18next, recortado a lo que se usa aquí (así las pruebas pasan uno propio). */
export type Translate = (key: string, opts?: Record<string, unknown>) => string;

export function escapeAttr(value: string): string {
    return value.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

/** La ficha que reemplaza una expresión en el lienzo (el texto se pone en la vista; aquí solo la marca). */
export function liquidSpan(expr: string): string {
    return `<span ${LIQUID_ATTR}="${escapeAttr(expr)}"></span>`;
}

const RAW_TEXT = new Set(['style', 'script', 'mj-style', 'mj-head', 'textarea']);

/**
 * Convierte en fichas el Liquid que está en el texto (no en los atributos, ni dentro de `<mj-head>`, `<style>` o
 * `<script>`). Respeta las comillas dentro de las etiquetas: un `>` en un atributo no corta la etiqueta.
 */
export function wrapLiquid(html: string): string {
    let out = '';
    let i = 0;
    const n = html.length;
    while (i < n) {
        const ch = html[i];
        if (ch === '<') {
            const end = tagEnd(html, i);
            const tag = html.slice(i, end);
            out += tag;
            i = end;
            const name = /^<([a-zA-Z][\w-]*)/.exec(tag)?.[1]?.toLowerCase();
            // Contenido que no es texto: se copia tal cual hasta su cierre.
            if (name && RAW_TEXT.has(name) && !tag.endsWith('/>')) {
                const close = html.toLowerCase().indexOf(`</${name}`, i);
                const stop = close < 0 ? n : close;
                out += html.slice(i, stop);
                i = stop;
            }
            continue;
        }
        if (ch === '{' && (html[i + 1] === '{' || html[i + 1] === '%')) {
            const closer = html[i + 1] === '{' ? '}}' : '%}';
            const close = html.indexOf(closer, i + 2);
            if (close >= 0) {
                out += liquidSpan(html.slice(i, close + 2));
                i = close + 2;
                continue;
            }
        }
        out += ch ?? '';
        i++;
    }
    return out;
}

/** Dónde termina la etiqueta que empieza en `start` (después de su `>`), saltando lo que va entre comillas. */
function tagEnd(html: string, start: number): number {
    // Comentarios: hasta `-->`.
    if (html.startsWith('<!--', start)) {
        const close = html.indexOf('-->', start + 4);
        return close < 0 ? html.length : close + 3;
    }
    let quote: string | null = null;
    for (let j = start + 1; j < html.length; j++) {
        const c = html[j];
        if (quote) {
            if (c === quote) quote = null;
        } else if (c === '"' || c === "'") {
            quote = c;
        } else if (c === '>') {
            return j + 1;
        }
    }
    return html.length;
}

/**
 * MJML que el parser HTML del editor entiende bien:
 * - Las etiquetas `mj-*` con cierre propio (`<mj-image … />`) pasan a tener su cierre: el parser HTML no conoce
 *   las etiquetas vacías de MJML y metía lo que seguía adentro (así `<mj-all … />` se tragaba el resto de
 *   `<mj-attributes>` y su `mj-text` se veía como una fila vacía arriba del correo).
 * - Lo que quedó anidado dentro de `<mj-attributes>` por ese error en correos ya guardados vuelve a quedar plano.
 */
export function normalizeMjml(mjml: string): string {
    const expanded = mjml.replace(/<(mj-[a-z-]+)((?:[^<>"']|"[^"]*"|'[^']*')*?)\s*\/>/g, (_m, tag: string, attrs: string) => `<${tag}${attrs}></${tag}>`);
    return expanded.replace(/<mj-attributes>([\s\S]*?)<\/mj-attributes>/g, (_m, inner: string) => `<mj-attributes>${flattenAttributes(inner)}</mj-attributes>`);
}

/** `<mj-all a="1"><mj-text b="2"></mj-text></mj-all>` → `<mj-all a="1"></mj-all><mj-text b="2"></mj-text>` (todas son hojas). */
function flattenAttributes(inner: string): string {
    const re = /<(\/?)(mj-[a-z-]+|mj-class)((?:[^<>"']|"[^"]*"|'[^']*')*)>/g;
    const parts: string[] = [];
    let match: RegExpExecArray | null;
    while ((match = re.exec(inner)) !== null) {
        if (match[1] === '/') continue;
        const tag = match[2] ?? '';
        const attrs = (match[3] ?? '').replace(/\s*\/$/, '');
        parts.push(`<${tag}${attrs}></${tag}>`);
    }
    return parts.length > 0 ? parts.join('') : inner;
}

/* ───────────────────────────── Fichas ───────────────────────────── */

/** Nombre legible de cada variable (llave en `templates.liquid.vars`). Las del bucle (`p.*`, `item.*`) también. */
const VAR_KEYS: Record<string, string> = {
    'tienda.nombre': 'storeName',
    'tienda.logo': 'storeLogo',
    'tienda.url': 'storeUrl',
    'tienda.whatsapp_url': 'storeWhatsapp',
    'tienda.direccion': 'storeAddress',
    'tienda.contacto': 'storeContact',
    'contacto.nombre': 'contactName',
    'contacto.apellido': 'contactLastName',
    'contacto.ciudad': 'contactCity',
    'contacto.pedidos': 'contactOrders',
    'contacto.puntos': 'contactPoints',
    'contacto.nivel': 'contactTier',
    'carrito.subtotal': 'cartSubtotal',
    'carrito.cantidad_items': 'cartCount',
    'carrito.url_restaurar': 'cartUrl',
    'carrito.items': 'cartItems',
    'cupon.codigo': 'couponCode',
    'cupon.descuento': 'couponDiscount',
    'cupon.vence': 'couponExpires',
    'pedido.numero': 'orderNumber',
    'pedido.total': 'orderTotal',
    'pedido.url_pago': 'orderPayUrl',
    'pedido.metodo_pago': 'orderPayment',
    'producto.nombre': 'productName',
    'producto.precio': 'productPrice',
    'producto.precio_anterior': 'productOldPrice',
    'producto.url': 'productUrl',
    'producto.imagen': 'productImage',
    'p.nombre': 'productName',
    'p.precio': 'productPrice',
    'p.url': 'productUrl',
    'p.imagen': 'productImage',
    'item.nombre': 'productName',
    'item.precio': 'productPrice',
    'item.url': 'productUrl',
    'item.imagen': 'productImage',
    'item.cantidad': 'itemQuantity',
    'recomendaciones.para_ti': 'recoForYou',
    'recomendaciones.mas_vendidos': 'recoBestSellers',
    'cita.servicio': 'apptService',
    'cita.fecha': 'apptDate',
    'cita.hora': 'apptTime',
    'cita.lugar': 'apptPlace',
    'cita.enlace': 'apptLink',
    'lead.servicio': 'leadService',
    'lead.etapa': 'leadStage',
    'cotizacion.servicio': 'quoteService',
    'cotizacion.valor': 'quoteValue',
    'evento.nombre': 'eventName',
    'evento.fecha': 'eventDate',
    'evento.hora': 'eventTime',
    'evento.enlace': 'eventLink',
    'enlaces.baja': 'linkUnsubscribe',
    'enlaces.preferencias': 'linkPreferences',
    'enlaces.agendar': 'linkBook',
    'enlaces.resena': 'linkReview',
    'enlaces.resena_google': 'linkGoogleReview',
    'enlaces.nps': 'linkNps',
    'enlaces.referido': 'linkReferral',
    n: 'loopNumber',
};

/** `ultimo_pedido` → «Ultimo pedido»: lo que no está en la lista se lee igual. */
function humanize(path: string): string {
    const last = path.split('.').pop() ?? path;
    const words = last.replace(/_/g, ' ').trim();
    return `${words.charAt(0).toUpperCase()}${words.slice(1)}`;
}

function varLabel(path: string, t: Translate): string {
    const key = VAR_KEYS[path];
    return key ? t(`templates.liquid.vars.${key}`) : humanize(path);
}

/** «Imagen del producto» → «imagen del producto» dentro de una frase; los nombres propios (WhatsApp, Google) quedan igual. */
function inSentence(label: string): string {
    return /^[A-Z][a-z]+[A-Z]/.test(label) || label.startsWith('Google') ? label : `${label.charAt(0).toLowerCase()}${label.slice(1)}`;
}

/** La variable principal de una condición: `tienda.logo != ""`, `p.imagen and p.imagen != ""`, `contacto.nivel`. */
function conditionSubject(cond: string): string | null {
    const m = /^\s*([a-z_][\w]*(?:\.[\w]+)+|[a-z_]\w*)\b/.exec(cond);
    if (!m?.[1]) return null;
    const simple = new RegExp(`^\\s*${escapeRegex(m[1])}\\s*(?:!=\\s*(?:""|''|blank|empty|nil)\\s*)?(?:and\\s+${escapeRegex(m[1])}\\s*!=\\s*(?:""|''|blank|empty|nil)\\s*)?$`);
    return simple.test(cond) ? m[1] : null;
}

function escapeRegex(s: string): string {
    return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/** La ficha de una expresión: qué tipo es y cómo se lee. */
export function liquidChip(expr: string, t: Translate): LiquidChip {
    const output = /^\{\{-?\s*([\s\S]*?)\s*-?\}\}$/.exec(expr);
    if (output) {
        const path = (output[1] ?? '').split('|')[0]?.trim() ?? '';
        return { kind: 'var', label: /^[\w.]+$/.test(path) ? varLabel(path, t) : t('templates.liquid.value') };
    }
    const tag = /^\{%-?\s*(\w+)\s*([\s\S]*?)\s*-?%\}$/.exec(expr);
    const name = tag?.[1] ?? '';
    const rest = tag?.[2] ?? '';
    switch (name) {
        case 'if':
        case 'elsif':
        case 'unless': {
            const subject = conditionSubject(rest);
            const key = name === 'unless' ? 'unless' : name;
            return subject
                ? { kind: 'logic', label: t(`templates.liquid.${key}Has`, { what: inSentence(varLabel(subject, t)) }) }
                : { kind: 'logic', label: t(`templates.liquid.${key}Other`) };
        }
        case 'else':
            return { kind: 'logic', label: t('templates.liquid.else') };
        case 'endif':
        case 'endunless':
            return { kind: 'end', label: t('templates.liquid.endIf') };
        case 'for': {
            const m = /^\w+\s+in\s+([\w.]+|\([^)]*\))(?:\s+limit:\s*(\d+))?/.exec(rest);
            const list = m?.[1] ?? '';
            const what = list.startsWith('(') ? t('templates.liquid.number') : inSentence(varLabel(list, t));
            return { kind: 'logic', label: m?.[2] ? t('templates.liquid.forLimit', { what, n: Number(m[2]) }) : t('templates.liquid.for', { what }) };
        }
        case 'endfor':
            return { kind: 'end', label: t('templates.liquid.endFor') };
        default:
            return { kind: 'logic', label: t('templates.liquid.logic') };
    }
}
