import { branchesOf } from '../catalog';
import { handleOf } from '../graph';
import type { Graph } from '../types';

/*
 * Acomodo automático del lienzo, por niveles y sin dependencias:
 *  1. Las conexiones que vuelven atrás (ciclos con espera) no cuentan para acomodar.
 *  2. Nivel de cada paso = el camino más largo desde el disparador: un paso al que se llega por
 *     dos caminos queda debajo de los dos.
 *  3. Un paso al que se llega por varios caminos cuelga del padre más cercano (el de más abajo),
 *     salvo que llegue por un «Si no se envía»: ese es un desvío y pesa menos. Los hijos van de
 *     izquierda a derecha en el orden de las ramas (Sí antes que No, Variante A antes que B).
 *  4. Árbol ordenado por contornos: cada rama se arrima a la de su izquierda sin pisarla en
 *     ningún nivel y cada padre queda centrado sobre sus hijos, así dos pasos nunca se pisan.
 * Los pasos sueltos (sin conexión desde el disparador) van a la derecha, desde el segundo nivel.
 */

export const NODE_WIDTH = 272;
/** Alto estimado de un paso cuando React Flow todavía no lo midió. */
export const NODE_HEIGHT = 104;
export const GAP_X = 48;
export const GAP_Y = 72;

export interface Size {
    width: number;
    height: number;
}

export type Positions = Record<string, { x: number; y: number }>;

/** Por nivel, la columna más a la izquierda y la más a la derecha que ocupa un subárbol. */
type Contour = Map<number, [number, number]>;

const keyOf = (from: string, to: string) => `${from}>${to}`;

export function layoutGraph(graph: Graph, sizes: Record<string, Size> = {}): Positions {
    const ids = graph.nodes.map((n) => n.id);
    if (ids.length === 0) {
        return {};
    }
    const byId = new Map(graph.nodes.map((n) => [n.id, n]));
    const declared = new Map(ids.map((id, i) => [id, i]));

    // Salidas de cada paso en el orden de sus ramas (rank = posición de la rama).
    const out = new Map<string, { to: string; rank: number; fallback: boolean }[]>();
    const incoming = new Map<string, number>();
    for (const node of graph.nodes) {
        const order = branchesOf(node).map(handleOf);
        const sorted = graph.edges
            .filter((e) => e.from === node.id && byId.has(e.to) && e.to !== node.id)
            .map((e) => {
                const index = order.indexOf(handleOf(e.branch));
                return { to: e.to, rank: index === -1 ? order.length : index, fallback: e.branch === 'failed' };
            })
            .sort((a, b) => a.rank - b.rank);
        // Dos ramas al mismo paso cuentan una vez (la primera).
        const list = sorted.filter((e, i) => sorted.findIndex((other) => other.to === e.to) === i);
        out.set(node.id, list);
        for (const e of list) {
            incoming.set(e.to, (incoming.get(e.to) ?? 0) + 1);
        }
    }

    // 1. Raíces y conexiones hacia atrás (DFS en el orden de las ramas).
    const roots: string[] = [];
    if (byId.has('trigger')) {
        roots.push('trigger');
    }
    for (const id of ids) {
        if (id !== 'trigger' && (incoming.get(id) ?? 0) === 0) {
            roots.push(id);
        }
    }
    const state = new Map<string, 1 | 2>();
    const back = new Set<string>();
    const post: string[] = [];
    const visit = (id: string) => {
        state.set(id, 1);
        for (const { to } of out.get(id) ?? []) {
            const s = state.get(to);
            if (s === 1) {
                back.add(keyOf(id, to));
            } else if (s === undefined) {
                visit(to);
            }
        }
        state.set(id, 2);
        post.push(id);
    };
    for (const root of roots) {
        if (!state.has(root)) {
            visit(root);
        }
    }
    // Un ciclo sin entrada (todos sus pasos sueltos): se entra por el primero declarado.
    for (const id of ids) {
        if (!state.has(id)) {
            roots.push(id);
            visit(id);
        }
    }
    const forward = (id: string) => (out.get(id) ?? []).filter((e) => !back.has(keyOf(id, e.to)));

    // 2. Niveles por el camino más largo (orden topológico = postorden al revés).
    const hasTrigger = byId.has('trigger');
    const depth = new Map<string, number>();
    for (const id of [...post].reverse()) {
        if (!depth.has(id)) {
            depth.set(id, id === 'trigger' || !hasTrigger ? 0 : 1);
        }
        const d = depth.get(id) ?? 0;
        for (const { to } of forward(id)) {
            if ((depth.get(to) ?? -1) < d + 1) {
                depth.set(to, d + 1);
            }
        }
    }

    // Orden de descubrimiento a lo ancho, para desempatar.
    const seq = new Map<string, number>();
    const queue = [...roots];
    while (queue.length > 0) {
        const id = queue.shift() as string;
        if (seq.has(id)) {
            continue;
        }
        seq.set(id, seq.size);
        for (const { to } of forward(id)) {
            if (!seq.has(to)) {
                queue.push(to);
            }
        }
    }

    // 3. Padre de cada paso: primero los caminos normales (no «Si no se envía»), luego el más
    //    cercano (más profundo) y, si empatan, el primero descubierto.
    const parent = new Map<string, { id: string; fallback: boolean }>();
    for (const id of ids) {
        for (const { to, fallback } of forward(id)) {
            const current = parent.get(to);
            const better =
                current === undefined ||
                (current.fallback && !fallback) ||
                (current.fallback === fallback &&
                    ((depth.get(id) ?? 0) > (depth.get(current.id) ?? 0) ||
                        ((depth.get(id) ?? 0) === (depth.get(current.id) ?? 0) && (seq.get(id) ?? 0) < (seq.get(current.id) ?? 0))));
            if (better) {
                parent.set(to, { id, fallback });
            }
        }
    }
    const children = new Map<string, string[]>();
    for (const id of ids) {
        const p = parent.get(id)?.id;
        if (p !== undefined) {
            children.set(p, [...(children.get(p) ?? []), id]);
        }
    }
    for (const [id, list] of children) {
        const rank = (child: string) => forward(id).find((e) => e.to === child)?.rank ?? 0;
        list.sort((a, b) => rank(a) - rank(b) || (seq.get(a) ?? 0) - (seq.get(b) ?? 0) || (declared.get(a) ?? 0) - (declared.get(b) ?? 0));
    }

    // 4. Columnas por contornos: cada subárbol se arrima al de su izquierda lo más que se pueda sin
    //    pisarlo en ningún nivel (un paso suelto no se aleja por lo ancho que sea su hermano más abajo),
    //    los hermanos guardan su orden y cada padre queda centrado sobre su primer y su último hijo.
    const offset = new Map<string, number>();
    const pack = (items: { id: string; contour: Contour }[], gap: number): { xs: number[]; contour: Contour } => {
        const xs: number[] = [];
        const merged: Contour = new Map();
        for (const [i, item] of items.entries()) {
            let x = i === 0 ? 0 : (xs[i - 1] ?? 0) + 1;
            for (const [level, [min]] of item.contour) {
                const other = merged.get(level);
                if (other !== undefined && i > 0) {
                    x = Math.max(x, other[1] + gap - min);
                }
            }
            xs.push(x);
            for (const [level, [min, max]] of item.contour) {
                const other = merged.get(level);
                merged.set(level, other === undefined ? [min + x, max + x] : [Math.min(other[0], min + x), Math.max(other[1], max + x)]);
            }
        }
        return { xs, contour: merged };
    };
    const build = (id: string): Contour => {
        const kids = children.get(id) ?? [];
        const own: [number, number] = [0, 0];
        if (kids.length === 0) {
            return new Map([[depth.get(id) ?? 0, own]]);
        }
        const { xs, contour } = pack(
            kids.map((kid) => ({ id: kid, contour: build(kid) })),
            1,
        );
        const middle = ((xs[0] ?? 0) + (xs[xs.length - 1] ?? 0)) / 2;
        kids.forEach((kid, i) => offset.set(kid, (xs[i] ?? 0) - middle));
        const shifted: Contour = new Map([[depth.get(id) ?? 0, own]]);
        for (const [level, [min, max]] of contour) {
            const mine = shifted.get(level);
            shifted.set(level, mine === undefined ? [min - middle, max - middle] : [Math.min(mine[0], min - middle), Math.max(mine[1], max - middle)]);
        }
        return shifted;
    };
    // Los grupos sueltos se separan media columna más del anterior.
    const tops = roots.filter((r) => !parent.has(r));
    const placed = pack(
        tops.map((root) => ({ id: root, contour: build(root) })),
        1.5,
    );
    const center = new Map<string, number>();
    const assign = (id: string, x: number) => {
        center.set(id, x);
        for (const kid of children.get(id) ?? []) {
            assign(kid, x + (offset.get(kid) ?? 0));
        }
    };
    tops.forEach((root, i) => assign(root, placed.xs[i] ?? 0));

    // Alto de cada nivel = el paso más alto del nivel.
    const levels = Math.max(...[...depth.values()]) + 1;
    const levelHeight = Array.from({ length: levels }, () => 0);
    for (const id of ids) {
        const d = depth.get(id) ?? 0;
        levelHeight[d] = Math.max(levelHeight[d] ?? 0, sizes[id]?.height ?? NODE_HEIGHT);
    }
    const top: number[] = [];
    let y = 0;
    for (let d = 0; d < levels; d++) {
        top.push(y);
        y += (levelHeight[d] ?? NODE_HEIGHT) + GAP_Y;
    }

    const unit = NODE_WIDTH + GAP_X;
    const origin = center.get(hasTrigger ? 'trigger' : (ids[0] as string)) ?? 0;
    const positions: Positions = {};
    for (const id of ids) {
        const width = sizes[id]?.width ?? NODE_WIDTH;
        positions[id] = { x: Math.round(((center.get(id) ?? 0) - origin) * unit - width / 2), y: Math.round(top[depth.get(id) ?? 0] ?? 0) };
    }
    return positions;
}

export interface Viewport {
    x: number;
    y: number;
    zoom: number;
}

/**
 * Vista para mostrar un flujo: completo si cabe con el texto legible; si no, el comienzo
 * arriba y centrado, con el zoom mínimo legible (se recorre desplazando).
 */
export function readableViewport(boxes: { x: number; y: number; width: number; height: number }[], container: Size, readableZoom = 0.75, padding = 32): Viewport | null {
    if (boxes.length === 0 || container.width <= 0 || container.height <= 0) {
        return null;
    }
    const minX = Math.min(...boxes.map((b) => b.x));
    const maxX = Math.max(...boxes.map((b) => b.x + b.width));
    const minY = Math.min(...boxes.map((b) => b.y));
    const maxY = Math.max(...boxes.map((b) => b.y + b.height));
    const fit = Math.min(1, (container.width - padding * 2) / Math.max(1, maxX - minX), (container.height - padding * 2) / Math.max(1, maxY - minY));
    const zoom = Math.max(fit, readableZoom);
    const x = container.width / 2 - ((minX + maxX) / 2) * zoom;
    const y = fit >= readableZoom ? container.height / 2 - ((minY + maxY) / 2) * zoom : padding - minY * zoom;
    return { x, y, zoom };
}

type Box = { x: number; y: number; width: number; height: number };

/** ¿Algún paso tapa a otro? Pasa con acomodos viejos cuando un paso crece (p. ej. al mostrar un pendiente). */
export function hasOverlap(boxes: Box[], margin = 4): boolean {
    return boxes.some((a, i) =>
        boxes.some((b, j) => j > i && a.x < b.x + b.width + margin && b.x < a.x + a.width + margin && a.y < b.y + b.height + margin && b.y < a.y + a.height + margin),
    );
}
