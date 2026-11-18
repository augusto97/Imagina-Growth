import type { Edge, Node } from '@xyflow/react';
import { branchesOf } from './catalog';
import type { Graph, GraphNode } from './types';

/* Conversión entre el grafo de la API ({nodes, edges}) y los nodos y aristas de React Flow. */

export interface FlowNodeData extends Record<string, unknown> {
    node: GraphNode;
}

export type FlowCanvasNode = Node<FlowNodeData, 'flow'>;

/** Manija de salida: la rama, o `next` para la salida normal. */
export const NEXT = 'next';

export function handleOf(branch: string | null | undefined): string {
    return branch === null || branch === undefined || branch === '' ? NEXT : branch;
}

export function branchOf(handle: string | null | undefined): string | null {
    return handle === null || handle === undefined || handle === NEXT ? null : handle;
}

export function edgeId(from: string, branch: string | null): string {
    return `${from}__${handleOf(branch)}`;
}

export function toCanvas(graph: Graph): { nodes: FlowCanvasNode[]; edges: Edge[] } {
    const nodes: FlowCanvasNode[] = graph.nodes.map((n, i) => ({
        id: n.id,
        type: 'flow',
        position: n.position ?? { x: 0, y: i * 150 },
        data: { node: { id: n.id, type: n.type, config: n.config } },
        deletable: n.type !== 'trigger',
    }));
    const edges: Edge[] = graph.edges.map((e) => ({
        id: edgeId(e.from, e.branch ?? null),
        source: e.from,
        target: e.to,
        sourceHandle: handleOf(e.branch),
        targetHandle: 'in',
    }));
    return { nodes, edges };
}

export function fromCanvas(nodes: FlowCanvasNode[], edges: Edge[]): Graph {
    const ids = new Set(nodes.map((n) => n.id));
    return {
        nodes: nodes.map((n) => ({ id: n.id, type: n.data.node.type, config: n.data.node.config, position: { x: Math.round(n.position.x), y: Math.round(n.position.y) } })),
        edges: edges
            .filter((e) => ids.has(e.source) && ids.has(e.target))
            .map((e) => {
                const branch = branchOf(e.sourceHandle);
                return branch === null ? { from: e.source, to: e.target } : { from: e.source, to: e.target, branch };
            }),
    };
}

/** ID libre para un nodo nuevo: «n1», «n2»… */
export function nextNodeId(nodes: { id: string }[]): string {
    const used = new Set(nodes.map((n) => n.id));
    let i = nodes.length;
    while (used.has(`n${i}`)) {
        i++;
    }
    return `n${i}`;
}

/** Primera salida libre de un nodo (para conectar el que se agrega). */
export function freeBranch(node: GraphNode, edges: Edge[]): string | null | undefined {
    const taken = new Set(edges.filter((e) => e.source === node.id).map((e) => handleOf(branchOf(e.sourceHandle))));
    return branchesOf(node).find((b) => !taken.has(handleOf(b)));
}

/** Quita las aristas que salen por ramas que el nodo ya no tiene (p. ej. se quitó una variante). */
export function pruneEdges(nodes: FlowCanvasNode[], edges: Edge[]): Edge[] {
    const valid = new Map(nodes.map((n) => [n.id, new Set(branchesOf(n.data.node).map(handleOf))]));
    return edges.filter((e) => valid.get(e.source)?.has(e.sourceHandle ?? NEXT) ?? false);
}
