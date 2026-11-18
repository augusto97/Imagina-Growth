import { ArrowDown, ArrowUp, CircleAlert, Plus, Trash2, X } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Input, Select, Textarea } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { useWorkspace } from '@/hooks/useWorkspace';
import { useSegmentOptions } from '@/features/segments/api';
import { defaultCondition } from '@/features/segments/ast';
import { BuilderContext } from '@/features/segments/builder/context';
import { AddConditionMenu, GroupEditor } from '@/features/segments/builder/GroupEditor';
import type { Group } from '@/features/segments/types';
import { metaOf } from '../catalog';
import type { Filter, FlowOptions, GraphNode } from '../types';

type Config = Record<string, unknown>;

interface Props {
    node: GraphNode;
    options: FlowOptions;
    issues: string[];
    readOnly: boolean;
    webhookSecret: string | null;
    onChange: (config: Config) => void;
    onDelete: () => void;
}

const str = (v: unknown, fallback = '') => (typeof v === 'string' ? v : typeof v === 'number' ? String(v) : fallback);
const list = (v: unknown): string[] => (Array.isArray(v) ? v.map((x) => String(x)) : []);
const csv = (v: string): string[] => v.split(',').map((x) => x.trim()).filter((x) => x !== '');

export function Field({ label, htmlFor, help, children }: { label: string; htmlFor?: string; help?: ReactNode; children: ReactNode }) {
    return (
        <div className="igr:space-y-1">
            <Label htmlFor={htmlFor}>{label}</Label>
            {children}
            {help ? <p className="igr:text-[11.5px] igr:text-muted-foreground">{help}</p> : null}
        </div>
    );
}

export function DurationInput({ id, value, onChange, disabled }: { id: string; value: unknown; onChange: (v: { value: number; unit: string }) => void; disabled: boolean }) {
    const { t } = useTranslation();
    const d = (value ?? {}) as { value?: number; unit?: string };
    const amount = typeof d.value === 'number' ? d.value : 1;
    const unit = d.unit ?? 'hours';
    return (
        <div className="igr:flex igr:gap-2">
            <Input id={id} type="number" min={1} max={365} className="igr:w-24" value={amount} disabled={disabled} onChange={(e) => onChange({ value: Math.max(1, Number(e.target.value) || 1), unit })} />
            <Select aria-label={t('flows.config.unit')} value={unit} disabled={disabled} onChange={(e) => onChange({ value: amount, unit: e.target.value })}>
                {(['minutes', 'hours', 'days'] as const).map((u) => (
                    <option key={u} value={u}>
                        {t(`flows.unitNames.${u}`)}
                    </option>
                ))}
            </Select>
        </div>
    );
}

/** Un filtro opcional sobre las propiedades de un evento. */
export function FilterRows({ value, onChange, disabled }: { value: Filter[]; onChange: (f: Filter[]) => void; disabled: boolean }) {
    const { t } = useTranslation();
    const set = (i: number, patch: Partial<Filter>) => onChange(value.map((f, j) => (j === i ? { ...f, ...patch } : f)));
    return (
        <div className="igr:space-y-2">
            {value.map((f, i) => (
                // En el panel angosto: la propiedad ocupa la primera línea; operador, valor y quitar, la segunda.
                <div key={i} className="igr:grid igr:grid-cols-[auto_1fr_auto] igr:items-center igr:gap-1.5 igr:rounded-md igr:border igr:p-1.5">
                    <Input
                        className="igr:col-span-3"
                        aria-label={t('flows.config.filterProp')}
                        placeholder="order.payment_method.id"
                        value={f.prop}
                        disabled={disabled}
                        onChange={(e) => set(i, { prop: e.target.value.trim() })}
                    />
                    <Select aria-label={t('flows.settings.operator')} value={f.operator} disabled={disabled} onChange={(e) => set(i, { operator: e.target.value })}>
                        {['eq', 'neq', 'gte', 'lte', 'in', 'contains', 'starts_with', 'exists'].map((op) => (
                            <option key={op} value={op}>
                                {t(`flows.operators.${op}`)}
                            </option>
                        ))}
                    </Select>
                    <Input
                        aria-label={t('flows.config.filterValue')}
                        value={Array.isArray(f.value) ? f.value.join(', ') : str(f.value)}
                        disabled={disabled || f.operator === 'exists'}
                        onChange={(e) => set(i, { value: f.operator === 'in' ? csv(e.target.value) : e.target.value })}
                    />
                    <Button variant="ghost" size="icon" className="igr:size-7" disabled={disabled} aria-label={t('flows.config.remove')} onClick={() => onChange(value.filter((_, j) => j !== i))}>
                        <X />
                    </Button>
                </div>
            ))}
            {!disabled && value.length < 5 ? (
                <Button variant="outline" size="sm" onClick={() => onChange([...value, { prop: '', operator: 'eq', value: '' }])}>
                    <Plus />
                    {t('flows.config.addFilter')}
                </Button>
            ) : null}
        </div>
    );
}

export function NodePanel({ node, options, issues, readOnly, webhookSecret, onChange, onDelete }: Props) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const c = node.config;
    const set = (patch: Config) => onChange({ ...c, ...patch });
    const meta = metaOf(node.type);
    const id = (name: string) => `node-${node.id}-${name}`;

    let body: ReactNode = null;
    if (meta.phase) {
        body = <p className="igr:text-[13px] igr:text-muted-foreground">{t('flows.config.laterPhase', { phase: meta.phase })}</p>;
    } else {
        switch (node.type) {
            case 'wait': {
                const mode = str(c.mode, 'duration');
                const days = Array.isArray(c.weekdays) ? (c.weekdays as number[]) : [1, 2, 3, 4, 5, 6, 7];
                body = (
                    <>
                        <Field label={t('flows.config.mode')} htmlFor={id('mode')}>
                            <Select id={id('mode')} value={mode} disabled={readOnly} onChange={(e) => set(e.target.value === 'until_time' ? { mode: 'until_time', hour: 9, minute: 0, weekdays: [1, 2, 3, 4, 5, 6] } : { mode: 'duration', duration: { value: 1, unit: 'hours' } })}>
                                <option value="duration">{t('flows.config.duration')}</option>
                                <option value="until_time">{t('flows.config.untilTime')}</option>
                            </Select>
                        </Field>
                        {mode === 'until_time' ? (
                            <>
                                <div className="igr:grid igr:grid-cols-2 igr:gap-2">
                                    <Field label={t('flows.config.hour')} htmlFor={id('hour')}>
                                        <Input id={id('hour')} type="number" min={0} max={23} value={str(c.hour, '9')} disabled={readOnly} onChange={(e) => set({ hour: Math.min(23, Math.max(0, Number(e.target.value) || 0)) })} />
                                    </Field>
                                    <Field label={t('flows.config.minute')} htmlFor={id('minute')}>
                                        <Input id={id('minute')} type="number" min={0} max={59} value={str(c.minute, '0')} disabled={readOnly} onChange={(e) => set({ minute: Math.min(59, Math.max(0, Number(e.target.value) || 0)) })} />
                                    </Field>
                                </div>
                                <fieldset className="igr:space-y-1">
                                    <legend className="igr:text-[13px] igr:font-medium">{t('flows.config.weekdays')}</legend>
                                    <div className="igr:flex igr:flex-wrap igr:gap-1">
                                        {[1, 2, 3, 4, 5, 6, 7].map((d) => (
                                            <label key={d} className="igr:flex igr:items-center igr:gap-1 igr:rounded-md igr:border igr:px-2 igr:py-1 igr:text-xs">
                                                <input type="checkbox" checked={days.includes(d)} disabled={readOnly} onChange={(e) => set({ weekdays: e.target.checked ? [...days, d].sort() : days.filter((x) => x !== d) })} />
                                                {t(`flows.weekdaysShort.${d}`)}
                                            </label>
                                        ))}
                                    </div>
                                </fieldset>
                            </>
                        ) : (
                            <Field label={t('flows.config.duration')} htmlFor={id('duration')}>
                                <DurationInput id={id('duration')} value={c.duration} disabled={readOnly} onChange={(duration) => set({ duration })} />
                            </Field>
                        )}
                    </>
                );
                break;
            }
            case 'wait_event':
                body = (
                    <>
                        <Field label={t('flows.config.event')} htmlFor={id('event')}>
                            <EventSelect id={id('event')} value={str(c.event)} events={options.events} disabled={readOnly} onChange={(event) => set({ event })} />
                        </Field>
                        <Field label={t('flows.config.timeout')} htmlFor={id('timeout')}>
                            <DurationInput id={id('timeout')} value={c.timeout} disabled={readOnly} onChange={(timeout) => set({ timeout })} />
                        </Field>
                        <Field label={t('flows.config.filter')}>
                            <FilterRows value={(Array.isArray(c.filters) ? c.filters : []) as Filter[]} disabled={readOnly} onChange={(filters) => set({ filters })} />
                        </Field>
                    </>
                );
                break;
            case 'condition':
                body = <ConditionConfig config={c} readOnly={readOnly} onChange={onChange} />;
                break;
            case 'ab_split': {
                const variants = (Array.isArray(c.variants) ? c.variants : []) as { key: string; weight: number }[];
                const sum = variants.reduce((acc, v) => acc + (Number.isFinite(v.weight) ? v.weight : 0), 0);
                body = (
                    <Field label={t('flows.config.variants')} help={sum !== 100 ? <span className="igr:text-destructive">{t('flows.config.weightsSum', { sum })}</span> : undefined}>
                        <div className="igr:space-y-1.5">
                            {variants.map((v, i) => (
                                <div key={v.key} className="igr:flex igr:items-center igr:gap-2">
                                    <span className="igr:w-20 igr:text-[13px]">{t('flows.branches.variant', { key: v.key.toUpperCase() })}</span>
                                    <Input type="number" min={1} max={99} aria-label={t('flows.config.weight')} className="igr:w-24" value={v.weight} disabled={readOnly} onChange={(e) => set({ variants: variants.map((x, j) => (j === i ? { ...x, weight: Number(e.target.value) || 0 } : x)) })} />
                                    {variants.length > 2 && !readOnly ? (
                                        <Button variant="ghost" size="icon" className="igr:size-7" aria-label={t('flows.config.remove')} onClick={() => set({ variants: variants.filter((_, j) => j !== i) })}>
                                            <X />
                                        </Button>
                                    ) : null}
                                </div>
                            ))}
                            {variants.length < 4 && !readOnly ? (
                                <Button variant="outline" size="sm" onClick={() => set({ variants: [...variants, { key: ['a', 'b', 'c', 'd'].find((k) => !variants.some((v) => v.key === k)) ?? 'd', weight: 0 }] })}>
                                    <Plus />
                                    {t('flows.config.addVariant')}
                                </Button>
                            ) : null}
                        </div>
                    </Field>
                );
                break;
            }
            case 'channel_split': {
                const channels = list(c.channels);
                const move = (i: number, dir: -1 | 1) => {
                    const next = [...channels];
                    const [item] = next.splice(i, 1);
                    next.splice(i + dir, 0, item as string);
                    set({ channels: next });
                };
                body = (
                    <Field label={t('flows.config.channels')} help={t('flows.config.channelsHelp')}>
                        <ul className="igr:space-y-1">
                            {(['whatsapp', 'email', 'sms'] as const).map((ch) => {
                                const i = channels.indexOf(ch);
                                return (
                                    <li key={ch} className="igr:flex igr:items-center igr:gap-2 igr:text-[13px]">
                                        <label className="igr:flex igr:flex-1 igr:items-center igr:gap-2">
                                            <input type="checkbox" checked={i >= 0} disabled={readOnly} onChange={(e) => set({ channels: e.target.checked ? [...channels, ch] : channels.filter((x) => x !== ch) })} />
                                            {i >= 0 ? <span className="igr:tabular-nums igr:text-muted-foreground">{i + 1}.</span> : null}
                                            {t(`flows.channels.${ch}`)}
                                        </label>
                                        {i >= 0 && !readOnly ? (
                                            <>
                                                <Button variant="ghost" size="icon" className="igr:size-7" disabled={i === 0} aria-label="↑" onClick={() => move(i, -1)}>
                                                    <ArrowUp />
                                                </Button>
                                                <Button variant="ghost" size="icon" className="igr:size-7" disabled={i === channels.length - 1} aria-label="↓" onClick={() => move(i, 1)}>
                                                    <ArrowDown />
                                                </Button>
                                            </>
                                        ) : null}
                                    </li>
                                );
                            })}
                        </ul>
                    </Field>
                );
                break;
            }
            case 'send_whatsapp':
            case 'send_email':
            case 'send_sms': {
                const channel = node.type === 'send_whatsapp' ? 'whatsapp' : node.type === 'send_email' ? 'email' : 'sms';
                const templates = options.templates.filter((tpl) => tpl.channel === channel);
                const selected = templates.find((tpl) => tpl.id === c.template_id) ?? templates.find((tpl) => tpl.library_key !== null && tpl.library_key === c.template_key);
                const kind = str(c.kind, 'marketing');
                body = (
                    <>
                        <Field
                            label={t('flows.config.template')}
                            htmlFor={id('template')}
                            help={templates.length === 0 ? t('flows.config.noTemplates') : !selected && typeof c.template_key === 'string' ? t('flows.config.libraryKey', { key: c.template_key }) : undefined}
                        >
                            <Select id={id('template')} value={selected?.id ?? ''} disabled={readOnly} onChange={(e) => set({ template_id: e.target.value || null, template_key: templates.find((tpl) => tpl.id === e.target.value)?.library_key ?? null })}>
                                <option value="">{t('flows.config.pickTemplate')}</option>
                                {templates.map((tpl) => (
                                    <option key={tpl.id} value={tpl.id}>
                                        {tpl.name}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <Field label={t('flows.config.kind')} htmlFor={id('kind')} help={t('flows.config.kindHelp')}>
                            <Select id={id('kind')} value={kind} disabled={readOnly} onChange={(e) => set({ kind: e.target.value })}>
                                {(['marketing', 'utility', 'transactional'] as const).map((k) => (
                                    <option key={k} value={k}>
                                        {t(`flows.kinds.${k}`)}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        {kind !== 'marketing' ? (
                            <Field label={t('flows.config.reason')} htmlFor={id('reason')} help={t('flows.config.reasonHelp')}>
                                <Textarea id={id('reason')} className="igr:min-h-16" maxLength={300} value={str(c.reclassify_reason)} disabled={readOnly} onChange={(e) => set({ reclassify_reason: e.target.value })} />
                            </Field>
                        ) : null}
                    </>
                );
                break;
            }
            case 'coupon': {
                const kind = str(c.kind, 'percent');
                body = (
                    <>
                        <Field label={t('flows.config.couponKind')} htmlFor={id('kind')}>
                            <Select id={id('kind')} value={kind} disabled={readOnly} onChange={(e) => set({ kind: e.target.value })}>
                                <option value="percent">{t('flows.config.couponPercent')}</option>
                                <option value="fixed_cart">{t('flows.config.couponFixed')}</option>
                                <option value="free_shipping">{t('flows.config.couponShipping')}</option>
                            </Select>
                        </Field>
                        <div className="igr:grid igr:grid-cols-2 igr:gap-2">
                            {kind !== 'free_shipping' ? (
                                <Field label={t('flows.config.amount')} htmlFor={id('amount')}>
                                    <Input id={id('amount')} inputMode="decimal" value={str(c.amount)} disabled={readOnly} onChange={(e) => set({ amount: e.target.value.replace(',', '.') })} />
                                </Field>
                            ) : null}
                            <Field label={t('flows.config.expires')} htmlFor={id('expires')}>
                                <Input id={id('expires')} type="number" min={1} max={90} value={str(c.expires_in_days, '7')} disabled={readOnly} onChange={(e) => set({ expires_in_days: Number(e.target.value) || 1 })} />
                            </Field>
                            <Field label={t('flows.config.prefix')} htmlFor={id('prefix')}>
                                <Input id={id('prefix')} maxLength={12} value={str(c.prefix, 'IGR')} disabled={readOnly} onChange={(e) => set({ prefix: e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '') })} />
                            </Field>
                            <Field label={t('flows.config.minimum')} htmlFor={id('minimum')}>
                                <Input id={id('minimum')} inputMode="decimal" value={str(c.minimum_amount)} disabled={readOnly} onChange={(e) => set({ minimum_amount: e.target.value })} />
                            </Field>
                        </div>
                    </>
                );
                break;
            }
            case 'tag':
                body = (
                    <>
                        <Field label={t('flows.config.tagsAdd')} htmlFor={id('add')}>
                            <Input id={id('add')} value={list(c.add).join(', ')} disabled={readOnly} onChange={(e) => set({ add: csv(e.target.value) })} />
                        </Field>
                        <Field label={t('flows.config.tagsRemove')} htmlFor={id('remove')}>
                            <Input id={id('remove')} value={list(c.remove).join(', ')} disabled={readOnly} onChange={(e) => set({ remove: csv(e.target.value) })} />
                        </Field>
                    </>
                );
                break;
            case 'update_attribute':
                body = (
                    <>
                        <Field label={t('flows.config.attributeKey')} htmlFor={id('key')}>
                            <Input id={id('key')} maxLength={40} placeholder="interes_principal" value={str(c.key)} disabled={readOnly} onChange={(e) => set({ key: e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, '_') })} />
                        </Field>
                        <Field label={t('flows.config.attributeValue')} htmlFor={id('value')}>
                            <Input id={id('value')} maxLength={500} value={str(c.value)} disabled={readOnly} onChange={(e) => set({ value: e.target.value })} />
                        </Field>
                    </>
                );
                break;
            case 'notify_team':
                body = (
                    <>
                        <Field label={t('flows.config.notifyChannel')} htmlFor={id('channel')}>
                            <Select id={id('channel')} value={str(c.channel, 'email')} disabled={readOnly} onChange={(e) => set({ channel: e.target.value })}>
                                <option value="email">{t('flows.channels.email')}</option>
                                <option value="whatsapp">{t('flows.channels.whatsapp')}</option>
                            </Select>
                        </Field>
                        {str(c.channel, 'email') === 'email' ? (
                            <label className="igr:flex igr:items-center igr:gap-2 igr:text-[13px]">
                                <input type="checkbox" checked={c.to_owners !== false} disabled={readOnly} onChange={(e) => set({ to_owners: e.target.checked })} />
                                {t('flows.config.toOwners')}
                            </label>
                        ) : null}
                        <Field label={t('flows.config.recipients')} htmlFor={id('recipients')}>
                            <Input id={id('recipients')} value={list(c.recipients).join(', ')} disabled={readOnly} onChange={(e) => set({ recipients: csv(e.target.value) })} />
                        </Field>
                        <Field label={t('flows.config.message')} htmlFor={id('message')}>
                            <Textarea id={id('message')} className="igr:min-h-20" maxLength={500} value={str(c.message)} disabled={readOnly} onChange={(e) => set({ message: e.target.value })} />
                        </Field>
                    </>
                );
                break;
            case 'give_points':
                body = (
                    <Field label={t('loyalty.flowNode.points')} htmlFor={id('points')} help={t('loyalty.flowNode.pointsHelp')}>
                        <Input id={id('points')} type="number" min={1} max={100000} className="igr:w-32" value={str(c.points, '')} disabled={readOnly} onChange={(e) => set({ points: Math.max(0, Math.min(100000, Math.round(Number(e.target.value) || 0))) })} />
                    </Field>
                );
                break;
            case 'give_wallet':
                body = (
                    <Field label={t('loyalty.flowNode.amount', { currency: workspace.currency })} htmlFor={id('amount')} help={t('loyalty.flowNode.amountHelp')}>
                        <Input id={id('amount')} inputMode="decimal" className="igr:w-36" value={str(c.amount)} disabled={readOnly} onChange={(e) => set({ amount: e.target.value.replace(',', '.').replace(/[^0-9.]/g, '') })} />
                    </Field>
                );
                break;
            case 'webhook':
                body = (
                    <>
                        <Field label={t('flows.config.url')} htmlFor={id('url')}>
                            <Input id={id('url')} type="url" placeholder="https://" value={str(c.url)} disabled={readOnly} onChange={(e) => set({ url: e.target.value.trim() })} />
                        </Field>
                        {webhookSecret ? (
                            <Field label={t('flows.config.secret')}>
                                <code className="igr:block igr:break-all igr:rounded-md igr:bg-muted igr:px-2 igr:py-1 igr:text-[11.5px]">{webhookSecret}</code>
                            </Field>
                        ) : null}
                    </>
                );
                break;
            case 'exit':
                body = <p className="igr:text-[13px] igr:text-muted-foreground">{t('flows.config.exitHelp')}</p>;
                break;
        }
    }

    return (
        <div className="igr:space-y-3">
            <div className="igr:flex igr:items-center igr:justify-between igr:gap-2">
                <h2 className="igr:text-[13px] igr:font-semibold">{t(`flows.nodes.${node.type}`, { defaultValue: node.type })}</h2>
                {!readOnly ? (
                    <Button variant="ghost" size="sm" onClick={onDelete}>
                        <Trash2 />
                        {t('flows.builder.deleteNode')}
                    </Button>
                ) : null}
            </div>
            {issues.length > 0 ? (
                <ul className="igr:space-y-1 igr:rounded-md igr:border igr:border-destructive/40 igr:bg-destructive/5 igr:p-2 igr:text-xs igr:text-destructive">
                    {issues.map((m) => (
                        <li key={m} className="igr:flex igr:gap-1.5">
                            <CircleAlert className="igr:mt-px igr:size-3.5 igr:shrink-0" aria-hidden />
                            {m}
                        </li>
                    ))}
                </ul>
            ) : null}
            {body}
        </div>
    );
}

export function EventSelect({ id, value, events, onChange, disabled }: { id?: string; value: string; events: string[]; onChange: (v: string) => void; disabled: boolean }) {
    const { t } = useTranslation();
    return (
        <Select id={id} value={value} disabled={disabled} onChange={(e) => onChange(e.target.value)}>
            <option value="">—</option>
            {events.map((ev) => (
                <option key={ev} value={ev}>
                    {t(`flows.events.${ev.replace(/\./g, '_')}`, { defaultValue: ev })}
                </option>
            ))}
        </Select>
    );
}

function ConditionConfig({ config, readOnly, onChange }: { config: Config; readOnly: boolean; onChange: (c: Config) => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const segmentOptions = useSegmentOptions(workspace.id);
    const mode = str(config.mode, 'segment');
    const definition = (config.definition ?? { op: 'and', conditions: [] }) as Group;
    const exitIfNo = config.exit_if_no === true;

    return (
        <>
            <Field label={t('flows.config.conditionMode')} htmlFor="cond-mode">
                <Select
                    id="cond-mode"
                    value={mode}
                    disabled={readOnly}
                    onChange={(e) =>
                        onChange(e.target.value === 'context' ? { mode: 'context', path: 'last_event.props.button_payload', operator: 'eq', value: '', exit_if_no: exitIfNo } : { mode: 'segment', definition: { op: 'and', conditions: [] }, exit_if_no: exitIfNo })
                    }
                >
                    <option value="segment">{t('flows.config.segmentMode')}</option>
                    <option value="context">{t('flows.config.contextMode')}</option>
                </Select>
            </Field>
            {mode === 'context' ? (
                <>
                    <Field label={t('flows.config.contextPath')} htmlFor="cond-path" help={t('flows.config.contextHelp')}>
                        <Input id="cond-path" value={str(config.path)} disabled={readOnly} onChange={(e) => onChange({ ...config, path: e.target.value.trim() })} />
                    </Field>
                    <div className="igr:grid igr:grid-cols-[auto_1fr] igr:gap-2">
                        <Select aria-label="operador" value={str(config.operator, 'eq')} disabled={readOnly} onChange={(e) => onChange({ ...config, operator: e.target.value })}>
                            {['eq', 'neq', 'gte', 'lte', 'contains', 'exists'].map((op) => (
                                <option key={op} value={op}>
                                    {t(`flows.operators.${op}`)}
                                </option>
                            ))}
                        </Select>
                        <Input aria-label={t('flows.config.filterValue')} value={str(config.value)} disabled={readOnly} onChange={(e) => onChange({ ...config, value: e.target.value })} />
                    </div>
                </>
            ) : segmentOptions.isPending ? (
                <Skeleton className="igr:h-24" />
            ) : segmentOptions.data ? (
                <BuilderContext.Provider value={{ ws: workspace.id, options: segmentOptions.data, readOnly, errors: {}, currency: workspace.currency, locale: workspace.locale }}>
                    <div className="igr:rounded-md igr:border igr:p-2">
                        {definition.conditions.length === 0 ? (
                            readOnly ? null : <AddConditionMenu onAdd={(type) => onChange({ ...config, definition: { ...definition, conditions: [defaultCondition(type)] } })} />
                        ) : (
                            <GroupEditor group={definition} path={[]} root={definition} setRoot={(g) => onChange({ ...config, definition: g })} />
                        )}
                    </div>
                </BuilderContext.Provider>
            ) : null}
            <label className="igr:flex igr:items-start igr:gap-2 igr:text-[13px]">
                <input type="checkbox" className="igr:mt-0.5" checked={exitIfNo} disabled={readOnly} onChange={(e) => onChange({ ...config, exit_if_no: e.target.checked })} />
                {t('flows.config.exitIfNo')}
            </label>
        </>
    );
}
