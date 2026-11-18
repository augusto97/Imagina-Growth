import {
    Bell,
    Clock,
    Flag,
    GitBranch,
    Hourglass,
    Mail,
    MessageCircle,
    MessageSquareText,
    Pencil,
    Radio,
    Shuffle,
    Split,
    Tag,
    Ticket,
    Webhook,
    Zap,
    type LucideIcon,
    Award,
    Wallet,
    UserCheck,
    Briefcase,
    ListTodo,
} from 'lucide-react';
import type { TFunction } from 'i18next';
import type { FlowOptions, GraphNode, Trigger } from './types';

/* Catálogo de nodos de modulos.md M09 para el constructor. Las ramas y la validación son del servidor (NodeTypes). */

export type Category = 'trigger' | 'wait' | 'logic' | 'action' | 'control';

export interface NodeMeta {
    icon: LucideIcon;
    category: Category;
    phase?: number;
    /** Ramas fijas; las dinámicas salen de la configuración. `null` = salida normal. */
    branches: (string | null)[] | 'dynamic';
    defaults: () => Record<string, unknown>;
}

export const NODE_META: Record<string, NodeMeta> = {
    trigger: { icon: Zap, category: 'trigger', branches: [null], defaults: () => ({}) },
    wait: { icon: Clock, category: 'wait', branches: [null], defaults: () => ({ mode: 'duration', duration: { value: 1, unit: 'hours' } }) },
    wait_event: { icon: Hourglass, category: 'wait', branches: ['event', 'timeout'], defaults: () => ({ event: 'order.paid', timeout: { value: 1, unit: 'days' }, filters: [] }) },
    condition: {
        icon: GitBranch,
        category: 'logic',
        branches: ['yes', 'no'],
        defaults: () => ({ mode: 'segment', definition: { op: 'and', conditions: [] } }),
    },
    ab_split: {
        icon: Shuffle,
        category: 'logic',
        branches: 'dynamic',
        defaults: () => ({ variants: [{ key: 'a', weight: 50 }, { key: 'b', weight: 50 }] }),
    },
    channel_split: { icon: Split, category: 'logic', branches: 'dynamic', defaults: () => ({ channels: ['whatsapp', 'email'] }) },
    send_whatsapp: { icon: MessageCircle, category: 'action', branches: [null, 'failed'], defaults: () => ({ template_id: null, kind: 'marketing' }) },
    send_email: { icon: Mail, category: 'action', branches: [null, 'failed'], defaults: () => ({ template_id: null, kind: 'marketing' }) },
    send_sms: { icon: MessageSquareText, category: 'action', branches: [null, 'failed'], defaults: () => ({ template_id: null, kind: 'marketing' }) },
    coupon: { icon: Ticket, category: 'action', branches: [null, 'failed'], defaults: () => ({ kind: 'percent', amount: '10', expires_in_days: 7, prefix: 'IGR' }) },
    tag: { icon: Tag, category: 'action', branches: [null], defaults: () => ({ add: [], remove: [] }) },
    update_attribute: { icon: Pencil, category: 'action', branches: [null], defaults: () => ({ key: '', value: '' }) },
    notify_team: { icon: Bell, category: 'action', branches: [null, 'failed'], defaults: () => ({ channel: 'email', to_owners: true, recipients: [], message: '', template_key: 'team_alert' }) },
    webhook: { icon: Webhook, category: 'action', branches: [null, 'failed'], defaults: () => ({ url: '' }) },
    exit: { icon: Flag, category: 'control', branches: [], defaults: () => ({}) },
    give_points: { icon: Award, category: 'action', phase: 3, branches: [null], defaults: () => ({}) },
    give_wallet: { icon: Wallet, category: 'action', phase: 3, branches: [null], defaults: () => ({}) },
    assign_agent: { icon: UserCheck, category: 'action', phase: 4, branches: [null], defaults: () => ({}) },
    lead_stage: { icon: Briefcase, category: 'action', phase: 5, branches: [null], defaults: () => ({}) },
    crm_task: { icon: ListTodo, category: 'action', phase: 5, branches: [null], defaults: () => ({}) },
};

export const PALETTE: { category: Category; types: string[] }[] = [
    { category: 'wait', types: ['wait', 'wait_event'] },
    { category: 'logic', types: ['condition', 'ab_split', 'channel_split'] },
    { category: 'action', types: ['send_whatsapp', 'send_email', 'send_sms', 'coupon', 'tag', 'update_attribute', 'notify_team', 'webhook', 'give_points', 'give_wallet', 'assign_agent', 'lead_stage', 'crm_task'] },
    { category: 'control', types: ['exit'] },
];

export const CHANNEL_ICON: Record<string, LucideIcon> = { whatsapp: MessageCircle, email: Mail, sms: MessageSquareText, none: Radio };

export function metaOf(type: string): NodeMeta {
    return NODE_META[type] ?? { icon: Radio, category: 'action', branches: [null], defaults: () => ({}) };
}

/** Ramas de un nodo con su configuración actual. */
export function branchesOf(node: Pick<GraphNode, 'type' | 'config'>): (string | null)[] {
    const meta = metaOf(node.type);
    if (meta.branches !== 'dynamic') {
        return meta.branches;
    }
    if (node.type === 'ab_split') {
        const variants = Array.isArray(node.config.variants) ? (node.config.variants as { key?: string }[]) : [];
        return variants.map((v) => String(v.key ?? ''));
    }
    const channels = Array.isArray(node.config.channels) ? (node.config.channels as string[]) : [];
    return [...channels, 'none'];
}

/** Etiqueta de una rama («Sí», «Falló», «Variante A», «WhatsApp»…). */
export function branchLabel(t: TFunction, type: string, branch: string | null): string {
    if (branch === null) {
        return t('flows.branches.next');
    }
    if (type === 'ab_split') {
        return t('flows.branches.variant', { key: branch.toUpperCase() });
    }
    if (type === 'channel_split') {
        return t(`flows.channels.${branch}`);
    }
    return t(`flows.branches.${branch}`);
}

const num = (v: unknown, fallback = 0) => (typeof v === 'number' ? v : typeof v === 'string' && v !== '' && !Number.isNaN(Number(v)) ? Number(v) : fallback);

/** Duración legible: «3 horas». */
export function durationText(t: TFunction, d: unknown): string {
    const value = num((d as { value?: unknown } | null)?.value, 0);
    const unit = String((d as { unit?: unknown } | null)?.unit ?? 'hours');
    return t(`flows.units.${unit}`, { count: value });
}

/** Una línea que dice qué hace el nodo con su configuración. */
export function summarize(t: TFunction, node: GraphNode, options: FlowOptions | undefined, trigger?: Trigger): string {
    const c = node.config;
    switch (node.type) {
        case 'trigger':
            return trigger ? triggerText(t, trigger, options) : '';
        case 'wait':
            if (c.mode === 'until_time') {
                const days = Array.isArray(c.weekdays) && c.weekdays.length > 0 && c.weekdays.length < 7 ? (c.weekdays as number[]).map((d) => t(`flows.weekdaysShort.${d}`)).join(', ') : t('flows.summary.everyDay');
                return t('flows.summary.untilTime', { hour: String(num(c.hour, 9)).padStart(2, '0'), minute: String(num(c.minute, 0)).padStart(2, '0'), days });
            }
            return t('flows.summary.wait', { duration: durationText(t, c.duration) });
        case 'wait_event':
            return t('flows.summary.waitEvent', { event: eventLabel(t, String(c.event ?? '')), duration: durationText(t, c.timeout) });
        case 'condition':
            if (c.mode === 'context') {
                return t('flows.summary.contextCondition', { path: String(c.path ?? ''), value: String(c.value ?? '') });
            }
            return t('flows.summary.segmentCondition', { count: Array.isArray((c.definition as { conditions?: unknown[] } | undefined)?.conditions) ? (c.definition as { conditions: unknown[] }).conditions.length : 0 });
        case 'ab_split':
            return (Array.isArray(c.variants) ? (c.variants as { key: string; weight: number }[]) : []).map((v) => `${v.key.toUpperCase()} ${v.weight}%`).join(' · ');
        case 'channel_split':
            return (Array.isArray(c.channels) ? (c.channels as string[]) : []).map((ch) => t(`flows.channels.${ch}`)).join(' → ');
        case 'send_whatsapp':
        case 'send_email':
        case 'send_sms': {
            const template = options?.templates.find((tpl) => tpl.id === c.template_id || (tpl.library_key !== null && tpl.library_key === c.template_key));
            const name = template?.name ?? (typeof c.template_key === 'string' ? c.template_key : null);
            return name ? `${name}${c.kind && c.kind !== 'marketing' ? ` · ${t(`flows.kinds.${String(c.kind)}`)}` : ''}` : t('flows.summary.noTemplate');
        }
        case 'coupon':
            return c.kind === 'free_shipping'
                ? t('flows.summary.couponShipping', { days: num(c.expires_in_days, 7) })
                : t(c.kind === 'percent' ? 'flows.summary.couponPercent' : 'flows.summary.couponFixed', { amount: String(c.amount ?? ''), days: num(c.expires_in_days, 7) });
        case 'tag': {
            const add = Array.isArray(c.add) ? (c.add as string[]) : [];
            const remove = Array.isArray(c.remove) ? (c.remove as string[]) : [];
            return [add.length ? `+ ${add.join(', ')}` : '', remove.length ? `− ${remove.join(', ')}` : ''].filter(Boolean).join(' · ') || t('flows.summary.noTags');
        }
        case 'update_attribute':
            return c.key ? `${String(c.key)} = ${String(c.value ?? '')}` : t('flows.summary.noAttribute');
        case 'notify_team':
            return String(c.message ?? '') || t('flows.summary.noMessage');
        case 'webhook':
            return String(c.url ?? '') || t('flows.summary.noUrl');
        case 'exit':
            return t('flows.summary.exit');
        default:
            return metaOf(node.type).phase ? t('flows.summary.comingPhase', { phase: metaOf(node.type).phase }) : '';
    }
}

export function eventLabel(t: TFunction, event: string): string {
    return t(`flows.events.${event.replace(/\./g, '_')}`, { defaultValue: event });
}

export function triggerText(t: TFunction, trigger: Trigger, options?: FlowOptions): string {
    switch (trigger.type) {
        case 'event':
            return trigger.event ? t('flows.triggerText.event', { event: eventLabel(t, trigger.event) }) : t('flows.triggerText.pick');
        case 'segment': {
            const segment = options?.segments.find((s) => s.id === trigger.segment_id);
            return t(trigger.on === 'exited' ? 'flows.triggerText.segmentExited' : 'flows.triggerText.segmentEntered', { name: segment?.name ?? '…' });
        }
        case 'date':
            return trigger.attribute
                ? t('flows.triggerText.date', { attribute: t(`flows.dateAttributes.${trigger.attribute}`), offset: offsetText(t, trigger.offset_days), hour: String(trigger.hour).padStart(2, '0') })
                : t('flows.triggerText.pick');
        case 'threshold':
            return trigger.metric ? t('flows.triggerText.threshold', { metric: t(`flows.metrics.${trigger.metric}`), operator: t(`flows.operators.${trigger.operator}`), value: trigger.value ?? '' }) : t('flows.triggerText.pick');
        case 'manual':
            return t('flows.triggerText.manual');
        case 'webhook':
            return t('flows.triggerText.webhook');
        default:
            return t('flows.triggerText.pick');
    }
}

function offsetText(t: TFunction, days: number): string {
    if (days === 0) {
        return t('flows.offset.same');
    }
    return days < 0 ? t('flows.offset.before', { count: -days }) : t('flows.offset.after', { count: days });
}
