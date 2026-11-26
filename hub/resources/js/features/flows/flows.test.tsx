import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, describe, expect, it } from 'vitest';
import i18n from '@/lib/i18n';
import texts from '@/locales/es-CO/flows.json';
import { type FlowSettings, SettingsPanel } from './builder/SettingsPanel';
import { branchesOf, branchLabel, metaOf, NODE_META, PALETTE, summarize, triggerText } from './catalog';
import { businessKind, servesAudience } from './business';
import { goalWord } from './FlowsPage';
import { edgeId, type FlowCanvasNode, freeBranch, fromCanvas, nextNodeId, pruneEdges, toCanvas } from './graph';
import { sortLibrary } from './LibraryGallery';
import type { FlowOptions, Graph, GraphNode, LibraryFlow } from './types';

type Tree = { [key: string]: string | Tree };

function has(path: string): boolean {
    let node: string | Tree = texts;
    for (const part of path.split('.')) {
        if (typeof node !== 'object' || !(part in node)) {
            return false;
        }
        node = node[part] as string | Tree;
    }
    return typeof node === 'string';
}

const t = i18n.t.bind(i18n);

const options: FlowOptions = {
    nodes: [],
    events: ['cart.abandoned', 'order.paid', 'order.created'],
    templates: [{ id: 'tpl_1', name: 'Carrito 1 (WhatsApp)', channel: 'whatsapp', category: 'marketing', library_key: 'wa_abandoned_cart_1', status: 'approved' }],
    segments: [{ id: 'sg_1', name: 'En riesgo', is_system: true }],
    flows: [
        { id: 'fl_1', name: 'Este flujo' },
        { id: 'fl_2', name: 'Bienvenida' },
    ],
    date_attributes: ['birthday', 'expected_next_order_at'],
    threshold_metrics: ['revenue_total_ws', 'orders_count'],
};

const graph: Graph = {
    nodes: [
        { id: 'trigger', type: 'trigger', config: {}, position: { x: 0, y: 0 } },
        { id: 'n1', type: 'wait', config: { mode: 'duration', duration: { value: 1, unit: 'hours' } }, position: { x: 0, y: 150 } },
        { id: 'n2', type: 'send_whatsapp', config: { template_id: 'tpl_1', kind: 'marketing' }, position: { x: 0, y: 300 } },
        { id: 'n3', type: 'send_email', config: { template_key: 'email_abandoned_cart_1', kind: 'marketing' }, position: { x: 300, y: 450 } },
        { id: 'n4', type: 'exit', config: {}, position: { x: 0, y: 450 } },
    ],
    edges: [
        { from: 'trigger', to: 'n1' },
        { from: 'n1', to: 'n2' },
        { from: 'n2', to: 'n4' },
        { from: 'n2', to: 'n3', branch: 'failed' },
    ],
};

describe('grafo ↔ lienzo', () => {
    it('convierte ida y vuelta sin perder ramas ni posiciones', () => {
        const canvas = toCanvas(graph);
        expect(canvas.nodes).toHaveLength(5);
        expect(canvas.nodes[0]?.deletable).toBe(false);
        expect(canvas.edges.map((e) => e.id)).toEqual(['trigger__next', 'n1__next', 'n2__next', 'n2__failed']);
        expect(canvas.edges[3]?.sourceHandle).toBe('failed');

        const back = fromCanvas(canvas.nodes, canvas.edges);
        expect(back.edges).toEqual(graph.edges);
        expect(back.nodes.map((n) => n.position)).toEqual(graph.nodes.map((n) => n.position));
    });

    it('descarta aristas hacia nodos que ya no existen', () => {
        const canvas = toCanvas(graph);
        const nodes = canvas.nodes.filter((n) => n.id !== 'n3');
        expect(fromCanvas(nodes, canvas.edges).edges).not.toContainEqual({ from: 'n2', to: 'n3', branch: 'failed' });
    });

    it('da IDs libres y la primera salida libre de un nodo', () => {
        const canvas = toCanvas(graph);
        expect(nextNodeId(canvas.nodes)).toBe('n5');
        expect(nextNodeId([{ id: 'trigger' }, { id: 'n1' }])).toBe('n2');

        const send = graph.nodes[2] as GraphNode;
        expect(freeBranch(send, canvas.edges)).toBeUndefined();
        expect(freeBranch(send, canvas.edges.filter((e) => e.id !== edgeId('n2', 'failed')))).toBe('failed');
        expect(freeBranch(graph.nodes[4] as GraphNode, canvas.edges)).toBeUndefined();
    });

    it('quita las aristas de una variante A/B eliminada', () => {
        const split: FlowCanvasNode = { id: 'ab', type: 'flow', position: { x: 0, y: 0 }, data: { node: { id: 'ab', type: 'ab_split', config: { variants: [{ key: 'a', weight: 100 }] } } } };
        const edges = [
            { id: edgeId('ab', 'a'), source: 'ab', target: 'x', sourceHandle: 'a' },
            { id: edgeId('ab', 'b'), source: 'ab', target: 'y', sourceHandle: 'b' },
        ];
        expect(pruneEdges([split], edges).map((e) => e.id)).toEqual(['ab__a']);
    });
});

describe('catálogo de nodos', () => {
    it('cada tipo tiene nombre y la paleta solo usa tipos conocidos', () => {
        for (const type of Object.keys(NODE_META)) {
            expect(has(`flows.nodes.${type}`), type).toBe(true);
        }
        for (const group of PALETTE) {
            expect(has(`flows.categories.${group.category}`)).toBe(true);
            for (const type of group.types) {
                expect(NODE_META[type], type).toBeDefined();
            }
        }
    });

    it('calcula las ramas fijas y dinámicas', () => {
        expect(branchesOf({ type: 'condition', config: {} })).toEqual(['yes', 'no']);
        expect(branchesOf({ type: 'wait_event', config: {} })).toEqual(['event', 'timeout']);
        expect(branchesOf({ type: 'ab_split', config: { variants: [{ key: 'a' }, { key: 'b' }, { key: 'c' }] } })).toEqual(['a', 'b', 'c']);
        expect(branchesOf({ type: 'channel_split', config: { channels: ['whatsapp', 'email'] } })).toEqual(['whatsapp', 'email', 'none']);
        expect(branchesOf({ type: 'exit', config: {} })).toEqual([]);
        expect(metaOf('desconocido').category).toBe('action');
    });

    it('todas las ramas tienen etiqueta traducida', () => {
        for (const [type, meta] of Object.entries(NODE_META)) {
            const branches = meta.branches === 'dynamic' ? branchesOf({ type, config: meta.defaults() }) : meta.branches;
            for (const branch of branches) {
                const label = branchLabel(t, type, branch);
                expect(label, `${type}:${String(branch)}`).not.toMatch(/^flows\./);
            }
        }
    });

    it('resume cada nodo en una línea legible', () => {
        expect(summarize(t, graph.nodes[1] as GraphNode, options)).toBe('Espera 1 hora');
        expect(summarize(t, { id: 'w', type: 'wait', config: { mode: 'duration', duration: { value: 3, unit: 'days' } } }, options)).toBe('Espera 3 días');
        expect(summarize(t, { id: 'w', type: 'wait', config: { mode: 'until_time', hour: 9, minute: 0, weekdays: [] } }, options)).toBe('Hasta las 09:00 (cualquier día)');
        expect(summarize(t, graph.nodes[2] as GraphNode, options)).toBe('Carrito 1 (WhatsApp)');
        expect(summarize(t, { id: 's', type: 'send_email', config: { template_id: null, kind: 'marketing' } }, options)).toBe('Elige una plantilla');
        expect(summarize(t, { id: 'c', type: 'coupon', config: { kind: 'percent', amount: '10', expires_in_days: 2 } }, options)).toBe('10% · vence en 2 días');
        expect(summarize(t, { id: 'g', type: 'tag', config: { add: ['vip'], remove: [] } }, options)).toBe('+ vip');
        expect(summarize(t, { id: 'p', type: 'give_points', config: { points: 250 } }, options)).toBe('+250 puntos');
        expect(summarize(t, { id: 'p', type: 'give_wallet', config: {} }, options)).toBe('Indica la cantidad');
        expect(summarize(t, { id: 'p', type: 'assign_agent', config: {} }, options)).toBe('Por turnos (bandeja y leads)');
        expect(summarize(t, { id: 'p', type: 'assign_agent', config: { mode: 'user', user_id: 'usr_1' } }, { ...options, team: [{ id: 'usr_1', name: 'Laura' }] })).toBe('A Laura');
        expect(summarize(t, { id: 's', type: 'lead_stage', config: { stage: 'proposal' } }, { ...options, lead_stages: [{ key: 'proposal', label: 'Propuesta', kind: 'open', pipeline: 'Ventas' }] })).toBe('A «Propuesta»');
        expect(summarize(t, { id: 'k', type: 'crm_task', config: { title: 'Llamar', kind: 'call', due_in: { value: 2, unit: 'days' } } }, options)).toBe('Llamada: Llamar · vence en 2 días');
        expect(summarize(t, { id: 'f', type: 'lead_followup', config: { channels: ['whatsapp', 'email'], step: 2 } }, options)).toBe('Toque 2 · WhatsApp → Correo');
        expect(branchLabel(t, 'lead_stage', 'failed')).toBe('Si no se pudo');
        for (const [type, meta] of Object.entries(NODE_META)) {
            expect(summarize(t, { id: 'x', type, config: meta.defaults() }, options), type).not.toMatch(/flows\./);
        }
    });

    it('describe el disparador', () => {
        expect(triggerText(t, { type: 'event', event: null, filters: [] }, options)).not.toMatch(/flows\./);
        expect(triggerText(t, { type: 'segment', segment_id: 'sg_1', on: 'entered' }, options)).toContain('En riesgo');
        expect(triggerText(t, { type: 'date', attribute: 'birthday', offset_days: 0, hour: 10 }, options)).toContain('10');
        expect(triggerText(t, { type: 'manual' }, options)).not.toMatch(/flows\./);
    });
});

function Harness({ initial }: { initial: FlowSettings }) {
    const [value, setValue] = useState(initial);
    return (
        <>
            <SettingsPanel flowId="fl_1" value={value} options={options} readOnly={false} errors={{ 'trigger.event': 'Elige el evento que inicia el flujo.' }} webhookUrl={null} onChange={setValue} />
            <output data-testid="state">{JSON.stringify(value)}</output>
        </>
    );
}

describe('ajustes del flujo', () => {
    afterEach(cleanup);
    const initial: FlowSettings = {
        name: 'Carrito abandonado',
        description: '',
        trigger: { type: 'event', event: null, filters: [] },
        goal: null,
        reentry_policy: { mode: 'once' },
        exclusions: [],
        holdout_pct: 0,
    };
    const state = () => JSON.parse(screen.getByTestId('state').textContent) as FlowSettings;

    it('muestra los errores del servidor y cambia el disparador con valores por defecto', () => {
        render(<Harness initial={initial} />);
        expect(screen.getByText('Elige el evento que inicia el flujo.')).toBeInTheDocument();

        fireEvent.change(screen.getByLabelText(t('flows.settings.triggerType')), { target: { value: 'date' } });
        expect(state().trigger).toEqual({ type: 'date', attribute: 'birthday', offset_days: 0, hour: 10 });

        fireEvent.change(screen.getByLabelText(t('flows.settings.reentry')), { target: { value: 'every_days' } });
        expect(state().reentry_policy).toEqual({ mode: 'every_days', days: 30 });
    });

    it('no ofrece excluir el propio flujo', () => {
        render(<Harness initial={initial} />);
        expect(screen.queryByLabelText('Este flujo')).toBeNull();
        fireEvent.click(screen.getByLabelText('Bienvenida'));
        expect(state().exclusions).toEqual(['fl_2']);
    });
});

describe('galería de la biblioteca', () => {
    const flow = (key: string, number: number, audience: LibraryFlow['audience'], rank: number | null = null): LibraryFlow => ({
        key, number, name: key, description: '', phase: 2, available: true, module: null, trigger: null, templates: [], missing_templates: [], created_flow_id: null, steps: 3,
        goal_event: null, goal: 'sell_again', audience, verticals: null, rank, strategy: null, channels: ['email'], messages: 1,
    });

    it('pone primero las recomendadas para la vertical y, en servicios, las de servicios antes que las de tienda', () => {
        const list = [flow('abandoned_cart', 2, 'store'), flow('periodic_service', 26, 'both'), flow('lead_nurture', 21, 'services'), flow('appointment_confirmation', 23, 'services', 1)];
        expect(sortLibrary(list, 'services').map((f) => f.key)).toEqual(['appointment_confirmation', 'lead_nurture', 'periodic_service', 'abandoned_cart']);
        expect(sortLibrary(list, 'store').map((f) => f.key)).toEqual(['appointment_confirmation', 'abandoned_cart', 'periodic_service', 'lead_nurture']);
        expect(servesAudience('services', 'store')).toBe(false);
        expect(servesAudience('both', 'store')).toBe(true);
    });

    it('sabe si el negocio es tienda, servicios o los dos (asistente, sitios o nada)', () => {
        expect(businessKind({ business_type: 'services' })).toBe('services');
        expect(businessKind({ business_type: null, capabilities: { store: true, services: false } })).toBe('store');
        expect(businessKind({ business_type: null, capabilities: { store: null, services: null } })).toBe('both');
    });

    it('dice lo que se logró con la palabra de la meta', () => {
        expect(goalWord('order.paid')).toBe('order_paid');
        expect(goalWord('appointment.booked')).toBe('appointment_booked');
        expect(goalWord('lead.stage_changed')).toBe('lead_stage_changed');
        expect(goalWord('nps.answered')).toBe('default');
        expect(goalWord(null)).toBe('default');
        expect(t('flows.list.goalDone.appointment_booked', { count: 3 })).toBe('3 agendaron');
        expect(has('flows.library.goals.convert_leads')).toBe(true);
    });
});
