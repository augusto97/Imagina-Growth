import { describe, expect, it } from 'vitest';
import { nextStep, progressRatio, shouldOffer, summarizeActivation, waiting } from './api';
import type { Onboarding, OnboardingStep } from './types';

const steps = (overrides: Partial<Record<OnboardingStep['key'], Partial<OnboardingStep>>> = {}): OnboardingStep[] =>
    (
        [
            { key: 'business', done: false },
            { key: 'connect', done: false, site_id: null, pending: false },
            { key: 'history', done: false, status: null, progress: null, site_id: null, learned: null },
            { key: 'channels', done: false, channels: { whatsapp: null, email: null } },
            { key: 'strategies', done: false, active: [] },
            { key: 'done', done: false },
        ] as OnboardingStep[]
    ).map((s) => ({ ...s, ...(overrides[s.key] ?? {}) }) as OnboardingStep);

const state = (overrides: Partial<Onboarding> = {}): Pick<Onboarding, 'steps' | 'current_step' | 'status' | 'progress'> => ({
    steps: steps(),
    current_step: 'business',
    status: 'not_started',
    progress: { completed: 0, total: 5 },
    ...overrides,
});

describe('asistente de configuración', () => {
    it('espera sola mientras el sitio se conecta o corre la importación', () => {
        expect(waiting(undefined)).toBe(false);
        expect(waiting(state())).toBe(false);
        expect(waiting(state({ current_step: 'connect' }))).toBe(true);
        expect(waiting(state({ current_step: 'connect', steps: steps({ connect: { done: true } }) }))).toBe(false);
        expect(waiting(state({ current_step: 'channels', steps: steps({ history: { status: 'running' } }) }))).toBe(true);
    });

    it('avanza en orden y termina en «Listo»', () => {
        expect(nextStep('business')).toBe('connect');
        expect(nextStep('strategies')).toBe('done');
        expect(nextStep('done')).toBe('done');
    });

    it('progreso y cuándo ofrecerlo', () => {
        expect(progressRatio(state({ progress: { completed: 2, total: 5 } }))).toBe(0.4);
        expect(progressRatio(state({ progress: { completed: 0, total: 0 } }))).toBe(0);
        expect(shouldOffer(state())).toBe(true);
        expect(shouldOffer(state({ status: 'skipped' }))).toBe(false);
        expect(shouldOffer(state({ status: 'completed' }))).toBe(false);
    });

    it('resume lo que quedó prendido y lo que pide algo antes', () => {
        const summary = summarizeActivation([
            { key: 'abandoned_cart', result: 'activated' },
            { key: 'second_purchase', result: 'already_active' },
            { key: 'appointment_reminders', result: 'needs_setup', message: 'Crea un servicio' },
        ]);
        expect(summary.on).toEqual(['abandoned_cart', 'second_purchase']);
        expect(summary.pending.map((p) => p.key)).toEqual(['appointment_reminders']);
    });
});
