import { describe, expect, it } from 'vitest';
import texts from '@/locales/es-CO/today.json';
import { canSend, groupByKind, hoursSince, totalItems } from './logic';
import { TODAY_KINDS, type TodayItem } from './types';

const item = (key: string, kind: TodayItem['kind']): TodayItem => ({ key, kind, since: null, due_at: null, days: null, overdue: false, lead: null, task: null, owner: null });

type Tree = { [key: string]: string | Tree };

function has(path: string): boolean {
    let node: string | Tree = texts;
    for (const part of path.split('.')) {
        if (typeof node !== 'object' || !(part in node)) return false;
        node = node[part] as string | Tree;
    }
    return typeof node === 'string';
}

describe('Hoy', () => {
    it('agrupa en el orden de urgencia y omite los grupos vacíos', () => {
        const groups = groupByKind([item('s', 'stale'), item('r', 'reply'), item('t', 'task'), item('r2', 'reply')]);
        expect(groups.map((g) => g.kind)).toEqual(['reply', 'task', 'stale']);
        expect(groups[0]?.items.map((i) => i.key)).toEqual(['r', 'r2']);
    });

    it('solo deja enviar con texto y con un canal disponible', () => {
        expect(canSend('Hola', 'text')).toBe(true);
        expect(canSend('   ', 'text')).toBe(false);
        expect(canSend('Hola', 'none')).toBe(false);
        expect(canSend('Hola', undefined)).toBe(false);
        expect(canSend('x'.repeat(1001), 'email')).toBe(false);
    });

    it('cuenta horas y el total', () => {
        expect(hoursSince('2026-11-17T10:00:00Z', new Date('2026-11-17T14:30:00Z'))).toBe(4);
        expect(hoursSince(null)).toBeNull();
        expect(totalItems({ reply: 1, first_contact: 2, task: 0, next_step: 3, stale: 1 })).toBe(7);
        expect(totalItems(undefined)).toBe(0);
    });

    it('tiene texto para cada tipo de ítem y cada camino de envío', () => {
        const missing = [...TODAY_KINDS.map((k) => `today.kinds.${k}`), ...['text', 'template', 'email', 'none'].map((m) => `today.reach.${m}`)].filter((k) => !has(k));
        expect(missing).toEqual([]);
    });
});
