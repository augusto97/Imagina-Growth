import { branchesOf } from '../catalog';
import { handleOf } from '../graph';
import type { Graph, GraphNode } from '../types';

/*
 * El flujo como lista de pasos numerados (vista del celular). Una cadena sin ramas se lee de
 * corrido; un paso con ramas abre una sublista por cada salida. Un paso al que se llega por
 * otro camino no se repite: se dice «sigue en el paso N».
 */

export type OutlineItem = { kind: 'step'; step: OutlineStep } | { kind: 'goto'; id: string; number: number };

export interface OutlineStep {
    id: string;
    number: number;
    node: GraphNode;
    /** Solo en los pasos que se dividen: una lista por salida, en el orden de las ramas. */
    branches: { branch: string | null; items: OutlineItem[] }[];
}

export interface Outline {
    items: OutlineItem[];
    /** Pasos sueltos: nadie llega a ellos desde el disparador. */
    orphans: OutlineItem[];
}

export function outline(graph: Graph): Outline {
    const byId = new Map(graph.nodes.map((n) => [n.id, n]));
    const numbers = new Map<string, number>();

    const targetOf = (id: string, branch: string | null) => graph.edges.find((e) => e.from === id && handleOf(e.branch) === handleOf(branch) && byId.has(e.to))?.to;

    const walk = (start: string | undefined): OutlineItem[] => {
        const items: OutlineItem[] = [];
        let id = start;
        while (id !== undefined) {
            const seen = numbers.get(id);
            if (seen !== undefined) {
                items.push({ kind: 'goto', id, number: seen });
                break;
            }
            const node = byId.get(id) as GraphNode;
            numbers.set(id, numbers.size + 1);
            const step: OutlineStep = { id, number: numbers.size, node, branches: [] };
            items.push({ kind: 'step', step });
            const branches = branchesOf(node);
            const connected = branches.filter((b) => targetOf(id as string, b) !== undefined);
            if (connected.length === 0) {
                break;
            }
            // Cadena: una sola salida conectada y es la normal.
            if (connected.length === 1 && connected[0] === null) {
                id = targetOf(id, null);
                continue;
            }
            const from = id;
            step.branches = branches.map((branch) => ({ branch, items: walk(targetOf(from, branch)) }));
            break;
        }
        return items;
    };

    const start = byId.has('trigger') ? 'trigger' : graph.nodes[0]?.id;
    const items = walk(start);
    const orphans: OutlineItem[] = [];
    for (const node of graph.nodes) {
        if (!numbers.has(node.id)) {
            orphans.push(...walk(node.id));
        }
    }
    return { items, orphans };
}
