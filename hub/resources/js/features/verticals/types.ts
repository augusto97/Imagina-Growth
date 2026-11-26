import type { BusinessType, StrategyKey, Vertical } from '@/features/strategies/types';

/* Perfiles de vertical (modulos.md M18 «Verticales»): GET /api/w/{ws}/verticals y POST verticals/{clave}/apply. */

export type { BusinessType, Vertical };

export type StageKind = 'open' | 'won' | 'lost';

export interface VerticalStage {
    key: string;
    label: string;
    kind: StageKind;
}

/** Cómo se llama a la persona en esa vertical (cliente, paciente, estudiante, prospecto…), ya traducido. */
export interface VerticalTerms {
    client: string;
    clients: string;
    prospect: string;
    prospects: string;
}

export interface VerticalProfile {
    key: Vertical;
    name: string;
    examples: string;
    description: string;
    business_type: BusinessType;
    terms: VerticalTerms;
    /** El embudo de leads por defecto; null en la tienda. Ganado siempre es `won` y perdido `lost`. */
    pipeline: { name: string; stages: VerticalStage[] } | null;
    priorities: string[];
    /** Estrategias recomendadas en orden (las mismas de «Recomendadas para ti»). */
    strategies: StrategyKey[];
    /** Claves de FlowLibrary que usan esas estrategias. */
    flows: string[];
    /** Claves de la galería de plantillas sugeridas (gal_* y wa_gal_*). */
    templates: string[];
    /** Industria de la copia de las plantillas, si la vertical tiene una sola. */
    industry: string | null;
}

export type PipelineOutcome = 'created' | 'unchanged' | 'updated' | 'kept_custom' | 'kept_in_use' | 'not_applicable';
export type IndustryOutcome = 'set' | 'kept' | 'unchanged' | 'not_applicable';

export interface AppliedDefaults {
    vertical: Vertical;
    pipeline: PipelineOutcome;
    industry: IndustryOutcome;
    applied_at: string;
}

export interface VerticalsResponse {
    current: {
        business_type: BusinessType;
        /** La guardada o la sugerida por la industria o los datos. */
        vertical: Vertical;
        /** ¿La eligió la persona? */
        saved: boolean;
        applied: AppliedDefaults | null;
    };
    business_types: BusinessType[];
    data: VerticalProfile[];
}

export interface ApplyVerticalResult {
    vertical: Vertical;
    pipeline: PipelineOutcome;
    /** ID público del pipeline principal (`lpl_…`), null si no aplica. */
    pipeline_id: string | null;
    industry: IndustryOutcome;
}
