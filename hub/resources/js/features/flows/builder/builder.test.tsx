import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import i18n from '@/lib/i18n';
import { toCanvas } from '../graph';
import type { FlowOptions, Graph, GraphEdge, GraphNode } from '../types';
import { branchText, describeNode, edgeLabel } from './describe';
import { addAt, appendAfter, connectedHandles, insertOnEdge } from './edit';
import { emptyHistory, record, redo, undo } from './history';
import { GAP_X, GAP_Y, hasOverlap, layoutGraph, NODE_HEIGHT, NODE_WIDTH, readableViewport } from './layout';
import { FlowOutlineList } from './MobileFlowView';
import { outline } from './outline';

const t = i18n.t.bind(i18n);
const UNIT = NODE_WIDTH + GAP_X;

const options: FlowOptions = {
    nodes: [],
    events: ['cart.abandoned', 'order.paid'],
    templates: [{ id: 'tpl_1', name: 'Carrito 1', channel: 'email', category: 'marketing', library_key: 'abandoned_cart_1', status: 'approved' }],
    segments: [{ id: 'sg_1', name: 'En riesgo', is_system: true }],
    flows: [],
    date_attributes: [],
    threshold_metrics: [],
};

const n = (id: string, type: string, config: Record<string, unknown> = {}): GraphNode => ({ id, type, config });
const e = (from: string, to: string, branch?: string): GraphEdge => (branch === undefined ? { from, to } : { from, to, branch });
const boxes = (graph: Graph, pos: ReturnType<typeof layoutGraph>) => graph.nodes.map((node) => ({ ...(pos[node.id] ?? { x: 0, y: 0 }), width: NODE_WIDTH, height: NODE_HEIGHT }));
const cx = (pos: ReturnType<typeof layoutGraph>, id: string) => (pos[id]?.x ?? NaN) + NODE_WIDTH / 2;

afterEach(cleanup);

describe('acomodo automático', () => {
    it('una cadena queda en una columna, de arriba hacia abajo, con el disparador en x = 0', () => {
        const graph: Graph = { nodes: [n('trigger', 'trigger'), n('a', 'wait'), n('b', 'send_email')], edges: [e('trigger', 'a'), e('a', 'b')] };
        const pos = layoutGraph(graph);
        expect(cx(pos, 'trigger')).toBe(0);
        expect(cx(pos, 'a')).toBe(0);
        expect(cx(pos, 'b')).toBe(0);
        expect(pos.a?.y).toBe(NODE_HEIGHT + GAP_Y);
        expect(pos.b?.y).toBe(2 * (NODE_HEIGHT + GAP_Y));
    });

    it('las ramas van en su orden (Sí a la izquierda) y el padre queda centrado', () => {
        const graph: Graph = {
            nodes: [n('trigger', 'trigger'), n('c', 'condition'), n('no', 'exit'), n('yes', 'send_email')],
            edges: [e('trigger', 'c'), e('c', 'no', 'no'), e('c', 'yes', 'yes')],
        };
        const pos = layoutGraph(graph);
        expect(cx(pos, 'yes')).toBe(-UNIT / 2);
        expect(cx(pos, 'no')).toBe(UNIT / 2);
        expect(pos.yes?.y).toBe(pos.no?.y);
        expect(cx(pos, 'c')).toBe(0);
    });

    it('un paso al que se llega por dos caminos queda debajo de los dos, sin pisar a nadie', () => {
        const graph: Graph = {
            nodes: [n('trigger', 'trigger'), n('c', 'condition'), n('a', 'wait'), n('b', 'wait'), n('m', 'send_email')],
            edges: [e('trigger', 'c'), e('c', 'a', 'yes'), e('c', 'b', 'no'), e('a', 'm'), e('b', 'm')],
        };
        const pos = layoutGraph(graph);
        expect(pos.m?.y).toBeGreaterThan(pos.a?.y ?? Infinity);
        expect(pos.m?.y).toBeGreaterThan(pos.b?.y ?? Infinity);
        expect(hasOverlap(boxes(graph, pos))).toBe(false);
    });

    it('el «Si no se envía» es un desvío: el camino normal sigue por la columna del envío', () => {
        // Correo → (siguiente) espera; (si no se envía) WhatsApp → espera.
        const graph: Graph = {
            nodes: [n('trigger', 'trigger'), n('s', 'send_email'), n('w', 'wait'), n('f', 'send_whatsapp')],
            edges: [e('trigger', 's'), e('s', 'w'), e('s', 'f', 'failed'), e('f', 'w')],
        };
        const pos = layoutGraph(graph);
        expect(cx(pos, 'f')).toBe(cx(pos, 's'));
        expect(cx(pos, 'w')).toBe(cx(pos, 's'));
        expect(pos.w?.y).toBeGreaterThan(pos.f?.y ?? Infinity);
    });

    it('los pasos sueltos de una división se arriman aunque su hermano sea ancho más abajo', () => {
        const graph: Graph = {
            nodes: [
                n('trigger', 'trigger'),
                n('split', 'channel_split', { channels: ['whatsapp', 'email'] }),
                n('wa', 'exit'),
                n('em', 'condition'),
                n('none', 'exit'),
                n('c1', 'condition'),
                n('c2', 'condition'),
                n('l1', 'exit'),
                n('l2', 'exit'),
                n('l3', 'exit'),
                n('l4', 'exit'),
            ],
            edges: [
                e('trigger', 'split'),
                e('split', 'wa', 'whatsapp'),
                e('split', 'em', 'email'),
                e('split', 'none', 'none'),
                e('em', 'c1', 'yes'),
                e('em', 'c2', 'no'),
                e('c1', 'l1', 'yes'),
                e('c1', 'l2', 'no'),
                e('c2', 'l3', 'yes'),
                e('c2', 'l4', 'no'),
            ],
        };
        const pos = layoutGraph(graph);
        expect(cx(pos, 'wa')).toBe(cx(pos, 'em') - UNIT);
        expect(cx(pos, 'none')).toBe(cx(pos, 'em') + UNIT);
        expect(cx(pos, 'split')).toBe(cx(pos, 'em'));
        expect(hasOverlap(boxes(graph, pos))).toBe(false);
    });

    it('aguanta ciclos con espera y deja los pasos sueltos a la derecha', () => {
        const graph: Graph = {
            nodes: [n('trigger', 'trigger'), n('w', 'wait'), n('s', 'send_email'), n('loose', 'tag'), n('loose2', 'exit')],
            edges: [e('trigger', 'w'), e('w', 's'), e('s', 'w'), e('loose', 'loose2')],
        };
        const pos = layoutGraph(graph);
        expect(Object.keys(pos).sort()).toEqual(['loose', 'loose2', 's', 'trigger', 'w']);
        expect(pos.s?.y).toBeGreaterThan(pos.w?.y ?? Infinity);
        expect(pos.loose?.y).toBe(NODE_HEIGHT + GAP_Y);
        expect(pos.loose?.x).toBeGreaterThan(pos.w?.x ?? Infinity);
        expect(hasOverlap(boxes(graph, pos))).toBe(false);
    });

    it('usa el alto medido de cada nivel', () => {
        const graph: Graph = { nodes: [n('trigger', 'trigger'), n('a', 'wait'), n('b', 'wait')], edges: [e('trigger', 'a'), e('a', 'b')] };
        const pos = layoutGraph(graph, { trigger: { width: NODE_WIDTH, height: 150 }, a: { width: NODE_WIDTH, height: 60 } });
        expect(pos.a?.y).toBe(150 + GAP_Y);
        expect(pos.b?.y).toBe(150 + GAP_Y + 60 + GAP_Y);
    });

    it('el carrito abandonado de la biblioteca queda sin pasos encimados', () => {
        const graph: Graph = {
            nodes: [
                n('trigger', 'trigger'),
                n('w1', 'wait'),
                n('wa1', 'send_whatsapp'),
                n('w2', 'wait'),
                n('em1', 'send_email'),
                n('w3', 'wait'),
                n('cpn', 'coupon'),
                n('wa2', 'send_whatsapp'),
                n('em2', 'send_email'),
            ],
            edges: [
                e('trigger', 'w1'),
                e('w1', 'wa1'),
                e('wa1', 'w2'),
                e('wa1', 'w2', 'failed'),
                e('w2', 'em1'),
                e('em1', 'w3'),
                e('w3', 'cpn'),
                e('cpn', 'wa2'),
                e('wa2', 'em2', 'failed'),
            ],
        };
        const pos = layoutGraph(graph);
        expect(hasOverlap(boxes(graph, pos))).toBe(false);
        expect(new Set(['trigger', 'w1', 'wa1', 'w2', 'em1', 'w3', 'cpn', 'wa2', 'em2'].map((id) => cx(pos, id)))).toEqual(new Set([0]));
        expect(layoutGraph({ nodes: [], edges: [] })).toEqual({});
    });
});

describe('vista inicial y pasos encimados', () => {
    it('muestra todo si cabe y, si no, el comienzo con zoom legible', () => {
        const small = readableViewport([{ x: 0, y: 0, width: 200, height: 100 }], { width: 1000, height: 600 }, 0.9);
        expect(small).toEqual({ x: 400, y: 250, zoom: 1 });
        const tall = readableViewport(
            [
                { x: 0, y: 0, width: 200, height: 100 },
                { x: 0, y: 3000, width: 200, height: 100 },
            ],
            { width: 1000, height: 600 },
            0.9,
        );
        expect(tall?.zoom).toBe(0.9);
        expect(tall?.y).toBe(32);
        expect(readableViewport([], { width: 1000, height: 600 })).toBeNull();
    });

    it('detecta pasos que se tapan', () => {
        expect(
            hasOverlap([
                { x: 0, y: 0, width: 100, height: 100 },
                { x: 0, y: 150, width: 100, height: 100 },
            ]),
        ).toBe(false);
        expect(
            hasOverlap([
                { x: 0, y: 0, width: 100, height: 120 },
                { x: 50, y: 100, width: 100, height: 100 },
            ]),
        ).toBe(true);
    });
});

describe('deshacer y rehacer', () => {
    it('vuelve al estado anterior y adelante otra vez', () => {
        let h = emptyHistory<number>();
        h = record(h, 1, { now: 0 });
        h = record(h, 2, { now: 5000 });
        const back = undo(h, 3);
        expect(back?.state).toBe(2);
        const back2 = back ? undo(back.history, back.state) : null;
        expect(back2?.state).toBe(1);
        expect(back2 ? undo(back2.history, 1) : 'x').toBeNull();
        const fwd = back2 ? redo(back2.history, 1) : null;
        expect(fwd?.state).toBe(2);
        const fwd2 = fwd ? redo(fwd.history, 2) : null;
        expect(fwd2?.state).toBe(3);
        expect(fwd2 ? redo(fwd2.history, 3) : 'x').toBeNull();
    });

    it('junta los cambios seguidos de un mismo campo y un cambio nuevo borra lo rehecho', () => {
        let h = emptyHistory<string>();
        h = record(h, 'a', { key: 'config:n1', now: 0 });
        h = record(h, 'ab', { key: 'config:n1', now: 300 });
        h = record(h, 'abc', { key: 'config:n1', now: 600 });
        expect(h.past).toEqual(['a']);
        h = record(h, 'abcd', { key: 'config:n1', now: 5000 });
        expect(h.past).toEqual(['a', 'abcd']);
        h = record(h, 'x', { key: 'config:n2', now: 5100 });
        expect(h.past).toHaveLength(3);
        const back = undo(h, 'y');
        expect(back?.history.future).toEqual(['y']);
        const after = back ? record(back.history, 'z', { now: 9000 }) : h;
        expect(after.future).toEqual([]);
    });

    it('guarda como mucho el límite de pasos', () => {
        let h = emptyHistory<number>();
        for (let i = 0; i < 10; i++) {
            h = record(h, i, { now: i * 5000, limit: 3 });
        }
        expect(h.past).toEqual([7, 8, 9]);
    });
});

describe('resumen de cada paso', () => {
    it('dice qué hace el paso en palabras', () => {
        expect(describeNode(t, n('w', 'wait', { mode: 'duration', duration: { value: 1, unit: 'hours' } }), { options })).toEqual({
            title: 'Esperar',
            summary: 'Espera 1 hora',
            details: [],
            incomplete: false,
        });

        const send = describeNode(t, n('s', 'send_email', { template_id: 'tpl_1', kind: 'marketing' }), { options });
        expect(send.title).toBe('Enviar correo');
        expect(send.summary).toBe('Carrito 1');
        expect(send.details).toEqual(['Marketing: respeta horario y frecuencia']);
        expect(send.incomplete).toBe(false);

        const empty = describeNode(t, n('s', 'send_whatsapp', { template_id: null }), { options });
        expect(empty.summary).toBe('Elige una plantilla');
        expect(empty.incomplete).toBe(true);
    });

    it('describe condiciones, el disparador con su meta y el webhook', () => {
        expect(describeNode(t, n('c', 'condition', { mode: 'segment', definition: { op: 'and', conditions: [] } }), { options })).toMatchObject({
            summary: 'Elige la condición',
            incomplete: true,
        });
        const consent = describeNode(
            t,
            n('c', 'condition', { mode: 'segment', definition: { op: 'and', conditions: [{ type: 'consent', channel: 'whatsapp', purpose: 'marketing', status: 'granted' }] } }),
            { options },
        );
        expect(consent.summary).toBe('¿Acepta WhatsApp?');
        const context = describeNode(t, n('c', 'condition', { mode: 'context', path: 'last_event.props.button_payload', operator: 'eq', value: 'cod_confirm', exit_if_no: true }), {
            options,
        });
        expect(context.summary).toBe('¿El botón que respondió es «cod_confirm»?');
        expect(context.details).toEqual(['Si no se cumple, sale del flujo']);

        const trigger = describeNode(t, n('trigger', 'trigger'), { options, trigger: { type: 'event', event: 'cart.abandoned', filters: [] }, goal: { event: 'order.paid' } });
        expect(trigger.summary).toBe('Cuando pasa «Carrito abandonado»');
        expect(trigger.details).toEqual(['Sale si logra: Pedido pagado']);
        expect(describeNode(t, n('trigger', 'trigger'), { options, trigger: { type: 'event', event: null, filters: [] } }).incomplete).toBe(true);

        expect(describeNode(t, n('h', 'webhook', { url: 'https://crm.example.com/hooks/igr' }), { options }).summary).toBe('Avisa a crm.example.com');
        expect(describeNode(t, n('h', 'webhook', { url: '' }), { options }).incomplete).toBe(true);
        // Un tipo sin caso propio usa el resumen del catálogo.
        expect(describeNode(t, n('x', 'exit'), { options }).summary).toBe('Termina el flujo');
    });

    it('nombra las salidas según lo que se espera y solo etiqueta las ramas con nombre', () => {
        const paid = n('w', 'wait_event', { event: 'order.paid' });
        expect(branchText(t, paid, 'event')).toBe('Compró');
        expect(branchText(t, paid, 'timeout')).toBe('No compró');
        expect(branchText(t, n('w', 'wait_event', { event: 'message.opened' }), 'timeout')).toBe('No abrió');
        expect(branchText(t, n('w', 'wait_event', { event: 'cart.abandoned' }), 'event')).toBe('Lo hizo');

        expect(edgeLabel(t, n('c', 'condition'), 'yes')).toBe('Sí');
        expect(edgeLabel(t, n('s', 'send_email'), 'failed')).toBe('Si no se envía');
        expect(edgeLabel(t, n('s', 'send_email'), null)).toBeNull();
        expect(edgeLabel(t, n('w', 'wait'), null)).toBeNull();
    });
});

describe('agregar pasos', () => {
    const base: Graph = {
        nodes: [
            { ...n('trigger', 'trigger'), position: { x: 0, y: 0 } },
            { ...n('a', 'wait'), position: { x: 0, y: 200 } },
            { ...n('b', 'send_email'), position: { x: 0, y: 400 } },
        ],
        edges: [e('trigger', 'a'), e('a', 'b')],
    };

    it('en una conexión: queda en medio, se conecta a los dos lados y lo de abajo baja', () => {
        const canvas = toCanvas(base);
        const next = insertOnEdge(canvas, 'a__next', n('n3', 'condition'));
        expect(next.edges.map((x) => `${x.source}>${x.target}:${x.sourceHandle ?? ''}`).sort()).toEqual(['a>n3:next', 'n3>b:yes', 'trigger>a:next']);
        const added = next.nodes.find((x) => x.id === 'n3');
        expect(added?.position).toEqual({ x: 0, y: 400 });
        expect(added?.selected).toBe(true);
        expect(next.nodes.find((x) => x.id === 'b')?.position.y).toBe(400 + NODE_HEIGHT + GAP_Y);
        expect(next.nodes.find((x) => x.id === 'a')?.position.y).toBe(200);

        // Una «Salida» no sigue: el destino queda suelto.
        const exit = insertOnEdge(canvas, 'a__next', n('n3', 'exit'));
        expect(exit.edges.some((x) => x.source === 'n3')).toBe(false);
        expect(insertOnEdge(canvas, 'nope', n('n3', 'exit'))).toBe(canvas);
    });

    it('después de una salida libre: debajo, del lado de su rama, y corrido si el lugar está ocupado', () => {
        const canvas = toCanvas(base);
        const failed = appendAfter(canvas, 'b', 'failed', n('n3', 'send_whatsapp'));
        expect(failed.edges.at(-1)).toMatchObject({ source: 'b', target: 'n3', sourceHandle: 'failed' });
        expect(failed.nodes.at(-1)?.position).toEqual({ x: (NODE_WIDTH + GAP_X) / 2, y: 400 + NODE_HEIGHT + GAP_Y });

        // Debajo de «a» ya está «b»: se corre a la derecha.
        const taken = appendAfter(canvas, 'a', null, n('n3', 'tag'));
        expect(taken.nodes.at(-1)?.position.x).toBeGreaterThan(0);
        expect(taken.edges.filter((x) => x.source === 'a')).toHaveLength(1);
        expect(taken.edges.find((x) => x.source === 'a')?.target).toBe('n3');

        const loose = appendAfter(canvas, null, undefined, n('n3', 'tag'));
        expect(loose.edges).toEqual(canvas.edges);
        const dropped = addAt(canvas, n('n3', 'tag'), { x: 500, y: 500 });
        expect(dropped.nodes.at(-1)?.position).toEqual({ x: 500 - NODE_WIDTH / 2, y: 476 });
    });

    it('sabe qué salidas tienen conexión', () => {
        const canvas = toCanvas({ ...base, edges: [...base.edges, e('b', 'a', 'failed')] });
        expect(connectedHandles(canvas.edges)).toEqual({ trigger: ['next'], a: ['next'], b: ['failed'] });
    });
});

describe('lista de pasos (celular)', () => {
    const graph: Graph = {
        nodes: [
            n('trigger', 'trigger'),
            n('w', 'wait', { mode: 'duration', duration: { value: 2, unit: 'hours' } }),
            n('c', 'condition', { mode: 'segment', definition: { op: 'and', conditions: [{ type: 'consent', channel: 'email', purpose: 'marketing', status: 'granted' }] } }),
            n('s', 'send_email', { template_id: 'tpl_1' }),
            n('x', 'exit'),
            n('loose', 'tag', { add: ['vip'], remove: [] }),
        ],
        edges: [e('trigger', 'w'), e('w', 'c'), e('c', 's', 'yes'), e('s', 'x'), e('s', 'x', 'failed')],
    };

    it('numera la cadena de corrido y abre una sublista por rama', () => {
        const { items, orphans } = outline(graph);
        expect(items.map((i) => (i.kind === 'step' ? i.step.number : -1))).toEqual([1, 2, 3]);
        const c = items[2];
        expect(c?.kind === 'step' ? c.step.branches.map((b) => [b.branch, b.items.length]) : null).toEqual([
            ['yes', 1],
            ['no', 0],
        ]);
        // «s» va a «x» por las dos salidas: la segunda vez es «sigue en el paso».
        const s = c?.kind === 'step' ? c.step.branches[0]?.items[0] : undefined;
        expect(s?.kind === 'step' ? s.step.branches.map((b) => b.items[0]?.kind) : null).toEqual(['step', 'goto']);
        expect(orphans.map((i) => (i.kind === 'step' ? i.step.id : i.id))).toEqual(['loose']);
    });

    it('muestra los pasos en palabras, con sus ramas y los pasos sueltos', () => {
        render(
            <FlowOutlineList
                graph={graph}
                options={options}
                trigger={{ type: 'event', event: 'cart.abandoned', filters: [] }}
                goal={{ event: 'order.paid' }}
                issues={{ s: ['Falta algo'] }}
            />,
        );
        expect(screen.getByText('Cuando pasa «Carrito abandonado»')).toBeInTheDocument();
        expect(screen.getByText('Espera 2 horas')).toBeInTheDocument();
        expect(screen.getByText('¿Acepta correo?')).toBeInTheDocument();
        const yes = screen.getByRole('region', { name: 'Sí' });
        expect(within(yes).getByText('Carrito 1')).toBeInTheDocument();
        expect(within(screen.getByRole('region', { name: 'No' })).getByText('Termina el flujo.')).toBeInTheDocument();
        expect(screen.getByText('Falta algo')).toBeInTheDocument();
        expect(screen.getByText('Sigue en el paso 5')).toBeInTheDocument();
        expect(screen.getByText('Pasos sin conectar')).toBeInTheDocument();
    });
});
