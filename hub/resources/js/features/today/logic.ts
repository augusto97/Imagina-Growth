import { TODAY_KINDS, type ReachMode, type TodayItem, type TodayKind } from './types';

/* Lógica pura de «Hoy» (probada en today.test.ts). */

/** Cuántos ítems se piden sugeridos al abrir la pantalla; el resto, con el botón. */
export const AUTO_SUGGEST = 8;

/** Ítems que se ven por grupo antes de «Ver más» (con un motivo elegido, el doble). */
export const GROUP_PAGE = 5;

/** Agrupa en el orden de urgencia (respuestas, calientes, tareas, próximos pasos, quietos). */
export function groupByKind(items: TodayItem[]): { kind: TodayKind; items: TodayItem[] }[] {
    return TODAY_KINDS.map((kind) => ({ kind, items: items.filter((i) => i.kind === kind) })).filter((g) => g.items.length > 0);
}

/** ¿Se puede enviar? Sin texto o sin canal, no. */
export function canSend(text: string, mode: ReachMode | undefined): boolean {
    return text.trim().length > 0 && text.length <= 1000 && mode !== undefined && mode !== 'none';
}

/** Horas enteras desde una fecha (para «te escribió hace 4 h»). */
export function hoursSince(iso: string | null, now: Date = new Date()): number | null {
    if (!iso) return null;
    const then = Date.parse(iso);
    if (Number.isNaN(then)) return null;
    return Math.max(0, Math.floor((now.getTime() - then) / 3_600_000));
}

/** Total de ítems, para el título. */
export function totalItems(counts: Record<TodayKind, number> | undefined): number {
    return counts ? TODAY_KINDS.reduce((sum, k) => sum + counts[k], 0) : 0;
}
