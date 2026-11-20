import { useQuery } from '@tanstack/react-query';
import { wsPath } from '@/api/queries';
import type { Ability } from '@/api/types';
import { api } from '@/lib/api';

/* «Primeros pasos» del Inicio (docs/auditoria-2026-11.md F16): GET /api/w/{ws}/getting-started. */

export type GuideStepKey = 'connect_site' | 'import' | 'channel' | 'flow' | 'first_sale';

interface StepBase<K extends GuideStepKey> {
    key: K;
    done: boolean;
}

export type GuideStep =
    | (StepBase<'connect_site'> & { site_id: string | null; pending: boolean })
    | (StepBase<'import'> & { status: 'queued' | 'running' | 'paused' | 'completed' | 'failed' | null; progress: number | null; site_id: string | null })
    | (StepBase<'channel'> & { channels: ('email' | 'whatsapp')[] })
    | (StepBase<'flow'> & { status: 'missing' | 'draft' | 'active' | 'paused'; flow_id: string | null; library_key: string })
    | (StepBase<'first_sale'> & { count: number; revenue: string; first_at: string | null; first_revenue: string | null });

export interface GettingStarted {
    variant: 'store' | 'services';
    currency: string;
    steps: GuideStep[];
    completed: number;
    total: number;
}

export function useGettingStarted(ws: string, enabled = true) {
    return useQuery({
        queryKey: ['ws', ws, 'getting-started'],
        queryFn: ({ signal }) => api.get<GettingStarted>(`${wsPath(ws)}/getting-started`, signal),
        staleTime: 30_000,
        // Mientras corre la importación, el paso avanza solo.
        refetchInterval: (query) => (query.state.data?.steps.some((s) => s.key === 'import' && (s.status === 'running' || s.status === 'queued')) ? 10_000 : false),
        enabled,
    });
}

/** El primer paso sin hacer: el que se resalta. */
export function nextStep(steps: readonly GuideStep[]): GuideStepKey | null {
    return steps.find((s) => !s.done)?.key ?? null;
}

export function isComplete(guide: Pick<GettingStarted, 'steps'>): boolean {
    return guide.steps.length > 0 && guide.steps.every((s) => s.done);
}

/** Qué se ve: con todo listo, la persona lo puede ocultar; si algo deja de estar listo, vuelve a salir. */
export function guideVisible(guide: Pick<GettingStarted, 'steps'> | undefined, hidden: boolean): boolean {
    return guide !== undefined && !(hidden && isComplete(guide));
}

export interface StepAction {
    /** Texto en home.guide.actions.<label>. */
    label: string;
    /** Ruta relativa a /w/:ws (o absoluta si empieza con /). */
    to: string;
}

/**
 * El botón de cada paso pendiente según su estado y los permisos. null: no hay nada que la persona pueda hacer
 * (otra persona del equipo lo hace, o solo queda esperar).
 */
export function stepAction(step: GuideStep, abilities: readonly Ability[]): StepAction | null {
    const can = (a: Ability) => abilities.includes(a);
    switch (step.key) {
        case 'connect_site':
            if (step.pending && step.site_id) {
                return { label: 'viewSite', to: `sitios/${step.site_id}` };
            }
            return can('manageSites') ? { label: 'connect', to: 'sitios?conectar=1' } : null;
        case 'import':
            if (step.site_id === null) {
                return null;
            }
            return { label: step.status === 'failed' || step.status === 'paused' ? 'fixImport' : 'viewImport', to: `sitios/${step.site_id}` };
        case 'channel':
            return can('manageWorkspace') ? { label: 'connectChannel', to: 'canales' } : null;
        case 'flow':
            if (!can('manageMarketing')) {
                return null;
            }
            return step.flow_id !== null ? { label: 'reviewFlow', to: `flujos/${step.flow_id}` } : { label: 'activateFlow', to: 'flujos?tab=biblioteca' };
        case 'first_sale':
            return can('viewDashboards') ? { label: 'viewFlows', to: 'flujos' } : null;
    }
}

/** Quien no puede hacer el paso, ¿a quién se lo pide? */
export function stepOwnerHint(step: GuideStep): 'askAdmin' | 'askMarketing' | null {
    switch (step.key) {
        case 'connect_site':
        case 'channel':
            return 'askAdmin';
        case 'flow':
            return 'askMarketing';
        default:
            return null;
    }
}
