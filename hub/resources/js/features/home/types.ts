import type { OnboardingStatus, OnboardingStepKey } from '@/features/onboarding/types';
import type { BusinessType, MonthRange, StrategyKey, StrategyPotential, StrategySummary } from '@/features/strategies/types';

/* El Inicio (docs/diseno/rediseno-v2.md §5): GET /api/w/{ws}/home (App\Domain\Home\Queries\HomeOverview). */

/** Lo que generó cada estrategia (o lo que no es una estrategia: campañas, afiliados, otras automatizaciones). */
export interface MoneyBreakdownRow {
    strategy: StrategyKey | null;
    bucket: string;
    name: string;
    revenue: string;
    orders: number;
}

export interface HomeMoney {
    /** Montos como texto decimal en la moneda del espacio de trabajo. */
    total: string;
    orders: number;
    /** Todas las ventas del negocio en el mes (lo que la plataforma ayudó y lo que no). */
    store_total: string;
    share_pct: number;
    previous: { total: string; orders: number; share_pct: number; from: string; to: string };
    /** Contra el mes anterior; null si el anterior fue 0. */
    change_pct: number | null;
    breakdown: MoneyBreakdownRow[];
    /** En cero o bajo: el Inicio muestra el potencial. */
    low: boolean;
}

export interface HomePotential extends StrategyPotential {
    strategy: StrategyKey;
    name: string;
    status: 'available' | 'needs_setup' | 'paused';
}

export type TodayKind =
    | 'connect_site'
    | 'import_running'
    | 'import_failed'
    | 'connect_channel'
    | 'strategy_attention'
    | 'reply_conversations'
    | 'contact_leads'
    | 'insight'
    | 'activate_strategy';

/** Una acción de «Para hoy» con su enlace (ruta relativa a /w/:ws) y por qué importa. Los textos ya vienen traducidos. */
export interface TodayItem {
    kind: TodayKind;
    key: string;
    group: 'setup' | 'strategy' | 'conversations' | 'leads' | 'insight';
    title: string;
    why: string;
    action: string;
    to: string;
    priority: number;
    strategy: StrategyKey | null;
    count?: number;
    since?: string | null;
    amount?: string | null;
    insight_id?: string;
    severity?: 'critical' | 'warning' | 'opportunity' | 'info';
}

export interface KpiValue {
    /** null cuando no hay con qué calcularlo (p. ej. tiempo de primera respuesta sin contactos respondidos). */
    value: number | null;
    previous: number | null;
    change_pct: number | null;
}

export type StoreKpi = 'revenue' | 'orders' | 'new_customers' | 'returning_customers';

/** Negocio sin tienda (§11.2): nada de pedidos ni carritos. */
export type ServicesKpi = 'leads' | 'first_response_minutes' | 'appointments_booked' | 'appointments_attended' | 'close_rate' | 'won_value';

export type KpiKey = StoreKpi | ServicesKpi;

export type KpiUnit = 'money' | 'count' | 'minutes' | 'percent';

export interface KpiDefinition {
    unit: KpiUnit;
    label: string;
    /** Cómo se calcula, para la ayuda «?». */
    help: string;
}

export interface KpiPeriod {
    from: string;
    to: string;
    timezone: string;
    granularity: 'day' | 'week' | 'month';
    compare: { mode: string; from: string; to: string } | null;
}

export interface HomeKpis {
    /** `store`: ventas y clientes; `services`: contactos, respuesta, citas y cierre; `both`: los dos grupos. */
    kind: 'store' | 'services' | 'both';
    available: boolean;
    period: KpiPeriod;
    /** Los KPI que aplican a este negocio, en el orden en que se muestran: la UI no adivina. */
    applicable: KpiKey[];
    definitions: Partial<Record<KpiKey, KpiDefinition>>;
    metrics: Partial<Record<KpiKey, KpiValue>>;
    /** Las métricas que trae la serie de 30 días (minigráficas). */
    series_keys: KpiKey[];
    series: ({ date: string } & Partial<Record<KpiKey, number>>)[];
    /** De dónde vienen los contactos del mes (negocios sin tienda). */
    lead_sources: { origin: string; label: string; count: number }[];
}

export interface HealthNotice {
    site_id: string;
    host: string;
    state: 'lagging' | 'no_data';
    causes: { key: string; severity: string; params: Record<string, string | number> }[];
}

export interface HomeSetup {
    status: OnboardingStatus;
    /** La tarjeta de progreso se ve mientras el asistente no termine. */
    visible: boolean;
    current_step: OnboardingStepKey;
    completed: number;
    total: number;
    /** Si la persona con sesión ya vio la bienvenida (la primera vez el Inicio la lleva a verla). */
    welcome_seen: boolean;
}

export interface Home {
    workspace: { name: string; currency: string; timezone: string };
    greeting: { name: string | null };
    period: { key: 'month' } & MonthRange;
    business_type: BusinessType;
    setup: HomeSetup;
    money: HomeMoney;
    /** Solo con el dinero en cero o bajo. */
    potential: HomePotential | null;
    /** Máximo 5, en orden de prioridad. */
    today: TodayItem[];
    strategies: { active: StrategySummary[]; suggested: StrategySummary[] };
    kpis: HomeKpis;
    health: HealthNotice[];
}
