import { describe, expect, it } from 'vitest';
import { consentPayload, consentProblems, customKey, initialMapping, mappingProblems } from './mapping';

describe('mapeo de la importación CSV', () => {
    it('exige correo o teléfono', () => {
        expect(mappingProblems({ Nombre: 'first_name' })).toEqual([{ code: 'needsIdentifier' }]);
        expect(mappingProblems({ Celular: 'phone', Nombre: 'first_name' })).toEqual([]);
    });

    it('detecta un campo en dos columnas, salvo las etiquetas', () => {
        expect(mappingProblems({ Correo: 'email', 'Correo 2': 'email' })).toEqual([{ code: 'duplicate', field: 'email', headers: ['Correo', 'Correo 2'] }]);
        expect(mappingProblems({ Correo: 'email', Etiquetas: 'tags', Grupo: 'tags' })).toEqual([]);
    });

    it('valida la clave de los atributos personalizados', () => {
        expect(mappingProblems({ Correo: 'email', Talla: 'custom:' })).toEqual([{ code: 'invalidCustom', header: 'Talla' }]);
        expect(mappingProblems({ Correo: 'email', Talla: 'custom:talla' })).toEqual([]);
    });

    it('no repite un campo sugerido', () => {
        expect(initialMapping(['nombre', 'name', 'correo'], { nombre: 'first_name', name: 'first_name', correo: 'email' })).toEqual({
            nombre: 'first_name',
            name: 'ignore',
            correo: 'email',
        });
    });

    it('genera claves limpias para atributos', () => {
        expect(customKey('Código postal')).toBe('codigo_postal');
        expect(customKey('  Tipo de piel!! ')).toBe('tipo_de_piel');
    });
});

describe('declaración de consentimiento', () => {
    const today = '2026-11-18';

    it('es obligatorio elegir una opción', () => {
        expect(consentProblems({ mode: null, channels: [], source: '', date: '' }, today)).toEqual(['chooseMode']);
        expect(consentProblems({ mode: 'none', channels: [], source: '', date: '' }, today)).toEqual([]);
    });

    it('si se declara, pide canales, origen de 10 caracteres y una fecha no futura', () => {
        expect(consentProblems({ mode: 'declared', channels: [], source: 'corto', date: '2026-12-01' }, today)).toEqual(['channels', 'source', 'legalBasis', 'futureDate']);
        expect(consentProblems({ mode: 'declared', channels: ['email'], source: 'Formulario de la feria', legalBasis: 'written_form', date: '' }, today)).toEqual(['date']);
        const ok = { mode: 'declared' as const, channels: ['whatsapp' as const], source: '  Formulario de la feria  ', legalBasis: 'online_form' as const, date: '2026-10-01' };
        expect(consentProblems(ok, today)).toEqual([]);
        expect(consentPayload(ok)).toEqual({ channels: ['whatsapp'], source: 'Formulario de la feria', date: '2026-10-01', legal_basis: 'online_form' });
        expect(consentPayload({ ...ok, mode: 'none' })).toBeNull();
    });
});
