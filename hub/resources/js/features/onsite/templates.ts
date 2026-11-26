import type { WidgetTemplate } from './types';

/* Galería de plantillas (WidgetTemplates en el hub): cuáles ver y en qué orden según el tipo de negocio. */

export type TemplateObjective = WidgetTemplate['objective'];

/** Orden de la galería: lo que más rinde primero. Un negocio sin tienda empieza por el contacto y WhatsApp. */
const STORE_ORDER = ['whatsapp_coupon', 'exit_coupon', 'spin_wheel', 'product_quiz', 'survey', 'two_step', 'newsletter', 'wa_button', 'shipping_bar'];
const SERVICES_ORDER = ['contact_form', 'wa_button', 'callback', 'exit_info'];

/**
 * Las plantillas que aplican: una tienda ve las suyas (con cupón, quiz y catálogo) y las de los dos; un negocio sin tienda,
 * las de servicios y las de los dos, nunca una con cupón. Con los dos tipos (o sin saberlo) se ven todas.
 */
export function templatesFor(all: readonly WidgetTemplate[], audience: { store: boolean; services: boolean }): WidgetTemplate[] {
    const fits = (tpl: WidgetTemplate) => tpl.audience === 'both' || (tpl.audience === 'store' && audience.store) || (tpl.audience === 'services' && audience.services);
    const order = audience.store && !audience.services ? STORE_ORDER : !audience.store && audience.services ? SERVICES_ORDER : [...SERVICES_ORDER, ...STORE_ORDER];
    const rank = (key: string) => {
        const i = order.indexOf(key);
        return i === -1 ? order.length : i;
    };
    return all
        .filter(fits)
        .filter((tpl) => audience.store || !tpl.gives_coupon)
        .sort((a, b) => rank(a.key) - rank(b.key));
}
