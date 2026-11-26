import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { wsPath } from '@/api/queries';
import type { StrategyKey } from '@/features/strategies/types';
import { api } from '@/lib/api';
import type { ActivateRecommendedResponse, ActivationResult, Onboarding, OnboardingStep, OnboardingStepKey, OnboardingUpdate } from './types';

/* El asistente de configuración: el estado vive en el servidor, así se pausa y se retoma desde cualquier equipo. */

export const onboardingKeys = {
    state: (ws: string) => ['ws', ws, 'onboarding'] as const,
};

const base = (ws: string) => `${wsPath(ws)}/onboarding`;

export function useOnboarding(ws: string, enabled = true) {
    return useQuery({
        queryKey: onboardingKeys.state(ws),
        queryFn: ({ signal }) => api.get<Onboarding>(base(ws), signal),
        staleTime: 15_000,
        // Mientras se espera la conexión del sitio o corre la importación, el paso avanza solo.
        refetchInterval: (query) => (waiting(query.state.data) ? 5_000 : false),
        enabled,
    });
}

export function useUpdateOnboarding(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (input: OnboardingUpdate) => api.put<Onboarding>(base(ws), input),
        onSuccess: (state) => {
            qc.setQueryData(onboardingKeys.state(ws), state);
            void qc.invalidateQueries({ queryKey: ['ws', ws, 'home'] });
            void qc.invalidateQueries({ queryKey: ['ws', ws, 'strategies'] });
        },
    });
}

/** Paso 5: prende de una vez las recomendadas (o las que quedaron encendidas). */
export function useActivateRecommended(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (keys?: StrategyKey[]) => api.post<ActivateRecommendedResponse>(`${base(ws)}/strategies`, keys === undefined ? {} : { keys }),
        onSuccess: (res) => {
            qc.setQueryData(onboardingKeys.state(ws), res.onboarding);
            void qc.invalidateQueries({ queryKey: ['ws', ws, 'home'] });
            void qc.invalidateQueries({ queryKey: ['ws', ws, 'strategies'] });
        },
    });
}

// ── Lógica pura ─────────────────────────────────────────────────────────────

export const STEP_ORDER: readonly OnboardingStepKey[] = ['business', 'connect', 'history', 'channels', 'strategies', 'done'];

function step<K extends OnboardingStepKey>(state: Pick<Onboarding, 'steps'>, key: K): Extract<OnboardingStep, { key: K }> | undefined {
    return state.steps.find((s): s is Extract<OnboardingStep, { key: K }> => s.key === key);
}

/** Hay algo que se espera solo (el sitio conectándose o la importación corriendo). */
export function waiting(state: Pick<Onboarding, 'steps' | 'current_step'> | undefined): boolean {
    if (state === undefined) {
        return false;
    }
    const connect = step(state, 'connect');
    const history = step(state, 'history');
    return (state.current_step === 'connect' && connect !== undefined && !connect.done) || history?.status === 'running' || history?.status === 'queued';
}

/** El paso siguiente al actual (para «Continuar»). */
export function nextStep(current: OnboardingStepKey): OnboardingStepKey {
    const i = STEP_ORDER.indexOf(current);
    return STEP_ORDER[Math.min(i + 1, STEP_ORDER.length - 1)] ?? 'done';
}

/** El avance de la barra de progreso, de 0 a 1. */
export function progressRatio(state: Pick<Onboarding, 'progress'>): number {
    return state.progress.total > 0 ? Math.min(1, state.progress.completed / state.progress.total) : 0;
}

/** El asistente se ofrece a quien no lo terminó ni lo saltó. */
export function shouldOffer(state: Pick<Onboarding, 'status'>): boolean {
    return state.status === 'not_started' || state.status === 'in_progress';
}

/** Cuántas quedaron prendidas tras el paso 5 y cuáles piden algo antes. */
export function summarizeActivation(results: readonly ActivationResult[]): { on: StrategyKey[]; pending: ActivationResult[] } {
    return {
        on: results.filter((r) => r.result === 'activated' || r.result === 'already_active').map((r) => r.key),
        pending: results.filter((r) => r.result !== 'activated' && r.result !== 'already_active'),
    };
}
