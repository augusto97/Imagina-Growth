import type { BadgeProps } from '@/components/ui/badge';
import type { Touch } from './api';

/* Valores dinámicos del perfil 360: grupos de la línea de tiempo, segmentos RFM y canales. */

/** Grupos de la línea de tiempo, en el orden de hub/app/Domain/Contacts/Queries/ProfileQuery::TIMELINE_GROUPS. */
export const TIMELINE_GROUPS = ['navegacion', 'carrito', 'pedidos', 'cuenta', 'mensajes', 'resenas', 'encuestas', 'consentimiento', 'segmentos'] as const;
export type TimelineGroup = (typeof TIMELINE_GROUPS)[number];

const GROUP_BY_PREFIX: [string, TimelineGroup][] = [
    ['page.', 'navegacion'],
    ['product.', 'navegacion'],
    ['category.', 'navegacion'],
    ['search.', 'navegacion'],
    ['cta.', 'navegacion'],
    ['identify', 'navegacion'],
    ['cart.', 'carrito'],
    ['checkout.', 'carrito'],
    ['coupon.', 'carrito'],
    ['order.', 'pedidos'],
    ['customer.', 'cuenta'],
    ['form.', 'cuenta'],
    ['message.', 'mensajes'],
    ['review.', 'resenas'],
    ['survey.', 'encuestas'],
    ['consent.', 'consentimiento'],
    ['segment.', 'segmentos'],
];

export function eventGroup(event: string): TimelineGroup {
    return GROUP_BY_PREFIX.find(([prefix]) => event.startsWith(prefix))?.[1] ?? 'navegacion';
}

/** Clave plana del evento para i18n: `order.status_changed` → `order_status_changed`. */
export function eventKey(event: string): string {
    return event.replace(/[^a-z0-9]+/gi, '_');
}

/** Alterna un grupo en el filtro; sin grupos se muestran todos. */
export function toggleGroup(selected: readonly TimelineGroup[], group: TimelineGroup): TimelineGroup[] {
    const next = selected.includes(group) ? selected.filter((g) => g !== group) : [...selected, group];
    return TIMELINE_GROUPS.filter((g) => next.includes(g));
}

/** Segmentos RFM (inteligencia.md §3), del más valioso al menos. */
export const RFM_SEGMENTS = [
    'champions',
    'loyal',
    'cant_lose',
    'at_risk',
    'potential_loyal',
    'new',
    'promising',
    'need_attention',
    'about_to_sleep',
    'hibernating',
    'lost',
] as const;

export function rfmTone(segment: string | null | undefined): NonNullable<BadgeProps['variant']> {
    switch (segment) {
        case 'champions':
        case 'loyal':
            return 'success';
        case 'cant_lose':
        case 'at_risk':
            return 'warning';
        case 'hibernating':
        case 'lost':
            return 'outline';
        default:
            return 'secondary';
    }
}

export const LIFECYCLE_STAGES = ['visitor', 'subscriber', 'lead', 'customer', 'repeat', 'vip', 'at_risk', 'lost'] as const;

/** Canales de marketing con consentimiento (ContactConsent::CHANNELS sin push ni ads, que llegan después). */
export const CONSENT_CHANNELS = ['whatsapp', 'email', 'sms'] as const;

/** Canales de adquisición (hub/app/Ingest/Enrichment/ChannelClassifier.php). */
export const ACQUISITION_CHANNELS = [
    'ctwa',
    'igr_whatsapp',
    'igr_email',
    'igr_sms',
    'paid_meta',
    'paid_google',
    'paid_tiktok',
    'paid_other',
    'email',
    'whatsapp',
    'ai_assistants',
    'organic_search',
    'organic_social',
    'affiliates',
    'referral',
    'direct',
] as const;

/** «google / cpc · Rebajas» a partir de un toque de atribución. */
export function touchDetail(touch: Touch | null | undefined): string | null {
    if (!touch) {
        return null;
    }
    const sourceMedium = [touch.utm_source, touch.utm_medium].filter(Boolean).join(' / ');
    const parts = [sourceMedium, touch.utm_campaign].filter((p): p is string => typeof p === 'string' && p !== '');
    return parts.length > 0 ? parts.join(' · ') : null;
}

/** Iniciales para el avatar del encabezado. */
export function initials(name: string): string {
    const words = name.trim().split(/\s+/).filter(Boolean);
    if (words.length === 0) {
        return '·';
    }
    return ((words[0]?.[0] ?? '') + (words.length > 1 ? (words[words.length - 1]?.[0] ?? '') : '')).toUpperCase();
}

/** Normaliza una etiqueta escrita a mano (sin espacios repetidos, máximo 40 caracteres). */
export function normalizeTag(raw: string): string {
    return raw.trim().replace(/\s+/g, ' ').slice(0, 40);
}

export function addTag(tags: readonly string[], raw: string): string[] {
    const tag = normalizeTag(raw);
    if (tag === '' || tags.some((t) => t.toLocaleLowerCase() === tag.toLocaleLowerCase())) {
        return [...tags];
    }
    return [...tags, tag];
}

const KNOWN_SOURCES = ['checkout', 'import', 'admin', 'popup', 'whatsapp', 'widget'] as const;

/**
 * Origen de un consentimiento legible: `checkout`, `import`, `admin` o
 * `form:<plugin>:<id>` (ProjectConsents). Devuelve la clave i18n y sus valores;
 * nunca usa el texto crudo como clave (los «:» son separadores en i18next).
 */
export function consentSource(source: string | null | undefined): { key: string; values: Record<string, string> } | null {
    if (!source) {
        return null;
    }
    if (source.startsWith('form:')) {
        const [, plugin = '', id = ''] = source.split(':');
        return { key: 'contacts.privacy.sources.form', values: { plugin, id } };
    }
    const known = KNOWN_SOURCES.find((s) => s === source);
    return known ? { key: `contacts.privacy.sources.${known}`, values: {} } : { key: 'contacts.privacy.sources.other', values: { source } };
}
