import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { wsPath } from '@/api/queries';
import { api } from '@/lib/api';

/* Predicciones (M17, inteligencia.md §4). Rutas en hub/routes/api/predictions.php. */

export const MODEL_KEYS = ['edno', 'churn', 'clv', 'propensity', 'affinity', 'next_product', 'price_band', 'best_hour', 'engagement'] as const;
export type ModelKey = (typeof MODEL_KEYS)[number];
export type RunStatus = 'completed' | 'heuristic' | 'insufficient_data' | 'failed' | 'never';
export type Reliability = 'high' | 'medium' | 'low' | null;

export interface Aggregate {
    predicted_total: number;
    actual_total: number;
    error_pct: number | null;
    mae: number | null;
    correlation: number | null;
}

export interface Decile {
    decile: number;
    customers: number;
    predicted: number;
    actual: number;
}

export interface ModelRun {
    key: ModelKey;
    status: RunStatus;
    trained_at: string | null;
    reason: string | null;
    reliability: Reliability;
    params: { channels?: Record<string, number> } | null;
    metrics: {
        customers?: number;
        holdout_days?: number;
        auc?: number | null;
        active_in_holdout_pct?: number | null;
        purchases?: Aggregate | null;
        purchases_by_decile?: Decile[];
        revenue?: Aggregate | { error: string } | null;
        due_in_holdout?: number;
        due_and_bought?: number;
        precision_pct?: number | null;
        median_error_days?: number | null;
        within_15_days_pct?: number | null;
        contacts?: number;
        transitions?: number;
        contacts_with_hour?: number;
        contacts_with_channel?: number;
        bands?: Record<string, number>;
        [key: string]: unknown;
    };
}

export interface PredictionsOverview {
    models: ModelRun[];
    ml_configured: boolean;
    coverage: { contacts: number; customers: number; with_clv: number; with_churn: number; with_next_order: number; with_affinity: number };
    distributions: {
        churn: Record<string, number>;
        p_alive: { from: number; to: number; n: number }[];
        next_order: { overdue: number; next_7: number; next_30: number; later: number };
        clv_total: string;
    };
    holdout_days: number;
}

export const PRESETS = ['high_value_at_risk', 'buying_soon', 'disengaged'] as const;
export type Preset = (typeof PRESETS)[number];

const base = (ws: string) => `${wsPath(ws)}/predictions`;

export function usePredictions(ws: string) {
    return useQuery({ queryKey: ['ws', ws, 'predictions'], queryFn: ({ signal }) => api.get<PredictionsOverview>(base(ws), signal) });
}

export function useRunPredictions(ws: string) {
    return useMutation({ mutationFn: (train: boolean) => api.post<{ queued: boolean }>(`${base(ws)}/run`, { train }) });
}

export function useCreatePresetSegment(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (preset: Preset) => api.post<{ id: string; name: string }>(`${base(ws)}/segments`, { preset }),
        onSuccess: () => qc.invalidateQueries({ queryKey: ['ws', ws, 'segments'] }),
    });
}

/**
 * Cifras de «qué tan confiable es» de un modelo, en palabras (las arma la pantalla con t()).
 * null si el modelo no tiene validación contra la reserva.
 */
export function reliabilityFacts(run: ModelRun): { key: string; values: Record<string, number> } | null {
    const m = run.metrics;
    if (run.status !== 'completed') return null;
    if (run.key === 'clv' && m.purchases && m.purchases.error_pct !== null) {
        return { key: 'clv', values: { days: m.holdout_days ?? 90, predicted: Math.round(m.purchases.predicted_total), actual: Math.round(m.purchases.actual_total), error: Math.abs(m.purchases.error_pct) } };
    }
    if (run.key === 'churn' && typeof m.auc === 'number') {
        return { key: 'churn', values: { days: m.holdout_days ?? 90, auc: Math.round(m.auc * 100) } };
    }
    if (run.key === 'edno' && typeof m.within_15_days_pct === 'number') {
        return { key: 'edno', values: { days: m.holdout_days ?? 90, within: m.within_15_days_pct, median: m.median_error_days ?? 0, precision: m.precision_pct ?? 0 } };
    }
    return null;
}
