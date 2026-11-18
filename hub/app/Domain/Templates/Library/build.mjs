#!/usr/bin/env node
/*
 * Compila la biblioteca de plantillas de correo (modulos.md M08).
 *
 *   node app/Domain/Templates/Library/build.mjs        (desde hub/)
 *
 * Lee library.json, arma el MJML de cada plantilla con los bloques de abajo y lo
 * compila a HTML con el mismo MJML que usa el editor (grapesjs-mjml). Escribe
 * email/<clave>.mjml y email/<clave>.html, que se versionan: el hub los usa al
 * instalar una plantilla sin pasar por el navegador. Los textos van como
 * [[token]] y TemplateLibrary los reemplaza por la copia de cada industria.
 */
import { createRequire } from 'node:module';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const require = createRequire(import.meta.url);
globalThis.window = globalThis; // el paquete de navegador espera `window`
const mjml2html = require(join(here, '../../../../node_modules/grapesjs-mjml/node_modules/mjml-browser'));

const INK = '#111827';
const MUTED = '#6b7280';
const LINE = '#e5e7eb';
const SOFT = '#f9fafb';

/** Bloques reutilizables. Cada uno devuelve una o más <mj-section>. */
export const BLOCKS = {
    header: () => `
    <mj-section padding="24px 24px 8px">
      <mj-column>
        <mj-text align="center" font-size="20px" font-weight="700" color="${INK}">{% if tienda.logo != "" %}<img src="{{ tienda.logo }}" alt="{{ tienda.nombre }}" height="40" style="height:40px;width:auto;border:0;display:inline-block">{% else %}{{ tienda.nombre }}{% endif %}</mj-text>
      </mj-column>
    </mj-section>`,
    hero: () => `
    <mj-section padding="8px 24px 0">
      <mj-column>
        <mj-text font-size="24px" line-height="32px" font-weight="700" color="${INK}">[[headline]]</mj-text>
        <mj-text font-size="16px" line-height="24px" color="${INK}">[[intro]]</mj-text>
      </mj-column>
    </mj-section>`,
    text: () => `
    <mj-section padding="0 24px">
      <mj-column>
        <mj-text font-size="16px" line-height="24px" color="${INK}">[[body]]</mj-text>
      </mj-column>
    </mj-section>`,
    button: () => `
    <mj-section padding="8px 24px 16px">
      <mj-column>
        <mj-button href="[[cta_url]]" background-color="${INK}" color="#ffffff" font-size="16px" font-weight="600" border-radius="8px" inner-padding="14px 28px">[[cta]]</mj-button>
      </mj-column>
    </mj-section>`,
    whatsapp: () => `
    <mj-section padding="0 24px 16px">
      <mj-column>
        <mj-text align="center" font-size="14px" color="${MUTED}">{% if tienda.whatsapp_url != "" %}¿Tienes dudas? <a href="{{ tienda.whatsapp_url }}" style="color:#128C7E;font-weight:600;text-decoration:none">Escríbenos por WhatsApp</a>{% endif %}</mj-text>
      </mj-column>
    </mj-section>`,
    cart: () => `
    <mj-section padding="8px 24px">
      <mj-column border="1px solid ${LINE}" border-radius="8px" padding="8px 12px">
        <mj-text font-size="14px" line-height="20px" color="${INK}"><div>{% for item in carrito.items limit: 6 %}<div style="padding:8px 0;border-bottom:1px solid ${LINE}">{% if item.imagen and item.imagen != "" %}<img src="{{ item.imagen }}" alt="" width="56" height="56" style="border-radius:6px;float:left;margin-right:12px;object-fit:cover">{% endif %}<div style="text-align:right;float:right;white-space:nowrap;font-weight:600">{{ item.precio | moneda }}</div><a href="{{ item.url }}" style="color:${INK};font-weight:600;text-decoration:none">{{ item.nombre }}</a><br><span style="color:${MUTED}">Cantidad: {{ item.cantidad }}</span><div style="clear:both"></div></div>{% endfor %}</div><p style="margin:12px 0 4px;font-weight:600">Subtotal: {{ carrito.subtotal | moneda }}</p></mj-text>
      </mj-column>
    </mj-section>`,
    coupon: () => `
    <mj-section padding="8px 24px">
      <mj-column background-color="${SOFT}" border="2px dashed ${INK}" border-radius="8px" padding="12px 0">
        <mj-text align="center" font-size="14px" color="${MUTED}">[[coupon_label]]</mj-text>
        <mj-text align="center" font-size="28px" font-weight="700" letter-spacing="2px" color="${INK}">{{ cupon.codigo }}</mj-text>
        <mj-text align="center" font-size="14px" color="${MUTED}">{{ cupon.descuento }} de descuento{% if cupon.vence != "" %} · vence el {{ cupon.vence | fecha }}{% endif %}</mj-text>
      </mj-column>
    </mj-section>`,
    product: () => `
    <mj-section padding="8px 24px">
      <mj-column border="1px solid ${LINE}" border-radius="8px">
        <mj-image src="{{ producto.imagen }}" alt="{{ producto.nombre }}" href="{{ producto.url }}" padding="16px 16px 0" border-radius="6px" />
        <mj-text align="center" font-size="18px" font-weight="600" color="${INK}">{{ producto.nombre }}</mj-text>
        <mj-text align="center" font-size="16px" color="${INK}">{% if producto.precio_anterior != "" and producto.precio_anterior != producto.precio %}<s style="color:${MUTED}">{{ producto.precio_anterior | moneda }}</s> {% endif %}<strong>{{ producto.precio | moneda }}</strong></mj-text>
      </mj-column>
    </mj-section>`,
    products: (opts = {}) => `
    <mj-section padding="8px 24px 0">
      <mj-column>
        <mj-text font-size="18px" font-weight="700" color="${INK}">[[products_title]]</mj-text>
        <mj-text align="center" font-size="14px" line-height="20px" color="${INK}"><div>{% for p in recomendaciones.${opts.source ?? 'para_ti'} limit: 3 %}<div style="display:inline-block;width:30%;min-width:140px;vertical-align:top;margin:0 1% 12px;text-align:center"><a href="{{ p.url }}" style="color:${INK};text-decoration:none">{% if p.imagen and p.imagen != "" %}<img src="{{ p.imagen }}" alt="" width="160" style="width:100%;max-width:160px;border-radius:6px;display:block;margin:0 auto 8px">{% endif %}<span style="font-weight:600">{{ p.nombre }}</span><br><span>{{ p.precio | moneda }}</span></a></div>{% endfor %}</div></mj-text>
      </mj-column>
    </mj-section>`,
    order: () => `
    <mj-section padding="8px 24px">
      <mj-column background-color="${SOFT}" border-radius="8px" padding="8px 0">
        <mj-text font-size="15px" line-height="22px" color="${INK}">Pedido <strong>#{{ pedido.numero }}</strong><br>Total: <strong>{{ pedido.total | moneda }}</strong>{% if pedido.metodo_pago != "" %}<br>Medio de pago: {{ pedido.metodo_pago }}{% endif %}</mj-text>
      </mj-column>
    </mj-section>`,
    stars: () => `
    <mj-section padding="8px 24px">
      <mj-column>
        <mj-text align="center" font-size="36px" line-height="40px"><a href="{{ enlaces.resena }}5" style="text-decoration:none;color:#f59e0b">★★★★★</a></mj-text>
        <mj-text align="center" font-size="14px" color="${MUTED}">Toca las estrellas para calificar. Te toma un minuto.</mj-text>
      </mj-column>
    </mj-section>`,
    nps: () => `
    <mj-section padding="8px 24px">
      <mj-column>
        <mj-text align="center" font-size="14px" color="${INK}"><div>{% for n in (0..10) %}<a href="{{ enlaces.nps }}{{ n }}" style="display:inline-block;width:28px;line-height:28px;margin:2px;border:1px solid ${LINE};border-radius:6px;color:${INK};text-decoration:none;text-align:center">{{ n }}</a>{% endfor %}</div></mj-text>
        <mj-text align="center" font-size="12px" color="${MUTED}">0 = nada probable · 10 = muy probable</mj-text>
      </mj-column>
    </mj-section>`,
    points: () => `
    <mj-section padding="8px 24px">
      <mj-column background-color="${SOFT}" border-radius="8px" padding="8px 0">
        <mj-text align="center" font-size="14px" color="${MUTED}">Tu saldo</mj-text>
        <mj-text align="center" font-size="32px" font-weight="700" color="${INK}">{{ contacto.puntos }} puntos</mj-text>
        <mj-text align="center" font-size="14px" color="${MUTED}">{% if contacto.nivel != "" %}Nivel {{ contacto.nivel }}{% endif %}</mj-text>
      </mj-column>
    </mj-section>`,
    appointment: () => `
    <mj-section padding="8px 24px">
      <mj-column background-color="${SOFT}" border-radius="8px" padding="8px 0">
        <mj-text font-size="15px" line-height="22px" color="${INK}"><strong>{{ cita.fecha | fecha }}</strong> a las <strong>{{ cita.hora }}</strong>{% if cita.lugar != "" %}<br>{{ cita.lugar }}{% endif %}</mj-text>
      </mj-column>
    </mj-section>`,
    footer: () => `
    <mj-section padding="24px 24px 32px">
      <mj-column>
        <mj-divider border-color="${LINE}" border-width="1px" padding="0 0 16px" />
        <mj-text css-class="igr-legal" align="center" font-size="12px" line-height="18px" color="${MUTED}">Recibes este correo porque aceptaste recibir novedades de {{ tienda.nombre }}.<br>{{ tienda.nombre }}{% if tienda.direccion != "" %} · {{ tienda.direccion }}{% endif %}{% if tienda.contacto != "" %} · {{ tienda.contacto }}{% endif %}<br><a href="{{ enlaces.preferencias }}" style="color:${MUTED}">Preferencias</a> · <a href="{{ enlaces.baja }}" style="color:${MUTED}">Darte de baja</a></mj-text>
      </mj-column>
    </mj-section>`,
    footer_transactional: () => `
    <mj-section padding="24px 24px 32px">
      <mj-column>
        <mj-divider border-color="${LINE}" border-width="1px" padding="0 0 16px" />
        <mj-text css-class="igr-legal" align="center" font-size="12px" line-height="18px" color="${MUTED}">{{ tienda.nombre }}{% if tienda.direccion != "" %} · {{ tienda.direccion }}{% endif %}{% if tienda.contacto != "" %} · {{ tienda.contacto }}{% endif %}</mj-text>
      </mj-column>
    </mj-section>`,
};

/** Documento MJML completo de una plantilla. */
export function assemble(entry) {
    const blocks = ['header', ...entry.blocks, entry.category === 'marketing' ? 'footer' : 'footer_transactional'];
    const body = blocks
        .map((b) => {
            const [name, source] = b.split(':');
            const fn = BLOCKS[name];
            if (!fn) throw new Error(`Bloque desconocido «${name}» en ${entry.key}`);
            return fn({ source });
        })
        .join('');
    return `<mjml>
  <mj-head>
    <mj-attributes>
      <mj-all font-family="Inter, Helvetica, Arial, sans-serif" />
      <mj-text padding="8px 0" />
      <mj-section background-color="#ffffff" />
    </mj-attributes>
    <mj-style>.igr-legal a { color: ${MUTED}; }</mj-style>
  </mj-head>
  <mj-body background-color="#f3f4f6" width="600px">${body}
  </mj-body>
</mjml>
`;
}

export function compile(mjmlSource) {
    const out = mjml2html(mjmlSource, { validationLevel: 'soft', minify: false });
    return { html: out.html, errors: out.errors };
}

if (import.meta.url === `file://${process.argv[1]}`) {
    // --check: no escribe; falla si los archivos versionados no coinciden con library.json.
    const check = process.argv.includes('--check');
    const library = JSON.parse(readFileSync(join(here, 'library.json'), 'utf8'));
    mkdirSync(join(here, 'email'), { recursive: true });
    let count = 0;
    const stale = [];
    for (const entry of library.templates) {
        if (entry.channel !== 'email') continue;
        const source = assemble(entry);
        const { html, errors } = compile(source);
        if (errors.length) {
            console.error(`✗ ${entry.key}:`, errors.map((e) => e.formattedMessage).join('; '));
            process.exitCode = 1;
        }
        const files = { [`${entry.key}.mjml`]: source, [`${entry.key}.html`]: html };
        for (const [name, content] of Object.entries(files)) {
            const path = join(here, 'email', name);
            if (check) {
                if (!existsSync(path) || readFileSync(path, 'utf8') !== content) stale.push(name);
            } else {
                writeFileSync(path, content);
            }
        }
        count++;
    }
    if (check && stale.length) {
        console.error(`✗ Desactualizadas (corre build.mjs): ${stale.join(', ')}`);
        process.exitCode = 1;
    } else {
        console.log(`✓ ${count} plantillas de correo ${check ? 'al día' : 'compiladas'} en ${join(here, 'email')}`);
    }
}
