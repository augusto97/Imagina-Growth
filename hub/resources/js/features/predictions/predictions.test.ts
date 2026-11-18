import { describe, expect, it } from 'vitest';
import texts from '@/locales/es-CO/predictions.json';
import { MODEL_KEYS, type ModelRun, PRESETS, reliabilityFacts } from './api';

type Tree = { [key: string]: string | Tree };

function has(tree: Tree, path: string): boolean {
    let node: string | Tree = tree;
    for (const part of path.split('.')) {
        if (typeof node !== 'object' || !(part in node)) return false;
        node = node[part] as string | Tree;
    }
    return typeof node === 'string';
}

const run = (over: Partial<ModelRun>): ModelRun => ({ key: 'clv', status: 'completed', trained_at: null, reason: null, reliability: null, params: null, metrics: {}, ...over });

describe('Predicciones', () => {
    it('tiene textos para cada modelo, estado y atajo de segmento', () => {
        const missing: string[] = [];
        for (const k of MODEL_KEYS) for (const f of ['title', 'done']) if (!has(texts, `predictions.models.${k}.${f}`)) missing.push(`${k}.${f}`);
        for (const s of ['completed', 'heuristic', 'insufficient_data', 'failed', 'never']) if (!has(texts, `predictions.status.${s}`)) missing.push(s);
        for (const p of PRESETS) if (!has(texts, `predictions.presets.${p}.title`)) missing.push(p);
        expect(missing).toEqual([]);
    });

    it('arma las cifras de confiabilidad desde la validación con la reserva', () => {
        const clv = run({ metrics: { holdout_days: 90, purchases: { predicted_total: 412.4, actual_total: 398, error_pct: 3.62, mae: 0.4, correlation: 0.6 } } });
        expect(reliabilityFacts(clv)).toEqual({ key: 'clv', values: { days: 90, predicted: 412, actual: 398, error: 3.62 } });
        expect(reliabilityFacts(run({ key: 'churn', metrics: { auc: 0.784, holdout_days: 90 } }))?.values.auc).toBe(78);
        expect(reliabilityFacts(run({ key: 'edno', metrics: { within_15_days_pct: 41.2, median_error_days: 18, precision_pct: 55 } }))?.key).toBe('edno');
        expect(reliabilityFacts(run({ status: 'insufficient_data' }))).toBeNull();
    });
});
