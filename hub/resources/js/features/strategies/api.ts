import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { wsPath } from '@/api/queries';
import type { Ability } from '@/api/types';
import { api, isApiError } from '@/lib/api';
import type {
    ActivateInput,
    ActivateResponse,
    NeedsSetupError,
    PauseResponse,
    PlanRequiredError,
    StrategyCatalog,
    StrategyDetail,
    StrategyGoal,
    StrategyKey,
    StrategyRequirement,
    StrategyStatus,
    StrategySummary,
    Vertical,
} from './types';

/* Hooks de TanStack Query de las estrategias y la lógica pura que comparten las pantallas. */

export const strategyKeys = {
    all: (ws: string) => ['ws', ws, 'strategies'] as const,
    detail: (ws: string, key: string) => ['ws', ws, 'strategies', key] as const,
};

const base = (ws: string) => `${wsPath(ws)}/strategies`;

/** El catálogo; con `vertical`, ordena «Recomendadas para ti» para esa vertical en lugar de la del negocio. */
export function useStrategies(ws: string, enabled = true, vertical?: Vertical) {
    return useQuery({
        queryKey: vertical === undefined ? strategyKeys.all(ws) : [...strategyKeys.all(ws), { vertical }],
        queryFn: ({ signal }) => api.get<StrategyCatalog>(vertical === undefined ? base(ws) : `${base(ws)}?vertical=${encodeURIComponent(vertical)}`, signal),
        staleTime: 30_000,
        enabled,
    });
}

export function useStrategy(ws: string, key: string | undefined) {
    return useQuery({
        queryKey: strategyKeys.detail(ws, key ?? ''),
        queryFn: ({ signal }) => api.get<StrategyDetail>(`${base(ws)}/${encodeURIComponent(key ?? '')}`, signal),
        enabled: key !== undefined && key !== '',
    });
}

/** Activar o pausar cambia el catálogo, el Inicio y el asistente: se refrescan los tres. */
function useInvalidate(ws: string) {
    const qc = useQueryClient();
    return (detail: StrategyDetail) => {
        qc.setQueryData(strategyKeys.detail(ws, detail.key), detail);
        void qc.invalidateQueries({ queryKey: strategyKeys.all(ws), exact: true });
        void qc.invalidateQueries({ queryKey: ['ws', ws, 'home'] });
        void qc.invalidateQueries({ queryKey: ['ws', ws, 'onboarding'] });
        void qc.invalidateQueries({ queryKey: ['ws', ws, 'getting-started'] });
    };
}

export function useActivateStrategy(ws: string) {
    const refresh = useInvalidate(ws);
    return useMutation({
        mutationFn: ({ key, input }: { key: StrategyKey; input?: ActivateInput }) =>
            api.post<ActivateResponse>(`${base(ws)}/${encodeURIComponent(key)}/activate`, input ?? {}),
        onSuccess: refresh,
    });
}

export function usePauseStrategy(ws: string) {
    const refresh = useInvalidate(ws);
    return useMutation({
        mutationFn: (key: StrategyKey) => api.post<PauseResponse>(`${base(ws)}/${encodeURIComponent(key)}/pause`),
        onSuccess: refresh,
    });
}

// ── Lógica pura ─────────────────────────────────────────────────────────────

/** Prendida (funcionando o con un problema que revisar). */
export function isOn(status: StrategyStatus): boolean {
    return status === 'active' || status === 'attention';
}

/** Se puede activar desde el catálogo (los requisitos obligatorios los revisa el servidor y los devuelve si faltan). */
export function canActivate(strategy: Pick<StrategySummary, 'status'>): boolean {
    return strategy.status === 'available' || strategy.status === 'paused' || strategy.status === 'needs_setup';
}

/** «Recomendadas para ti» en el orden que mandó el servidor. */
export function forYou(catalog: Pick<StrategyCatalog, 'for_you' | 'data'>): StrategySummary[] {
    return catalog.for_you.map((key) => catalog.data.find((s) => s.key === key)).filter((s): s is StrategySummary => s !== undefined);
}

/** La persona tiene todos los permisos que pide la estrategia para activarla o pausarla. */
export function canManage(strategy: Pick<StrategySummary, 'abilities'>, abilities: readonly Ability[]): boolean {
    return strategy.abilities.every((a) => abilities.includes(a));
}

/** Los requisitos obligatorios que faltan, en el orden en que conviene resolverlos. */
export function blockingRequirements(strategy: Pick<StrategySummary, 'requirements'>): StrategyRequirement[] {
    return strategy.requirements.filter((r) => r.required && !r.met);
}

/** El primer paso para dejarla funcionando: el requisito que falta, o null si ya se puede activar. */
export function nextFix(strategy: Pick<StrategySummary, 'requirements'>): StrategyRequirement | null {
    return blockingRequirements(strategy)[0] ?? null;
}

/** El catálogo agrupado por objetivo, en el orden de los objetivos; las que le sirven al negocio primero en cada grupo. */
export function byGoal(catalog: Pick<StrategyCatalog, 'goals' | 'data'>): { goal: StrategyGoal; items: StrategySummary[] }[] {
    return catalog.goals
        .map((g) => ({
            goal: g.key,
            items: catalog.data.filter((s) => s.goal === g.key).sort((a, b) => Number(b.relevant) - Number(a.relevant)),
        }))
        .filter((g) => g.items.length > 0);
}

/** El error de activar, para mostrar qué falta (422) o qué plan la incluye (402). */
export function activationProblem(error: unknown): { kind: 'needs_setup'; body: NeedsSetupError } | { kind: 'plan_required'; body: PlanRequiredError } | null {
    if (isApiError(error, 'needs_setup') || isApiError(error, 'coming_soon')) {
        return { kind: 'needs_setup', body: error.body as NeedsSetupError };
    }
    if (isApiError(error, 'plan_required')) {
        return { kind: 'plan_required', body: error.body as PlanRequiredError };
    }
    return null;
}
