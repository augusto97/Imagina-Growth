import type { TFunction } from 'i18next';
import { describe as describeSegment } from '@/features/segments/humanize';
import type { Group } from '@/features/segments/types';
import { branchLabel, eventLabel, summarize } from '../catalog';
import type { FlowOptions, Goal, GraphNode, Trigger } from '../types';

/*
 * Lo que el lienzo y la lista del celular dicen de cada paso, en palabras del dueño:
 * qué es (título), qué hace con su configuración (resumen), datos de apoyo y si le falta algo.
 * Los tipos sin un caso propio usan el resumen del catálogo.
 */

export interface NodeDescription {
    title: string;
    summary: string;
    details: string[];
    /** Le falta algo para funcionar (plantilla, condición, URL…): el resumen lo dice. */
    incomplete: boolean;
}

export interface DescribeContext {
    options: FlowOptions | undefined;
    trigger?: Trigger | undefined;
    goal?: Goal | null | undefined;
}

const str = (v: unknown): string => (typeof v === 'string' ? v : typeof v === 'number' ? String(v) : '');

function host(url: string): string {
    try {
        return new URL(url).host || url;
    } catch {
        return url;
    }
}

function conditionText(t: TFunction, c: Record<string, unknown>, options: FlowOptions | undefined): { text: string; incomplete: boolean } {
    if (c.mode === 'context') {
        const path = str(c.path);
        if (path === '') {
            return { text: t('flows.canvas.summary.pickCondition'), incomplete: true };
        }
        const field = t(`flows.canvas.paths.${path.replace(/\./g, '_')}`, { defaultValue: path.split('.').at(-1) ?? path });
        const operator = t(`flows.operators.${str(c.operator) || 'eq'}`);
        const value = str(c.value);
        return { text: t('flows.canvas.summary.question', { text: c.operator === 'exists' ? `${field} ${operator}` : `${field} ${operator} «${value}»` }), incomplete: false };
    }
    const definition = c.definition as Group | undefined;
    if (!definition || !Array.isArray(definition.conditions) || definition.conditions.length === 0) {
        return { text: t('flows.canvas.summary.pickCondition'), incomplete: true };
    }
    const names = Object.fromEntries((options?.segments ?? []).map((s) => [s.id, s.name]));
    return { text: t('flows.canvas.summary.question', { text: describeSegment(t, definition, names) }), incomplete: false };
}

export function describeNode(t: TFunction, node: GraphNode, ctx: DescribeContext): NodeDescription {
    const c = node.config;
    const title = t(`flows.nodes.${node.type}`, { defaultValue: node.type });
    const base = (summary: string, incomplete = false, details: string[] = []): NodeDescription => ({ title, summary, details, incomplete });

    switch (node.type) {
        case 'trigger': {
            const trigger = ctx.trigger;
            const missing = !trigger || trigger.type === null || (trigger.type === 'event' && !trigger.event) || (trigger.type === 'segment' && !trigger.segment_id);
            const details = ctx.goal ? [t('flows.canvas.details.goal', { event: eventLabel(t, ctx.goal.event) })] : [t('flows.canvas.details.noGoal')];
            return base(trigger ? summarize(t, node, ctx.options, trigger) : t('flows.triggerText.pick'), missing, details);
        }
        case 'send_whatsapp':
        case 'send_email':
        case 'send_sms': {
            const missing = !c.template_id && !c.template_key;
            const kind = str(c.kind) || 'marketing';
            return base(summarize(t, node, ctx.options), missing, missing ? [] : [t(`flows.canvas.details.kind.${kind}`, { defaultValue: t(`flows.kinds.${kind}`) })]);
        }
        case 'wait':
            if (c.mode === 'until_date') {
                const missing = c.anchor === 'prop' && str(c.path) === '';
                return base(summarize(t, node, ctx.options), missing, [t(c.if_past === 'exit' ? 'flows.canvas.details.ifPastExit' : 'flows.canvas.details.ifPastContinue')]);
            }
            return base(summarize(t, node, ctx.options));
        case 'condition': {
            if (c.mode === 'date') {
                const missing = c.anchor === 'prop' && str(c.path) === '';
                return base(summarize(t, node, ctx.options), missing);
            }
            const { text, incomplete } = conditionText(t, c, ctx.options);
            return base(text, incomplete, c.exit_if_no === true ? [t('flows.canvas.details.exitIfNo')] : []);
        }
        case 'wait_event': {
            const filters = Array.isArray(c.filters) ? c.filters.length : 0;
            return base(summarize(t, node, ctx.options), str(c.event) === '', filters > 0 ? [t('flows.canvas.details.filters', { count: filters })] : []);
        }
        case 'channel_split':
            return base(summarize(t, node, ctx.options), false, [t('flows.canvas.details.channelSplit')]);
        case 'notify_team': {
            const channel = t(`flows.channels.${str(c.channel) || 'email'}`);
            return base(summarize(t, node, ctx.options), false, [
                c.to_owners === false ? t('flows.canvas.details.notifyOthers', { channel }) : t('flows.canvas.details.notifyOwners', { channel }),
            ]);
        }
        case 'webhook': {
            const url = str(c.url);
            return base(url === '' ? t('flows.summary.noUrl') : t('flows.canvas.summary.webhook', { host: host(url) }), url === '');
        }
        case 'update_attribute':
            return base(summarize(t, node, ctx.options), str(c.key) === '');
        case 'tag': {
            const empty = (!Array.isArray(c.add) || c.add.length === 0) && (!Array.isArray(c.remove) || c.remove.length === 0);
            return base(summarize(t, node, ctx.options), empty);
        }
        case 'crm_task':
            return base(summarize(t, node, ctx.options), str(c.title) === '');
        case 'lead_stage':
            return base(summarize(t, node, ctx.options), str(c.stage) === '');
        case 'assign_agent':
            return base(summarize(t, node, ctx.options), c.mode === 'user' && !c.user_id);
        default:
            return base(summarize(t, node, ctx.options));
    }
}

/** Eventos de espera con nombre propio para sus dos salidas («Compró / No compró»). */
const EVENT_BRANCHES: Record<string, string> = {
    'order.paid': 'bought',
    'order.created': 'bought',
    'order.completed': 'bought',
    'message.opened': 'opened',
    'message.read': 'read',
    'message.clicked': 'clicked',
    'message.replied': 'replied',
    'whatsapp.inbound': 'replied',
    'form.submitted': 'submitted',
};

/** Nombre de una salida de un paso, con el contexto del paso cuando ayuda. */
export function branchText(t: TFunction, node: Pick<GraphNode, 'type' | 'config'>, branch: string | null): string {
    if (node.type === 'condition' && node.config.mode === 'date' && (branch === 'yes' || branch === 'no')) {
        return t(`flows.canvas.dateBranches.${branch}`);
    }
    if (node.type === 'wait_event' && (branch === 'event' || branch === 'timeout')) {
        const kind = EVENT_BRANCHES[str(node.config.event)];
        if (kind !== undefined) {
            return t(`flows.canvas.eventBranches.${kind}.${branch}`);
        }
    }
    return branchLabel(t, node.type, branch);
}

/**
 * Etiqueta de una conexión: la de las ramas con nombre (Sí/No, Compró/No compró, Si no se envía…).
 * La salida normal («Siguiente») no la lleva: es el camino de siempre.
 */
export function edgeLabel(t: TFunction, node: Pick<GraphNode, 'type' | 'config'>, branch: string | null): string | null {
    return branch === null ? null : branchText(t, node, branch);
}
