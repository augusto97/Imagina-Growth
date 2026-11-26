/*
 * Resultados de un negocio sin tienda (modulos.md M18 «Resultados de servicios»): GET /api/w/{ws}/services/results.
 * Los montos llegan como texto decimal ("2400000.00") en la moneda del espacio de trabajo.
 */

export type ResultsDays = 7 | 30 | 90 | 365;
export type ResultsGranularity = 'day' | 'week' | 'month';
export type KpiUnit = 'count' | 'minutes' | 'percent' | 'money';
export type ServicesKpiKey = 'leads' | 'first_response_minutes' | 'appointments_booked' | 'appointments_attended' | 'close_rate' | 'won_value';
export type AttributionGroupKey = 'flows' | 'campaigns' | 'forms' | 'chats' | 'bookings' | 'quotes' | 'other';

/** Los mismos indicadores del Inicio de servicios, frente al periodo anterior del mismo largo. */
export interface ServicesKpi {
    value: number | null;
    previous: number | null;
    change_pct: number | null;
}

export interface ResultsPeriod {
    from: string;
    to: string;
    timezone: string;
    granularity: ResultsGranularity;
    compare: { mode: string; from: string; to: string } | null;
}

export interface LeadSourceRow {
    /** Canal de la fuente (paid_social, organic_search, whatsapp…) o `unknown`. */
    channel: string;
    label: string;
    leads: number;
    won: number;
    won_value: string;
    conversion_rate: number | null;
}

export interface LeadOriginRow {
    /** form, whatsapp, instagram, messenger, booking, quote, manual, api… */
    origin: string;
    label: string;
    count: number;
}

export interface ResultsSeriesPoint {
    /** Día, lunes de la semana o primer día del mes. */
    date: string;
    leads: number;
    won: number;
    won_value: string;
}

export interface FunnelStage {
    stage: string;
    label: string;
    kind: 'open' | 'won' | 'lost';
    /** Leads del periodo que llegaron a esa etapa (o más allá, en las abiertas). */
    leads: number;
    rate: number | null;
}

export interface ServicesResults {
    period: ResultsPeriod;
    currency: string;
    units: Record<ServicesKpiKey, KpiUnit>;
    kpis: Record<ServicesKpiKey, ServicesKpi>;
    leads: {
        total: number;
        conversion_rate: number | null;
        open: { count: number; hot: number; unanswered: number };
        first_response: { answered: number; unanswered: number; median_seconds: number | null; within_15m: number; within_15m_rate: number | null };
        by_source: LeadSourceRow[];
        by_origin: LeadOriginRow[];
        series: ResultsSeriesPoint[];
        funnel: { pipeline: { id: string; name: string }; stages: FunnelStage[] };
    };
    appointments: {
        booked: number;
        completed: number;
        no_show: number;
        cancelled: number;
        /** Del periodo y todavía activas (agendada, confirmada o reprogramada). */
        pending: number;
        confirmed: number;
        /** Las que vienen (desde ahora, de cualquier fecha). */
        upcoming: number;
        /** Cumplidas ÷ (cumplidas + no asistidas). */
        attendance_rate: number | null;
    };
    quotes: {
        sent: number;
        from_calculator: number;
        /** Leads que entraron a una etapa de cotización, propuesta u oferta. */
        proposals: number;
        accepted: number;
        accepted_value: string;
        acceptance_rate: number | null;
        stages: string[];
    };
    won: { count: number; value: string };
    /** Lo que trajo cada lead ganado (una vez cada uno). */
    attribution: {
        window_days: number;
        won: number;
        /** Lo ganado por lo que pasó por la plataforma (todos los grupos menos «otros»: manuales y de la API). */
        platform_value: string;
        groups: Array<{ key: AttributionGroupKey; label: string; count: number; value: string }>;
        flows: Array<{ id: string; name: string; count: number; value: string }>;
        campaigns: Array<{ id: string; name: string; count: number; value: string }>;
    };
}

/** Un periodo fijo (`days`) o uno propio (`from` y `to`, Y-m-d, hasta 400 días). */
export type ResultsRange = { days: ResultsDays } | { from: string; to: string };
