import { Plus, X } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { Input, Select } from '@/components/ui/input';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatPercent } from '@/lib/format';
import { consentsForFields, consentText, isPrizes, prizeOdds, slugKey } from '../model';
import type { ConsentChannel, ConsentConfig, CouponConfig, CouponKind, CouponSpec, OnsiteOptions, Prize, WidgetDraft, WidgetKind } from '../types';
import { Check, Field, MultiCheck, Section } from './fields';

const CHANNELS: ConsentChannel[] = ['whatsapp', 'email', 'sms'];
const COUPON_KINDS: CouponKind[] = ['percent', 'fixed_cart', 'free_shipping'];

/** Casillas de consentimiento por canal con el texto legal vigente, y doble opt-in por correo. */
export function ConsentEditor({ draft, options, onChange }: { draft: WidgetDraft; options: OnsiteOptions | undefined; onChange: (c: ConsentConfig) => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const config = draft.consent_config;
    const fields = draft.variants.flatMap((v) => [...v.content.fields, ...(v.content.step2?.fields ?? [])]);
    const orphan = config.channels.filter((c) => !consentsForFields([c], fields).length);

    return (
        <div className="igr:space-y-5">
            <Section title={t('onsite.consent.title')} description={t('onsite.consent.help')}>
                <MultiCheck values={CHANNELS} selected={config.channels} onChange={(channels) => onChange({ ...config, channels, double_opt_in: channels.includes('email') && config.double_opt_in })} label={(c) => t(`onsite.channels.${c}`)} />
                {orphan.length > 0 ? <p className="igr:text-xs igr:text-warning">{t('onsite.consent.orphan', { channels: orphan.map((c) => t(`onsite.channels.${c}`)).join(', ') })}</p> : null}
                <div className="igr:space-y-2">
                    {config.channels.map((c) => {
                        const text = consentText(options, c);
                        return (
                            <div key={c} className="igr:rounded-md igr:border igr:bg-muted/30 igr:p-3 igr:text-[13px]">
                                <p className="igr:mb-1 igr:text-xs igr:font-medium igr:text-muted-foreground">
                                    {t(`onsite.channels.${c}`)} · {text ? t('onsite.consent.version', { version: text.version }) : t('onsite.consent.noText')}
                                </p>
                                <p>{text?.label ?? '—'}</p>
                            </div>
                        );
                    })}
                </div>
                <p className="igr:text-xs igr:text-muted-foreground">
                    {t('onsite.consent.unchecked')}{' '}
                    <Link to={`/w/${workspace.id}/consentimiento`} className="igr:underline">
                        {t('onsite.consent.editTexts')}
                    </Link>
                </p>
            </Section>
            {config.channels.includes('email') ? (
                <Section title={t('onsite.consent.doi.title')}>
                    <Check checked={config.double_opt_in} onChange={(v) => onChange({ ...config, double_opt_in: v })} help={t('onsite.consent.doi.help')}>
                        {t('onsite.consent.doi.enable')}
                    </Check>
                    {config.double_opt_in ? (
                        <Field label={t('onsite.consent.doi.template')} help={t('onsite.consent.doi.templateHelp', { link: '{{ enlaces.confirmar }}' })}>
                            {(id) => (
                                <Select id={id} value={config.template_id ?? ''} onChange={(e) => onChange({ ...config, template_id: e.target.value || null })}>
                                    <option value="">{t('onsite.consent.doi.systemTemplate')}</option>
                                    {(options?.email_templates ?? []).map((tpl) => (
                                        <option key={tpl.id} value={tpl.id}>
                                            {tpl.name}
                                        </option>
                                    ))}
                                </Select>
                            )}
                        </Field>
                    ) : null}
                </Section>
            ) : null}
        </div>
    );
}

/** El regalo del widget (cupón único) o los premios de la ruleta con sus probabilidades. */
export function CouponEditor({ kind, coupon, onChange }: { kind: WidgetKind; coupon: CouponConfig; onChange: (c: CouponConfig) => void }) {
    const { t } = useTranslation();
    if (kind === 'referral_welcome') {
        return (
            <Section title={t('onsite.coupon.title')}>
                <p className="igr:text-[13px] igr:text-muted-foreground">{t('onsite.coupon.referral')}</p>
            </Section>
        );
    }
    if (isPrizes(coupon)) {
        return <PrizesEditor prizes={coupon.prizes} onChange={(prizes) => onChange({ prizes })} />;
    }
    return (
        <Section title={t('onsite.coupon.title')} description={t('onsite.coupon.help')}>
            <Check checked={coupon.enabled} onChange={(enabled) => onChange({ ...coupon, enabled })}>
                {t('onsite.coupon.enable')}
            </Check>
            {coupon.enabled ? <CouponFields spec={coupon} onChange={(spec) => onChange({ ...coupon, ...spec })} /> : null}
        </Section>
    );
}

function CouponFields({ spec, onChange }: { spec: CouponSpec; onChange: (s: CouponSpec) => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    return (
        <div className="igr:grid igr:gap-3 igr:sm:grid-cols-2 igr:lg:grid-cols-3">
            <Field label={t('onsite.coupon.kind')}>
                {(id) => (
                    <Select id={id} value={spec.kind} onChange={(e) => onChange({ ...spec, kind: e.target.value as CouponKind })}>
                        {COUPON_KINDS.map((k) => (
                            <option key={k} value={k}>
                                {t(`onsite.coupon.kinds.${k}`)}
                            </option>
                        ))}
                    </Select>
                )}
            </Field>
            {spec.kind !== 'free_shipping' ? (
                <Field label={spec.kind === 'percent' ? t('onsite.coupon.percent') : t('onsite.coupon.amount', { currency: workspace.currency })}>
                    {(id) => <Input id={id} type="number" min={0} max={spec.kind === 'percent' ? 100 : undefined} value={spec.amount} onChange={(e) => onChange({ ...spec, amount: e.target.value })} />}
                </Field>
            ) : null}
            <Field label={t('onsite.coupon.expires')}>
                {(id) => <Input id={id} type="number" min={1} max={365} value={spec.expires_in_days} onChange={(e) => onChange({ ...spec, expires_in_days: Number(e.target.value) })} />}
            </Field>
            <Field label={t('onsite.coupon.prefix')} help={t('onsite.coupon.prefixHelp')}>
                {(id) => <Input id={id} value={spec.prefix} maxLength={12} onChange={(e) => onChange({ ...spec, prefix: e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '') })} />}
            </Field>
            <Field label={t('onsite.coupon.minimum', { currency: workspace.currency })}>
                {(id) => <Input id={id} type="number" min={0} value={spec.minimum_amount ?? ''} onChange={(e) => onChange({ ...spec, minimum_amount: e.target.value === '' ? null : e.target.value })} />}
            </Field>
        </div>
    );
}

function PrizesEditor({ prizes, onChange }: { prizes: Prize[]; onChange: (p: Prize[]) => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const odds = prizeOdds(prizes);
    const set = (i: number, patch: Partial<Prize>) => onChange(prizes.map((p, j) => (j === i ? { ...p, ...patch } : p)));
    return (
        <Section title={t('onsite.coupon.prizes.title')} description={t('onsite.coupon.prizes.help')}>
            <div className="igr:space-y-3">
                {prizes.map((p, i) => (
                    <div key={i} className="igr:space-y-3 igr:rounded-md igr:border igr:p-3">
                        <div className="igr:grid igr:grid-cols-[1fr_100px_auto] igr:items-end igr:gap-2">
                            <Field label={t('onsite.coupon.prizes.label')}>{(id) => <Input id={id} value={p.label} maxLength={40} onChange={(e) => set(i, { label: e.target.value })} />}</Field>
                            <Field label={t('onsite.coupon.prizes.weight')}>
                                {(id) => <Input id={id} type="number" min={0} max={1000} value={p.weight} onChange={(e) => set(i, { weight: Number(e.target.value) })} />}
                            </Field>
                            <Button type="button" variant="ghost" size="icon" aria-label={t('onsite.coupon.prizes.remove')} onClick={() => onChange(prizes.filter((_, j) => j !== i))} disabled={prizes.length <= 2}>
                                <X />
                            </Button>
                        </div>
                        <p className="igr:text-xs igr:text-muted-foreground">{t('onsite.coupon.prizes.odds', { value: formatPercent(odds[i] ?? 0, workspace.locale, 1) })}</p>
                        <Check
                            checked={p.coupon !== null}
                            onChange={(on) => set(i, { coupon: on ? { kind: 'percent', amount: '10', expires_in_days: 3, prefix: 'RULETA', minimum_amount: null } : null })}
                            help={t('onsite.coupon.prizes.noCouponHelp')}
                        >
                            {t('onsite.coupon.prizes.givesCoupon')}
                        </Check>
                        {p.coupon ? <CouponFields spec={p.coupon} onChange={(coupon) => set(i, { coupon })} /> : null}
                    </div>
                ))}
            </div>
            {prizes.length < 12 ? (
                <Button type="button" variant="outline" size="sm" onClick={() => onChange([...prizes, { key: slugKey(`premio ${prizes.length + 1}`, `p${prizes.length + 1}`), label: '', weight: 10, coupon: null }])}>
                    <Plus />
                    {t('onsite.coupon.prizes.add')}
                </Button>
            ) : null}
        </Section>
    );
}
