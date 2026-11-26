import type { ContactAppointment, ContactProfile, ContactServiceLead, ContactServices } from '../api';

/*
 * El encabezado del perfil en palabras (rediseño v2, Clientes): qué tan probable es que vuelva a comprar, en qué va su
 * proceso y su próxima cita. Lógica pura, probada en profile.test.ts; los textos los pone la pantalla.
 */

export type PurchaseOutlook =
    | { kind: 'noPurchases' }
    | { kind: 'risk'; band: 'high' | 'medium' | 'low' }
    | { kind: 'next'; at: string }
    | { kind: 'unknown' };

/**
 * Lo que viene con sus compras: sin compras, primero eso; un riesgo alto de irse manda sobre la fecha esperada (es lo
 * que hay que atender); si no, la próxima compra esperada; y sin predicción, el riesgo que haya.
 */
export function purchaseOutlook(profile: Pick<ContactProfile, 'stats' | 'churn' | 'expected_next_order_at'>): PurchaseOutlook {
    if (profile.stats.orders_count === 0) {
        return { kind: 'noPurchases' };
    }
    const band = profile.churn.band;
    if (band === 'high') {
        return { kind: 'risk', band };
    }
    if (profile.expected_next_order_at) {
        return { kind: 'next', at: profile.expected_next_order_at };
    }
    if (band === 'medium' || band === 'low') {
        return { kind: 'risk', band };
    }
    return { kind: 'unknown' };
}

/** El lead que importa: el abierto más reciente; si no hay, el último que se cerró. */
export function currentLead(services: Pick<ContactServices, 'leads'> | undefined): ContactServiceLead | null {
    const leads = services?.leads ?? [];
    return leads.find((l) => l.stage_kind === 'open') ?? leads[0] ?? null;
}

const ACTIVE = ['booked', 'confirmed', 'rescheduled'];

/** La próxima cita activa (las citas llegan con las próximas primero, en orden). */
export function nextAppointment(services: Pick<ContactServices, 'appointments'> | undefined, now: Date = new Date()): ContactAppointment | null {
    return (services?.appointments ?? []).find((a) => ACTIVE.includes(a.status) && Date.parse(a.starts_at) >= now.getTime()) ?? null;
}

/** Solo los dígitos de un teléfono E.164 para un enlace de WhatsApp (`wa.me/573001234567`); null si está enmascarado. */
export function whatsappDigits(phone: string | null, visible: boolean): string | null {
    if (!visible || !phone || phone.includes('*')) {
        return null;
    }
    const digits = phone.replace(/\D/g, '');
    return digits.length >= 8 ? digits : null;
}
