import type { BusinessType, StrategyDetail, StrategyKey, StrategyRequirement, StrategySummary } from '@/features/strategies/types';

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
          /** Lo que ya se aprendió mientras se importa (sin datos personales). */
          learned: { customers: number; orders: number; revenue: string; top_products: string[] } | null;
      })
    | (StepBase<'channels'> & { channels: { whatsapp: 'active' | 'down' | null; email: 'active' | 'down' | null } })
    | (StepBase<'strategies'> & { active: StrategyKey[] })
    | StepBase<'done'>;

export type RecommendedStrategy = StrategySummary & Pick<StrategyDetail, 'steps' | 'preview'>;

export interface Onboarding {
    status: OnboardingStatus;
    /** Lo que eligió la persona; null mientras no elija. */
    business_type: BusinessType | null;
    /** Lo que dicen los datos, para precargar el paso 1. */
    suggested_business_type: BusinessType;
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

export interface OnboardingUpdate {
    business_type?: BusinessType;
    current_step?: OnboardingStepKey;
    action?: 'skip' | 'resume' | 'complete';
    welcome_seen?: boolean;
}

export type ActivationResultKind = 'activated' | 'already_active' | 'needs_setup' | 'not_in_plan' | 'forbidden';

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
