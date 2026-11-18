/*
 * Bloques dinámicos del editor de correo (modulos.md M08). Cada uno inserta MJML
 * con Liquid. Los bucles van dentro de <div>, nunca entre etiquetas de tabla: el
 * parser HTML del navegador sacaría el texto `{% for %}` fuera de la tabla.
 */

const INK = '#111827';
const MUTED = '#6b7280';
const LINE = '#e5e7eb';
const SOFT = '#f9fafb';

export interface DynamicBlock {
    id: string;
    /** Clave i18n de la etiqueta: templates.blocks.<id> */
    labelKey: string;
    phase?: number;
    content: string;
}

export const DYNAMIC_BLOCKS: DynamicBlock[] = [
    {
        id: 'igr-product',
        labelKey: 'product',
        content: `<mj-section padding="8px 24px"><mj-column border="1px solid ${LINE}" border-radius="8px">
  <mj-image src="{{ producto.imagen }}" alt="{{ producto.nombre }}" href="{{ producto.url }}" padding="16px 16px 0" border-radius="6px" />
  <mj-text align="center" font-size="18px" font-weight="600" color="${INK}">{{ producto.nombre }}</mj-text>
  <mj-text align="center" font-size="16px" color="${INK}"><strong>{{ producto.precio | moneda }}</strong></mj-text>
  <mj-button href="{{ producto.url }}" background-color="${INK}" color="#ffffff" border-radius="8px">Ver producto</mj-button>
</mj-column></mj-section>`,
    },
    {
        id: 'igr-recommendations',
        labelKey: 'recommendations',
        content: `<mj-section padding="8px 24px"><mj-column>
  <mj-text font-size="18px" font-weight="700" color="${INK}">Elegidos para ti</mj-text>
  <mj-text align="center" font-size="14px" line-height="20px" color="${INK}"><div>{% for p in recomendaciones.para_ti limit: 3 %}<div style="display:inline-block;width:30%;min-width:140px;vertical-align:top;margin:0 1% 12px;text-align:center"><a href="{{ p.url }}" style="color:${INK};text-decoration:none">{% if p.imagen != "" %}<img src="{{ p.imagen }}" alt="" width="160" style="width:100%;max-width:160px;border-radius:6px;display:block;margin:0 auto 8px">{% endif %}<span style="font-weight:600">{{ p.nombre }}</span><br><span>{{ p.precio | moneda }}</span></a></div>{% endfor %}</div></mj-text>
</mj-column></mj-section>`,
    },
    {
        id: 'igr-cart',
        labelKey: 'cart',
        content: `<mj-section padding="8px 24px"><mj-column border="1px solid ${LINE}" border-radius="8px" padding="8px 12px">
  <mj-text font-size="14px" line-height="20px" color="${INK}"><div>{% for item in carrito.items limit: 6 %}<div style="padding:8px 0;border-bottom:1px solid ${LINE}"><div style="float:right;font-weight:600">{{ item.precio | moneda }}</div><a href="{{ item.url }}" style="color:${INK};font-weight:600;text-decoration:none">{{ item.nombre }}</a><br><span style="color:${MUTED}">Cantidad: {{ item.cantidad }}</span><div style="clear:both"></div></div>{% endfor %}</div><p style="margin:12px 0 4px;font-weight:600">Subtotal: {{ carrito.subtotal | moneda }}</p></mj-text>
  <mj-button href="{{ carrito.url_restaurar }}" background-color="${INK}" color="#ffffff" border-radius="8px">Terminar mi compra</mj-button>
</mj-column></mj-section>`,
    },
    {
        id: 'igr-coupon',
        labelKey: 'coupon',
        content: `<mj-section padding="8px 24px"><mj-column background-color="${SOFT}" border="2px dashed ${INK}" border-radius="8px" padding="12px 0">
  <mj-text align="center" font-size="14px" color="${MUTED}">Tu código</mj-text>
  <mj-text align="center" font-size="28px" font-weight="700" letter-spacing="2px" color="${INK}">{{ cupon.codigo }}</mj-text>
  <mj-text align="center" font-size="14px" color="${MUTED}">{{ cupon.descuento }} de descuento · vence el {{ cupon.vence | fecha }}</mj-text>
</mj-column></mj-section>`,
    },
    {
        id: 'igr-points',
        labelKey: 'points',
        phase: 3,
        content: `<mj-section padding="8px 24px"><mj-column background-color="${SOFT}" border-radius="8px" padding="8px 0">
  <mj-text align="center" font-size="14px" color="${MUTED}">Tu saldo</mj-text>
  <mj-text align="center" font-size="32px" font-weight="700" color="${INK}">{{ contacto.puntos }} puntos</mj-text>
  <mj-text align="center" font-size="14px" color="${MUTED}">{% if contacto.nivel != "" %}Nivel {{ contacto.nivel }}{% endif %}</mj-text>
</mj-column></mj-section>`,
    },
    {
        id: 'igr-reviews',
        labelKey: 'reviews',
        phase: 3,
        content: `<mj-section padding="8px 24px"><mj-column>
  <mj-text align="center" font-size="16px" font-weight="600" color="${INK}">Lo que dicen nuestros clientes</mj-text>
  <mj-text align="center" font-size="14px" color="${MUTED}">Las reseñas con foto llegan con el módulo de reseñas (fase 3).</mj-text>
</mj-column></mj-section>`,
    },
    {
        id: 'igr-whatsapp',
        labelKey: 'whatsapp',
        content: `<mj-section padding="8px 24px"><mj-column>
  <mj-button href="{{ tienda.whatsapp_url }}" background-color="#25D366" color="#0b3d2e" border-radius="8px" font-weight="600">Escríbenos por WhatsApp</mj-button>
</mj-column></mj-section>`,
    },
    {
        id: 'igr-legal-footer',
        labelKey: 'legalFooter',
        content: `<mj-section padding="24px 24px 32px"><mj-column>
  <mj-divider border-color="${LINE}" border-width="1px" padding="0 0 16px" />
  <mj-text align="center" font-size="12px" line-height="18px" color="${MUTED}">Recibes este correo porque aceptaste recibir novedades de {{ tienda.nombre }}.<br>{{ tienda.nombre }} · {{ tienda.direccion }} · {{ tienda.contacto }}<br><a href="{{ enlaces.preferencias }}" style="color:${MUTED}">Preferencias</a> · <a href="{{ enlaces.baja }}" style="color:${MUTED}">Darte de baja</a></mj-text>
</mj-column></mj-section>`,
    },
];
