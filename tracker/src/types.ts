/** Contrato de los datos en la página (docs/api-connector.md §7). */

export interface Consent {
    analytics: boolean;
    marketing: boolean;
    source: string;
}

export interface Config {
    siteId: string;
    siteKey: string;
    endpoint: string;
    platform?: 'woocommerce' | 'wordpress';
    consentMode?: 'strict' | 'informed';
    consent?: Consent;
    cookieDomain?: string;
    pingUrl?: string;
    waRef?: { enabled?: boolean; format?: string; numbers?: string[] };
    widgetsUrl?: string;
    widgetsJs?: string;
    pixelsJs?: string;
    pixels?: { meta?: string; tiktok?: string; google?: string };
    currency?: string;
}

export interface PageData {
    type?: string;
    objectId?: string | number | null;
    objectType?: string | null;
    name?: string;
    search?: { query?: string; results?: number | null; engine?: string };
    product?: {
        id: string | number;
        variationId?: string | number | null;
        sku?: string | null;
        name?: string;
        price?: string;
        currency?: string;
        categories?: Array<string | number>;
        inStock?: boolean;
    };
}

export interface OrderData {
    eventId?: string;
    orderId?: string;
    value?: string;
    currency?: string;
    /** El pedido ya está pagado: solo entonces el píxel dispara la compra. */
    paid?: boolean;
    token?: string;
}

/** Un toque de marketing (formato `touch` de `_defs.json`, con `at` en ms en la cookie). */
export interface Touch {
    utm?: Record<string, string>;
    click_ids?: Record<string, string>;
    referrer?: string;
    landing?: string;
    at?: number;
}

export type Props = Record<string, unknown>;

export interface Envelope {
    schema_version: 2;
    event_id: string;
    event: string;
    occurred_at: string;
    source: { type: 'browser'; platform: string; site_id: string; sdk: string };
    identity: Record<string, string>;
    context: Record<string, unknown>;
    properties: Props;
}
