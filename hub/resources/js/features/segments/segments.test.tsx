import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, describe, expect, it } from 'vitest';
import i18n from '@/lib/i18n';
import texts from '@/locales/es-CO/segments.json';
import { appendTo, countConditions, defaultCondition, emptyGroup, getAt, isComplete, prune, removeAt, updateAt } from './ast';
import { BuilderContext, errorsFor, pathKey } from './builder/context';
import { conditionTypesFor, GroupEditor } from './builder/GroupEditor';
import { extractContacts } from './dialogs';
import { summarize } from './humanize';
import { explain, familyOf, trendTone } from './look';
import { quickStarts } from './SegmentBuilderPage';
import { groupByFolder, groupsByFamily } from './SegmentsPage';
import type { ConditionType, Group, SegmentOptions, SegmentSummary } from './types';

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

const options: SegmentOptions = {
    attributes: [
        { key: 'city', type: 'string', operators: ['eq', 'neq', 'in', 'not_in', 'contains', 'starts_with', 'is_set', 'is_not_set'], values: null },
        { key: 'lifecycle_stage', type: 'enum', operators: ['eq', 'neq', 'in', 'not_in', 'is_set', 'is_not_set'], values: ['lead', 'customer', 'vip'] },
    ],
    purchase_fields: [{ key: 'orders_count', type: 'number', operators: ['eq', 'gte', 'between'], values: null }],
    predictions: [{ key: 'rfm_segment', type: 'enum', operators: ['eq', 'in'], values: ['champions', 'lost'] }],
    custom_attributes: [],
    events: ['product.viewed', 'checkout.started'],
    event_columns: ['product_id'],
    rfm_segments: [
        { key: 'champions', name: 'Campeones' },
        { key: 'lost', name: 'Perdidos' },
    ],
    values: { city: ['Manizales', 'Pereira'], region_code: [], country: ['CO'], payment_method: [], brand: [], hdyhau: [] },
    segments: [{ id: 'sg_1', name: 'Clientes VIP' }],
    message_actions: ['received', 'opened', 'read', 'clicked', 'replied'],
    message_channels: ['any', 'email', 'whatsapp', 'sms'],
    engagement_days: 90,
    flows: [{ id: 'fl_1', name: 'Carrito abandonado' }],
    campaigns: [{ id: 'cmp_1', name: 'Black Friday' }],
    lead_fields: [{ key: 'has_open_lead', type: 'bool', operators: ['is_true', 'is_false'], values: null }],
    service_fields: [{ key: 'has_upcoming', type: 'bool', operators: ['is_true', 'is_false'], values: null }],
    loyalty_fields: [
        { key: 'tier', type: 'tier', operators: ['in', 'not_in', 'at_least', 'is_set', 'is_not_set'], values: null },
        { key: 'points_balance', type: 'number', operators: ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'between'], values: null },
        { key: 'points_expiring', type: 'expiring', operators: ['within_next_days'], values: null },
        { key: 'was_referred', type: 'bool', operators: ['is_true', 'is_false'], values: null },
    ],
    loyalty_tiers: [
        { id: 'tr_1', name: 'Plata', rank: 1 },
        { id: 'tr_2', name: 'Oro', rank: 2 },
    ],
};

describe('AST del constructor', () => {
    const base: Group = { op: 'and', conditions: [defaultCondition('attribute'), { op: 'or', conditions: [defaultCondition('cart')] }] };

    it('edita, agrega y quita nodos por ruta sin mutar el original', () => {
        const added = appendTo(base, [1], defaultCondition('consent'));
        expect(countConditions(added)).toBe(3);
        expect(countConditions(base)).toBe(2);
        const changed = updateAt(added, [0], () => ({ type: 'attribute', field: 'city', operator: 'in', value: ['Manizales'] }));
        expect(getAt(changed, [0])).toEqual({ type: 'attribute', field: 'city', operator: 'in', value: ['Manizales'] });
        expect(countConditions(removeAt(changed, [1, 0]))).toBe(2);
    });

    it('la vista previa ignora condiciones incompletas y grupos vacíos', () => {
        const draft: Group = { op: 'and', conditions: [defaultCondition('attribute'), emptyGroup('or'), defaultCondition('cart'), defaultCondition('segment')] };
        const pruned = prune(draft);
        expect(pruned.conditions).toEqual([defaultCondition('cart')]);
        expect(isComplete({ type: 'purchase', scope: 'category', ids: [], operator: 'ever' })).toBe(false);
        expect(isComplete({ type: 'purchase', scope: 'coupon', ids: [], operator: 'ever' })).toBe(true);
        expect(isComplete({ type: 'attribute', field: 'birthday', operator: 'this_month' })).toBe(true);
    });

    it('ubica los errores del servidor en su nodo y no en el padre', () => {
        const errors = { 'definition.conditions.1.conditions.0.value': 'Elige al menos un valor.', 'definition.conditions.1.conditions': 'El grupo está vacío.' };
        expect(pathKey([1, 0])).toBe('definition.conditions.1.conditions.0');
        expect(errorsFor(errors, [1, 0])).toEqual(['Elige al menos un valor.']);
        expect(errorsFor(errors, [1])).toEqual(['El grupo está vacío.']);
        expect(errorsFor(errors, [])).toEqual([]);
    });
});

describe('textos', () => {
    it('tiene texto para cada tipo, operador, ventana, conteo, campo y canal', () => {
        const missing: string[] = [];
        const need = (key: string) => {
            if (!has(key) && !has(`${key}_one`)) missing.push(key);
        };
        const types: ConditionType[] = ['attribute', 'purchase', 'event', 'cart', 'prediction', 'consent', 'segment', 'messaging', 'loyalty'];
        types.forEach((type) => {
            need(`segments.types.${type}`);
            need(`segments.typeHelp.${type}`);
        });
        ['messaging', 'loyalty', 'lead'].forEach((type) => need(`segments.types.${type}`));
        const ops = ['eq', 'neq', 'in', 'not_in', 'contains', 'starts_with', 'is_set', 'is_not_set', 'gt', 'gte', 'lt', 'lte', 'between', 'before', 'after', 'within_last_days', 'more_than_days_ago', 'within_next_days', 'this_month', 'is_true', 'is_false'];
        ops.forEach((op) => {
            need(`segments.operators.${op}`);
            need(`segments.op.${op}`);
        });
        ['contains_any', 'contains_all', 'not_contains', 'is_set', 'is_not_set'].forEach((op) => need(`segments.operators.tags_${op}`));
        ['contains_any', 'contains_all', 'not_contains'].forEach((op) => need(`segments.op.${op}`));
        ['ever', 'last_days', 'this_month', 'between', 'last_days_n', 'between_dates'].forEach((w) => need(`segments.window.${w}`));
        ['ever', 'never', 'count_gte', 'count_lte', 'count_eq'].forEach((c) => {
            need(`segments.countOps.${c}`);
            need(`segments.count.${c}`);
        });
        ['times', 'sessions', 'days'].forEach((u) => need(`segments.units.${u}`));
        ['any', 'product', 'category', 'brand', 'coupon', 'payment_method'].forEach((s) => {
            need(`segments.scope.${s}`);
            if (s !== 'any') need(`segments.scope.${s}_items`);
        });
        const fields = ['city', 'region_code', 'country', 'locale', 'lifecycle_stage', 'hdyhau', 'tags', 'birthday', 'first_seen_at', 'last_seen_at', 'orders_count', 'total_spent', 'aov', 'refunds_count', 'coupon_ratio', 'avg_days_between_orders', 'first_order_at', 'last_order_at', 'rfm_segment', 'rfm_r', 'rfm_f', 'rfm_m', 'churn_band', 'churn_risk', 'predicted_clv_12m', 'expected_next_order_at', 'engagement_score', 'price_band', 'preferred_channel'];
        fields.forEach((f) => need(`segments.fields.${f}`));
        ['email', 'whatsapp', 'sms'].forEach((c) => need(`segments.channels.${c}`));
        ['received', 'opened', 'read', 'clicked', 'replied', 'engagement'].forEach((a) => need(`segments.msgAction.${a}`));
        ['received', 'opened', 'read', 'clicked', 'replied'].forEach((a) => need(`segments.describe.msg_${a}`));
        ['any', 'email', 'whatsapp', 'sms'].forEach((c) => need(`segments.msgChannel.${c}`));
        ['in', 'not_in', 'at_least', 'is_set', 'is_not_set'].forEach((op) => {
            need(`segments.tierOps.${op}`);
            need(`segments.describe.tier_${op}`);
        });
        ['engagement', 'tier', 'points_balance', 'wallet_balance', 'points_expiring', 'referrals_made', 'was_referred'].forEach((f) => need(`segments.fields.${f}`));
        ['visitor', 'subscriber', 'lead', 'customer', 'repeat', 'vip', 'at_risk', 'lost'].forEach((s) => need(`segments.enum.lifecycle_stage.${s}`));
        ['product.viewed', 'checkout.started', 'cart.item_added', 'order.paid', 'search.performed', 'page.viewed'].forEach((e) => need(`segments.events.${e.replace('.', '_')}`));
        expect(missing).toEqual([]);
    });

    it('describe un segmento en español', () => {
        void i18n.changeLanguage('es-CO');
        const definition: Group = {
            op: 'and',
            conditions: [
                { type: 'attribute', field: 'city', operator: 'in', value: ['Manizales', 'Pereira'] },
                { type: 'purchase', scope: 'category', ids: ['12'], operator: 'count_gte', value: 2, window: { kind: 'last_days', days: 365 } },
                {
                    op: 'or',
                    negate: true,
                    conditions: [
                        { type: 'prediction', field: 'rfm_segment', operator: 'eq', value: 'lost' },
                        { type: 'segment', segment_id: 'sg_1', operator: 'in' },
                    ],
                },
            ],
        };
        expect(summarize(t, definition, { lost: 'Perdidos', sg_1: 'Clientes VIP', '12': 'Pinturas' })).toBe(
            'Ciudad es Manizales, Pereira y Compró la categoría Pinturas al menos 2 veces · en los últimos 365 días y (Excepto (Grupo según sus compras es Perdidos o Está en «Clientes VIP»))',
        );
        expect(summarize(t, null)).toBe('Lista fija de personas (no cambia sola).');
    });

    it('describe mensajería y fidelización', () => {
        void i18n.changeLanguage('es-CO');
        const definition: Group = {
            op: 'and',
            conditions: [
                { type: 'messaging', action: 'clicked', channel: 'whatsapp', kind: 'marketing', flow_id: 'fl_1', operator: 'count_gte', value: 2, window: { kind: 'last_days', days: 30 } },
                { type: 'messaging', action: 'engagement', operator: 'gte', value: 10 },
                { type: 'loyalty', field: 'tier', operator: 'at_least', value: 'tr_2' },
                { type: 'loyalty', field: 'points_expiring', operator: 'within_next_days', value: 15 },
                { type: 'loyalty', field: 'was_referred', operator: 'is_true' },
            ],
        };
        expect(summarize(t, definition, { fl_1: 'Carrito abandonado', tr_2: 'Oro' })).toBe(
            'Hizo clic en mensajes de marketing por WhatsApp del flujo «Carrito abandonado» al menos 2 veces · en los últimos 30 días y Interacción con mensajes de al menos 10 y Nivel VIP es al menos Oro y Puntos por vencer en los próximos 15 días y Llegó referido por alguien',
        );
    });
});

describe('listas y carpetas', () => {
    it('saca correos y teléfonos de un CSV sin repetir', () => {
        const csv = 'nombre,correo,telefono\nAna,ANA@ejemplo.com,+57 300 111 2233\n"Luis","luis@ejemplo.com",3001112233\nAna,ana@ejemplo.com,123';
        expect(extractContacts(csv)).toEqual(['ana@ejemplo.com', '+57 300 111 2233', 'luis@ejemplo.com', '3001112233']);
    });

    it('agrupa por carpeta con las del sistema primero y «sin carpeta» al final', () => {
        const mk = (name: string, folder: string | null, isSystem = false): SegmentSummary => ({
            id: name, name, description: null, folder, kind: 'dynamic', is_system: isSystem, system_key: null, member_count: 0, revenue_ws: '0', channel_counts: {}, last_computed_at: null, updated_at: null,
        });
        const groups = groupByFolder([mk('B', null), mk('Mía', 'Campañas'), mk('Campeones', 'RFM', true), mk('A', null)]);
        expect(groups.map((g) => g.folder)).toEqual(['RFM', 'Campañas', null]);
        expect(groups[2]?.items.map((s) => s.name)).toEqual(['A', 'B']);
    });
});

describe('constructor visual', () => {
    afterEach(cleanup);

    function Harness({ initial }: { initial: Group }) {
        const [root, setRoot] = useState(initial);
        return (
            <BuilderContext.Provider value={{ ws: 'ws_1', options, readOnly: false, errors: { 'definition.conditions.0.value': 'Elige al menos un valor.' }, currency: 'COP', locale: 'es-CO' }}>
                <GroupEditor group={root} path={[]} root={root} setRoot={setRoot} />
                <output data-testid="ast">{JSON.stringify(root)}</output>
            </BuilderContext.Provider>
        );
    }

    it('cambia «todas» por «cualquiera», quita condiciones y muestra el error en su lugar', () => {
        void i18n.changeLanguage('es-CO');
        render(<Harness initial={{ op: 'and', conditions: [{ type: 'attribute', field: 'city', operator: 'in', value: [] }, defaultCondition('cart')] }} />);
        expect(screen.getByText('Elige al menos un valor.')).toBeInTheDocument();
        expect(screen.getByText('Abandonó un carrito')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('radio', { name: 'cualquiera' }));
        expect(JSON.parse(screen.getByTestId('ast').textContent)).toMatchObject({ op: 'or' });

        const input = screen.getByRole('combobox', { name: 'Valor' });
        fireEvent.change(input, { target: { value: 'Manizales' } });
        expect(screen.getByText('Manizales')).toBeInTheDocument();

        fireEvent.click(screen.getAllByRole('button', { name: 'Quitar condición' })[1] as HTMLElement);
        expect((JSON.parse(screen.getByTestId('ast').textContent) as Group).conditions).toEqual([{ type: 'attribute', field: 'city', operator: 'in', value: ['Manizales'] }]);
    });

    it('edita mensajería: cambiar a «abrió» deja solo el correo y el puntaje cambia la forma', () => {
        void i18n.changeLanguage('es-CO');
        render(<Harness initial={{ op: 'and', conditions: [defaultCondition('messaging')] }} />);
        const ast = () => (JSON.parse(screen.getByTestId('ast').textContent) as Group).conditions[0];

        fireEvent.change(screen.getByRole('combobox', { name: 'Qué hizo con los mensajes' }), { target: { value: 'opened' } });
        expect(ast()).toMatchObject({ type: 'messaging', action: 'opened', channel: 'email' });
        expect(screen.getByRole('combobox', { name: 'Canal' }).querySelectorAll('option')).toHaveLength(1);

        fireEvent.change(screen.getByRole('combobox', { name: 'De qué flujo o campaña' }), { target: { value: 'campaign:cmp_1' } });
        expect(ast()).toMatchObject({ campaign_id: 'cmp_1' });
        expect(ast()).not.toHaveProperty('flow_id');

        fireEvent.change(screen.getByRole('combobox', { name: 'Qué hizo con los mensajes' }), { target: { value: 'engagement' } });
        expect(ast()).toEqual({ type: 'messaging', action: 'engagement', operator: 'gte', value: 5 });
        expect(screen.getByText(/últimos 90 días/)).toBeInTheDocument();
    });

    it('edita fidelización: nivel con «al menos» y los niveles del programa', () => {
        void i18n.changeLanguage('es-CO');
        render(<Harness initial={{ op: 'and', conditions: [defaultCondition('loyalty')] }} />);
        const ast = () => (JSON.parse(screen.getByTestId('ast').textContent) as Group).conditions[0];
        expect(ast()).toEqual({ type: 'loyalty', field: 'points_balance', operator: 'gte', value: 500 });

        fireEvent.change(screen.getByRole('combobox', { name: 'Campo' }), { target: { value: 'tier' } });
        fireEvent.change(screen.getByRole('combobox', { name: 'Operador' }), { target: { value: 'at_least' } });
        fireEvent.change(screen.getByRole('combobox', { name: 'Nivel VIP' }), { target: { value: 'tr_2' } });
        expect(ast()).toEqual({ type: 'loyalty', field: 'tier', operator: 'at_least', value: 'tr_2' });
        expect(isComplete(ast() as never)).toBe(true);
    });
});

describe('grupos de clientes según el negocio', () => {
    const seg = (over: Partial<SegmentSummary>): SegmentSummary => ({
        id: 'sg_x',
        name: 'X',
        description: null,
        folder: null,
        kind: 'dynamic',
        is_system: false,
        system_key: null,
        audience: null,
        member_count: 0,
        revenue_ws: '0',
        channel_counts: {},
        last_computed_at: null,
        updated_at: null,
        ...over,
    });
    const list = [
        seg({ id: 'a', name: 'Mis VIP' }),
        seg({ id: 'b', name: 'Leales', is_system: true, system_key: 'rfm_loyal', audience: 'store' }),
        seg({ id: 'c', name: 'Campeones', is_system: true, system_key: 'rfm_champions', audience: 'store' }),
        seg({ id: 'd', name: 'Con una cita próxima', is_system: true, system_key: 'appointment_upcoming', audience: 'services' }),
        seg({ id: 'e', name: 'Nunca usan cupón', is_system: true, system_key: 'never_coupon', audience: 'store', folder: 'Descuentos' }),
    ];

    it('sin tienda no muestra los de compras; en una tienda, no los de servicios; los RFM van en su orden', () => {
        const ids = (store: boolean, services: boolean) => groupsByFamily(list, store, services, '').map((f) => [f.family, f.items.map((s) => s.id)]);
        expect(ids(false, true)).toEqual([
            ['custom', ['a']],
            ['services', ['d']],
            ['rfm', []],
            ['library', []],
        ]);
        expect(ids(true, false)).toEqual([
            ['custom', ['a']],
            ['services', []],
            ['rfm', ['c', 'b']],
            ['library', ['e']],
        ]);
        expect(groupsByFamily(list, true, true, 'cita').flatMap((f) => f.items.map((s) => s.id))).toEqual(['d']);
    });

    it('explica los RFM en palabras con qué hacer, y los demás con su descripción', () => {
        void i18n.changeLanguage('es-CO');
        const rfm = explain(t, list[2] as SegmentSummary);
        expect(rfm.what).toMatch(/Compran seguido/);
        expect(rfm.todo).toMatch(/reseñas/);
        expect(explain(t, seg({ description: 'Hola' }))).toEqual({ what: 'Hola', todo: null });
        expect(familyOf(list[3] as SegmentSummary)).toBe('services');
        expect(trendTone(-3)).toBe('down');
        expect(trendTone(null)).toBeNull();
    });

    it('ofrece las condiciones y los atajos del negocio', () => {
        expect(conditionTypesFor(false, true)).not.toContain('purchase');
        expect(conditionTypesFor(false, true)).toContain('service');
        expect(conditionTypesFor(true, false)).not.toContain('lead');
        expect(conditionTypesFor(true, true)).toHaveLength(11);
        expect(quickStarts(false, true).map((q) => q.key)).toEqual(['leadHot', 'leadOpen', 'upcoming', 'city', 'whatsapp']);
        expect(quickStarts(true, false).map((q) => q.key)).toEqual(['city', 'bought', 'viewed', 'cart', 'rfm']);
        for (const q of [...quickStarts(false, true), ...quickStarts(true, true)]) {
            expect(has(`segments.form.quick.${q.key}`), q.key).toBe(true);
        }
    });
});
