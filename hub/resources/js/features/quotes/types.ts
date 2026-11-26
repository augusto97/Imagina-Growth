export type CalculatorKind = 'service' | 'area' | 'package';
export type CalculatorStatus = 'draft' | 'active' | 'paused';
export type FieldType = 'number' | 'select' | 'checkbox';

export interface QuoteOption {
    value: string;
    label: string;
    price: number;
    multiplier: number;
    /** Emoji de la tarjeta en el cotizador público. */
    icon?: string;
    /** Una línea que explica la opción. */
    description?: string;
}

export interface QuoteField {
    key: string;
    label: string;
    type: FieldType;
    required: boolean;
    help: string | null;
    unit?: string | null;
    min?: number;
    max?: number | null;
    step?: number;
    default?: number | null;
    unit_price?: number;
    options?: QuoteOption[];
    icon?: string;
}

export interface QuotePackage {
    key: string;
    label: string;
    description: string | null;
    price: number;
    icon?: string;
}

export interface QuoteDefinition {
    currency: string | null;
    base_price: number;
    minimum: number;
    range_pct: number;
    round_to: number;
    /** Frase de bienvenida del cotizador público. */
    intro?: string | null;
    note: string | null;
    internal_notes: string | null;
    fields: QuoteField[];
    packages: QuotePackage[];
}

export interface QuoteCaptureSettings {
    required: boolean;
    fields: ('first_name' | 'last_name' | 'phone' | 'email')[];
    require: 'phone' | 'email' | 'phone_or_email';
    consent_channels: ('whatsapp' | 'email' | 'sms')[];
    title: string | null;
    cta: string | null;
    success: string | null;
    whatsapp_number: string | null;
}

export interface Calculator {
    id: string;
    name: string;
    kind: CalculatorKind;
    status: CalculatorStatus;
    site_id: string | null;
    definition: QuoteDefinition;
    capture: QuoteCaptureSettings;
    shortcode: string;
    /** La página del cotizador en el hub, para compartir por enlace o QR. */
    page_url: string;
    updated_at: string | null;
    stats?: { quotes_30d: number; contacts_30d: number; leads_30d: number; avg_mid: string | null };
}

export interface CalculatorList {
    can_manage: boolean;
    /** Si la IA está disponible para «Descríbelo y la IA lo arma». */
    ai: boolean;
    currency: string;
    sites: { id: string; name: string; public_key: string }[];
    data: Calculator[];
}

export interface Estimate {
    min: string;
    max: string;
    mid: string;
    answers: Record<string, unknown>;
    lines: { label: string; amount: string }[];
}

export interface QuoteRequest {
    id: string;
    created_at: string;
    calculator: string;
    estimate_min: string | null;
    estimate_max: string | null;
    currency: string;
    answers: Record<string, unknown>;
    lead_id: string | null;
    contact: { id: string; name: string | null; email: string | null; phone: string | null } | null;
}

/** Plantilla por oficio del asistente, con precios de ejemplo en la moneda del espacio de trabajo. */
export interface QuoteTemplate {
    key: string;
    icon: string;
    kind: CalculatorKind;
    name: string;
    description: string;
    definition: QuoteDefinition;
}

/** Lo que propone la IA (nunca guardado): `needs_price` son las rutas de los precios que la persona debe poner. */
export interface QuoteProposal {
    name: string;
    kind: CalculatorKind;
    definition: QuoteDefinition;
    needs_price: string[];
}

export type ScenarioResult = ({ ok: true } & Estimate) | { ok: false; message: string };
