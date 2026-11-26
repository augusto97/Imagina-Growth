import type { BusinessType, StrategyDetail, StrategyKey, StrategyRequirement, StrategySummary, Vertical } from '@/features/strategies/types';

/* El asistente de configuración (docs/diseno/rediseno-v2.md §4): GET/PUT /api/w/{ws}/onboarding y
 * POST /api/w/{ws}/onboarding/strategies (App\Domain\Onboarding). */

export type OnboardingStatus = 'not_started' | 'in_progress' | 'skipped' | 'completed';

export type OnboardingStepKey = 'business' | 'connect' | 'history' | 'channels' | 'strategies' | 'done';

export type ImportStatus = 'queued' | 'running' | 'paused' | 'completed' | 'failed' | null;

interface StepBase<K extends OnboardingStepKey> {
    key: K;
    /** Hecho según los datos reales (no según lo que se marcó). */
    done: boolean;
}

export type OnboardingStep =
    | StepBase<'business'>
    | (StepBase<'connect'> & { site_id: string | null; pending: boolean })
    | (StepBase<'history'> & {
          status: ImportStatus;
          progress: number | null;
          site_id: string | null;
          /** Lo que ya se aprendió mientras se importa (sin datos personales); null sin web conectada. */
          learned: LearnedSoFar | null;
      })
    | (StepBase<'channels'> & { channels: { whatsapp: 'active' | 'down' | null; email: 'active' | 'down' | null } })
    | (StepBase<'strategies'> & { active: StrategyKey[] })
    | StepBase<'done'>;

/** Lo aprendido del historial: la tienda (clientes, pedidos, ventas y productos) y, para todos, personas y contactos. */
export interface LearnedSoFar {
    customers: number;
    orders: number;
    revenue: string;
    top_products: string[];
    contacts: number;
    leads: number;
    appointments: number;
    /** De dónde llegan los contactos (negocios sin tienda), los 3 primeros. */
    sources: { origin: string; label: string; count: number }[];
}

export type RecommendedStrategy = StrategySummary & Pick<StrategyDetail, 'steps' | 'preview'>;

export interface Onboarding {
    status: OnboardingStatus;
    /** Lo que eligió la persona; null mientras no elija. */
    business_type: BusinessType | null;
    /** Lo que dicen los datos, para precargar el paso 1. */
    suggested_business_type: BusinessType;
    /** A qué se dedica (se guarda en el espacio de trabajo y se cambia después en Configuración). */
    vertical: Vertical | null;
    suggested_vertical: Vertical;
    /** Las opciones del paso 1, con su nombre, ejemplos y el tipo de negocio que implican. */
    verticals: VerticalOption[];
    current_step: OnboardingStepKey;
    steps: OnboardingStep[];
    progress: { completed: number; total: number };
    /** De la persona con sesión en este espacio de trabajo. */
    welcome_seen: boolean;
    skipped_at: string | null;
    completed_at: string | null;
    workspace: { name: string; country: string; currency: string; timezone: string };
    /** Paso 5: las 3 recomendadas para el tipo de negocio, con su vista previa (también las ya prendidas). */
    recommended: RecommendedStrategy[];
    /** Paso 6: «qué va a pasar ahora». */
    timeline: { when: 'today' | 'week' | 'month'; text: string }[];
}

export interface VerticalOption {
    key: Vertical;
    name: string;
    examples: string;
    business_type: 'store' | 'services';
}

export interface OnboardingUpdate {
    business_type?: BusinessType;
    vertical?: Vertical;
    current_step?: OnboardingStepKey;
    action?: 'skip' | 'resume' | 'complete';
    welcome_seen?: boolean;
}

export type ActivationResultKind = 'activated' | 'already_active' | 'needs_setup' | 'coming_soon' | 'not_in_plan' | 'forbidden';

export interface ActivationResult {
    key: StrategyKey;
    result: ActivationResultKind;
    message?: string;
    requirements?: StrategyRequirement[];
}

export interface ActivateRecommendedResponse {
    results: ActivationResult[];
    onboarding: Onboarding;
}
