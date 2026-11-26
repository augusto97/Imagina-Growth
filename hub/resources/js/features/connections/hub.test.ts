import { describe, expect, it } from 'vitest';
import type { Site } from '@/api/types';
import type { ChannelAccount } from '@/features/channels/api';
import type { Destination } from '@/features/measurement/api';
import texts from '@/locales/es-CO/connections.json';
import type { Connection, ProviderInfo } from './api';
import { adsStatus, calendarStatus, CARD_KEYS, cardFromParam, channelStatus, channelsTabTarget, emailStatus, GROUPS, sendsCtaClicks, summarize, toggleCtaClicks, webStatus } from './hub';

const site = (over: Partial<Site> = {}): Site => ({
    id: 'sit_1',
    name: 'Tienda',
    url: 'https://tienda.co',
    host: 'tienda.co',
    platform: 'woocommerce',
    status: 'connected',
    plugin_version: '0.2.0',
    wp_version: null,
    wc_version: null,
    connected_at: '2026-11-01T00:00:00Z',
    last_seen_at: null,
    last_event_at: null,
    backfill: null,
    health_status: { state: 'ok', causes: [], checked_at: '2026-11-20T00:00:00Z' },
    ...over,
});

const account = (over: Partial<ChannelAccount>): ChannelAccount =>
    ({
        id: 'cha_1',
        channel: 'whatsapp',
        provider: 'meta_cloud',
        display_name: 'WhatsApp',
        identity: '+57 300 000 0000',
        status: 'active',
        is_default: true,
        domain: null,
        from_email: null,
        ...over,
    }) as ChannelAccount;

const connection = (over: Partial<Connection> = {}): Connection =>
    ({ id: 'oac_1', provider: 'google', kind: 'workspace', status: 'active', account_name: 'Tienda Ads', connections: [], ...over }) as unknown as Connection;
const provider = (connections: Connection[]): ProviderInfo => ({ key: 'google', configured: true, missing: [], redirect_uri: null, connections });
const destination = (over: Partial<Destination> = {}): Destination => ({ id: 'dst_1', platform: 'google', status: 'active', events_enabled: ['order.paid'], last_error: null, pixel_id: 'G-1', ...over }) as Destination;

describe('Conexiones: el estado de cada tarjeta en palabras', () => {
    it('tu web: sin sitio, sin terminar, sin datos, con atraso o al día', () => {
        expect(webStatus([]).label).toBe('webNone');
        expect(webStatus([site({ status: 'pending' })])).toMatchObject({ tone: 'attention', label: 'webPending' });
        expect(webStatus([site({ status: 'disconnected' })])).toMatchObject({ tone: 'attention', label: 'webDisconnected' });
        expect(webStatus([site({ health_status: { state: 'no_data', causes: [], checked_at: '' } })]).label).toBe('webNoData');
        expect(webStatus([site({ status: 'degraded' })]).label).toBe('webLagging');
        expect(webStatus([site()])).toMatchObject({ tone: 'on', label: 'webOk', detail: 'tienda.co' });
    });

    it('canales: la cuenta activa y su número; lo no oficial y los problemas piden atención', () => {
        expect(channelStatus([], 'whatsapp')).toMatchObject({ tone: 'off', label: 'notConnected' });
        expect(channelStatus([account({})], 'whatsapp')).toMatchObject({ tone: 'on', label: 'connected', detail: '+57 300 000 0000' });
        expect(channelStatus([account({ provider: 'zender' })], 'whatsapp').label).toBe('unofficial');
        expect(channelStatus([account({ status: 'error' })], 'whatsapp').label).toBe('error');
        expect(channelStatus([account({ status: 'disconnected' })], 'whatsapp').tone).toBe('off');
        // Desconectado con conversaciones (sigue en connections.channels): se pide reconectar, no «sin conectar».
        expect(channelStatus([account({ status: 'disconnected' })], 'whatsapp', true)).toMatchObject({ tone: 'attention', label: 'disconnected' });
        expect(channelStatus([account({})], 'messenger').tone).toBe('off');
    });

    it('correo: dominio propio verificado, SMTP o DNS pendiente', () => {
        expect(emailStatus([account({ channel: 'email', provider: 'ses', from_email: 'hola@tienda.co' })])).toMatchObject({ tone: 'on', label: 'emailDomain', detail: 'hola@tienda.co' });
        expect(emailStatus([account({ channel: 'email', provider: 'smtp' })]).label).toBe('emailSmtp');
        expect(emailStatus([account({ channel: 'email', provider: 'ses', status: 'pending', domain: 'tienda.co' })])).toMatchObject({ tone: 'attention', label: 'emailDns', detail: 'tienda.co' });
        expect(emailStatus(undefined).tone).toBe('off');
    });

    it('anuncios: con un clic, faltan las cuentas, venció, a mano o con errores de entrega', () => {
        expect(adsStatus(undefined, undefined).label).toBe('notConnected');
        expect(adsStatus(provider([connection()]), undefined).label).toBe('pickAccounts');
        expect(adsStatus(provider([connection({ status: 'expired' })]), destination()).label).toBe('reconnect');
        expect(adsStatus(provider([connection()]), destination())).toMatchObject({ tone: 'on', label: 'sending', detail: 'Tienda Ads' });
        expect(adsStatus(provider([]), destination())).toMatchObject({ tone: 'on', label: 'sendingManual', detail: 'G-1' });
        expect(adsStatus(provider([]), destination({ last_error: 'token inválido' })).label).toBe('deliveryError');
        expect(adsStatus(provider([]), destination({ status: 'paused' })).label).toBe('paused');
        expect(calendarStatus(2)).toMatchObject({ tone: 'on', params: { count: 2 } });
        expect(calendarStatus(0).tone).toBe('off');
    });

    it('los clics que valen se prenden y apagan sin tocar los demás eventos', () => {
        expect(sendsCtaClicks(destination())).toBe(false);
        expect(toggleCtaClicks(['order.paid'], true)).toEqual(['order.paid', 'cta.clicked']);
        expect(toggleCtaClicks(['order.paid', 'cta.clicked'], false)).toEqual(['order.paid']);
        expect(sendsCtaClicks(destination({ events_enabled: ['cta.clicked'] }))).toBe(true);
    });

    it('las rutas de antes abren la tarjeta que corresponde', () => {
        expect(cardFromParam('correo')).toBe('email');
        expect(cardFromParam('whatsapp')).toBe('whatsapp');
        expect(cardFromParam('meta_whatsapp')).toBe('whatsapp');
        expect(cardFromParam('nada')).toBeNull();
        expect(channelsTabTarget('email')).toEqual({ to: 'card', card: 'email' });
        expect(channelsTabTarget('sms')).toEqual({ to: 'card', card: 'sms' });
        expect(channelsTabTarget('suppressions')).toEqual({ to: 'suppressions' });
        expect(channelsTabTarget(null)).toEqual({ to: 'hub' });
    });

    it('el resumen cuenta lo que funciona y lo que pide atención', () => {
        expect(summarize({ web: { tone: 'on', label: 'webOk' }, whatsapp: { tone: 'attention', label: 'error' }, email: { tone: 'off', label: 'notConnected' } }, ['web', 'whatsapp', 'email'])).toEqual({
            on: 1,
            total: 3,
            attention: ['whatsapp'],
        });
    });

    it('cada tarjeta y cada estado tienen su texto', () => {
        const hub = (texts as unknown as { connections: { hub: { cards: Record<string, { name: string; allows: string }>; groups: Record<string, { title: string }>; status: Record<string, string> } } }).connections.hub;
        for (const key of CARD_KEYS) {
            expect(hub.cards[key]?.name, key).toBeTruthy();
            expect(hub.cards[key]?.allows, key).toBeTruthy();
        }
        for (const g of GROUPS) expect(hub.groups[g.key]?.title, g.key).toBeTruthy();
        const labels = ['webNone', 'webPending', 'webDisconnected', 'webNoData', 'webLagging', 'notConnected', 'connected_one', 'unofficial', 'error', 'paused', 'pending', 'emailDomain', 'emailSmtp', 'emailDns', 'reconnect', 'pickAccounts', 'deliveryError', 'sending', 'sendingManual', 'calendarOn_one', 'webOk_one'];
        for (const label of labels) expect(hub.status[label], label).toBeTruthy();
    });
});
