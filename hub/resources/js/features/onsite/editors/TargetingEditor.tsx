import { useTranslation } from 'react-i18next';
import { Input, Select, Textarea } from '@/components/ui/input';
import { linesToList } from '../model';
import type { RulesSummary } from '../rules';
import type { OnsiteOptions, Targeting, TriggerType, WidgetKind } from '../types';
import { Check, Field, MultiCheck, Section } from './fields';

const COUNTRIES = ['CO', 'MX', 'VE', 'EC', 'PE', 'CL', 'AR', 'PA', 'CR', 'GT', 'DO', 'US', 'ES'];

/** Cuándo y a quién se muestra: disparador, páginas, dispositivos, origen, país, segmentos y frecuencia. */
export function TargetingEditor({
    kind,
    targeting,
    options,
    onChange,
    store = true,
    summary,
}: {
    kind: WidgetKind;
    targeting: Targeting;
    options: OnsiteOptions | undefined;
    onChange: (t: Targeting) => void;
    /** Sin tienda no hay carrito ni referidos: esas condiciones no se muestran. */
    store?: boolean;
    /** Las reglas en palabras, arriba de los controles (se actualizan en vivo). */
    summary?: RulesSummary;
}) {
    const { t } = useTranslation();
    const set = (patch: Partial<Targeting>) => onChange({ ...targeting, ...patch });
    const trigger = targeting.trigger;
    const embedded = kind === 'embedded_form';

    return (
        <div className="igr:space-y-5">
            {summary ? (
                <div className="igr:rounded-lg igr:bg-brand-soft/60 igr:p-4" aria-live="polite">
                    <p className="igr:text-[13px] igr:font-semibold igr:text-foreground">{summary.when}</p>
                    <ul className="igr:mt-1 igr:space-y-0.5 igr:text-support igr:text-muted-foreground">
                        {summary.conditions.map((c) => (
                            <li key={c}>· {c}</li>
                        ))}
                        {summary.frequency ? <li>· {summary.frequency}</li> : null}
                    </ul>
                </div>
            ) : null}
            {!embedded ? (
                <Section title={t('onsite.targeting.trigger.title')} description={t('onsite.targeting.trigger.help')}>
                    <div className="igr:grid igr:gap-3 igr:sm:grid-cols-2">
                        <Field label={t('onsite.targeting.trigger.type')}>
                            {(id) => (
                                <Select id={id} value={trigger.type} onChange={(e) => set({ trigger: { type: e.target.value as TriggerType, value: e.target.value === 'time' ? 8 : e.target.value === 'scroll' ? 50 : null } })}>
                                    {(options?.triggers ?? ['exit_intent', 'time', 'scroll', 'immediate', 'click']).map((tr) => (
                                        <option key={tr} value={tr}>
                                            {t(`onsite.targeting.trigger.types.${tr}`)}
                                        </option>
                                    ))}
                                </Select>
                            )}
                        </Field>
                        {trigger.type === 'time' || trigger.type === 'scroll' ? (
                            <Field label={trigger.type === 'time' ? t('onsite.targeting.trigger.seconds') : t('onsite.targeting.trigger.percent')}>
                                {(id) => (
                                    <Input id={id} type="number" min={trigger.type === 'time' ? 0 : 1} max={trigger.type === 'time' ? 600 : 100} value={Number(trigger.value ?? 0)} onChange={(e) => set({ trigger: { ...trigger, value: Number(e.target.value) } })} />
                                )}
                            </Field>
                        ) : null}
                        {trigger.type === 'click' ? (
                            <Field label={t('onsite.targeting.trigger.selector')} help={t('onsite.targeting.trigger.selectorHelp')}>
                                {(id) => <Input id={id} value={String(trigger.value ?? '')} placeholder=".abrir-quiz" onChange={(e) => set({ trigger: { ...trigger, value: e.target.value || null } })} />}
                            </Field>
                        ) : null}
                    </div>
                </Section>
            ) : null}

            <Section title={t('onsite.targeting.pages.title')} description={t('onsite.targeting.pages.help')}>
                <Field label={t('onsite.targeting.pages.types')} help={t('onsite.targeting.pages.typesHelp')}>
                    {() => <MultiCheck values={options?.page_types ?? []} selected={targeting.page_types} onChange={(page_types) => set({ page_types })} label={(v) => t(`onsite.pageTypes.${v}`)} />}
                </Field>
                <div className="igr:grid igr:gap-3 igr:sm:grid-cols-2">
                    <Field label={t('onsite.targeting.pages.include')} help={t('onsite.targeting.pages.patternHelp')}>
                        {(id) => <Textarea id={id} rows={3} defaultValue={targeting.pages.include.join('\n')} placeholder="/tienda/*" onBlur={(e) => set({ pages: { ...targeting.pages, include: linesToList(e.target.value) } })} />}
                    </Field>
                    <Field label={t('onsite.targeting.pages.exclude')}>
                        {(id) => <Textarea id={id} rows={3} defaultValue={targeting.pages.exclude.join('\n')} placeholder="/mi-cuenta*" onBlur={(e) => set({ pages: { ...targeting.pages, exclude: linesToList(e.target.value) } })} />}
                    </Field>
                </div>
            </Section>

            <Section title={t('onsite.targeting.audience.title')}>
                <Field label={t('onsite.targeting.audience.devices')} help={t('onsite.targeting.audience.allIfNone')}>
                    {() => <MultiCheck values={options?.devices ?? ['mobile', 'desktop', 'tablet']} selected={targeting.devices} onChange={(devices) => set({ devices })} label={(v) => t(`onsite.devices.${v}`)} />}
                </Field>
                <div className="igr:grid igr:gap-3 igr:sm:grid-cols-2">
                    <Field label={t('onsite.targeting.audience.visitors')}>
                        {(id) => (
                            <Select id={id} value={targeting.new_visitors === null ? 'all' : targeting.new_visitors ? 'new' : 'returning'} onChange={(e) => set({ new_visitors: e.target.value === 'all' ? null : e.target.value === 'new' })}>
                                <option value="all">{t('onsite.targeting.audience.visitorsAll')}</option>
                                <option value="new">{t('onsite.targeting.audience.visitorsNew')}</option>
                                <option value="returning">{t('onsite.targeting.audience.visitorsReturning')}</option>
                            </Select>
                        )}
                    </Field>
                    {store ? (
                        <Field label={t('onsite.targeting.audience.cart')} help={t('onsite.targeting.audience.cartHelp')}>
                            {(id) => <Input id={id} type="number" min={0} value={targeting.cart_value_min ?? ''} onChange={(e) => set({ cart_value_min: e.target.value === '' ? null : e.target.value })} />}
                        </Field>
                    ) : null}
                </div>
                <Field label={t('onsite.targeting.audience.countries')} help={t('onsite.targeting.audience.countriesHelp')}>
                    {() => <MultiCheck values={COUNTRIES} selected={targeting.countries} onChange={(countries) => set({ countries })} label={(v) => v} />}
                </Field>
                <div className="igr:grid igr:gap-3 igr:sm:grid-cols-3">
                    {(['source', 'medium', 'campaign'] as const).map((k) => (
                        <Field key={k} label={t(`onsite.targeting.audience.utm.${k}`)}>
                            {(id) => <Input id={id} value={targeting.utm[k] ?? ''} onChange={(e) => set({ utm: { ...targeting.utm, [k]: e.target.value } })} />}
                        </Field>
                    ))}
                </div>
                <Field label={t('onsite.targeting.audience.segments')} help={t('onsite.targeting.audience.segmentsHelp')}>
                    {() =>
                        (options?.segments ?? []).length === 0 ? (
                            <p className="igr:text-xs igr:text-muted-foreground">{t('onsite.targeting.audience.noSegments')}</p>
                        ) : (
                            <div className="igr:max-h-40 igr:space-y-1 igr:overflow-y-auto igr:rounded-md igr:border igr:p-2">
                                {(options?.segments ?? []).map((s) => (
                                    <Check key={s.id} checked={targeting.segments.includes(s.id)} onChange={(on) => set({ segments: on ? [...targeting.segments, s.id] : targeting.segments.filter((x) => x !== s.id) })}>
                                        {s.name} <span className="igr:text-xs igr:text-muted-foreground">({s.member_count})</span>
                                    </Check>
                                ))}
                            </div>
                        )
                    }
                </Field>
                <Check checked={targeting.hide_for_subscribers} onChange={(v) => set({ hide_for_subscribers: v })} help={t('onsite.targeting.audience.hideHelp')}>
                    {t('onsite.targeting.audience.hide')}
                </Check>
                {store || targeting.referral ? (
                    <Check checked={targeting.referral} onChange={(v) => set({ referral: v })} help={t('onsite.targeting.audience.referralHelp')}>
                        {t('onsite.targeting.audience.referral')}
                    </Check>
                ) : null}
            </Section>

            {!embedded ? (
                <Section title={t('onsite.targeting.frequency.title')} description={t('onsite.targeting.frequency.help')}>
                    <div className="igr:grid igr:gap-3 igr:sm:grid-cols-2">
                        <Field label={t('onsite.targeting.frequency.maxViews')} help={t('onsite.targeting.frequency.zero')}>
                            {(id) => <Input id={id} type="number" min={0} max={50} value={targeting.frequency.max_views} onChange={(e) => set({ frequency: { ...targeting.frequency, max_views: Number(e.target.value) } })} />}
                        </Field>
                        <Field label={t('onsite.targeting.frequency.cooldown')}>
                            {(id) => <Input id={id} type="number" min={0} max={365} value={targeting.frequency.cooldown_days} onChange={(e) => set({ frequency: { ...targeting.frequency, cooldown_days: Number(e.target.value) } })} />}
                        </Field>
                    </div>
                </Section>
            ) : null}
        </div>
    );
}
