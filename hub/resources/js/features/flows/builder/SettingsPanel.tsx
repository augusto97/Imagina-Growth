import { useTranslation } from 'react-i18next';
import { Input, Select, Textarea } from '@/components/ui/input';
import { FieldError } from '@/components/ui/misc';
import type { Filter, FlowOptions, Goal, ReentryPolicy, Trigger } from '../types';
import { EventSelect, Field, FilterRows } from './NodePanel';

export interface FlowSettings {
    name: string;
    description: string;
    trigger: Trigger;
    goal: Goal | null;
    reentry_policy: ReentryPolicy;
    exclusions: string[];
    holdout_pct: number;
}

interface Props {
    flowId: string;
    value: FlowSettings;
    options: FlowOptions;
    readOnly: boolean;
    errors: Record<string, string>;
    webhookUrl: string | null;
    onChange: (next: FlowSettings) => void;
}

const TRIGGER_TYPES = ['event', 'segment', 'date', 'threshold', 'manual', 'webhook'] as const;

function defaultTrigger(type: (typeof TRIGGER_TYPES)[number]): Trigger {
    switch (type) {
        case 'event':
            return { type: 'event', event: null, filters: [] };
        case 'segment':
            return { type: 'segment', segment_id: null, on: 'entered' };
        case 'date':
            return { type: 'date', attribute: 'birthday', offset_days: 0, hour: 10 };
        case 'threshold':
            return { type: 'threshold', metric: 'revenue_total_ws', operator: 'gte', value: null };
        default:
            return { type };
    }
}

/** Ajustes del flujo: cómo entran, la meta y las políticas (modulos.md M09). */
export function SettingsPanel({ flowId, value, options, readOnly, errors, webhookUrl, onChange }: Props) {
    const { t } = useTranslation();
    const set = (patch: Partial<FlowSettings>) => onChange({ ...value, ...patch });
    const trigger = value.trigger;
    const setTrigger = (patch: Partial<Trigger>) => set({ trigger: { ...trigger, ...patch } as Trigger });
    const err = (...keys: string[]) => keys.map((k) => errors[k]).find((m) => m !== undefined);

    return (
        <div className="igr:space-y-4">
            <h2 className="igr:text-[13px] igr:font-semibold">{t('flows.builder.settings')}</h2>
            <Field label={t('flows.name')} htmlFor="flow-set-name">
                <Input id="flow-set-name" maxLength={160} value={value.name} disabled={readOnly} onChange={(e) => set({ name: e.target.value })} />
                <FieldError message={err('name')} />
            </Field>
            <Field label={t('flows.descriptionLabel')} htmlFor="flow-set-desc">
                <Textarea id="flow-set-desc" className="igr:min-h-14" maxLength={500} value={value.description} disabled={readOnly} onChange={(e) => set({ description: e.target.value })} />
            </Field>

            <section className="igr:space-y-3 igr:border-t igr:pt-3">
                <Field label={t('flows.settings.triggerType')} htmlFor="flow-set-trigger">
                    <Select id="flow-set-trigger" value={trigger.type ?? ''} disabled={readOnly} onChange={(e) => set({ trigger: defaultTrigger(e.target.value as (typeof TRIGGER_TYPES)[number]) })}>
                        {TRIGGER_TYPES.map((type) => (
                            <option key={type} value={type}>
                                {t(`flows.settings.types.${type}`)}
                            </option>
                        ))}
                    </Select>
                    <FieldError message={err('trigger.type')} />
                </Field>
                {trigger.type === 'event' ? (
                    <>
                        <Field label={t('flows.settings.event')} htmlFor="flow-set-event">
                            <EventSelect id="flow-set-event" value={trigger.event ?? ''} events={options.events} disabled={readOnly} onChange={(event) => setTrigger({ event })} />
                            <FieldError message={err('trigger.event')} />
                        </Field>
                        <Field label={t('flows.config.filter')}>
                            <FilterRows value={trigger.filters} disabled={readOnly} onChange={(filters: Filter[]) => setTrigger({ filters })} />
                            <FieldError message={err('trigger.filters')} />
                        </Field>
                    </>
                ) : null}
                {trigger.type === 'segment' ? (
                    <div className="igr:grid igr:grid-cols-[1fr_auto] igr:gap-2">
                        <Field label={t('flows.settings.segment')} htmlFor="flow-set-segment">
                            <Select id="flow-set-segment" value={trigger.segment_id ?? ''} disabled={readOnly} onChange={(e) => setTrigger({ segment_id: e.target.value || null })}>
                                <option value="">—</option>
                                {options.segments.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <Field label={t('flows.settings.on')} htmlFor="flow-set-on">
                            <Select id="flow-set-on" value={trigger.on} disabled={readOnly} onChange={(e) => setTrigger({ on: e.target.value === 'exited' ? 'exited' : 'entered' })}>
                                <option value="entered">{t('flows.settings.onEntered')}</option>
                                <option value="exited">{t('flows.settings.onExited')}</option>
                            </Select>
                        </Field>
                        <div className="igr:col-span-2">
                            <FieldError message={err('trigger.segment_id')} />
                        </div>
                    </div>
                ) : null}
                {trigger.type === 'date' ? (
                    <>
                        <Field label={t('flows.settings.attribute')} htmlFor="flow-set-attr">
                            <Select id="flow-set-attr" value={trigger.attribute ?? ''} disabled={readOnly} onChange={(e) => setTrigger({ attribute: e.target.value })}>
                                {options.date_attributes.map((a) => (
                                    <option key={a} value={a}>
                                        {t(`flows.dateAttributes.${a}`)}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <div className="igr:grid igr:grid-cols-2 igr:gap-2">
                            <Field label={t('flows.settings.offset')} htmlFor="flow-set-offset">
                                <Input id="flow-set-offset" type="number" min={-60} max={60} value={trigger.offset_days} disabled={readOnly} onChange={(e) => setTrigger({ offset_days: Number(e.target.value) || 0 })} />
                            </Field>
                            <Field label={t('flows.settings.hour')} htmlFor="flow-set-hour">
                                <Input id="flow-set-hour" type="number" min={0} max={23} value={trigger.hour} disabled={readOnly} onChange={(e) => setTrigger({ hour: Math.min(23, Math.max(0, Number(e.target.value) || 0)) })} />
                            </Field>
                        </div>
                        <FieldError message={err('trigger.attribute')} />
                    </>
                ) : null}
                {trigger.type === 'threshold' ? (
                    <div className="igr:grid igr:grid-cols-3 igr:gap-2">
                        <Field label={t('flows.settings.metric')} htmlFor="flow-set-metric">
                            <Select id="flow-set-metric" value={trigger.metric ?? ''} disabled={readOnly} onChange={(e) => setTrigger({ metric: e.target.value })}>
                                {options.threshold_metrics.map((m) => (
                                    <option key={m} value={m}>
                                        {t(`flows.metrics.${m}`)}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <Field label={t('flows.settings.operator')} htmlFor="flow-set-op">
                            <Select id="flow-set-op" value={trigger.operator} disabled={readOnly} onChange={(e) => setTrigger({ operator: e.target.value })}>
                                {['gte', 'gt', 'lte', 'lt'].map((op) => (
                                    <option key={op} value={op}>
                                        {t(`flows.operators.${op}`)}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <Field label={t('flows.settings.value')} htmlFor="flow-set-value">
                            <Input id="flow-set-value" inputMode="decimal" value={trigger.value ?? ''} disabled={readOnly} onChange={(e) => setTrigger({ value: e.target.value === '' ? null : Number(e.target.value.replace(',', '.')) })} />
                        </Field>
                        <div className="igr:col-span-3">
                            <FieldError message={err('trigger.metric')} />
                        </div>
                    </div>
                ) : null}
                {trigger.type === 'webhook' ? (
                    <Field label={t('flows.settings.webhookUrl')} help={t('flows.settings.webhookHelp')}>
                        {webhookUrl ? <code className="igr:block igr:break-all igr:rounded-md igr:bg-muted igr:px-2 igr:py-1 igr:text-[11.5px]">{webhookUrl}</code> : <span className="igr:text-xs igr:text-muted-foreground">—</span>}
                    </Field>
                ) : null}
            </section>

            <section className="igr:space-y-3 igr:border-t igr:pt-3">
                <Field label={t('flows.settings.goal')} htmlFor="flow-set-goal" help={t('flows.settings.goalHelp')}>
                    <Select id="flow-set-goal" value={value.goal?.event ?? ''} disabled={readOnly} onChange={(e) => set({ goal: e.target.value === '' ? null : { event: e.target.value, filters: [] } })}>
                        <option value="">{t('flows.settings.noGoal')}</option>
                        {options.events.map((ev) => (
                            <option key={ev} value={ev}>
                                {t(`flows.events.${ev.replace(/\./g, '_')}`, { defaultValue: ev })}
                            </option>
                        ))}
                    </Select>
                    <FieldError message={err('goal')} />
                </Field>
                <Field label={t('flows.settings.reentry')} htmlFor="flow-set-reentry">
                    <div className="igr:flex igr:gap-2">
                        <Select
                            id="flow-set-reentry"
                            value={value.reentry_policy.mode}
                            disabled={readOnly}
                            onChange={(e) => {
                                const mode = e.target.value as ReentryPolicy['mode'];
                                set({ reentry_policy: mode === 'every_days' ? { mode, days: 30 } : { mode } });
                            }}
                        >
                            <option value="once">{t('flows.settings.reentryOnce')}</option>
                            <option value="every_days">{t('flows.settings.reentryDays')}</option>
                            <option value="always">{t('flows.settings.reentryAlways')}</option>
                        </Select>
                        {value.reentry_policy.mode === 'every_days' ? (
                            <Input
                                type="number"
                                min={1}
                                max={365}
                                className="igr:w-24"
                                aria-label={t('flows.settings.days')}
                                value={value.reentry_policy.days ?? 30}
                                disabled={readOnly}
                                onChange={(e) => set({ reentry_policy: { mode: 'every_days', days: Number(e.target.value) || 1 } })}
                            />
                        ) : null}
                    </div>
                    <FieldError message={err('reentry_policy')} />
                </Field>
                <Field label={t('flows.settings.exclusions')}>
                    <div className="igr:max-h-28 igr:space-y-1 igr:overflow-y-auto igr:rounded-md igr:border igr:p-2">
                        {options.flows.filter((f) => f.id !== flowId).length === 0 ? <span className="igr:text-xs igr:text-muted-foreground">—</span> : null}
                        {options.flows
                            .filter((f) => f.id !== flowId)
                            .map((f) => (
                                <label key={f.id} className="igr:flex igr:items-center igr:gap-2 igr:text-[13px]">
                                    <input
                                        type="checkbox"
                                        checked={value.exclusions.includes(f.id)}
                                        disabled={readOnly}
                                        onChange={(e) => set({ exclusions: e.target.checked ? [...value.exclusions, f.id] : value.exclusions.filter((x) => x !== f.id) })}
                                    />
                                    {f.name}
                                </label>
                            ))}
                    </div>
                    <FieldError message={err('exclusions')} />
                </Field>
                <Field label={t('flows.settings.holdout')} htmlFor="flow-set-holdout" help={t('flows.settings.holdoutHelp')}>
                    <div className="igr:flex igr:items-center igr:gap-2">
                        <input
                            id="flow-set-holdout"
                            type="range"
                            min={0}
                            max={50}
                            step={5}
                            className="igr:flex-1"
                            value={value.holdout_pct}
                            disabled={readOnly}
                            onChange={(e) => set({ holdout_pct: Number(e.target.value) })}
                        />
                        <span className="igr:w-10 igr:text-right igr:text-[13px] igr:tabular-nums">{value.holdout_pct}%</span>
                    </div>
                    <FieldError message={err('holdout_pct')} />
                </Field>
            </section>
        </div>
    );
}
