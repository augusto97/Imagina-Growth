import type { Edge } from '@xyflow/react';
import { branchesOf } from '../catalog';
import { branchOf, edgeId, type FlowCanvasNode, handleOf, NEXT } from '../graph';
import type { GraphNode } from '../types';
import { GAP_X, GAP_Y, NODE_HEIGHT, NODE_WIDTH } from './layout';

/* Cambios del lienzo como funciones puras: agregar un paso en una conexión o después de una salida. */

export interface CanvasGraph {
    nodes: FlowCanvasNode[];
    edges: Edge[];
}

const heightOf = (n: FlowCanvasNode) => n.measured?.height ?? NODE_HEIGHT;
const widthOf = (n: FlowCanvasNode) => n.measured?.width ?? NODE_WIDTH;

/** Salidas con conexión de cada paso (`next`, `yes`, `failed`…). */
export function connectedHandles(edges: Edge[]): Record<string, string[]> {
    const out: Record<string, string[]> = {};
    for (const e of edges) {
        (out[e.source] ??= []).push(e.sourceHandle ?? NEXT);
    }
    return out;
}

function canvasNode(node: GraphNode, position: { x: number; y: number }): FlowCanvasNode {
    return { id: node.id, type: 'flow', position, data: { node }, selected: true, deletable: node.type !== 'trigger' };
}

function connect(from: string, branch: string | null, to: string): Edge {
    return { id: edgeId(from, branch), source: from, target: to, sourceHandle: handleOf(branch), targetHandle: 'in' };
}

/**
 * Pone un paso nuevo en medio de una conexión: el origen va al nuevo por la misma rama y el
 * nuevo sigue al destino por su primera salida (un «Salida» no sigue). Todo lo que está desde el
 * destino hacia abajo baja para hacerle espacio.
 */
export function insertOnEdge(graph: CanvasGraph, id: string, node: GraphNode): CanvasGraph {
    const edge = graph.edges.find((e) => e.id === id);
    const target = graph.nodes.find((n) => n.id === edge?.target);
    if (!edge || !target) {
        return graph;
    }
    const shift = NODE_HEIGHT + GAP_Y;
    const limit = target.position.y - 1;
    const nodes = graph.nodes.map((n) => ({ ...n, selected: false, position: n.position.y >= limit ? { x: n.position.x, y: n.position.y + shift } : n.position }));
    const position = { x: target.position.x + (widthOf(target) - NODE_WIDTH) / 2, y: target.position.y };
    const first = branchesOf(node)[0];
    const edges = graph.edges.filter((e) => e.id !== id);
    edges.push(connect(edge.source, branchOf(edge.sourceHandle), node.id));
    if (first !== undefined) {
        edges.push(connect(node.id, first, edge.target));
    }
    return { nodes: [...nodes, canvasNode(node, position)], edges };
}

/**
 * Agrega un paso debajo de otro. Con una rama, queda conectado por ella y a su altura en el
 * nodo (la rama izquierda a la izquierda); sin rama, queda suelto. Si el lugar está ocupado,
 * se corre a la derecha hasta encontrar espacio.
 */
export function appendAfter(graph: CanvasGraph, fromId: string | null, branch: string | null | undefined, node: GraphNode): CanvasGraph {
    const from = graph.nodes.find((n) => n.id === fromId);
    const position = { x: 0, y: 0 };
    if (from) {
        const branches = branchesOf(from.data.node);
        const index = branch === undefined ? -1 : branches.findIndex((b) => handleOf(b) === handleOf(branch));
        const offset = index === -1 || branches.length < 2 ? 0 : (index - (branches.length - 1) / 2) * (NODE_WIDTH + GAP_X);
        position.x = from.position.x + (widthOf(from) - NODE_WIDTH) / 2 + offset;
        position.y = from.position.y + heightOf(from) + GAP_Y;
    }
    const taken = (x: number, y: number) =>
        graph.nodes.some(
            (n) => x < n.position.x + widthOf(n) + 16 && n.position.x < x + NODE_WIDTH + 16 && y < n.position.y + heightOf(n) + 16 && n.position.y < y + NODE_HEIGHT + 16,
        );
    while (taken(position.x, position.y)) {
        position.x += NODE_WIDTH + GAP_X;
    }
    const nodes = graph.nodes.map((n) => ({ ...n, selected: false }));
    const edges =
        from && branch !== undefined
            ? [...graph.edges.filter((e) => !(e.source === from.id && (e.sourceHandle ?? NEXT) === handleOf(branch))), connect(from.id, branch, node.id)]
            : graph.edges;
    return { nodes: [...nodes, canvasNode(node, position)], edges };
}

/** Agrega un paso suelto en un punto del lienzo (al soltarlo arrastrado desde la lista). */
export function addAt(graph: CanvasGraph, node: GraphNode, point: { x: number; y: number }): CanvasGraph {
    return {
        nodes: [...graph.nodes.map((n) => ({ ...n, selected: false })), canvasNode(node, { x: Math.round(point.x - NODE_WIDTH / 2), y: Math.round(point.y - 24) })],
        edges: graph.edges,
    };
}
