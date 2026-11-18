import type { Condition, ConditionType, Group, Node, Window } from './types';
import { isGroup } from './types';

/*
 * Edición inmutable del AST: cada nodo se ubica por su ruta de índices
 * dentro de `conditions` (la raíz es []).
 */

export type Path = number[];

export const MAX_DEPTH = 4;

export function emptyGroup(op: Group['op'] = 'and'): Group {
    return { op, conditions: [] };
}

export function defaultCondition(type: ConditionType): Condition {
    switch (type) {
        case 'attribute':
            return { type: 'attribute', field: 'city', operator: 'in', value: [] };
        case 'purchase':
            return { type: 'purchase', scope: 'any', ids: [], operator: 'count_gte', value: 1, window: { kind: 'last_days', days: 90 } };
        case 'event':
            return { type: 'event', event: 'product.viewed', filters: [], measure: 'events', operator: 'count_gte', value: 1, window: { kind: 'last_days', days: 30 } };
        case 'cart':
            return { type: 'cart', operator: 'abandoned', window: { kind: 'last_days', days: 7 } };
        case 'prediction':
            return { type: 'prediction', field: 'rfm_segment', operator: 'in', value: [] };
        case 'consent':
            return { type: 'consent', channel: 'whatsapp', purpose: 'marketing', status: 'granted' };
        case 'segment':
            return { type: 'segment', segment_id: '', operator: 'in' };
    }
}

export function getAt(root: Group, path: Path): Node | undefined {
    let node: Node | undefined = root;
    for (const i of path) {
        if (node === undefined || !isGroup(node)) {
            return undefined;
        }
        node = node.conditions[i];
    }
    return node;
}

/** Reemplaza el nodo en `path` por lo que devuelva `fn`. */
export function updateAt(root: Group, path: Path, fn: (node: Node) => Node): Group {
    if (path.length === 0) {
        const next = fn(root);
        return isGroup(next) ? next : root;
    }
    const [head, ...rest] = path;
    return {
        ...root,
        conditions: root.conditions.map((child, i) => {
            if (i !== head) {
                return child;
            }
            if (rest.length === 0) {
                return fn(child);
            }
            return isGroup(child) ? updateAt(child, rest, fn) : child;
        }),
    };
}

export function removeAt(root: Group, path: Path): Group {
    if (path.length === 0) {
        return root;
    }
    const parentPath = path.slice(0, -1);
    const index = path[path.length - 1] ?? -1;
    return updateAt(root, parentPath, (parent) => (isGroup(parent) ? { ...parent, conditions: parent.conditions.filter((_, i) => i !== index) } : parent));
}

export function appendTo(root: Group, path: Path, node: Node): Group {
    return updateAt(root, path, (target) => (isGroup(target) ? { ...target, conditions: [...target.conditions, node] } : target));
}

export function depthOf(path: Path): number {
    return path.length + 1;
}

export function countConditions(node: Node): number {
    return isGroup(node) ? node.conditions.reduce((n, child) => n + countConditions(child), 0) : 1;
}

/** Quita grupos vacíos y condiciones incompletas antes de pedir la vista previa. */
export function prune(group: Group): Group {
    return {
        ...group,
        conditions: group.conditions
            .map((child) => (isGroup(child) ? prune(child) : child))
            .filter((child) => (isGroup(child) ? child.conditions.length > 0 : isComplete(child))),
    };
}

export function isComplete(c: Condition): boolean {
    switch (c.type) {
        case 'attribute':
        case 'prediction':
            return hasValue(c.operator, c.value);
        case 'purchase':
            if ('scope' in c) {
                return ['any', 'coupon'].includes(c.scope) || c.ids.length > 0;
            }
            return hasValue(c.operator, c.value);
        case 'event':
            return c.event !== '' && c.filters.every((f) => (Array.isArray(f.value) ? f.value.length > 0 : f.value !== ''));
        case 'segment':
            return c.segment_id !== '';
        default:
            return true;
    }
}

const NO_VALUE = ['is_set', 'is_not_set', 'this_month', 'is_true', 'is_false'];

function hasValue(operator: string, value: unknown): boolean {
    if (NO_VALUE.includes(operator)) {
        return true;
    }
    if (Array.isArray(value)) {
        return value.length > 0 && value.every((v) => v !== '' && v !== null);
    }
    return value !== undefined && value !== null && value !== '';
}

export function windowLabelKey(w: Window | undefined): string {
    return `segments.window.${w?.kind ?? 'ever'}`;
}
