import type { CampaignChannel, CampaignSummary } from '@/features/campaigns/types';
import type { BusinessType, Vertical } from '@/features/strategies/types';

/*
 * Calendario comercial (modulos.md M10 «Calendario comercial»): los momentos de cada país con su campaña sugerida
 * (GET /api/w/{ws}/calendar) y las excepciones de la plataforma (GET/PUT/DELETE /api/platform/calendar).
 */

export type CalendarCountry = 'CO' | 'MX' | 'VE';

/** Claves estables de los momentos (CommercialCalendar::EVENTS); cada país tiene los suyos. */
export type MomentKey =
    | 'ano_nuevo'
    | 'inscripciones_enero'
    | 'regreso_clases_a'
    | 'san_valentin'
    | 'declaracion_islr_ve'
    | 'semana_santa'
    | 'declaracion_anual_mx'
    | 'dia_nino_co'
    | 'dia_nino_mx'
    | 'dia_madre'
    | 'dia_madre_mx'
    | 'hot_sale'
    | 'dia_padre'
    | 'vacaciones_mitad_ano_co'
    | 'prima_junio'
    | 'vacaciones_verano_mx'
    | 'dia_nino_ve'
    | 'vacaciones_escolares_ve'
    | 'declaracion_renta_co'
    | 'regreso_clases_mx'
    | 'regreso_clases_ve'
    | 'amor_amistad'
    | 'halloween'
    | 'buen_fin'
    | 'cierre_ano_b2b'
    | 'black_friday'
    | 'cyberlunes'
    | 'vacaciones_fin_ano'
    | 'prima_diciembre'
    | 'navidad';

/** Para quién es la campaña sugerida: tienda, cualquier negocio de servicios o una vertical. */
export type CampaignAudience = 'store' | 'services' | Vertical;

export interface SuggestedCampaign {
    for: CampaignAudience;
    channel: CampaignChannel;
    /** Clave de la galería (gal_promotion, gal_seasonal…). */
    template_key: string;
    template_name: string | null;
    /** Asunto propuesto (con variables Liquid). */
    subject: string | null;
    /** Segmento del sistema (rfm_…) o null para todos los que aceptaron correo. */
    segment_key: string | null;
    segment_name: string | null;
}

export interface CalendarMoment {
    key: MomentKey;
    year: number;
    name: string;
    /** Inicio y fin del momento (Y-m-d, en la zona del espacio de trabajo). */
    date: string;
    ends: string;
    /** Día sugerido para enviar la campaña. */
    send_on: string;
    /** Desde cuándo se avisa en el calendario y en las recomendaciones. */
    notice_from: string;
    days_left: number;
    ideas: string[];
    /** La plataforma movió la fecha de este año. */
    overridden: boolean;
    campaign: SuggestedCampaign;
    /** La campaña ya creada desde el calendario (`cmp_…`, en borrador o programada); null si no hay o se canceló. */
    campaign_id: string | null;
}

export interface CalendarResponse {
    country: CalendarCountry;
    business_type: BusinessType;
    vertical: Vertical;
    data: CalendarMoment[];
}

/** POST calendar/{clave}/campaign: la campaña en borrador (201 nueva, 200 ya existía). */
export type CalendarCampaignResult = CampaignSummary & { created: boolean };

// ── Plataforma (superadmin con 2FA) ─────────────────────────────────────────

export interface CalendarOverride {
    starts_on: string | null;
    ends_on: string | null;
    /** Ese año no se hace. */
    disabled: boolean;
    note: string | null;
}

export interface PlatformCalendarEvent {
    key: MomentKey;
    name: string;
    /** La fecha según la regla (sin excepciones). */
    rule_date: string | null;
    /** La que rige (null si ese año está apagado). */
    date: string | null;
    ends: string | null;
    audiences: CampaignAudience[];
    override: CalendarOverride | null;
}

export interface PlatformCalendarResponse {
    country: CalendarCountry;
    year: number;
    countries: CalendarCountry[];
    events: PlatformCalendarEvent[];
}

export interface CalendarOverrideInput {
    country: CalendarCountry;
    key: MomentKey;
    year: number;
    /** Obligatoria salvo con `disabled`; del mismo año. */
    starts_on?: string | null;
    ends_on?: string | null;
    disabled?: boolean;
    note?: string | null;
}
