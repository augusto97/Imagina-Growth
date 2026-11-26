import type { BusinessType, Vertical } from '@/features/strategies/types';
import { STEP_ORDER } from './api';
import type { Onboarding, OnboardingStep, OnboardingStepKey } from './types';

/* Lógica pura del asistente y la bienvenida (docs/diseno/rediseno-v2.md §4 y §11.2), con pruebas en flow.test.ts. */

/** Cómo habla la bienvenida: a una tienda, a un negocio de servicios o neutra mientras no se sepa. */
export type WelcomeAudience = 'store' | 'services' | 'neutral';

export function audienceOf(type: BusinessType | null | undefined): WelcomeAudience {
    if (type === 'services') {
        return 'services';
    }
    return type === 'store' || type === 'both' ? 'store' : 'neutral';
}

/** El paso que se abre: el de `?paso=` si existe, si no donde quedó el negocio. */
export function initialStep(state: Pick<Onboarding, 'current_step'>, requested: string | null): OnboardingStepKey {
    return requested !== null && (STEP_ORDER as readonly string[]).includes(requested) ? (requested as OnboardingStepKey) : state.current_step;
}

export function previousStep(current: OnboardingStepKey): OnboardingStepKey | null {
    const i = STEP_ORDER.indexOf(current);
    return i > 0 ? (STEP_ORDER[i - 1] ?? null) : null;
}

/** Los pasos para la barra de arriba: los anteriores al que se ve, hechos; el que se ve, actual. */
export function stepperStatuses(viewing: OnboardingStepKey): { key: OnboardingStepKey; status: 'done' | 'current' | 'todo' }[] {
    const at = STEP_ORDER.indexOf(viewing);
    return STEP_ORDER.map((key, i) => ({ key, status: i < at ? 'done' : i === at ? 'current' : 'todo' }));
}

export function stepOf<K extends OnboardingStepKey>(state: Pick<Onboarding, 'steps'>, key: K): Extract<OnboardingStep, { key: K }> | undefined {
    return state.steps.find((s): s is Extract<OnboardingStep, { key: K }> => s.key === key);
}

/** El tipo de negocio que implica cada elección del paso 1 (la vertical manda si no es «las dos cosas»). */
export function businessChoice(type: BusinessType, vertical: Vertical | null): { business_type: BusinessType; vertical: Vertical | null } {
    if (type === 'store') {
        return { business_type: 'store', vertical: 'store' };
    }
    return { business_type: type, vertical: vertical === 'store' ? null : vertical };
}

/** La vertical que se muestra elegida en el paso 1: la guardada, si no la sugerida (para servicios, nunca «tienda»). */
export function preselectedVertical(state: Pick<Onboarding, 'vertical' | 'suggested_vertical'>): Vertical | null {
    const v = state.vertical ?? state.suggested_vertical;
    return v === 'store' ? null : v;
}

/** ¿Habla como un negocio sin tienda? (nada de carritos ni pedidos, §11.2) */
export function speaksServices(type: BusinessType | null | undefined): boolean {
    return type === 'services';
}

/** Un texto de «qué va a pasar ahora» sin «Hoy:» (la línea de tiempo ya lo dice), empezando con mayúscula. */
export function timelineText(text: string): string {
    const rest = text.replace(/^[^:]{1,20}:\s*/, '');
    return rest.charAt(0).toLocaleUpperCase('es') + rest.slice(1);
}

/** El paso del asistente que resuelve un requisito de una estrategia (conectar la web o un canal), o null. */
export function stepForRequirement(key: string): OnboardingStepKey | null {
    if (key === 'site' || key === 'store') {
        return 'connect';
    }
    return key === 'channel' || key === 'email' || key === 'whatsapp' ? 'channels' : null;
}
