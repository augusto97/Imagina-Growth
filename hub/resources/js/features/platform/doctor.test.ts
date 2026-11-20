import { describe, expect, it } from 'vitest';
import { type DoctorCheck, sortChecks, statusVariant } from './doctor-api';

const check = (key: string, status: DoctorCheck['status']): DoctorCheck => ({ key, group: 'email', status, title: key, detail: '', items: [] });

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
});
