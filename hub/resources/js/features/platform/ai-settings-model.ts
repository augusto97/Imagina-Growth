import type { SettingField } from './settings-api';
import type { Draft } from './settings-model';

/*
 * Lógica de Plataforma → Ajustes → IA, sin React: qué campos son del modelo y el esfuerzo (los pinta su propio bloque,
 * no la lista genérica), qué elige cada grupo y cada función con lo que la persona todavía no guardó, y qué esfuerzos
 * admite el modelo elegido (lo mismo que valida el hub, `AiModels::supportsEffort`).
 */

export type AiTier = 'main' | 'fast';

export interface AiModelInfo {
    id: string;
    efforts: string[];
    default_effort: string | null;
    input_usd: number;
    output_usd: number;
}

export interface AiFeatureInfo {
    key: string;
    tier: AiTier;
    default_model: string;
    default_effort: string | null;
    effective: { model: string; effort: string | null; tier: AiTier; source: 'override' | 'tier' | 'default' };
}

export const AI_EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'] as const;

/** Los campos de modelo y esfuerzo (de los grupos y de cada función): van en su bloque, no en la lista de campos. */
export function isAiModelField(key: string): boolean {
    return /^(main|fast)_(model|effort)$/.test(key) || /^feature_[a-z_]+_(model|effort)$/.test(key);
}

/** Lo que muestra un selector: lo que la persona eligió sin guardar, si no lo guardado; '' = vacío (de fábrica o del grupo). */
export function choice(field: SettingField | undefined, draft: Draft): string {
    if (!field) return '';
    if (field.key in draft) {
        const pending = draft[field.key];
        return typeof pending === 'string' ? pending : '';
    }
    return typeof field.value === 'string' ? field.value : '';
}

/** Los esfuerzos que admite un modelo; sin modelo (o uno que no está en la lista), todos. */
export function effortsFor(models: AiModelInfo[], modelId: string): string[] {
    const model = models.find((m) => m.id === modelId);
    return model ? model.efforts : [...AI_EFFORTS];
}

/**
 * El modelo que usaría un grupo con lo elegido ahora: el del panel (o el borrador), si no el del servidor, si no el de
 * fábrica cuando todas sus funciones usan el mismo (null si cada una usa el suyo).
 */
export function tierModel(tier: AiTier, fields: SettingField[], draft: Draft, features: AiFeatureInfo[]): string | null {
    const field = fields.find((f) => f.key === `${tier}_model`);
    const picked = choice(field, draft);
    if (picked !== '') return picked;
    if (field && typeof field.fallback === 'string' && field.fallback !== '') return field.fallback;
    return factoryModel(tier, features);
}

/** El modelo de fábrica de un grupo si todas sus funciones usan el mismo. */
export function factoryModel(tier: AiTier, features: AiFeatureInfo[]): string | null {
    const models = [...new Set(features.filter((f) => f.tier === tier).map((f) => f.default_model))];
    return models.length === 1 ? (models[0] ?? null) : null;
}

/** Cuántas funciones tienen su propio modelo o esfuerzo (guardado o por guardar). */
export function overridesCount(fields: SettingField[], draft: Draft, features: AiFeatureInfo[]): number {
    return features.filter((f) => {
        const model = fields.find((x) => x.key === `feature_${f.key}_model`);
        const effort = fields.find((x) => x.key === `feature_${f.key}_effort`);
        return choice(model, draft) !== '' || choice(effort, draft) !== '';
    }).length;
}

/** Precio por millón de tokens en dólares, corto: «US$0,1», «US$10». */
export function usd(value: number, locale = 'es-CO'): string {
    const digits: Intl.NumberFormatOptions = value > 0 && value < 1 ? { maximumSignificantDigits: 2 } : { maximumFractionDigits: 2, minimumFractionDigits: 0 };
    return new Intl.NumberFormat(locale, { style: 'currency', currency: 'USD', ...digits }).format(value);
}
