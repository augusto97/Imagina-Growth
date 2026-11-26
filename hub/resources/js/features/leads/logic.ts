import { BRIEF_KEYS, type BoardColumn, type Brief, type BriefKey, type LeadFilters, type LeadRow, type Stage, type Temperature } from './types';

/* Lógica pura de la pantalla de leads (probada en leads.test.ts). */

/** Query string de los filtros, sin vacíos y en orden estable. */
export function filtersQuery(f: LeadFilters): string {
    const entries = Object.entries(f)
        .filter(([, v]) => v !== undefined && v !== null && v !== '')
        .sort(([a], [b]) => a.localeCompare(b))
        .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(String(v))}`);
    return entries.length > 0 ? `?${entries.join('&')}` : '';
}

/**
 * Mueve una tarjeta en el tablero de forma optimista: la saca de su columna, la
 * pone primera en la de destino y ajusta conteos. El valor de las columnas no se
 * toca (lo recalcula el servidor al refrescar).
 */
export function moveCard(columns: BoardColumn[], leadId: string, to: string): BoardColumn[] {
    const moved = columns.flatMap((c) => c.leads).find((l) => l.id === leadId);
    if (!moved) return columns;
    const card: LeadRow = { ...moved, stage: to };
    return columns
        .map((c) => (c.leads.some((l) => l.id === leadId) ? { ...c, count: Math.max(0, c.count - 1), leads: c.leads.filter((l) => l.id !== leadId) } : c))
        .map((c) => (c.key === to ? { ...c, count: c.count + 1, leads: [card, ...c.leads] } : c));
}

/** ¿Pedir valor o motivo antes de mover? Ganado pide valor; perdido, motivo. */
export function moveNeeds(stage: Stage | undefined): 'value' | 'reason' | null {
    if (!stage) return null;
    return stage.kind === 'won' ? 'value' : stage.kind === 'lost' ? 'reason' : null;
}

export function temperatureTone(t: Temperature): 'destructive' | 'warning' | 'secondary' {
    return t === 'hot' ? 'destructive' : t === 'warm' ? 'warning' : 'secondary';
}

/**
 * Cuánto lleva un lead abierto sin que nadie le responda (desde que llegó): null si ya le respondieron o está cerrado.
 * Urgente pasada una hora (la meta de primera respuesta de los indicadores es 15 minutos).
 */
export function waitingFor(lead: Pick<LeadRow, 'first_response_at' | 'won_at' | 'lost_at' | 'created_at'>, now: number): { since: string; urgent: boolean } | null {
    if (lead.first_response_at !== null || lead.won_at !== null || lead.lost_at !== null || lead.created_at === null) return null;
    const since = Date.parse(lead.created_at);
    if (Number.isNaN(since)) return null;
    return { since: lead.created_at, urgent: now - since > 3_600_000 };
}

/** Cuántas tarjetas se ven por columna antes de «Ver más» (el tablero trae hasta 50 por etapa). */
export const BOARD_PAGE = 12;

/** La etapa que abre la lista del celular: la primera abierta con leads, si no la primera. */
export function firstBusyStage(columns: Pick<BoardColumn, 'key' | 'kind' | 'count'>[]): string | null {
    return (columns.find((c) => c.kind === 'open' && c.count > 0) ?? columns[0])?.key ?? null;
}

/** Duración legible de la primera respuesta: «4 min», «2 h 5 min», «3 d». */
export function duration(seconds: number | null): { unit: 'none' | 'sec' | 'min' | 'hm' | 'day'; a: number; b: number } {
    if (seconds === null) return { unit: 'none', a: 0, b: 0 };
    if (seconds < 60) return { unit: 'sec', a: seconds, b: 0 };
    const min = Math.round(seconds / 60);
    if (min < 60) return { unit: 'min', a: min, b: 0 };
    if (min < 60 * 24) return { unit: 'hm', a: Math.floor(min / 60), b: min % 60 };
    return { unit: 'day', a: Math.round(min / (60 * 24)), b: 0 };
}

/** Clave de etapa a partir de un nombre («Visita técnica» → «visita_tecnica»), única en la lista. */
export function stageKey(label: string, taken: string[]): string {
    const base =
        label
            .normalize('NFD')
            .replace(/[̀-ͯ]/g, '')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '_')
            .replace(/^_+|_+$/g, '')
            .replace(/^[^a-z]+/, '')
            .slice(0, 28) || 'etapa';
    let key = base;
    let i = 2;
    while (taken.includes(key)) key = `${base}_${i++}`;
    return key;
}

/** Errores del editor de pipelines, con las mismas reglas que el servidor. */
export function pipelineErrors(name: string, stages: Stage[]): string[] {
    const errors: string[] = [];
    if (name.trim() === '') errors.push('name');
    if (stages.length < 3 || stages.length > 12) errors.push('count');
    const kinds = stages.map((s) => s.kind);
    if (!kinds.includes('open') || kinds.filter((k) => k === 'won').length !== 1 || kinds.filter((k) => k === 'lost').length !== 1) errors.push('kinds');
    if (stages.some((s) => s.label.trim() === '')) errors.push('labels');
    if (new Set(stages.map((s) => s.key)).size !== stages.length) errors.push('keys');
    return errors;
}

/** Lista escrita por la persona («Bogotá, Chía ; Cota») a valores limpios y sin repetir. */
export function parseList(text: string, max = 300): string[] {
    const out: string[] = [];
    for (const part of text.split(/[,;\n]/)) {
        const v = part.trim();
        if (v !== '' && !out.includes(v)) out.push(v);
    }
    return out.slice(0, max);
}

/** Cuántos datos de la ficha tienen valor. */
export function briefFilled(brief: Brief | null | undefined): number {
    return brief ? BRIEF_KEYS.filter((k) => (brief[k]?.value ?? null) !== null).length : 0;
}

/** Valores de la ficha para el formulario (vacío = sin dato). */
export function briefDraft(brief: Brief): Record<BriefKey, string> {
    return Object.fromEntries(BRIEF_KEYS.map((k) => [k, brief[k]?.value ?? ''])) as Record<BriefKey, string>;
}

/** Solo lo que la persona cambió: un campo vacío se manda como null (queda vacío a propósito). */
export function briefEdits(brief: Brief, draft: Record<BriefKey, string>): Partial<Record<BriefKey, string | null>> {
    const out: Partial<Record<BriefKey, string | null>> = {};
    for (const k of BRIEF_KEYS) {
        const next = draft[k].trim();
        const current = brief[k]?.value ?? '';
        if (next !== current) out[k] = next === '' ? null : next;
    }
    return out;
}

/** Cambio en puntos porcentuales con su signo: «+25», «−5», «0». */
export function signedPoints(points: number): string {
    const rounded = Math.round(points * 10) / 10;
    const text = String(Math.abs(rounded)).replace('.', ',');
    return rounded > 0 ? `+${text}` : rounded < 0 ? `−${text}` : '0';
}
