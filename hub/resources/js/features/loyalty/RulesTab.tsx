import { Plus, Trash2 } from 'lucide-react';
import { type ReactNode, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input, Select } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { FieldError } from '@/components/ui/misc';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { formatMoney, formatNumber } from '@/lib/format';
import { toast } from '@/stores/toast';
import { errorMessage } from '@/features/contacts/errors';
import { useLoyaltyMutations } from './api';
import { type ProgramDraft, pointsFor, programProblems, toProgramDraft, toProgramPayload } from './program';
import { FIXED_REASONS, type LoyaltyOverview } from './types';

const digits = (v: string) => v.replace(/[^0-9.,]/g, '');

function NumberField({ id, label, value, onChange, readOnly, suffix, help, error, width = 'igr:w-28' }: { id: string; label: string; value: string; onChange: (v: string) => void; readOnly: boolean; suffix?: string; help?: ReactNode; error?: string; width?: string }) {
    return (
        <div className="igr:space-y-1">
            <Label htmlFor={id}>{label}</Label>
            <div className="igr:flex igr:items-center igr:gap-2">
                <Input id={id} inputMode="decimal" className={width} value={value} readOnly={readOnly} aria-invalid={error ? true : undefined} onChange={(e) => onChange(digits(e.target.value))} />
                {suffix ? <span className="igr:text-[13px] igr:text-muted-foreground">{suffix}</span> : null}
            </div>
            {help ? <p className="igr:text-xs igr:text-muted-foreground">{help}</p> : null}
            <FieldError message={error} />
        </div>
    );
}

/** Reglas del programa: cómo se gana, campañas de puntos extra, canje, vencimiento, devolución y cashback. */
export function RulesTab({ data }: { data: LoyaltyOverview }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canEdit = useCan('manageMarketing');
    const { saveProgram } = useLoyaltyMutations(workspace.id);
    const [draft, setDraft] = useState<ProgramDraft>(() => toProgramDraft(data.program));
    const [showErrors, setShowErrors] = useState(false);
    const problems = programProblems(draft);
    const server = isApiError(saveProgram.error) ? saveProgram.error.fieldErrors : {};
    const set = (patch: Partial<ProgramDraft>) => setDraft({ ...draft, ...patch });
    const readOnly = !canEdit;
    const money = (v: number) => formatMoney(v, data.currency, workspace.locale);
    const err = (key: string, local?: string) => (showErrors && local ? local : server[key]);

    const save = () => {
        if (problems.length > 0) {
            setShowErrors(true);
            return;
        }
        saveProgram.mutate(toProgramPayload(draft), {
            onSuccess: (saved) => {
                setDraft(toProgramDraft(saved.program));
                setShowErrors(false);
                toast.success(t('loyalty.rules.saved'));
            },
            onError: (error) => toast.error(errorMessage(error, t)),
        });
    };

    return (
        <div className="igr:space-y-4">
            <Card>
                <CardHeader>
                    <CardTitle>{t('loyalty.rules.earnTitle')}</CardTitle>
                    <CardDescription>{t('loyalty.rules.earnHelp')}</CardDescription>
                </CardHeader>
                <CardContent className="igr:space-y-4">
                    <div className="igr:space-y-1">
                        <Label htmlFor="lr-name">{t('loyalty.rules.currencyName')}</Label>
                        <Input id="lr-name" className="igr:max-w-sm" maxLength={60} value={draft.currency_name} readOnly={readOnly} onChange={(e) => set({ currency_name: e.target.value })} />
                        <FieldError message={err('currency_name', problems.includes('currency_name') ? t('loyalty.rules.errors.currencyName') : undefined)} />
                    </div>
                    <div className="igr:flex igr:flex-wrap igr:items-start igr:gap-4">
                        <NumberField id="lr-points" label={t('loyalty.rules.points')} value={draft.points_per} readOnly={readOnly} onChange={(v) => set({ points_per: v })} error={err('earn_rules.per_amount.points')} />
                        <NumberField id="lr-amount" label={t('loyalty.rules.perAmount', { currency: data.currency })} value={draft.amount_per} readOnly={readOnly} onChange={(v) => set({ amount_per: v })} width="igr:w-36" error={err('earn_rules.per_amount.amount', problems.includes('amount_per') ? t('loyalty.rules.errors.amount') : undefined)} />
                        <div className="igr:space-y-1">
                            <Label htmlFor="lr-basis">{t('loyalty.rules.basis')}</Label>
                            <Select id="lr-basis" value={draft.basis} disabled={readOnly} onChange={(e) => set({ basis: e.target.value === 'total' ? 'total' : 'without_shipping' })}>
                                <option value="without_shipping">{t('loyalty.rules.basisWithoutShipping')}</option>
                                <option value="total">{t('loyalty.rules.basisTotal')}</option>
                            </Select>
                        </div>
                    </div>
                    <p className="igr:rounded-md igr:bg-muted/50 igr:p-3 igr:text-[13px]">
                        {t('loyalty.rules.example', { amount: money(100000), points: formatNumber(pointsFor(100000, draft), workspace.locale), name: draft.currency_name })}
                    </p>
                    <div className="igr:grid igr:gap-3 igr:sm:grid-cols-2 igr:lg:grid-cols-3">
                        {FIXED_REASONS.map((r) => (
                            <NumberField key={r} id={`lr-${r}`} label={t(`loyalty.rules.fixed.${r}`)} value={draft.fixed[r]} readOnly={readOnly} suffix={draft.currency_name} onChange={(v) => set({ fixed: { ...draft.fixed, [r]: v } })} />
                        ))}
                    </div>
                    <p className="igr:text-xs igr:text-muted-foreground">{t('loyalty.rules.fixedHelp')}</p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{t('loyalty.rules.multipliersTitle')}</CardTitle>
                    <CardDescription>{t('loyalty.rules.multipliersHelp')}</CardDescription>
                </CardHeader>
                <CardContent className="igr:space-y-4">
                    <fieldset className="igr:space-y-2">
                        <legend className="igr:text-[13px] igr:font-medium">{t('loyalty.rules.categories')}</legend>
                        {draft.category_multipliers.map((m, i) => (
                            <div key={i} className="igr:flex igr:items-center igr:gap-2">
                                <Select aria-label={t('loyalty.rules.category')} value={m.category_id} disabled={readOnly} onChange={(e) => set({ category_multipliers: draft.category_multipliers.map((x, j) => (j === i ? { ...x, category_id: e.target.value } : x)) })}>
                                    <option value="">{t('loyalty.rules.pickCategory')}</option>
                                    {data.categories.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.name}
                                        </option>
                                    ))}
                                </Select>
                                <span className="igr:text-[13px] igr:text-muted-foreground">×</span>
                                <Input aria-label={t('loyalty.rules.multiplier')} className="igr:w-20" inputMode="decimal" value={m.multiplier} readOnly={readOnly} onChange={(e) => set({ category_multipliers: draft.category_multipliers.map((x, j) => (j === i ? { ...x, multiplier: digits(e.target.value) } : x)) })} />
                                {!readOnly ? (
                                    <Button variant="ghost" size="icon" aria-label={t('loyalty.rules.remove')} onClick={() => set({ category_multipliers: draft.category_multipliers.filter((_, j) => j !== i) })}>
                                        <Trash2 />
                                    </Button>
                                ) : null}
                            </div>
                        ))}
                        {!readOnly ? (
                            <Button variant="outline" size="sm" disabled={data.categories.length === 0} onClick={() => set({ category_multipliers: [...draft.category_multipliers, { category_id: '', multiplier: '2' }] })}>
                                <Plus />
                                {t('loyalty.rules.addCategory')}
                            </Button>
                        ) : null}
                        {data.categories.length === 0 ? <p className="igr:text-xs igr:text-muted-foreground">{t('loyalty.rules.noCategories')}</p> : null}
                    </fieldset>
                    <fieldset className="igr:space-y-2">
                        <legend className="igr:text-[13px] igr:font-medium">{t('loyalty.rules.campaigns')}</legend>
                        {draft.campaigns.map((c, i) => (
                            <div key={i} className="igr:flex igr:flex-wrap igr:items-center igr:gap-2">
                                <Input aria-label={t('loyalty.rules.campaignName')} placeholder={t('loyalty.rules.campaignName')} className="igr:w-48" maxLength={80} value={c.name} readOnly={readOnly} onChange={(e) => set({ campaigns: draft.campaigns.map((x, j) => (j === i ? { ...x, name: e.target.value } : x)) })} />
                                <span className="igr:text-[13px] igr:text-muted-foreground">×</span>
                                <Input aria-label={t('loyalty.rules.multiplier')} className="igr:w-16" inputMode="decimal" value={c.multiplier} readOnly={readOnly} onChange={(e) => set({ campaigns: draft.campaigns.map((x, j) => (j === i ? { ...x, multiplier: digits(e.target.value) } : x)) })} />
                                <Input aria-label={t('loyalty.rules.from')} type="date" className="igr:w-40" value={c.starts_on} readOnly={readOnly} onChange={(e) => set({ campaigns: draft.campaigns.map((x, j) => (j === i ? { ...x, starts_on: e.target.value } : x)) })} />
                                <Input aria-label={t('loyalty.rules.to')} type="date" className="igr:w-40" value={c.ends_on} readOnly={readOnly} onChange={(e) => set({ campaigns: draft.campaigns.map((x, j) => (j === i ? { ...x, ends_on: e.target.value } : x)) })} />
                                {!readOnly ? (
                                    <Button variant="ghost" size="icon" aria-label={t('loyalty.rules.remove')} onClick={() => set({ campaigns: draft.campaigns.filter((_, j) => j !== i) })}>
                                        <Trash2 />
                                    </Button>
                                ) : null}
                                <FieldError message={err(`earn_rules.campaigns.${i}.ends_on`, problems.includes(`campaigns.${i}`) ? t('loyalty.rules.errors.campaign') : undefined)} />
                            </div>
                        ))}
                        {!readOnly ? (
                            <Button variant="outline" size="sm" onClick={() => set({ campaigns: [...draft.campaigns, { name: t('loyalty.rules.doublePoints'), multiplier: '2', starts_on: '', ends_on: '' }] })}>
                                <Plus />
                                {t('loyalty.rules.addCampaign')}
                            </Button>
                        ) : null}
                    </fieldset>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{t('loyalty.rules.conditionsTitle')}</CardTitle>
                    <CardDescription>{t('loyalty.rules.conditionsHelp')}</CardDescription>
                </CardHeader>
                <CardContent className="igr:grid igr:gap-4 igr:sm:grid-cols-2 igr:lg:grid-cols-3">
                    <NumberField id="lr-pending" label={t('loyalty.rules.pendingDays')} value={draft.pending_days} readOnly={readOnly} suffix={t('loyalty.rules.days')} onChange={(v) => set({ pending_days: v })} help={t('loyalty.rules.pendingHelp')} error={err('pending_days')} />
                    <NumberField id="lr-expiry" label={t('loyalty.rules.expiryMonths')} value={draft.expiry_months} readOnly={readOnly} suffix={t('loyalty.rules.months')} onChange={(v) => set({ expiry_months: v })} help={t('loyalty.rules.expiryHelp')} error={err('expiry_policy.months')} />
                    <NumberField id="lr-warning" label={t('loyalty.rules.warningDays')} value={draft.warning_days} readOnly={readOnly} suffix={t('loyalty.rules.days')} onChange={(v) => set({ warning_days: v })} help={t('loyalty.rules.warningHelp')} error={err('expiry_policy.warning_days')} />
                    <NumberField id="lr-min" label={t('loyalty.rules.minPoints')} value={draft.min_points} readOnly={readOnly} suffix={draft.currency_name} onChange={(v) => set({ min_points: v })} help={t('loyalty.rules.minPointsHelp')} error={err('redeem_rules.min_points')} />
                    <NumberField id="lr-coupon" label={t('loyalty.rules.couponDays')} value={draft.coupon_expiry_days} readOnly={readOnly} suffix={t('loyalty.rules.days')} onChange={(v) => set({ coupon_expiry_days: v })} help={t('loyalty.rules.couponDaysHelp')} error={err('redeem_rules.coupon_expiry_days')} />
                    <NumberField id="lr-cashback" label={t('loyalty.rules.cashback')} value={draft.cashback_pct} readOnly={readOnly} suffix="%" onChange={(v) => set({ cashback_pct: v })} help={t('loyalty.rules.cashbackHelp')} error={err('wallet_rules.cashback_pct')} />
                </CardContent>
            </Card>

            {canEdit ? (
                <div className="igr:flex igr:justify-end">
                    <Button onClick={save} loading={saveProgram.isPending}>
                        {t('loyalty.rules.save')}
                    </Button>
                </div>
            ) : null}
        </div>
    );
}
