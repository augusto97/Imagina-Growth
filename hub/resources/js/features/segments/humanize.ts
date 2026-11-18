import type { TFunction } from 'i18next';
import type { Condition, Group, Node, Window } from './types';
import { isGroup } from './types';

/*
 * Descripción en español de un segmento, para la lista y el detalle
 * («Ciudad es Manizales o Pereira · Compró la categoría 12 al menos 2 veces…»).
 */

export function describeWindow(t: TFunction, w: Window | undefined): string {
    if (!w || w.kind === 'ever') {
        return t('segments.window.ever');
    }
    if (w.kind === 'last_days') {
        return t('segments.window.last_days_n', { count: w.days });
    }
    if (w.kind === 'this_month') {
        return t('segments.window.this_month');
    }
    return t('segments.window.between_dates', { from: w.from, to: w.to });
}

function list(value: unknown): string {
    return Array.isArray(value) ? value.join(', ') : String(value ?? '');
}

function countText(t: TFunction, operator: string, value: number | undefined): string {
    return t(`segments.count.${operator}`, { count: value ?? 1 });
}

export function describeCondition(t: TFunction, c: Condition, names: Record<string, string> = {}): string {
    switch (c.type) {
        case 'attribute':
        case 'prediction': {
            const field = t(`segments.fields.${c.field.startsWith('custom.') ? 'custom' : c.field}`, { key: c.field.slice(7), defaultValue: c.field });
            const value = Array.isArray(c.value) ? c.value.map((v) => names[String(v)] ?? String(v)) : names[String(c.value)] ?? c.value;
            return t(`segments.op.${c.operator}`, { field, value: list(value) });
        }
        case 'purchase': {
            if ('scope' in c) {
                const what = c.scope === 'any' ? t('segments.scope.any') : t(`segments.scope.${c.scope}_items`, { items: c.ids.map((id) => names[id] ?? id).join(', ') || '—' });
                return `${t('segments.describe.purchase', { what })} ${countText(t, c.operator, c.value)} · ${describeWindow(t, c.window)}`;
            }
            const field = t(`segments.fields.${c.field}`);
            const base = t(`segments.op.${c.operator}`, { field, value: list(c.value) });
            return c.window ? `${base} · ${describeWindow(t, c.window)}` : base;
        }
        case 'event': {
            const filters = c.filters.map((f) => `${t(`segments.props.${f.prop}`, { defaultValue: f.prop })} ${t(`segments.filterOp.${f.operator}`)} ${list(f.value)}`).join(', ');
            const measure = c.measure === 'events' ? '' : ` (${t(`segments.measure.${c.measure}`)})`;
            return `${t('segments.describe.event', { event: t(`segments.events.${c.event}`, { defaultValue: c.event }) })}${filters ? ` [${filters}]` : ''} ${countText(t, c.operator, c.value)}${measure} · ${describeWindow(t, c.window)}`;
        }
        case 'cart':
            return `${t('segments.describe.cart')} · ${describeWindow(t, c.window)}`;
        case 'consent':
            return t(`segments.describe.consent_${c.status}`, { channel: t(`segments.channels.${c.channel}`) });
        case 'segment':
            return t(`segments.describe.segment_${c.operator}`, { name: names[c.segment_id] ?? c.segment_id });
    }
}

export function describe(t: TFunction, node: Node, names: Record<string, string> = {}): string {
    if (!isGroup(node)) {
        return describeCondition(t, node, names);
    }
    const joiner = ` ${t(`segments.join.${node.op}`)} `;
    const inner = node.conditions.map((child) => (isGroup(child) ? `(${describe(t, child, names)})` : describe(t, child, names))).join(joiner);
    return node.negate ? `${t('segments.describe.not')} (${inner})` : inner;
}

export function summarize(t: TFunction, definition: Group | null, names: Record<string, string> = {}): string {
    if (definition === null) {
        return t('segments.staticSummary');
    }
    return describe(t, definition, names);
}
