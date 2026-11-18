import { describe, expect, it } from 'vitest';
import { formatRange, newField, preset, sampleAnswers } from './model';

const t = (key: string, options?: Record<string, unknown>) => (options ? `${key}:${JSON.stringify(options)}` : key);

describe('cotizador', () => {
    it('cada tipo trae una definición de partida válida', () => {
        expect(preset('area', t).definition.fields[0]).toMatchObject({ type: 'number', unit: 'm²', unit_price: 12000 });
        expect(preset('package', t).definition.packages).toHaveLength(2);
        expect(preset('service', t).definition.fields.map((f) => f.type)).toEqual(['select', 'number']);
    });

    it('arma respuestas de ejemplo y conserva las que siguen siendo válidas', () => {
        const def = preset('area', t).definition;
        expect(sampleAnswers(def)).toEqual({ area: 20, tipo: 'interior' });
        expect(sampleAnswers(def, { area: 55, tipo: 'exterior' })).toEqual({ area: 55, tipo: 'exterior' });
        expect(sampleAnswers(def, { tipo: 'no-existe' })).toEqual({ area: 20, tipo: 'interior' });
        const pkg = preset('package', t).definition;
        expect(sampleAnswers(pkg, { package: 'completo', urgente: ['urgente', 'otra'] })).toEqual({ package: 'completo', urgente: ['urgente'] });
    });

    it('crea preguntas nuevas según el tipo', () => {
        expect(newField('number', 2, t)).toMatchObject({ key: 'campo_3', type: 'number', unit_price: 0, required: true });
        expect(newField('checkbox', 0, t)).toMatchObject({ required: false, options: [{ value: 'op_1', multiplier: 1 }] });
    });

    it('muestra el rango en pesos', () => {
        expect(formatRange('686000.00', '839000.00', 'COP').replace(/\s/g, ' ')).toBe('$ 686.000 – $ 839.000');
        expect(formatRange('300000', '300000', 'COP').replace(/\s/g, ' ')).toBe('$ 300.000');
    });
});
