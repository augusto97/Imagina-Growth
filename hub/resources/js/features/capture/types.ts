/**
 * Tipos de la captación sin tienda (rediseño v2 §11.3, modulos.md M12 y M18): páginas de captura alojadas en el hub,
 * QR, chats de WhatsApp con atribución y leads de anuncios (Meta Lead Ads y formularios de Google Ads).
 * La forma es la de `App\Domain\Capture\Http\*` (CapturePresenter y LeadAdsController).
 */

export type LandingStatus = 'draft' | 'published' | 'archived';
export type ConsentChannel = 'email' | 'whatsapp' | 'sms';
export type BuiltinFieldKey = 'name' | 'email' | 'phone' | 'message' | 'service';
export type LandingFieldType = 'text' | 'email' | 'tel' | 'textarea' | 'select' | 'checkbox';
export type CaptureVia = 'qr' | 'link';

export interface LandingField {
    /** `name`, `email`, `phone`, `message`, `service` o propio `custom_<clave>`. */
    key: BuiltinFieldKey | `custom_${string}`;
    label: string;
    type?: LandingFieldType;
    required?: boolean;
    placeholder?: string;
    options?: string[];
}

export interface LandingWhatsApp {
    enabled: boolean;
    label: string | null;
    message: string | null;
}

export interface LandingContent {
    title: string;
    body: string | null;
    button: string | null;
    success_title: string | null;
    success_message: string | null;
    redirect_url: string | null;
    color: string | null;
    logo_key: string | null;
    logo_url: string | null;
    image_key: string | null;
    image_url: string | null;
    whatsapp: LandingWhatsApp;
    /** Solo lectura: la URL con la que se ve el logo o la imagen (subidos o externos). */
    logo_src?: string | null;
    image_src?: string | null;
}

export type CaptureWarningCode = 'no_privacy_url' | 'no_consent' | 'no_leads' | 'no_whatsapp_number' | 'whatsapp_not_connected' | 'site_disconnected';

export interface CaptureWarning {
    code: CaptureWarningCode;
    /** El aviso en palabras, listo para mostrar. */
    message: string;
}

export interface LandingStatsSummary {
    views: number;
    submissions: number;
    qr_views: number;
}

export interface LandingPage {
    id: string;
    name: string;
    slug: string;
    status: LandingStatus;
    title: string;
    url: string;
    /** La URL del QR impreso (con utm_source=qr). */
    qr_url: string;
    /** Rutas de la API para descargar el QR (agrega `&download=1` para descargar). */
    qr: { svg: string; png: string };
    consent_channels: ConsentChannel[];
    site_id: string | null;
    whatsapp_link: { id: string; code: string; url: string } | null;
    stats: LandingStatsSummary | null;
    published_at: string | null;
    created_at: string | null;
    updated_at: string | null;
}

export interface LandingPageDetail extends LandingPage {
    content: LandingContent;
    fields: LandingField[];
    settings: { indexable: boolean };
    /** Vista previa firmada del borrador (vence en 30 minutos). */
    preview_url: string;
    warnings: CaptureWarning[];
}

export interface LandingPagePatch {
    name?: string;
    slug?: string;
    content?: Partial<Omit<LandingContent, 'logo_src' | 'image_src' | 'whatsapp'>> & { whatsapp?: Partial<LandingWhatsApp> };
    fields?: LandingField[];
    consent_channels?: ConsentChannel[];
    settings?: { indexable?: boolean };
    site_id?: string | null;
}

export interface CaptureOptions {
    base_url: string;
    fields: { key: BuiltinFieldKey; type: LandingFieldType }[];
    custom_types: Extract<LandingFieldType, 'text' | 'textarea' | 'select' | 'checkbox'>[];
    max_fields: number;
    consent_texts: { channel: ConsentChannel; label: string; version: string }[];
    whatsapp_accounts: { id: string; name: string; number: string | null; is_default: boolean }[];
    whatsapp_number: string | null;
    sites: { id: string; name: string; status: string }[];
    privacy_policy_url: string | null;
    images: { max_kb: number; supported: boolean };
}

export interface LandingStats {
    from: string;
    totals: {
        views: number;
        visitors: number;
        submissions: number;
        qr_views: number;
        qr_submissions: number;
        leads: number;
        qr_leads: number;
        new_contacts: number;
        with_consent: number;
        conversion_pct: number | null;
    };
    daily: { day: string; views: number; visitors: number; submissions: number; qr_views: number }[];
}

export interface MaskedContact {
    id: string;
    name: string | null;
    email: string | null;
    phone: string | null;
}

export interface LandingSubmission {
    id: string;
    contact: MaskedContact | null;
    lead_id: string | null;
    via: CaptureVia;
    source: Record<string, string> | null;
    consents: ConsentChannel[];
    was_known: boolean;
    /** Lo que escribió la persona (sin correo, teléfono ni nombre); null sin permiso para ver contactos completos. */
    answers: Record<string, string> | null;
    created_at: string;
}

export type WhatsAppLinkKind = 'qr' | 'page';

export interface WhatsAppLinkSettings {
    create_lead: boolean;
    ask_consent: boolean;
    service: string | null;
}

export interface WhatsAppLink {
    id: string;
    kind: WhatsAppLinkKind;
    name: string;
    /** El código de atribución que va en el mensaje como «ref: CÓDIGO». */
    code: string;
    message: string;
    status: 'active' | 'archived';
    page_id: string | null;
    channel_account_id: string | null;
    number: string | null;
    /** Enlace corto del hub (cuenta el escaneo y abre wa.me). */
    url: string;
    qr_url: string;
    wa_me_url: string | null;
    qr: { svg: string; png: string };
    settings: WhatsAppLinkSettings;
    warnings: CaptureWarning[];
    stats: { scans: number; chats: number; leads: number } | null;
    created_at: string | null;
    updated_at: string | null;
}

export interface WhatsAppLinkInput {
    name: string;
    message: string;
    channel_account_id?: string | null;
    settings?: Partial<WhatsAppLinkSettings>;
}

export interface WhatsAppLinkStats {
    from: string;
    totals: { scans: number; unique_scans: number; chats: number; new_contacts: number; leads: number; consents: number };
    daily: { day: string; scans: number }[];
}

export type LeadAdPlatform = 'meta' | 'google';

export interface LeadAdSource {
    id: string;
    platform: LeadAdPlatform;
    page_id: string | null;
    name: string | null;
    status: 'active' | 'error' | 'disabled';
    last_error: string | null;
    last_lead_at: string | null;
    test_received_at: string | null;
    forms_mapped: number;
    leads_30d: number;
    with_consent_30d: number;
}

export interface LeadAdsState {
    meta: {
        status: 'not_configured' | 'not_connected' | 'reconnect' | 'missing_permissions' | 'error' | 'ready' | 'active';
        /** El estado en palabras, listo para mostrar. */
        message: string;
        connection: { id: string; status: string; account_name: string | null } | null;
        missing_permissions: { scope: string; label: string }[];
        pages: LeadAdSource[];
    };
    google: {
        status: 'active' | 'not_configured';
        message: string;
        webhook_url: string | null;
        key_hint: string | null;
        source: LeadAdSource | null;
    };
}

/** Respuesta de generar o rotar la clave de Google: la clave solo se ve aquí. */
export type GoogleWebhookCreated = LeadAdsState['google'] & { key: string };

export interface MetaPageOption {
    page_id: string;
    name: string;
    source_id: string | null;
}

export interface LeadAdFormMapping {
    name?: string | null;
    consent: { key: string; channel: ConsentChannel; text?: string | null }[];
    service_key: string | null;
}

export interface LeadAdForm {
    id: string;
    name: string;
    status: string | null;
    questions: { key: string; label: string; type: string | null }[];
    /** Casillas de Meta; las marcadas por defecto no sirven como consentimiento. */
    checkboxes: { key: string; text: string; required: boolean; checked_by_default: boolean }[];
    mapping: LeadAdFormMapping;
}

export interface LeadAdLeadRow {
    id: string;
    platform: LeadAdPlatform;
    status: 'received' | 'processed' | 'failed' | 'test';
    is_test: boolean;
    error: string | null;
    form_id: string | null;
    form_name: string | null;
    campaign: { id: string | null; name: string | null };
    adset: { id: string | null; name: string | null };
    ad: { id: string | null; name: string | null };
    consents: ConsentChannel[];
    lead_id: string | null;
    contact: MaskedContact | null;
    received_at: string;
    processed_at: string | null;
}
