import { describe, expect, it } from 'vitest';
import { type DoctorCheck, doctorLinkParams, sortChecks, statusVariant } from './doctor-api';

const check = (key: string, status: DoctorCheck['status']): DoctorCheck => ({ key, group: 'email', status, title: key, detail: '', items: [], link: null });

describe('revisión de la instalación', () => {
    it('muestra primero lo que hay que arreglar', () => {
        const sorted = sortChecks([check('a', 'ok'), check('b', 'info'), check('c', 'error'), check('d', 'warning')]);
        expect(sorted.map((c) => c.key)).toEqual(['c', 'd', 'b', 'a']);
    });

    it('usa el color de cada estado', () => {
        expect(statusVariant('ok')).toBe('success');
        expect(statusVariant('warning')).toBe('warning');
        expect(statusVariant('error')).toBe('destructive');
        expect(statusVariant('info')).toBe('secondary');
    });

    it('lleva a la sección de Ajustes que lo arregla sin perder el resto de la dirección', () => {
        const current = new URLSearchParams('pestana=doctor&otra=1');
        expect(doctorLinkParams('pestana=ajustes&seccion=mail', current)?.toString()).toBe('pestana=ajustes&otra=1&seccion=mail');
        expect(doctorLinkParams('pestana=fx', new URLSearchParams('pestana=doctor&seccion=mail'))?.toString()).toBe('pestana=fx');
        expect(doctorLinkParams(null, current)).toBeNull();
        expect(doctorLinkParams('seccion=mail', current)).toBeNull();
    });
});
