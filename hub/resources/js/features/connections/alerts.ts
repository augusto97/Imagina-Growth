import type { TFunction } from 'i18next';
import { formatDate } from '@/lib/format';
import type { ConnectionAlert, RedirectProvider } from './api';

/* Textos y acción del aviso de conexiones por vencer o caídas (lote 4; hub: ConnectionAlerts). */

/** Qué hace «Reconectar»: abrir el consentimiento del proveedor o llevar a la pantalla donde se vuelve a conectar. */
export type ReconnectAction = { kind: 'oauth'; provider: RedirectProvider } | { kind: 'link'; path: string };

export function reconnectAction(alert: ConnectionAlert): ReconnectAction {
    if (alert.subject === 'calendar') {
        return { kind: 'link', path: 'agenda?pestana=personas' };
    }
    if (alert.kind === 'connection' && alert.provider !== 'meta_whatsapp') {
        return { kind: 'oauth', provider: alert.provider };
    }
    // WhatsApp se vuelve a conectar con la ventana de Meta (Embedded Signup); Messenger e Instagram, eligiendo la página.
    return { kind: 'link', path: 'conexiones' };
}

/** Lo urgente (vencido o desconectado) va en rojo; lo que está por vencer, en amarillo. */
export function isUrgent(alert: ConnectionAlert): boolean {
    return alert.state === 'expired' || alert.state === 'disconnected';
}

function providerName(t: TFunction, alert: ConnectionAlert): string {
    if (alert.subject === 'calendar') {
        return t('connections.alerts.calendar');
    }
    if (alert.kind === 'channel') {
        return t(`connections.usedBy.${alert.subject}`, { defaultValue: alert.subject });
    }
    return t(`connections.provider.${alert.provider}.name`, { defaultValue: alert.provider });
}

export function alertTitle(t: TFunction, alert: ConnectionAlert, locale?: string, timeZone?: string): string {
    const provider = providerName(t, alert);
    switch (alert.state) {
        case 'expires_7d':
            return t('connections.alerts.title.expires_7d', { provider, date: formatDate(alert.expires_at, locale, timeZone) });
        case 'expires_1d':
            return t('connections.alerts.title.expires_1d', { provider });
        case 'disconnected':
            return t('connections.alerts.title.disconnected', { provider, name: alert.name ?? '' });
        default:
            return t('connections.alerts.title.expired', { provider });
    }
}

export function alertBody(t: TFunction, alert: ConnectionAlert): string {
    if (alert.state === 'disconnected' && alert.message) {
        return alert.message;
    }
    return t(`connections.alerts.body.${alert.state}`);
}
