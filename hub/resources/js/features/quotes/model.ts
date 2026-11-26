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

/** El multiplicador de una opción como ajuste en porcentaje (1,25 → 25; 0,9 → −10), que es como lo piensa el negocio. */
export function multiplierToPct(multiplier: number): number {
    return Math.round((multiplier - 1) * 1000) / 10;
}

export function pctToMultiplier(pct: number): number {
    return Math.max(0.01, Math.round((1 + pct / 100) * 10000) / 10000);
}

/** La opción que más sube el precio: la de mayor multiplicador y, a igualdad, la de mayor precio. */
function priciest<T extends { price: number; multiplier: number }>(options: readonly T[]): T | undefined {
    return [...options].sort((a, b) => b.multiplier - a.multiplier || b.price - a.price)[0];
}

/**
 * Dos ejemplos para el asistente: lo básico (el valor por defecto, la primera opción, sin extras y el primer paquete) y
 * lo completo (el doble de cantidad, la opción que más sube el precio, todos los extras y el último paquete).
 */
export function exampleScenarios(def: QuoteDefinition): [Record<string, unknown>, Record<string, unknown>] {
    const basic = sampleAnswers(def);
    const full: Record<string, unknown> = {};
    const lastPackage = def.packages[def.packages.length - 1];
    if (lastPackage) full.package = lastPackage.key;
    for (const f of def.fields) {
        if (f.type === 'number') {
            const base = typeof basic[f.key] === 'number' ? (basic[f.key] as number) : Math.max(f.min ?? 0, 1);
            const doubled = Math.max(base * 2, f.min ?? 0);
            full[f.key] = f.max != null ? Math.min(doubled, f.max) : doubled;
        } else if (f.type === 'select') {
            full[f.key] = priciest(f.options ?? [])?.value ?? basic[f.key];
        } else {
            full[f.key] = (f.options ?? []).map((o) => o.value);
        }
    }
    return [basic, full];
}

/** Lo que pidió la persona en palabras: «40 m² · Lavable premium · 3 extras». */
export function describeAnswers(def: QuoteDefinition, answers: Record<string, unknown>, t: Translate, locale = 'es-CO'): string {
    const parts: string[] = [];
    const pkg = def.packages.find((p) => p.key === answers.package);
    if (pkg) parts.push(pkg.label);
    for (const f of def.fields) {
        const value = answers[f.key];
        if (f.type === 'number' && typeof value === 'number') {
            parts.push(`${new Intl.NumberFormat(locale).format(value)}${f.unit ? ` ${f.unit}` : ''}`);
        } else if (f.type === 'select' && typeof value === 'string') {
            const label = f.options?.find((o) => o.value === value)?.label;
            if (label) parts.push(label);
        } else if (f.type === 'checkbox' && Array.isArray(value) && value.length > 0) {
            parts.push(value.length === 1 ? (f.options?.find((o) => o.value === value[0])?.label ?? '') : t('quotes.wizard.extras', { count: value.length }));
        }
    }
    return parts.filter((p) => p !== '').join(' · ');
}

/**
 * Los precios que falta poner: los que la IA dejó en cero por no estar en la descripción (`needs`) y siguen así, y el
 * precio por unidad de un cotizador por área.
 */
export function missingPrices(kind: CalculatorKind, def: QuoteDefinition, needs: readonly string[] = []): string[] {
    const value = (path: string): number => {
        const [head, i, sub, j, leaf] = path.split('.');
        if (head === 'packages') return def.packages[Number(i)]?.price ?? 0;
        if (head === 'fields' && sub === 'unit_price') return def.fields[Number(i)]?.unit_price ?? 0;
        if (head === 'fields' && sub === 'options' && leaf === 'price') return def.fields[Number(i)]?.options?.[Number(j)]?.price ?? 0;
        if (head === 'fields' && sub === 'options' && leaf === 'multiplier')
            return Math.abs((def.fields[Number(i)]?.options?.[Number(j)]?.multiplier ?? 1) - 1);
        if (head === 'base_price' || head === 'minimum') return def[head];
        return 1;
    };
    const out = needs.filter((p) => value(p) === 0);
    if (kind === 'area' && !def.fields.some((f) => f.type === 'number' && (f.unit_price ?? 0) > 0)) {
        const i = def.fields.findIndex((f) => f.type === 'number');
        if (i >= 0 && !out.includes(`fields.${i}.unit_price`)) out.push(`fields.${i}.unit_price`);
    }
    return out;
}
