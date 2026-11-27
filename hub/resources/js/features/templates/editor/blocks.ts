import type { BlockIcon } from './blockIcons';
import type { Translate } from './liquid';

/*
 * Bloques del editor de correo (modulos.md M08). Cada uno inserta MJML ya diseñado; los de «Tu tienda» y «Tus
 * servicios» traen Liquid con los datos de cada contacto (carrito, cupón, cita…). Los bucles van dentro de <div>,
 * nunca entre etiquetas de tabla: el parser HTML del navegador sacaría el texto `{% for %}` fuera de la tabla.
 *
 * Los textos de muestra salen de i18n (`templates.blockCopy.*`) y los botones toman el color de la marca.
 */

export const INK = '#111827';
export const MUTED = '#6b7280';
export const LINE = '#e5e7eb';
export const SOFT = '#f9fafb';

export type BlockCategory = 'layout' | 'content' | 'store' | 'services' | 'sections';

/** Orden de las categorías en el panel. */
export const BLOCK_CATEGORIES: BlockCategory[] = ['layout', 'content', 'store', 'services', 'sections'];

export interface BlockContext {
    /** Color de los botones (#RRGGBB). */
    brand: string;
    /** Color del texto sobre `brand`. */
    onBrand: string;
    /** Origen del panel, para las imágenes de muestra (`https://…`). */
    origin: string;
    t: Translate;
}

export interface EmailBlock {
    id: string;
    category: BlockCategory;
    icon: BlockIcon;
    /** Solo para negocios con tienda o con servicios; sin esto, para todos. */
    audience?: 'store' | 'services';
    content: (ctx: BlockContext) => string;
}

/** Blanco u oscuro según el contraste con el color de fondo (WCAG). */
export function textOn(hex: string): string {
    const m = /^#?([0-9a-f]{6})$/i.exec(hex.trim());
    if (!m?.[1]) return '#ffffff';
    const n = parseInt(m[1], 16);
    const lin = (c: number) => {
        const s = c / 255;
        return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
    };
    const lum = 0.2126 * lin((n >> 16) & 255) + 0.7152 * lin((n >> 8) & 255) + 0.0722 * lin(n & 255);
    const contrastWhite = 1.05 / (lum + 0.05);
    const contrastInk = (lum + 0.05) / (0.0184 + 0.05);
    return contrastWhite >= contrastInk ? '#ffffff' : INK;
}

const c = (ctx: BlockContext, key: string, opts?: Record<string, unknown>) => ctx.t(`templates.blockCopy.${key}`, opts);
const section = (body: string, padding = '12px 24px', bg = '#ffffff') => `<mj-section padding="${padding}" background-color="${bg}">${body}</mj-section>`;
const button = (ctx: BlockContext, href: string, label: string, align = 'center') =>
    `<mj-button href="${href}" align="${align}" background-color="${ctx.brand}" color="${ctx.onBrand}" font-size="16px" font-weight="600" border-radius="8px" inner-padding="14px 28px">${label}</mj-button>`;
const img = (ctx: BlockContext, name: 'hero' | 'image' | 'square') => `${ctx.origin}/images/email/placeholder-${name}.png`;

const LOGO = `{% if tienda.logo != "" %}<img src="{{ tienda.logo }}" alt="{{ tienda.nombre }}" height="40" style="height:40px;width:auto;border:0;display:inline-block">{% else %}{{ tienda.nombre }}{% endif %}`;

const LEGAL = (ctx: BlockContext) =>
    section(
        `<mj-column><mj-divider border-color="${LINE}" border-width="1px" padding="0 0 16px"></mj-divider><mj-text align="center" font-size="12px" line-height="18px" color="${MUTED}">${c(ctx, 'legalWhy')} {{ tienda.nombre }}.<br>{{ tienda.nombre }}{% if tienda.direccion != "" %} · {{ tienda.direccion }}{% endif %}{% if tienda.contacto != "" %} · {{ tienda.contacto }}{% endif %}<br><a href="{{ enlaces.preferencias }}" style="color:${MUTED}">${c(ctx, 'legalPreferences')}</a> · <a href="{{ enlaces.baja }}" style="color:${MUTED}">${c(ctx, 'legalUnsubscribe')}</a></mj-text></mj-column>`,
        '24px 24px 32px',
    );

const productCards = (list: string, title: string) =>
    section(
        `<mj-column><mj-text font-size="20px" line-height="28px" font-weight="700" color="${INK}">${title}</mj-text><mj-text align="center" font-size="14px" line-height="20px" color="${INK}"><div>{% for p in ${list} limit: 3 %}<div style="display:inline-block;width:30%;min-width:150px;vertical-align:top;margin:0 1% 16px;text-align:center"><a href="{{ p.url }}" style="color:${INK};text-decoration:none">{% if p.imagen and p.imagen != "" %}<img src="{{ p.imagen }}" alt="" width="160" style="width:100%;max-width:160px;border-radius:8px;display:block;margin:0 auto 8px">{% endif %}<span style="font-weight:600">{{ p.nombre }}</span><br><span style="color:${MUTED}">{{ p.precio | moneda }}</span></a></div>{% endfor %}</div></mj-text></mj-column>`,
    );

const coupon = (ctx: BlockContext) =>
    section(
        `<mj-column background-color="${SOFT}" border="2px dashed ${ctx.brand}" border-radius="12px" padding="16px 0"><mj-text align="center" font-size="13px" letter-spacing="1px" color="${MUTED}">${c(ctx, 'couponLabel').toUpperCase()}</mj-text><mj-text align="center" font-size="30px" font-weight="700" letter-spacing="3px" color="${INK}">{{ cupon.codigo }}</mj-text><mj-text align="center" font-size="14px" color="${MUTED}">{{ cupon.descuento }} ${c(ctx, 'couponOff')}{% if cupon.vence != "" %} · ${c(ctx, 'couponExpires')} {{ cupon.vence | fecha }}{% endif %}</mj-text>${button(ctx, '{{ tienda.url }}', c(ctx, 'couponCta'))}</mj-column>`,
        '12px 24px',
    );

export const EMAIL_BLOCKS: EmailBlock[] = [
    /* ── Estructura ── */
    {
        id: 'igr-col-1',
        category: 'layout',
        icon: 'rectangleHorizontal',
        content: (ctx) => section(`<mj-column><mj-text font-size="16px" line-height="24px" color="${INK}">${c(ctx, 'col1')}</mj-text></mj-column>`),
    },
    {
        id: 'igr-col-2',
        category: 'layout',
        icon: 'columns2',
        content: (ctx) =>
            section(
                `<mj-column padding="0 8px 0 0"><mj-text font-size="16px" line-height="24px" color="${INK}">${c(ctx, 'col2Left')}</mj-text></mj-column><mj-column padding="0 0 0 8px"><mj-text font-size="16px" line-height="24px" color="${INK}">${c(ctx, 'col2Right')}</mj-text></mj-column>`,
            ),
    },
    {
        id: 'igr-col-3',
        category: 'layout',
        icon: 'columns3',
        content: (ctx) =>
            section(
                [1, 2, 3]
                    .map(
                        (n) =>
                            `<mj-column padding="0 6px"><mj-text align="center" font-size="16px" font-weight="700" color="${INK}">${c(ctx, `col3Title${n}`)}</mj-text><mj-text align="center" font-size="14px" line-height="21px" color="${MUTED}">${c(ctx, 'col3Text')}</mj-text></mj-column>`,
                    )
                    .join(''),
                '16px 18px',
            ),
    },
    {
        id: 'igr-divider',
        category: 'layout',
        icon: 'separatorHorizontal',
        content: () => section(`<mj-column><mj-divider border-color="${LINE}" border-width="1px" padding="8px 0"></mj-divider></mj-column>`, '0 24px'),
    },
    {
        id: 'igr-spacer',
        category: 'layout',
        icon: 'unfoldVertical',
        content: () => section(`<mj-column><mj-spacer height="32px"></mj-spacer></mj-column>`, '0'),
    },

    /* ── Contenido ── */
    {
        id: 'igr-header',
        category: 'content',
        icon: 'panelTop',
        content: () => section(`<mj-column><mj-text align="center" font-size="20px" font-weight="700" color="${INK}">${LOGO}</mj-text></mj-column>`, '24px 24px 8px'),
    },
    {
        id: 'igr-hero',
        category: 'content',
        icon: 'layoutTemplate',
        content: (ctx) =>
            section(`<mj-column><mj-image src="${img(ctx, 'hero')}" alt="" padding="0" border-radius="0"></mj-image></mj-column>`, '0') +
            section(
                `<mj-column><mj-text align="center" font-size="28px" line-height="36px" font-weight="700" color="${INK}">${c(ctx, 'heroTitle')}</mj-text><mj-text align="center" font-size="16px" line-height="24px" color="${MUTED}">${c(ctx, 'heroText')}</mj-text>${button(ctx, '{{ tienda.url }}', c(ctx, 'heroCta'))}</mj-column>`,
                '24px 32px 16px',
            ),
    },
    {
        id: 'igr-title',
        category: 'content',
        icon: 'heading',
        content: (ctx) => section(`<mj-column><mj-text font-size="24px" line-height="32px" font-weight="700" color="${INK}">${c(ctx, 'title')}</mj-text></mj-column>`, '16px 24px 0'),
    },
    {
        id: 'igr-text',
        category: 'content',
        icon: 'letterText',
        content: (ctx) => section(`<mj-column><mj-text font-size="16px" line-height="24px" color="${INK}">${c(ctx, 'text')}</mj-text></mj-column>`),
    },
    {
        id: 'igr-image',
        category: 'content',
        icon: 'image',
        content: (ctx) => section(`<mj-column><mj-image src="${img(ctx, 'image')}" alt="" border-radius="12px" padding="0"></mj-image></mj-column>`),
    },
    {
        id: 'igr-image-text',
        category: 'content',
        icon: 'layoutPanelLeft',
        content: (ctx) =>
            section(
                `<mj-column vertical-align="middle" padding="0 12px 0 0"><mj-image src="${img(ctx, 'square')}" alt="" border-radius="12px" padding="0"></mj-image></mj-column><mj-column vertical-align="middle" padding="0 0 0 12px"><mj-text font-size="20px" line-height="28px" font-weight="700" color="${INK}">${c(ctx, 'imageTextTitle')}</mj-text><mj-text font-size="15px" line-height="23px" color="${MUTED}">${c(ctx, 'imageTextText')}</mj-text>${button(ctx, '{{ tienda.url }}', c(ctx, 'imageTextCta'), 'left')}</mj-column>`,
                '16px 24px',
            ),
    },
    {
        id: 'igr-button',
        category: 'content',
        icon: 'mousePointerClick',
        content: (ctx) => section(`<mj-column>${button(ctx, '{{ tienda.url }}', c(ctx, 'button'))}</mj-column>`, '8px 24px 16px'),
    },
    {
        id: 'igr-whatsapp',
        category: 'content',
        icon: 'messageCircle',
        content: (ctx) =>
            section(
                `<mj-column><mj-text align="center" font-size="16px">{% if tienda.whatsapp_url != "" %}<a href="{{ tienda.whatsapp_url }}" style="display:inline-block;background:#25D366;color:#0b3d2e;padding:12px 22px;border-radius:8px;font-weight:600;text-decoration:none">${c(ctx, 'whatsapp')}</a>{% endif %}</mj-text></mj-column>`,
                '8px 24px 16px',
            ),
    },
    {
        id: 'igr-social',
        category: 'content',
        icon: 'share2',
        content: (ctx) =>
            section(
                `<mj-column><mj-text align="center" font-size="14px" color="${MUTED}">${c(ctx, 'social')}</mj-text><mj-social mode="horizontal" icon-size="28px" font-size="13px" padding="4px 0 8px"><mj-social-element name="facebook" href="https://www.facebook.com/"></mj-social-element><mj-social-element name="instagram" href="https://www.instagram.com/"></mj-social-element><mj-social-element name="youtube" href="https://www.youtube.com/"></mj-social-element><mj-social-element name="linkedin" href="https://www.linkedin.com/"></mj-social-element></mj-social></mj-column>`,
                '16px 24px',
            ),
    },
    {
        id: 'igr-testimonial',
        category: 'content',
        icon: 'quote',
        content: (ctx) =>
            section(
                `<mj-column background-color="${SOFT}" border-radius="12px" padding="20px 8px"><mj-text align="center" font-size="22px" color="#f59e0b">★★★★★</mj-text><mj-text align="center" font-size="17px" line-height="26px" font-style="italic" color="${INK}">${c(ctx, 'testimonialText')}</mj-text><mj-text align="center" font-size="14px" font-weight="600" color="${MUTED}">${c(ctx, 'testimonialAuthor')}</mj-text></mj-column>`,
                '12px 24px',
            ),
    },
    {
        id: 'igr-faq',
        category: 'content',
        icon: 'circleQuestionMark',
        content: (ctx) =>
            section(
                `<mj-column><mj-text font-size="20px" line-height="28px" font-weight="700" color="${INK}">${c(ctx, 'faqTitle')}</mj-text>${[1, 2, 3]
                    .map(
                        (n) =>
                            `<mj-text font-size="15px" line-height="22px" color="${INK}" padding="10px 0 0"><strong>${c(ctx, `faqQ${n}`)}</strong><br><span style="color:${MUTED}">${c(ctx, `faqA${n}`)}</span></mj-text>`,
                    )
                    .join('')}</mj-column>`,
                '16px 24px',
            ),
    },
    {
        id: 'igr-review',
        category: 'content',
        icon: 'star',
        content: (ctx) =>
            section(
                `<mj-column><mj-text align="center" font-size="22px" line-height="30px" font-weight="700" color="${INK}">${c(ctx, 'reviewTitle')}</mj-text><mj-text align="center" font-size="15px" line-height="22px" color="${MUTED}">${c(ctx, 'reviewText')}</mj-text><mj-text align="center" font-size="36px" line-height="40px"><a href="{{ enlaces.resena }}5" style="text-decoration:none;color:#f59e0b">★★★★★</a></mj-text></mj-column>`,
                '16px 24px',
            ),
    },
    {
        id: 'igr-nps',
        category: 'content',
        icon: 'gauge',
        content: (ctx) =>
            section(
                `<mj-column><mj-text align="center" font-size="18px" line-height="26px" font-weight="700" color="${INK}">${c(ctx, 'npsTitle')}</mj-text><mj-text align="center" font-size="14px" color="${INK}"><div>{% for n in (0..10) %}<a href="{{ enlaces.nps }}{{ n }}" style="display:inline-block;width:30px;line-height:30px;margin:2px;border:1px solid ${LINE};border-radius:6px;color:${INK};text-decoration:none;text-align:center">{{ n }}</a>{% endfor %}</div></mj-text><mj-text align="center" font-size="12px" color="${MUTED}">${c(ctx, 'npsScale')}</mj-text></mj-column>`,
                '16px 24px',
            ),
    },
    {
        id: 'igr-signature',
        category: 'content',
        icon: 'penLine',
        content: (ctx) =>
            section(`<mj-column><mj-text font-size="16px" line-height="24px" color="${INK}">${c(ctx, 'signature')}<br><strong>${c(ctx, 'signatureTeam')} {{ tienda.nombre }}</strong></mj-text></mj-column>`, '8px 24px 16px'),
    },
    { id: 'igr-legal-footer', category: 'content', icon: 'scale', content: LEGAL },

    /* ── Tu tienda ── */
    {
        id: 'igr-product',
        category: 'store',
        icon: 'package',
        audience: 'store',
        content: (ctx) =>
            section(
                `<mj-column border="1px solid ${LINE}" border-radius="12px" padding="8px 0"><mj-image src="{{ producto.imagen }}" alt="{{ producto.nombre }}" href="{{ producto.url }}" padding="16px 16px 0" border-radius="8px"></mj-image><mj-text align="center" font-size="18px" font-weight="600" color="${INK}">{{ producto.nombre }}</mj-text><mj-text align="center" font-size="16px" color="${INK}">{% if producto.precio_anterior != "" and producto.precio_anterior != producto.precio %}<s style="color:${MUTED}">{{ producto.precio_anterior | moneda }}</s>&nbsp; {% endif %}<strong>{{ producto.precio | moneda }}</strong></mj-text>${button(ctx, '{{ producto.url }}', c(ctx, 'product'))}</mj-column>`,
            ),
    },
    {
        id: 'igr-recommendations',
        category: 'store',
        icon: 'sparkles',
        audience: 'store',
        content: (ctx) => productCards('recomendaciones.para_ti', c(ctx, 'recoTitle')),
    },
    {
        id: 'igr-best-sellers',
        category: 'store',
        icon: 'layoutGrid',
        audience: 'store',
        content: (ctx) => productCards('recomendaciones.mas_vendidos', c(ctx, 'bestTitle')),
    },
    {
        id: 'igr-cart',
        category: 'store',
        icon: 'shoppingCart',
        audience: 'store',
        content: (ctx) =>
            section(
                `<mj-column border="1px solid ${LINE}" border-radius="12px" padding="8px 12px"><mj-text font-size="18px" font-weight="700" color="${INK}">${c(ctx, 'cartTitle')}</mj-text><mj-text font-size="14px" line-height="20px" color="${INK}"><div>{% for item in carrito.items limit: 6 %}<div style="padding:10px 0;border-bottom:1px solid ${LINE}">{% if item.imagen and item.imagen != "" %}<img src="{{ item.imagen }}" alt="" width="56" style="width:56px;height:56px;object-fit:cover;border-radius:6px;float:left;margin-right:12px">{% endif %}<div style="float:right;font-weight:600">{{ item.precio | moneda }}</div><a href="{{ item.url }}" style="color:${INK};font-weight:600;text-decoration:none">{{ item.nombre }}</a><br><span style="color:${MUTED}">${c(ctx, 'cartQty')}: {{ item.cantidad }}</span><div style="clear:both"></div></div>{% endfor %}</div><p style="margin:12px 0 4px;font-weight:600">${c(ctx, 'cartSubtotal')}: {{ carrito.subtotal | moneda }}</p></mj-text>${button(ctx, '{{ carrito.url_restaurar }}', c(ctx, 'cartCta'))}</mj-column>`,
            ),
    },
    { id: 'igr-coupon', category: 'store', icon: 'ticketPercent', audience: 'store', content: coupon },
    {
        id: 'igr-points',
        category: 'store',
        icon: 'award',
        audience: 'store',
        content: (ctx) =>
            section(
                `<mj-column background-color="${SOFT}" border-radius="12px" padding="12px 0"><mj-text align="center" font-size="13px" letter-spacing="1px" color="${MUTED}">${c(ctx, 'pointsLabel').toUpperCase()}</mj-text><mj-text align="center" font-size="34px" font-weight="700" color="${INK}">{{ contacto.puntos }} ${c(ctx, 'pointsUnit')}</mj-text><mj-text align="center" font-size="14px" color="${MUTED}">{% if contacto.nivel != "" %}${c(ctx, 'pointsTier')} {{ contacto.nivel }}{% endif %}</mj-text>${button(ctx, '{{ tienda.url }}', c(ctx, 'pointsCta'))}</mj-column>`,
            ),
    },
    {
        id: 'igr-order',
        category: 'store',
        icon: 'receipt',
        audience: 'store',
        content: (ctx) =>
            section(
                `<mj-column border="1px solid ${LINE}" border-radius="12px" padding="8px 12px"><mj-text font-size="18px" font-weight="700" color="${INK}">${c(ctx, 'orderTitle')} #{{ pedido.numero }}</mj-text><mj-text font-size="15px" line-height="24px" color="${INK}">${c(ctx, 'orderTotal')}: <strong>{{ pedido.total | moneda }}</strong>{% if pedido.metodo_pago != "" %}<br>${c(ctx, 'orderPayment')}: {{ pedido.metodo_pago }}{% endif %}</mj-text></mj-column>`,
            ),
    },

    /* ── Tus servicios ── */
    {
        id: 'igr-appointment',
        category: 'services',
        icon: 'calendarCheck',
        audience: 'services',
        content: (ctx) =>
            section(
                `<mj-column background-color="${SOFT}" border-radius="12px" padding="12px 8px"><mj-text font-size="13px" letter-spacing="1px" color="${MUTED}">${c(ctx, 'apptTitle').toUpperCase()}</mj-text><mj-text font-size="20px" line-height="28px" font-weight="700" color="${INK}">{{ cita.servicio }}</mj-text><mj-text font-size="16px" line-height="24px" color="${INK}">{{ cita.fecha }} · {{ cita.hora }}{% if cita.lugar != "" %}<br><span style="color:${MUTED}">{{ cita.lugar }}</span>{% endif %}</mj-text>${button(ctx, '{{ cita.enlace }}', c(ctx, 'apptCta'), 'left')}</mj-column>`,
            ),
    },
    {
        id: 'igr-book',
        category: 'services',
        icon: 'calendarPlus',
        audience: 'services',
        content: (ctx) =>
            section(
                `<mj-column><mj-text align="center" font-size="22px" line-height="30px" font-weight="700" color="${INK}">${c(ctx, 'bookTitle')}</mj-text><mj-text align="center" font-size="15px" line-height="22px" color="${MUTED}">${c(ctx, 'bookText')}</mj-text>${button(ctx, '{{ enlaces.agendar | default: tienda.url }}', c(ctx, 'bookCta'))}</mj-column>`,
                '16px 24px',
            ),
    },
    {
        id: 'igr-quote',
        category: 'services',
        icon: 'calculator',
        audience: 'services',
        content: (ctx) =>
            section(
                `<mj-column border="1px solid ${LINE}" border-radius="12px" padding="12px 8px"><mj-text font-size="13px" letter-spacing="1px" color="${MUTED}">${c(ctx, 'quoteTitle').toUpperCase()}</mj-text><mj-text font-size="18px" font-weight="600" color="${INK}">{{ cotizacion.servicio | default: '${c(ctx, 'quoteFallback')}' }}</mj-text><mj-text font-size="15px" color="${INK}">${c(ctx, 'quoteValue')}: <strong>{{ cotizacion.valor | moneda }}</strong></mj-text>${button(ctx, '{{ tienda.whatsapp_url | default: tienda.url }}', c(ctx, 'quoteCta'), 'left')}</mj-column>`,
            ),
    },
    {
        id: 'igr-event',
        category: 'services',
        icon: 'calendarDays',
        audience: 'services',
        content: (ctx) =>
            section(
                `<mj-column background-color="${SOFT}" border-radius="12px" padding="12px 8px"><mj-text align="center" font-size="13px" letter-spacing="1px" color="${MUTED}">${c(ctx, 'eventTitle').toUpperCase()}</mj-text><mj-text align="center" font-size="22px" line-height="30px" font-weight="700" color="${INK}">{{ evento.nombre | default: '${c(ctx, 'eventFallback')}' }}</mj-text><mj-text align="center" font-size="16px" color="${INK}">{% if evento.fecha and evento.fecha != "" %}{{ evento.fecha }}{% endif %}{% if evento.hora and evento.hora != "" %} · {{ evento.hora }}{% endif %}</mj-text>${button(ctx, '{{ evento.enlace }}', c(ctx, 'eventCta'))}</mj-column>`,
            ),
    },

    /* ── Secciones listas ── */
    {
        id: 'igr-sec-welcome',
        category: 'sections',
        icon: 'handHeart',
        content: (ctx) =>
            section(
                `<mj-column><mj-text align="center" font-size="28px" line-height="36px" font-weight="700" color="${INK}">${c(ctx, 'welcomeTitle')}</mj-text><mj-text align="center" font-size="16px" line-height="24px" color="${MUTED}">${c(ctx, 'welcomeText')}</mj-text></mj-column>`,
                '28px 32px 8px',
            ) +
            section(
                [1, 2, 3]
                    .map(
                        (n) =>
                            `<mj-column padding="0 6px"><mj-text align="center" font-size="15px" font-weight="700" color="${INK}">${c(ctx, `welcomeBenefit${n}`)}</mj-text><mj-text align="center" font-size="14px" line-height="21px" color="${MUTED}">${c(ctx, `welcomeBenefitText${n}`)}</mj-text></mj-column>`,
                    )
                    .join(''),
                '8px 18px',
            ) +
            section(`<mj-column>${button(ctx, '{{ tienda.url }}', c(ctx, 'welcomeCta'))}</mj-column>`, '8px 24px 24px'),
    },
    {
        id: 'igr-sec-news',
        category: 'sections',
        icon: 'newspaper',
        content: (ctx) =>
            section(`<mj-column><mj-image src="${img(ctx, 'image')}" alt="" border-radius="12px" padding="0"></mj-image></mj-column>`, '16px 24px 0') +
            section(
                `<mj-column><mj-text font-size="13px" letter-spacing="1px" color="${ctx.brand}">${c(ctx, 'newsKicker').toUpperCase()}</mj-text><mj-text font-size="24px" line-height="32px" font-weight="700" color="${INK}">${c(ctx, 'newsTitle')}</mj-text><mj-text font-size="16px" line-height="24px" color="${MUTED}">${c(ctx, 'newsText')}</mj-text>${button(ctx, '{{ tienda.url }}', c(ctx, 'newsCta'), 'left')}</mj-column>`,
                '8px 24px 24px',
            ),
    },
    {
        id: 'igr-sec-promo',
        category: 'sections',
        icon: 'megaphone',
        audience: 'store',
        content: (ctx) =>
            section(
                `<mj-column><mj-text align="center" font-size="13px" letter-spacing="1px" color="${ctx.brand}">${c(ctx, 'promoKicker').toUpperCase()}</mj-text><mj-text align="center" font-size="28px" line-height="36px" font-weight="700" color="${INK}">${c(ctx, 'promoTitle')}</mj-text><mj-text align="center" font-size="16px" line-height="24px" color="${MUTED}">${c(ctx, 'promoText')}</mj-text></mj-column>`,
                '28px 32px 8px',
            ) + coupon(ctx),
    },
    {
        id: 'igr-sec-reminder',
        category: 'sections',
        icon: 'bell',
        content: (ctx) =>
            section(
                `<mj-column background-color="${SOFT}" border-radius="12px" padding="16px 12px"><mj-text font-size="22px" line-height="30px" font-weight="700" color="${INK}">${c(ctx, 'reminderTitle')}</mj-text><mj-text font-size="16px" line-height="24px" color="${INK}">${c(ctx, 'reminderText')}</mj-text>${button(ctx, '{{ tienda.url }}', c(ctx, 'reminderCta'), 'left')}</mj-column>`,
                '16px 24px',
            ),
    },
    {
        id: 'igr-sec-social-proof',
        category: 'sections',
        icon: 'trophy',
        content: (ctx) =>
            section(`<mj-column><mj-text align="center" font-size="24px" line-height="32px" font-weight="700" color="${INK}">${c(ctx, 'proofTitle')}</mj-text></mj-column>`, '24px 24px 0') +
            section(
                [1, 2]
                    .map(
                        (n) =>
                            `<mj-column padding="0 6px"><mj-text align="center" font-size="18px" color="#f59e0b">★★★★★</mj-text><mj-text align="center" font-size="15px" line-height="23px" font-style="italic" color="${INK}">${c(ctx, `proofQuote${n}`)}</mj-text><mj-text align="center" font-size="13px" font-weight="600" color="${MUTED}">${c(ctx, `proofAuthor${n}`)}</mj-text></mj-column>`,
                    )
                    .join(''),
                '8px 18px',
            ) +
            section(`<mj-column>${button(ctx, '{{ tienda.url }}', c(ctx, 'proofCta'))}</mj-column>`, '8px 24px 24px'),
    },
];

/** Los bloques que ve un negocio: sin tienda no hay carrito, cupón, puntos ni pedidos; sin servicios, ni citas ni cotizaciones. */
export function blocksFor(business: { store: boolean; services: boolean }): EmailBlock[] {
    return EMAIL_BLOCKS.filter((b) => (b.audience === 'store' ? business.store : b.audience === 'services' ? business.services : true));
}
