import type { Ability } from '@/api/types';

/* Estrategias (docs/diseno/rediseno-v2.md §6): GET/POST /api/w/{ws}/strategies… (App\Domain\Strategies). */

export type StrategyGoal = 'recover_sales' | 'sell_again' | 'loyalty' | 'capture' | 'reputation' | 'attention' | 'ads' | 'services';

export type StrategyAudience = 'store' | 'services' | 'both';

export type BusinessType = 'store' | 'services' | 'both';

/** A qué se dedica el negocio (docs/diseno/rediseno-v2.md §11.1); se guarda en el espacio de trabajo. */
export type Vertical = 'store' | 'professional' | 'health' | 'education' | 'real_estate' | 'hospitality' | 'automotive' | 'b2b' | 'other';

/** `coming_soon`: declarada pero su flujo de la biblioteca todavía no existe (se activa sola al llegar). */
export type StrategyStatus = 'available' | 'needs_setup' | 'active' | 'paused' | 'attention' | 'not_in_plan' | 'coming_soon';

export type StrategyKey =
    | 'abandoned_cart'
    | 'abandoned_checkout'
    | 'payment_pending'
    | 'cod_confirm'
    | 'browse_abandonment'
    | 'second_purchase'
    | 'replenishment'
    | 'winback'
    | 'ai_reactivate'
    | 'back_in_stock'
    | 'loyalty_points'
    | 'birthday'
    | 'referrals'
    | 'welcome_popup'
    | 'whatsapp_button'
    | 'product_quiz'
    | 'review_request'
    | 'nps'
    | 'google_reviews'
    | 'ai_sales_agent'
    | 'inbox_assignment'
    | 'after_hours'
    | 'ads_conversions'
    | 'ads_audiences'
    | 'service_lead'
    | 'chat_qualification'
    | 'appointment_reminders'
    | 'renewal'
    | 'quote_calculator'
    // Biblioteca ampliada (§11.4, flujos 21 a 35): «Próximamente» hasta que su flujo exista en la biblioteca.
    | 'lead_nurture'
    | 'quote_followup'
    | 'appointment_confirmation'
    | 'no_show_recovery'
    | 'post_service'
    | 'periodic_service'
    | 'event_registration'
    | 'enrollment_followup'
    | 'property_interest'
    | 'lost_lead_revival'
    | 'new_client_welcome'
    | 'services_reactivation'
    | 'cross_sell'
    | 'delivery_check'
    | 'first_purchase_anniversary';

export type RequirementKey =
    | 'module'
    | 'site'
    | 'store'
    | 'history'
    | 'channel'
    | 'email'
    | 'whatsapp'
    | 'inbox_channel'
    | 'wa_templates'
    | 'ai'
    | 'ad_destination'
    | 'booking_service'
    | 'quote_calculator'
    | 'qualification_set'
    | 'agent_on'
    | 'google_url'
    | 'wa_number';

/** Un requisito con su estado y adónde ir a resolverlo (ruta relativa a /w/:ws). Los textos ya vienen traducidos. */
export interface StrategyRequirement {
    key: RequirementKey;
    met: boolean;
    /** Obligatorio para activar; uno opcional solo avisa (p. ej. sin plantillas de WhatsApp sale por correo). */
    required: boolean;
    label: string;
    hint: string | null;
    action: string | null;
    to: string | null;
}

export type StrategyProblemCode = 'channel_down' | 'no_channel' | 'template_rejected' | 'site_down' | 'ai_unavailable' | 'destination_error';

export interface StrategyProblem {
    code: StrategyProblemCode;
    message: string;
}

/** Lo que hay en juego con los datos reales del negocio. Montos como texto decimal en la moneda del espacio de trabajo. */
export interface StrategyPotential {
    kind: string;
    count: number;
    value: string | null;
    /** Lo que se recuperaría con la tasa de referencia (`rate`), o null si no aplica. */
    estimated: string | null;
    rate: number | null;
    rate_key: string | null;
    window_days: number | null;
    /** La frase lista para mostrar («En los últimos 30 días hubo 37 carritos abandonados por $4.200.000…»). */
    summary: string;
}

export type ConversionKind = 'goals' | 'captures' | 'reviews' | 'responses' | 'referrals' | 'redemptions' | 'appointments' | 'quotes' | 'leads';

/** El resultado del mes (MoneyGenerated). */
export interface StrategyResult {
    revenue: string;
    orders: number;
    conversions: { kind: ConversionKind; count: number } | null;
    messages: number | null;
}

export interface StrategySummary {
    key: StrategyKey;
    goal: StrategyGoal;
    audience: StrategyAudience;
    /** Para qué tipos de negocio sirve. */
    business_types: ('store' | 'services')[];
    /** Para qué verticales sirve (`all`: todas las de sus tipos de negocio). */
    verticals: 'all' | Vertical[];
    /** Le sirve a este negocio (tipo y vertical). */
    relevant: boolean;
    /** Está entre las 3 recomendadas (asistente y catálogo). */
    recommended: boolean;
    /** Su lugar en «Recomendadas para ti» para la vertical del negocio (1 = la primera), o null. */
    recommended_rank: number | null;
    /** false mientras su flujo de la biblioteca no exista (status `coming_soon`). */
    available: boolean;
    /** «Próximamente» u otro motivo cuando `available` es false. */
    unavailable_reason: string | null;
    name: string;
    promise: string;
    status: StrategyStatus;
    problems: StrategyProblem[];
    modules: string[];
    /** Con `not_in_plan`: los planes que la incluyen. */
    plans: { id: string; name: string }[];
    requirements: StrategyRequirement[];
    /** Permisos para activarla o pausarla (todos). */
    abilities: Ability[];
    potential: StrategyPotential | null;
    result: StrategyResult | null;
    /** «Ajustar»: la pantalla avanzada del bloque (el flujo, el widget, el programa). */
    manage_to: string | null;
    activated_at: string | null;
    paused_at: string | null;
}

export type StrategyPreview =
    | {
          kind: 'messages';
          messages: (
              | { channel: 'email'; template: string; subject?: string; preheader?: string; headline?: string; intro?: string; cta?: string }
              | { channel: 'whatsapp'; template: string; template_status: string | null; text: string }
              | { channel: 'ai'; template: null; text: string }
          )[];
      }
    | { kind: 'widget'; widget_kind: string; title: string; body: string; cta: string; fields: string[] }
    | { kind: 'summary'; lines: string[] };

export interface StrategyPart {
    kind: string;
    key: string | null;
    primary: boolean;
    status: 'on' | 'paused' | 'off';
    problems: StrategyProblemCode[];
    /** Un flujo de apoyo que ya se puede prender (tiene canal y plantillas). */
    ready: boolean;
    to: string | null;
}

export interface MonthRange {
    from: string;
    to: string;
}

export interface StrategyDetail extends StrategySummary {
    /** «Cómo funciona» en 3 pasos. */
    steps: string[];
    preview: StrategyPreview | null;
    parts: StrategyPart[];
    month: MonthRange;
    currency: string;
}

export interface StrategyGoalInfo {
    key: StrategyGoal;
    name: string;
    description: string;
}

export interface StrategyCatalog {
    business_type: BusinessType;
    /** La vertical con la que se ordenó (la del negocio o la de `?vertical=`). */
    vertical: Vertical;
    /** «Recomendadas para ti», en orden (hasta 6, sin las de «Próximamente» ni las que el plan no incluye). */
    for_you: StrategyKey[];
    currency: string;
    month: MonthRange;
    goals: StrategyGoalInfo[];
    recommended: StrategyKey[];
    data: StrategySummary[];
}

export interface ActivateInput {
    google_review_url?: string;
    wa_number?: string;
}

export interface ActivateResponse extends StrategyDetail {
    /** false si ya estaba activa (no se hizo nada). */
    activated: boolean;
    /** Flujos de apoyo que no se pudieron prender todavía (p. ej. sin canal). */
    skipped: string[];
}

export interface PauseResponse extends StrategyDetail {
    paused: boolean;
}

/** Cuerpo del 422 `needs_setup` (o `coming_soon`, sin requisitos) al activar. */
export interface NeedsSetupError {
    error: 'needs_setup' | 'coming_soon';
    message: string;
    requirements: StrategyRequirement[];
    issues: Record<string, string[]>;
    to: string | null;
}

/** Cuerpo del 402 `plan_required` al activar. */
export interface PlanRequiredError {
    error: 'plan_required';
    module: string | null;
    modules: string[];
    plans: { id: string; name: string }[];
    message: string;
}
