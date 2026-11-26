import type { Site } from '@/api/types';
import type { ChannelAccount, ChannelKind } from '@/features/channels/api';
import type { Destination, Platform } from '@/features/measurement/api';
import type { ProviderInfo } from './api';

/*
 * Conexiones, el centro único (docs/diseno/rediseno-v2.md §3): todo lo que se conecta, agrupado y con su estado en
 * palabras. Lógica pura (probada con Vitest): de las respuestas de sitios, canales, conexiones y destinos de Medición sale
 * el estado de cada tarjeta. Los textos viven en `connections.hub.status.<clave>`.
 */

export type CardKey = 'web' | 'capture' | 'whatsapp' | 'messenger' | 'instagram' | 'email' | 'sms' | 'meta' | 'google' | 'tiktok' | 'calendar';

export type GroupKey = 'web' | 'conversations' | 'email' | 'ads' | 'agenda';

/** `on`: funcionando; `attention`: conectada pero algo falta o falla; `off`: sin conectar. */
export type CardTone = 'on' | 'attention' | 'off';

export interface CardStatus {
    tone: CardTone;
    /** Clave del texto en `connections.hub.status`. */
    label: string;
    params?: Record<string, string | number>;
    /** Una línea de detalle (el número, el dominio, la cuenta…), ya lista para mostrar. */
    detail?: string | null;
}

export const GROUPS: { key: GroupKey; cards: CardKey[] }[] = [
    { key: 'web', cards: ['web', 'capture'] },
    { key: 'conversations', cards: ['whatsapp', 'messenger', 'instagram'] },
    { key: 'email', cards: ['email', 'sms'] },
    { key: 'ads', cards: ['meta', 'google', 'tiktok'] },
    { key: 'agenda', cards: ['calendar'] },
];

export const CARD_KEYS: CardKey[] = GROUPS.flatMap((g) => g.cards);

/** `?tarjeta=` válida (las rutas viejas y los avisos abren la tarjeta así). */
export function cardFromParam(value: string | null | undefined): CardKey | null {
    const aliases: Record<string, CardKey> = { correo: 'email', sitio: 'web', sitios: 'web', captura: 'capture', agenda: 'calendar', calendario: 'calendar', meta_whatsapp: 'whatsapp' };
    if (!value) return null;
    if ((CARD_KEYS as string[]).includes(value)) return value as CardKey;
    return aliases[value] ?? null;
}

/** De `canales?tab=…` (la pantalla de antes) a la tarjeta que corresponde; la supresión vive en Privacidad. */
export function channelsTabTarget(tab: string | null): { to: 'card'; card: CardKey } | { to: 'suppressions' } | { to: 'hub' } {
    switch (tab) {
        case 'email':
            return { to: 'card', card: 'email' };
        case 'whatsapp':
        case 'messenger':
        case 'instagram':
        case 'sms':
            return { to: 'card', card: tab };
        case 'suppressions':
            return { to: 'suppressions' };
        default:
            return { to: 'hub' };
    }
}

/** Tu web: el semáforo del sitio que peor está (sin datos, con atraso), o si la conexión no terminó o se cayó. */
export function webStatus(sites: readonly Site[] | undefined): CardStatus {
    const list = sites ?? [];
    if (list.length === 0) return { tone: 'off', label: 'webNone' };
    const live = list.filter((s) => s.status === 'connected' || s.status === 'degraded');
    const detail = list.map((s) => s.host).join(', ');
    if (live.length === 0) {
        if (list.some((s) => s.status === 'pending')) return { tone: 'attention', label: 'webPending', detail };
        return { tone: 'attention', label: 'webDisconnected', detail };
    }
    if (live.some((s) => s.health_status?.state === 'no_data')) return { tone: 'attention', label: 'webNoData', detail };
    if (live.some((s) => s.health_status?.state === 'lagging' || s.status === 'degraded')) return { tone: 'attention', label: 'webLagging', detail };
    return { tone: 'on', label: 'webOk', params: { count: live.length }, detail };
}

/** WhatsApp, Messenger, Instagram o SMS: la cuenta en uso (activa) y, si no, la que tiene un problema. */
export function channelStatus(accounts: readonly ChannelAccount[] | undefined, kind: Exclude<ChannelKind, 'email'>): CardStatus {
    const list = (accounts ?? []).filter((a) => a.channel === kind && a.status !== 'disconnected');
    const active = list.filter((a) => a.status === 'active');
    if (active.length > 0) {
        const first = active.find((a) => a.is_default) ?? active[0];
        const unofficial = active.some((a) => a.provider === 'zender');
        return { tone: unofficial ? 'attention' : 'on', label: unofficial ? 'unofficial' : 'connected', params: { count: active.length }, detail: first?.identity ?? first?.display_name ?? null };
    }
    const problem = list.find((a) => a.status === 'error') ?? list.find((a) => a.status === 'paused') ?? list.find((a) => a.status === 'pending');
    if (problem) return { tone: 'attention', label: problem.status === 'error' ? 'error' : problem.status === 'paused' ? 'paused' : 'pending', detail: problem.identity ?? problem.display_name };
    return { tone: 'off', label: 'notConnected' };
}

/** Correo: el dominio propio por la plataforma (SES, con su DNS) o un servidor SMTP propio. */
export function emailStatus(accounts: readonly ChannelAccount[] | undefined): CardStatus {
    const list = (accounts ?? []).filter((a) => a.channel === 'email' && a.status !== 'disconnected');
    const active = list.find((a) => a.status === 'active' && a.is_default) ?? list.find((a) => a.status === 'active');
    if (active) {
        return { tone: 'on', label: active.provider === 'smtp' ? 'emailSmtp' : 'emailDomain', detail: active.from_email ?? active.domain ?? active.identity };
    }
    const pending = list.find((a) => a.status === 'pending');
    if (pending) return { tone: 'attention', label: 'emailDns', detail: pending.domain ?? pending.identity };
    const failing = list.find((a) => a.status === 'error' || a.status === 'paused');
    if (failing) return { tone: 'attention', label: failing.status === 'error' ? 'error' : 'paused', detail: failing.domain ?? failing.identity };
    return { tone: 'off', label: 'notConnected' };
}

/**
 * Anuncios (Meta, Google, TikTok): conectado con un clic y enviando conversiones; conectado pero sin elegir las cuentas;
 * la conexión venció; o enviando con datos pegados a mano.
 */
export function adsStatus(info: ProviderInfo | undefined, destination: Destination | undefined): CardStatus {
    const connection = info?.connections[0] ?? null;
    const sending = destination !== undefined && destination.status === 'active';
    if (connection && (connection.status === 'expired' || connection.status === 'error' || connection.status === 'revoked')) {
        return { tone: 'attention', label: 'reconnect', detail: connection.account_name };
    }
    if (connection && !destination) return { tone: 'attention', label: 'pickAccounts', detail: connection.account_name };
    if (destination && destination.last_error) return { tone: 'attention', label: 'deliveryError', detail: connection?.account_name ?? destination.pixel_id };
    if (sending) return { tone: 'on', label: connection ? 'sending' : 'sendingManual', detail: connection?.account_name ?? destination.pixel_id };
    if (destination) return { tone: 'attention', label: 'paused', detail: destination.pixel_id };
    return { tone: 'off', label: 'notConnected' };
}

/** Agenda: cuántas personas conectaron su Google Calendar (las citas no se cruzan con su calendario). */
export function calendarStatus(count: number | undefined): CardStatus {
    return count && count > 0 ? { tone: 'on', label: 'calendarOn', params: { count } } : { tone: 'off', label: 'notConnected' };
}

/** El destino de Medición de cada plataforma (uno por plataforma; si hay varios, el activo). */
export function destinationFor(destinations: readonly Destination[] | undefined, platform: Platform): Destination | undefined {
    const list = (destinations ?? []).filter((d) => d.platform === platform);
    return list.find((d) => d.status === 'active') ?? list[0];
}

/** ¿El destino envía los clics que valen (WhatsApp, llamar, correo) como conversión? Apagado por defecto. */
export function sendsCtaClicks(destination: Pick<Destination, 'events_enabled'> | undefined): boolean {
    return (destination?.events_enabled ?? []).includes('cta.clicked');
}

/** La lista de eventos con los clics que valen prendidos o apagados (el resto queda igual). */
export function toggleCtaClicks(events: readonly string[], on: boolean): string[] {
    const rest = events.filter((e) => e !== 'cta.clicked');
    return on ? [...rest, 'cta.clicked'] : rest;
}

/** Cuántas de las tarjetas que aplican están funcionando, y cuáles faltan (para el resumen de arriba). */
export function summarize(statuses: Partial<Record<CardKey, CardStatus>>, keys: readonly CardKey[]): { on: number; total: number; attention: CardKey[] } {
    let on = 0;
    const attention: CardKey[] = [];
    for (const key of keys) {
        const s = statuses[key];
        if (s?.tone === 'on') on++;
        if (s?.tone === 'attention') attention.push(key);
    }
    return { on, total: keys.length, attention };
}
