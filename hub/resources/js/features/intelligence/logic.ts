/* Lógica pura de las pantallas de IA (probada con Vitest). */

export interface MarkdownBlock {
    kind: 'p' | 'list';
    lines: { text: string; bold: boolean }[][];
}

/**
 * Markdown mínimo y seguro de los informes: párrafos, listas con «- » y **negritas**.
 * Nunca HTML: cada trozo se pinta como texto.
 */
export function parseMarkdown(body: string): MarkdownBlock[] {
    const blocks: MarkdownBlock[] = [];
    for (const chunk of body.replace(/\r/g, '').split(/\n{2,}/)) {
        const lines = chunk.split('\n').filter((l) => l.trim() !== '');
        if (lines.length === 0) continue;
        const isList = lines.every((l) => /^\s*-\s+/.test(l));
        blocks.push({
            kind: isList ? 'list' : 'p',
            lines: lines.map((l) => inline(isList ? l.replace(/^\s*-\s+/, '') : l)),
        });
    }
    return blocks;
}

function inline(text: string): { text: string; bold: boolean }[] {
    return text
        .split(/(\*\*[^*]+\*\*)/g)
        .filter((part) => part !== '')
        .map((part) => (part.startsWith('**') && part.endsWith('**') && part.length > 4 ? { text: part.slice(2, -2), bold: true } : { text: part, bold: false }));
}

/** Ejemplos de preguntas en español para empezar. */
/**
 * Ejemplos de «Pregúntale a tus datos» por tipo de negocio (textos en intelligence.ask.examples.<tipo>.<n>): una
 * tienda pregunta por ventas y pedidos; un negocio sin tienda, por las visitas de su web (lo que mide el catálogo).
 */
export const EXAMPLE_KEYS = { store: ['q1', 'q2', 'q3', 'q4', 'q5'], services: ['q1', 'q2', 'q3', 'q4'] } as const;

export function examplesFor(audience: { store: boolean }): { kind: 'store' | 'services'; keys: readonly string[] } {
    const kind = audience.store ? 'store' : 'services';
    return { kind, keys: EXAMPLE_KEYS[kind] };
}

/** Parte del presupuesto usada, de 0 a 1. */
export function budgetShare(spent: number, budget: number): number {
    if (budget <= 0) return 0;
    return Math.min(1, Math.max(0, spent / budget));
}

/** La unidad del catálogo como la entienden los gráficos de Analítica. */
export function chartUnit(unit: 'money' | 'count' | 'percent' | undefined): 'money' | 'count' | 'ratio' {
    return unit === 'percent' ? 'ratio' : unit === 'money' ? 'money' : 'count';
}
