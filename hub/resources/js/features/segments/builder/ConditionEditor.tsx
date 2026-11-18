import { CalendarDays, Gift, MessageSquare, MousePointerClick, Plus, Shapes, ShieldCheck, ShoppingBag, ShoppingCart, Sparkles, Target, Trash2, UserRound, X } from 'lucide-react';
import type { TFunction } from 'i18next';
import type { LucideIcon } from 'lucide-react';
import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Input, Select } from '@/components/ui/input';
import { cn } from '@/lib/cn';
import type { Path } from '../ast';
import type {
    AttributeCondition,
    CartCondition,
    Condition,
    ConditionType,
    ConsentCondition,
    EngagementCondition,
    EventCondition,
    EventFilter,
    FieldOption,
    LeadCondition,
    ServiceCondition,
    LoyaltyCondition,
    MessageAction,
    MessageChannel,
    MessagingCondition,
    PredictionCondition,
    PurchaseAggregateCondition,
    PurchaseItemsCondition,
    PurchaseScope,
    SegmentRefCondition,
} from '../types';
import { errorsFor, useBuilder } from './context';
import { CountEditor, defaultValue, OperatorValue, WindowEditor } from './fields';
import { CatalogPicker, ChipsInput, SimpleSelect, ToggleChips } from './inputs';

export const TYPE_ICONS: Record<ConditionType, LucideIcon> = {
    attribute: UserRound,
    purchase: ShoppingBag,
    event: MousePointerClick,
    cart: ShoppingCart,
    prediction: Sparkles,
    consent: ShieldCheck,
    segment: Shapes,
    messaging: MessageSquare,
    loyalty: Gift,
    lead: Target,
    service: CalendarDays,
};

/** Predicciones que llegan con la Fase 4: se pueden usar, pero hoy no tienen datos. */
const PHASE4_FIELDS = ['churn_band', 'churn_risk', 'predicted_clv_12m', 'expected_next_order_at'];

export function ConditionEditor({ condition, path, onChange, onRemove }: { condition: Condition; path: Path; onChange: (c: Condition) => void; onRemove: () => void }) {
    const { t } = useTranslation();
    const { errors, readOnly } = useBuilder();
    const Icon = TYPE_ICONS[condition.type];
    const messages = errorsFor(errors, path);

    return (
        <div className={cn('igr:group igr:rounded-md igr:border igr:bg-background igr:p-2.5', messages.length > 0 && 'igr:border-destructive/50')}>
            <div className="igr:flex igr:items-start igr:gap-2">
                <span className="igr:mt-1.5 igr:flex igr:w-24 igr:shrink-0 igr:items-center igr:gap-1.5 igr:text-xs igr:font-medium igr:text-muted-foreground">
                    <Icon className="igr:size-3.5" />
                    {t(`segments.types.${condition.type}`)}
                </span>
                <div className="igr:flex igr:min-w-0 igr:flex-1 igr:flex-wrap igr:items-center igr:gap-1.5">
                    <Body condition={condition} onChange={onChange} />
                </div>
                {readOnly ? null : (
                    <Button variant="ghost" size="icon" className="igr:size-7 igr:shrink-0 igr:text-muted-foreground" onClick={onRemove} aria-label={t('segments.builder.removeCondition')}>
                        <Trash2 />
                    </Button>
                )}
            </div>
            {messages.length > 0 ? (
                <ul className="igr:mt-1.5 igr:pl-26 igr:text-xs igr:text-destructive">
                    {messages.map((m) => (
                        <li key={m}>{m}</li>
                    ))}
                </ul>
            ) : null}
        </div>
    );
}

function Body({ condition: c, onChange }: { condition: Condition; onChange: (c: Condition) => void }) {
    switch (c.type) {
        case 'attribute':
            return <AttributeEditor c={c} onChange={onChange} />;
        case 'purchase':
            return <PurchaseEditor c={c} onChange={onChange} />;
        case 'event':
            return <EventEditor c={c} onChange={onChange} />;
        case 'cart':
            return <CartEditor c={c} onChange={onChange} />;
        case 'prediction':
            return <PredictionEditor c={c} onChange={onChange} />;
        case 'consent':
            return <ConsentEditor c={c} onChange={onChange} />;
        case 'segment':
            return <SegmentRefEditor c={c} onChange={onChange} />;
        case 'messaging':
            return c.action === 'engagement' ? <EngagementEditor c={c} onChange={onChange} /> : <MessagingEditor c={c} onChange={onChange} />;
        case 'loyalty':
            return <LoyaltyEditor c={c} onChange={onChange} />;
        case 'lead':
            return <LeadEditor c={c} onChange={onChange} />;
        case 'service':
            return <ServiceEditor c={c} onChange={onChange} />;
    }
}

function firstOperator(option: FieldOption): string {
    for (const preferred of ['in', 'gte', 'within_last_days', 'contains_any', 'this_month', 'is_true']) {
        if (option.operators.includes(preferred)) {
            return preferred;
        }
    }
    return option.operators[0] ?? 'is_set';
}

function fieldLabel(t: TFunction, option: FieldOption): string {
    return option.key.startsWith('custom.') ? (option.label ?? option.key.slice(7)) : t(`segments.fields.${option.key}`);
}

function AttributeEditor({ c, onChange }: { c: AttributeCondition; onChange: (c: Condition) => void }) {
    const { t } = useTranslation();
    const { options, readOnly } = useBuilder();
    const all = [...options.attributes, ...options.custom_attributes];
    const option = all.find((f) => f.key === c.field);

    return (
        <>
            <Select
                className="igr:w-auto"
                aria-label={t('segments.builder.field')}
                disabled={readOnly}
                value={c.field}
                onChange={(e) => {
                    const next = all.find((f) => f.key === e.target.value);
                    if (next) {
                        const op = firstOperator(next);
                        onChange({ type: 'attribute', field: next.key, operator: op, value: defaultValue(next.type, op) });
                    }
                }}
            >
                <optgroup label={t('segments.builder.profile')}>
                    {options.attributes.map((f) => (
                        <option key={f.key} value={f.key}>
                            {fieldLabel(t, f)}
                        </option>
                    ))}
                </optgroup>
                {options.custom_attributes.length > 0 ? (
                    <optgroup label={t('segments.builder.custom')}>
                        {options.custom_attributes.map((f) => (
                            <option key={f.key} value={f.key}>
                                {fieldLabel(t, f)}
                            </option>
                        ))}
                    </optgroup>
                ) : null}
            </Select>
            {option ? <OperatorValue field={c.field} option={option} operator={c.operator} value={c.value} onChange={(next) => onChange({ ...c, ...next })} /> : null}
        </>
    );
}

const SCOPES: PurchaseScope[] = ['any', 'product', 'category', 'brand', 'coupon', 'payment_method'];

function PurchaseEditor({ c, onChange }: { c: PurchaseItemsCondition | PurchaseAggregateCondition; onChange: (c: Condition) => void }) {
    const { t } = useTranslation();
    const { options, readOnly } = useBuilder();
    const mode = 'scope' in c ? `scope:${c.scope}` : `field:${c.field}`;

    const changeMode = (value: string) => {
        const [kind, key = ''] = value.split(':');
        if (kind === 'scope') {
            const keep = 'scope' in c ? { operator: c.operator, value: c.value } : { operator: 'count_gte' as const, value: 1 };
            onChange({ type: 'purchase', scope: key as PurchaseScope, ids: [], ...keep, window: c.window ?? { kind: 'last_days', days: 90 } });
            return;
        }
        const option = options.purchase_fields.find((f) => f.key === key);
        if (option) {
            const op = firstOperator(option);
            onChange({ type: 'purchase', field: key, operator: op, value: defaultValue(option.type, op) });
        }
    };

    return (
        <>
            <Select className="igr:w-auto" aria-label={t('segments.builder.purchaseWhat')} disabled={readOnly} value={mode} onChange={(e) => changeMode(e.target.value)}>
                <optgroup label={t('segments.builder.bought')}>
                    {SCOPES.map((s) => (
                        <option key={s} value={`scope:${s}`}>
                            {t(`segments.scope.${s}`)}
                        </option>
                    ))}
                </optgroup>
                <optgroup label={t('segments.builder.totals')}>
                    {options.purchase_fields.map((f) => (
                        <option key={f.key} value={`field:${f.key}`}>
                            {t(`segments.fields.${f.key}`)}
                        </option>
                    ))}
                </optgroup>
            </Select>
            {'scope' in c ? <PurchaseItems c={c} onChange={onChange} /> : <PurchaseAggregate c={c} onChange={onChange} />}
        </>
    );
}

function PurchaseItems({ c, onChange }: { c: PurchaseItemsCondition; onChange: (c: Condition) => void }) {
    const { t } = useTranslation();
    const { options, readOnly } = useBuilder();
    const setIds = (ids: string[]) => onChange({ ...c, ids });
    const methods = options.values.payment_method;

    return (
        <>
            {c.scope === 'product' ? <CatalogPicker kind="products" ids={c.ids} onChange={setIds} /> : null}
            {c.scope === 'category' ? <CatalogPicker kind="categories" ids={c.ids} onChange={setIds} /> : null}
            {c.scope === 'brand' ? <ChipsInput label={t('segments.scope.brand')} disabled={readOnly} values={c.ids} onChange={setIds} suggestions={options.values.brand} /> : null}
            {c.scope === 'coupon' ? <ChipsInput label={t('segments.scope.coupon')} disabled={readOnly} values={c.ids} onChange={setIds} placeholder={t('segments.builder.anyCoupon')} /> : null}
            {c.scope === 'payment_method' ? (
                methods.length > 0 ? (
                    <ToggleChips label={t('segments.scope.payment_method')} disabled={readOnly} values={c.ids} onChange={setIds} options={methods.map((m) => ({ value: m.id, label: m.title ?? m.id }))} />
                ) : (
                    <ChipsInput label={t('segments.scope.payment_method')} disabled={readOnly} values={c.ids} onChange={setIds} />
                )
            ) : null}
            <CountEditor operator={c.operator} value={c.value} onChange={(next) => onChange({ ...c, operator: next.operator, value: next.value })} />
            <WindowEditor window={c.window} onChange={(w) => onChange({ ...c, window: w })} />
        </>
    );
}

function PurchaseAggregate({ c, onChange }: { c: PurchaseAggregateCondition; onChange: (c: Condition) => void }) {
    const { options } = useBuilder();
    const option = options.purchase_fields.find((f) => f.key === c.field);
    const windowed = c.field === 'orders_count' || c.field === 'total_spent';

    return (
        <>
            {option ? <OperatorValue field={c.field} option={option} operator={c.operator} value={c.value} onChange={(next) => onChange({ ...c, ...next })} /> : null}
            {windowed ? (
                <WindowEditor
                    window={c.window}
                    onChange={(w) => {
                        if (w.kind === 'ever') {
                            const rest: PurchaseAggregateCondition = { type: 'purchase', field: c.field, operator: c.operator, value: c.value };
                            onChange(rest);
                        } else {
                            onChange({ ...c, window: w });
                        }
                    }}
                />
            ) : null}
        </>
    );
}

const FILTER_OPS: EventFilter['operator'][] = ['eq', 'neq', 'in', 'contains'];
const COMMON_PROPS = ['category_id', 'query', 'cta_id', 'form_id', 'widget_id', 'coupon_code', 'payment_method'];

export function eventLabel(t: TFunction, event: string): string {
    return t(`segments.events.${event.replace(/\./g, '_')}`, { defaultValue: event });
}

function EventEditor({ c, onChange }: { c: EventCondition; onChange: (c: Condition) => void }) {
    const { t } = useTranslation();
    const { options, readOnly } = useBuilder();
    const propsId = useId();
    const setFilter = (i: number, f: EventFilter) => onChange({ ...c, filters: c.filters.map((x, j) => (j === i ? f : x)) });
    const unit = c.measure === 'events' ? 'times' : c.measure;

    return (
        <>
            <Select className="igr:w-auto" aria-label={t('segments.builder.event')} disabled={readOnly} value={c.event} onChange={(e) => onChange({ ...c, event: e.target.value })}>
                {options.events.map((e) => (
                    <option key={e} value={e}>
                        {eventLabel(t, e)}
                    </option>
                ))}
            </Select>
            <CountEditor operator={c.operator} value={c.value} unit={unit} onChange={(next) => onChange({ ...c, operator: next.operator, value: next.value })} />
            <SimpleSelect
                value={c.measure}
                label={t('segments.builder.measure')}
                disabled={readOnly}
                onChange={(m) => onChange({ ...c, measure: m })}
                options={(['events', 'sessions', 'days'] as const).map((m) => ({ value: m, label: t(`segments.measure.${m}`) }))}
            />
            <WindowEditor window={c.window} onChange={(w) => onChange({ ...c, window: w })} />
            <div className="igr:flex igr:w-full igr:flex-col igr:gap-1.5">
                {c.filters.map((f, i) => (
                    <div key={i} className="igr:flex igr:flex-wrap igr:items-center igr:gap-1.5">
                        <span className="igr:text-xs igr:text-muted-foreground">{i === 0 ? t('segments.builder.where') : t('segments.join.and')}</span>
                        <Input
                            className="igr:w-40"
                            list={propsId}
                            aria-label={t('segments.builder.property')}
                            placeholder={t('segments.builder.property')}
                            disabled={readOnly}
                            value={f.prop}
                            onChange={(e) => setFilter(i, { ...f, prop: e.target.value.trim().toLowerCase() })}
                        />
                        <SimpleSelect
                            value={f.operator}
                            label={t('segments.builder.operator')}
                            disabled={readOnly}
                            onChange={(op) => setFilter(i, { ...f, operator: op, value: op === 'in' ? (Array.isArray(f.value) ? f.value : f.value === '' ? [] : [f.value]) : Array.isArray(f.value) ? (f.value[0] ?? '') : f.value })}
                            options={FILTER_OPS.map((op) => ({ value: op, label: t(`segments.filterOp.${op}`) }))}
                        />
                        {f.operator === 'in' ? (
                            <ChipsInput label={t('segments.builder.value')} disabled={readOnly} values={Array.isArray(f.value) ? f.value : []} onChange={(v) => setFilter(i, { ...f, value: v })} />
                        ) : (
                            <Input className="igr:w-40" aria-label={t('segments.builder.value')} disabled={readOnly} value={Array.isArray(f.value) ? f.value.join(',') : f.value} onChange={(e) => setFilter(i, { ...f, value: e.target.value })} />
                        )}
                        {readOnly ? null : (
                            <Button variant="ghost" size="icon" className="igr:size-7" onClick={() => onChange({ ...c, filters: c.filters.filter((_, j) => j !== i) })} aria-label={t('segments.builder.removeFilter')}>
                                <X />
                            </Button>
                        )}
                    </div>
                ))}
                {readOnly || c.filters.length >= 5 ? null : (
                    <div>
                        <Button variant="ghost" size="sm" className="igr:text-muted-foreground" onClick={() => onChange({ ...c, filters: [...c.filters, { prop: 'product_id', operator: 'eq', value: '' }] })}>
                            <Plus />
                            {t('segments.builder.addFilter')}
                        </Button>
                    </div>
                )}
                <datalist id={propsId}>
                    {[...options.event_columns, ...COMMON_PROPS].map((p) => (
                        <option key={p} value={p} />
                    ))}
                </datalist>
            </div>
        </>
    );
}

function CartEditor({ c, onChange }: { c: CartCondition; onChange: (c: Condition) => void }) {
    const { t } = useTranslation();
    return (
        <>
            <span className="igr:text-[13px]">{t('segments.builder.cartAbandoned')}</span>
            <WindowEditor window={c.window} allowEver={false} onChange={(w) => onChange({ ...c, window: w })} />
        </>
    );
}

function PredictionEditor({ c, onChange }: { c: PredictionCondition; onChange: (c: Condition) => void }) {
    const { t } = useTranslation();
    const { options, readOnly } = useBuilder();
    const option = options.predictions.find((f) => f.key === c.field);

    return (
        <>
            <Select
                className="igr:w-auto"
                aria-label={t('segments.builder.field')}
                disabled={readOnly}
                value={c.field}
                onChange={(e) => {
                    const next = options.predictions.find((f) => f.key === e.target.value);
                    if (next) {
                        const op = firstOperator(next);
                        onChange({ type: 'prediction', field: next.key, operator: op, value: defaultValue(next.type, op) });
                    }
                }}
            >
                {options.predictions.map((f) => (
                    <option key={f.key} value={f.key}>
                        {PHASE4_FIELDS.includes(f.key) ? `${t(`segments.fields.${f.key}`)} · ${t('segments.builder.phase', { phase: 4 })}` : t(`segments.fields.${f.key}`)}
                    </option>
                ))}
            </Select>
            {option ? <OperatorValue field={c.field} option={option} operator={c.operator} value={c.value} onChange={(next) => onChange({ ...c, ...next })} /> : null}
            {PHASE4_FIELDS.includes(c.field) ? <p className="igr:w-full igr:text-xs igr:text-muted-foreground">{t('segments.builder.phase4Hint')}</p> : null}
        </>
    );
}

function ConsentEditor({ c, onChange }: { c: ConsentCondition; onChange: (c: Condition) => void }) {
    const { t } = useTranslation();
    const { readOnly } = useBuilder();
    return (
        <>
            <SimpleSelect value={c.status} label={t('segments.builder.status')} disabled={readOnly} onChange={(status) => onChange({ ...c, status })} options={(['granted', 'not_granted'] as const).map((s) => ({ value: s, label: t(`segments.consent.${s}`) }))} />
            <SimpleSelect value={c.purpose} label={t('segments.builder.purpose')} disabled={readOnly} onChange={(purpose) => onChange({ ...c, purpose })} options={(['marketing', 'transactional'] as const).map((p) => ({ value: p, label: t(`segments.purpose.${p}`) }))} />
            <span className="igr:text-xs igr:text-muted-foreground">{t('segments.builder.by')}</span>
            <SimpleSelect value={c.channel} label={t('segments.builder.channel')} disabled={readOnly} onChange={(channel) => onChange({ ...c, channel })} options={(['whatsapp', 'email', 'sms'] as const).map((ch) => ({ value: ch, label: t(`segments.channels.${ch}`) }))} />
        </>
    );
}

function SegmentRefEditor({ c, onChange }: { c: SegmentRefCondition; onChange: (c: Condition) => void }) {
    const { t } = useTranslation();
    const { options, readOnly, selfId } = useBuilder();
    const others = options.segments.filter((s) => s.id !== selfId);
    return (
        <>
            <SimpleSelect value={c.operator} label={t('segments.builder.operator')} disabled={readOnly} onChange={(operator) => onChange({ ...c, operator })} options={(['in', 'not_in'] as const).map((op) => ({ value: op, label: t(`segments.segmentOp.${op}`) }))} />
            <SimpleSelect
                value={c.segment_id}
                label={t('segments.types.segment')}
                disabled={readOnly}
                onChange={(segment_id) => onChange({ ...c, segment_id })}
                options={[{ value: '', label: t('segments.builder.chooseSegment'), disabled: true }, ...others.map((s) => ({ value: s.id, label: s.name }))]}
            />
        </>
    );
}

const MESSAGE_ACTIONS: MessageAction[] = ['received', 'opened', 'read', 'clicked', 'replied'];
const ENGAGEMENT_OPTION: FieldOption = { key: 'engagement', type: 'number', operators: ['gte', 'gt', 'lte', 'lt', 'eq', 'between'], values: null };

/** Qué hizo con los mensajes, o el puntaje de interacción (cambia la forma de la condición). */
function MessagingAction({ action, onChange }: { action: MessageAction | 'engagement'; onChange: (c: Condition) => void }) {
    const { t } = useTranslation();
    const { readOnly } = useBuilder();
    const change = (next: string) => {
        if (next === 'engagement') {
            onChange({ type: 'messaging', action: 'engagement', operator: 'gte', value: 5 });
            return;
        }
        const a = next as MessageAction;
        // Abrir es del correo y leer, de WhatsApp: el canal se ajusta solo.
        const channel: MessageChannel = a === 'opened' ? 'email' : a === 'read' ? 'whatsapp' : 'any';
        onChange({ type: 'messaging', action: a, channel, kind: 'marketing', operator: 'ever', window: { kind: 'last_days', days: 30 } });
    };
    return (
        <SimpleSelect
            value={action}
            label={t('segments.builder.messageAction')}
            disabled={readOnly}
            onChange={change}
            options={[...MESSAGE_ACTIONS, 'engagement' as const].map((a) => ({ value: a, label: t(`segments.msgAction.${a}`) }))}
        />
    );
}

function MessagingEditor({ c, onChange }: { c: MessagingCondition; onChange: (c: Condition) => void }) {
    const { t } = useTranslation();
    const { options, readOnly } = useBuilder();
    const channels: MessageChannel[] = c.action === 'opened' ? ['email'] : c.action === 'read' ? ['whatsapp'] : options.message_channels;
    const source = c.flow_id ? `flow:${c.flow_id}` : c.campaign_id ? `campaign:${c.campaign_id}` : '';
    const changeSource = (value: string) => {
        const rest: MessagingCondition = { ...c };
        delete rest.flow_id;
        delete rest.campaign_id;
        const [kind, id = ''] = value.split(':');
        onChange(kind === 'flow' ? { ...rest, flow_id: id } : kind === 'campaign' ? { ...rest, campaign_id: id } : rest);
    };

    return (
        <>
            <MessagingAction action={c.action} onChange={onChange} />
            <SimpleSelect value={c.channel} label={t('segments.builder.channel')} disabled={readOnly} onChange={(channel) => onChange({ ...c, channel })} options={channels.map((ch) => ({ value: ch, label: t(`segments.msgChannel.${ch}`) }))} />
            <SimpleSelect value={c.kind} label={t('segments.builder.messageKind')} disabled={readOnly} onChange={(kind) => onChange({ ...c, kind })} options={(['marketing', 'any'] as const).map((k) => ({ value: k, label: t(`segments.msgKind.${k}`) }))} />
            {options.flows.length > 0 || options.campaigns.length > 0 ? (
                <Select className="igr:w-auto igr:max-w-56" aria-label={t('segments.builder.messageSource')} disabled={readOnly} value={source} onChange={(e) => changeSource(e.target.value)}>
                    <option value="">{t('segments.builder.anySource')}</option>
                    {options.flows.length > 0 ? (
                        <optgroup label={t('segments.builder.flows')}>
                            {options.flows.map((f) => (
                                <option key={f.id} value={`flow:${f.id}`}>
                                    {f.name}
                                </option>
                            ))}
                        </optgroup>
                    ) : null}
                    {options.campaigns.length > 0 ? (
                        <optgroup label={t('segments.builder.campaigns')}>
                            {options.campaigns.map((x) => (
                                <option key={x.id} value={`campaign:${x.id}`}>
                                    {x.name}
                                </option>
                            ))}
                        </optgroup>
                    ) : null}
                </Select>
            ) : null}
            <CountEditor operator={c.operator} value={c.value} onChange={(next) => onChange({ ...c, operator: next.operator, value: next.value })} />
            <WindowEditor window={c.window} onChange={(w) => onChange({ ...c, window: w })} />
        </>
    );
}

function EngagementEditor({ c, onChange }: { c: EngagementCondition; onChange: (c: Condition) => void }) {
    const { t } = useTranslation();
    const { options } = useBuilder();
    return (
        <>
            <MessagingAction action="engagement" onChange={onChange} />
            <OperatorValue field="engagement" option={ENGAGEMENT_OPTION} operator={c.operator} value={c.value} onChange={(next) => onChange({ ...c, ...next })} />
            <p className="igr:w-full igr:text-xs igr:text-muted-foreground">{t('segments.builder.engagementHint', { days: options.engagement_days })}</p>
        </>
    );
}

function LoyaltyEditor({ c, onChange }: { c: LoyaltyCondition; onChange: (c: Condition) => void }) {
    const { t } = useTranslation();
    const { options, readOnly } = useBuilder();
    const option = options.loyalty_fields.find((f) => f.key === c.field);
    const tiers = options.loyalty_tiers;

    const changeField = (key: string) => {
        const next = options.loyalty_fields.find((f) => f.key === key);
        if (!next) {
            return;
        }
        if (next.type === 'tier') {
            onChange({ type: 'loyalty', field: key, operator: 'in', value: [] });
        } else if (next.type === 'expiring') {
            onChange({ type: 'loyalty', field: key, operator: 'within_next_days', value: 15 });
        } else {
            const op = firstOperator(next);
            onChange({ type: 'loyalty', field: key, operator: op, value: defaultValue(next.type, op) });
        }
    };

    let body = null;
    if (option?.type === 'tier') {
        const list = Array.isArray(c.value) ? c.value.map(String) : [];
        body =
            tiers.length === 0 ? (
                <span className="igr:text-xs igr:text-muted-foreground">{t('segments.builder.noTiers')}</span>
            ) : (
                <>
                    <SimpleSelect
                        value={c.operator}
                        label={t('segments.builder.operator')}
                        disabled={readOnly}
                        onChange={(op) => onChange({ ...c, operator: op, value: op === 'at_least' ? '' : op === 'in' || op === 'not_in' ? [] : undefined })}
                        options={option.operators.map((op) => ({ value: op, label: t(`segments.tierOps.${op}`) }))}
                    />
                    {c.operator === 'in' || c.operator === 'not_in' ? (
                        <ToggleChips label={t('segments.fields.tier')} disabled={readOnly} values={list} onChange={(value) => onChange({ ...c, value })} options={tiers.map((x) => ({ value: x.id, label: x.name }))} />
                    ) : null}
                    {c.operator === 'at_least' ? (
                        <SimpleSelect
                            value={typeof c.value === 'string' ? c.value : ''}
                            label={t('segments.fields.tier')}
                            disabled={readOnly}
                            onChange={(value) => onChange({ ...c, value })}
                            options={[{ value: '', label: t('segments.builder.chooseTier'), disabled: true }, ...tiers.map((x) => ({ value: x.id, label: x.name }))]}
                        />
                    ) : null}
                </>
            );
    } else if (option) {
        body = <OperatorValue field={c.field} option={option} operator={c.operator} value={c.value} onChange={(next) => onChange({ ...c, ...next })} />;
    }

    return (
        <>
            <SimpleSelect value={c.field} label={t('segments.builder.field')} disabled={readOnly} onChange={changeField} options={options.loyalty_fields.map((f) => ({ value: f.key, label: t(`segments.fields.${f.key}`) }))} />
            {body}
        </>
    );
}

function LeadEditor({ c, onChange }: { c: LeadCondition; onChange: (c: Condition) => void }) {
    const { t } = useTranslation();
    const { options, readOnly } = useBuilder();
    const option = options.lead_fields.find((f) => f.key === c.field);

    const changeField = (key: string) => {
        const next = options.lead_fields.find((f) => f.key === key);
        if (next) {
            const op = firstOperator(next);
            onChange({ type: 'lead', field: key, operator: op, value: defaultValue(next.type, op) });
        }
    };

    return (
        <>
            <SimpleSelect value={c.field} label={t('segments.builder.field')} disabled={readOnly} onChange={changeField} options={options.lead_fields.map((f) => ({ value: f.key, label: t(`segments.leadFields.${f.key}`) }))} />
            {option ? <OperatorValue field={c.field} option={option} operator={c.operator} value={c.value} onChange={(next) => onChange({ ...c, ...next })} /> : null}
        </>
    );
}

/** Citas (M18): el mismo patrón que los leads, sobre las citas del contacto. */
function ServiceEditor({ c, onChange }: { c: ServiceCondition; onChange: (c: Condition) => void }) {
    const { t } = useTranslation();
    const { options, readOnly } = useBuilder();
    const fields = options.service_fields;
    const option = fields.find((f) => f.key === c.field);

    const changeField = (key: string) => {
        const next = fields.find((f) => f.key === key);
        if (next) {
            const op = firstOperator(next);
            onChange({ type: 'service', field: key, operator: op, value: defaultValue(next.type, op) });
        }
    };

    return (
        <>
            <SimpleSelect value={c.field} label={t('segments.builder.field')} disabled={readOnly} onChange={changeField} options={fields.map((f) => ({ value: f.key, label: t(`segments.serviceFields.${f.key}`) }))} />
            {option ? <OperatorValue field={c.field} option={option} operator={c.operator} value={c.value} onChange={(next) => onChange({ ...c, ...next })} /> : null}
        </>
    );
}
