import { describe, expect, it } from 'vitest';
import { audienceOf, businessChoice, initialStep, preselectedVertical, previousStep, speaksServices, stepForRequirement, stepperStatuses, timelineText } from './flow';

describe('el asistente y la bienvenida', () => {
    it('la bienvenida habla a una tienda, a servicios o neutra mientras no se sepa', () => {
        expect(audienceOf('store')).toBe('store');
        expect(audienceOf('both')).toBe('store');
        expect(audienceOf('services')).toBe('services');
        expect(audienceOf(null)).toBe('neutral');
        expect(audienceOf(undefined)).toBe('neutral');
        expect(speaksServices('services')).toBe(true);
        expect(speaksServices('both')).toBe(false);
    });

    it('abre el paso pedido en ?paso= si existe; si no, donde quedó', () => {
        expect(initialStep({ current_step: 'history' }, 'connect')).toBe('connect');
        expect(initialStep({ current_step: 'history' }, 'otro')).toBe('history');
        expect(initialStep({ current_step: 'channels' }, null)).toBe('channels');
        expect(previousStep('business')).toBeNull();
        expect(previousStep('done')).toBe('strategies');
    });

    it('la barra de pasos marca los anteriores como hechos y el que se ve como actual', () => {
        expect(stepperStatuses('history').map((s) => s.status)).toEqual(['done', 'done', 'current', 'todo', 'todo', 'todo']);
    });

    it('el tipo de negocio y la vertical del paso 1', () => {
        expect(businessChoice('store', 'health')).toEqual({ business_type: 'store', vertical: 'store' });
        expect(businessChoice('services', 'health')).toEqual({ business_type: 'services', vertical: 'health' });
        expect(businessChoice('both', 'store')).toEqual({ business_type: 'both', vertical: null });
        expect(preselectedVertical({ vertical: null, suggested_vertical: 'health' })).toBe('health');
        expect(preselectedVertical({ vertical: null, suggested_vertical: 'store' })).toBeNull();
        expect(preselectedVertical({ vertical: 'education', suggested_vertical: 'health' })).toBe('education');
    });

    it('lo que falta para una estrategia se resuelve en su paso del asistente', () => {
        expect(stepForRequirement('channel')).toBe('channels');
        expect(stepForRequirement('whatsapp')).toBe('channels');
        expect(stepForRequirement('store')).toBe('connect');
        expect(stepForRequirement('history')).toBeNull();
    });

    it('la línea de tiempo quita «Hoy:» y empieza con mayúscula', () => {
        expect(timelineText('Hoy: tu web ya nos cuenta qué pasa.')).toBe('Tu web ya nos cuenta qué pasa.');
        expect(timelineText('Este mes: el Inicio te muestra todo.')).toBe('El Inicio te muestra todo.');
        expect(timelineText('Sin prefijo')).toBe('Sin prefijo');
    });
});
