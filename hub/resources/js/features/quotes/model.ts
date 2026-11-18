import type { CalculatorKind, QuoteDefinition, QuoteField } from './types';

type Translate = (key: string, options?: Record<string, unknown>) => string;

const empty = (over: Partial<QuoteDefinition>): QuoteDefinition => ({
    currency: null,
    base_price: 0,
    minimum: 0,
    range_pct: 10,
    round_to: 1000,
    note: null,
    internal_notes: null,
    fields: [],
    packages: [],
    ...over,
});

/** Definiciones de partida de cada tipo de calculadora (con textos en el idioma de la interfaz). */
export function preset(kind: CalculatorKind, t: Translate): { name: string; definition: QuoteDefinition } {
    if (kind === 'area') {
        return {
            name: t('quotes.presets.area.name'),
            definition: empty({
                base_price: 50000,
                minimum: 300000,
                range_pct: 10,
                fields: [
                    { key: 'area', label: t('quotes.presets.area.area'), type: 'number', required: true, help: null, unit: 'm²', min: 1, max: 5000, step: 1, default: 20, unit_price: 12000 },
                    {
                        key: 'tipo',
                        label: t('quotes.presets.area.kind'),
                        type: 'select',
                        required: true,
                        help: null,
                        options: [
                            { value: 'interior', label: t('quotes.presets.area.interior'), price: 0, multiplier: 1 },
                            { value: 'exterior', label: t('quotes.presets.area.exterior'), price: 0, multiplier: 1.3 },
                        ],
                    },
                ],
            }),
        };
    }
    if (kind === 'package') {
        return {
            name: t('quotes.presets.package.name'),
            definition: empty({
                range_pct: 0,
                packages: [
                    { key: 'basico', label: t('quotes.presets.package.basic'), description: null, price: 900000 },
                    { key: 'completo', label: t('quotes.presets.package.full'), description: null, price: 1500000 },
                ],
                fields: [
                    {
                        key: 'urgente',
                        label: t('quotes.presets.package.extras'),
                        type: 'checkbox',
                        required: false,
                        help: null,
                        options: [{ value: 'urgente', label: t('quotes.presets.package.rush'), price: 0, multiplier: 1.2 }],
                    },
                ],
            }),
        };
    }
    return {
        name: t('quotes.presets.service.name'),
        definition: empty({
            base_price: 150000,
            range_pct: 15,
            fields: [
                {
                    key: 'alcance',
                    label: t('quotes.presets.service.scope'),
                    type: 'select',
                    required: true,
                    help: null,
                    options: [
                        { value: 'basico', label: t('quotes.presets.service.small'), price: 0, multiplier: 1 },
                        { value: 'medio', label: t('quotes.presets.service.medium'), price: 200000, multiplier: 1 },
                        { value: 'grande', label: t('quotes.presets.service.large'), price: 500000, multiplier: 1 },
                    ],
                },
                { key: 'horas', label: t('quotes.presets.service.hours'), type: 'number', required: false, help: null, unit: 'h', min: 0, max: 200, step: 1, default: 0, unit_price: 80000 },
            ],
        }),
    };
}

/** Un campo nuevo del constructor. */
export function newField(type: QuoteField['type'], index: number, t: Translate): QuoteField {
    const base = { key: `campo_${index + 1}`, label: t(`quotes.builder.newField.${type}`), type, required: type !== 'checkbox', help: null };
    if (type === 'number') return { ...base, unit: null, min: 0, max: null, step: 1, default: null, unit_price: 0 };
    return { ...base, options: [{ value: 'op_1', label: t('quotes.builder.optionN', { n: 1 }), price: 0, multiplier: 1 }] };
}

/** Respuestas de ejemplo para la vista previa: el valor por defecto o el mínimo, la primera opción y el primer paquete. */
export function sampleAnswers(def: QuoteDefinition, current: Record<string, unknown> = {}): Record<string, unknown> {
    const out: Record<string, unknown> = {};
    if (def.packages.length > 0) {
        const chosen = typeof current.package === 'string' && def.packages.some((p) => p.key === current.package) ? current.package : def.packages[0]?.key;
        if (chosen !== undefined) out.package = chosen;
    }
    for (const f of def.fields) {
        const prev = current[f.key];
        if (f.type === 'number') {
            out[f.key] = typeof prev === 'number' ? prev : (f.default ?? Math.max(f.min ?? 0, 1));
        } else if (f.type === 'select') {
            const values = (f.options ?? []).map((o) => o.value);
            out[f.key] = typeof prev === 'string' && values.includes(prev) ? prev : values[0];
        } else {
            out[f.key] = Array.isArray(prev) ? prev.filter((v) => (f.options ?? []).some((o) => o.value === v)) : [];
        }
    }
    return out;
}

/** «$686.000 – $839.000 COP» */
export function formatRange(min: string, max: string, currency: string, locale = 'es-CO'): string {
    const fmt = (v: string) => new Intl.NumberFormat(locale, { style: 'currency', currency, maximumFractionDigits: currency === 'COP' ? 0 : 2 }).format(Number(v));
    return min === max ? fmt(min) : `${fmt(min)} – ${fmt(max)}`;
}
