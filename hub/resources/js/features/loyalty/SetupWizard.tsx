import { ArrowLeft, ArrowRight, Check, Gift } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { FieldError } from '@/components/ui/misc';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { formatMoney, formatNumber, formatPercent } from '@/lib/format';
import { cn } from '@/lib/cn';
import { toast } from '@/stores/toast';
import { errorMessage } from '@/features/contacts/errors';
import { useLoyaltyMutations } from './api';
import { type ProgramDraft, pointsFor, presetTiers, programProblems, type RewardDraft, rewardReturn, toProgramDraft, toProgramPayload, toRewardPayload, toTiersPayload } from './program';
import { FIXED_REASONS, type LoyaltyOverview } from './types';

const STEPS = ['basics', 'extras', 'reward', 'tiers', 'review'] as const;
type Step = (typeof STEPS)[number];

/** Asistente de 5 pasos para crear el programa (la primera vez que se entra a Fidelización). */
export function SetupWizard({ data }: { data: LoyaltyOverview }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageMarketing');
    const { saveProgram, createReward, saveTiers } = useLoyaltyMutations(workspace.id);
    const [step, setStep] = useState<Step>('basics');
    const [draft, setDraft] = useState<ProgramDraft>(() => ({ ...toProgramDraft(data.program), currency_name: t('loyalty.wizard.defaultName') }));
    const [reward, setReward] = useState<RewardDraft>({ name: t('loyalty.wizard.defaultReward', { amount: formatMoney(20000, data.currency, workspace.locale) }), kind: 'coupon_fixed', points_cost: '500', value: '20000', min_order: '', expires_in_days: '', product_id: '' });
    const [withTiers, setWithTiers] = useState(true);
    const [saving, setSaving] = useState(false);
    const problems = programProblems(draft);
    const index = STEPS.indexOf(step);
    const money = (v: number) => formatMoney(v, data.currency, workspace.locale);
    const example = 100000;
    const giveBack = rewardReturn(draft, reward);

    if (!canManage) {
        return (
            <Card>
                <CardContent className="igr:flex igr:items-center igr:gap-3 igr:py-6">
                    <Gift className="igr:size-5 igr:text-muted-foreground" />
                    <p className="igr:text-[13px] igr:text-muted-foreground">{t('loyalty.wizard.notConfigured')}</p>
                </CardContent>
            </Card>
        );
    }

    const finish = async (activate: boolean) => {
        setSaving(true);
        try {
            await saveProgram.mutateAsync(toProgramPayload(draft, 'draft'));
            if (reward.name.trim() !== '') {
                await createReward.mutateAsync(toRewardPayload(reward));
            }
            if (withTiers) {
                await saveTiers.mutateAsync(toTiersPayload(presetTiers('spend_12m', [t('loyalty.tiers.presetNames.0'), t('loyalty.tiers.presetNames.1'), t('loyalty.tiers.presetNames.2')]), 'spend_12m'));
            }
            if (activate) {
                await saveProgram.mutateAsync(toProgramPayload(draft, 'active'));
            }
            toast.success(t(activate ? 'loyalty.wizard.activated' : 'loyalty.wizard.savedDraft'));
        } catch (error) {
            toast.error(errorMessage(error, t));
        } finally {
            setSaving(false);
        }
    };

    const num = (id: string, label: string, value: string, onChange: (v: string) => void, help?: string, suffix?: string) => (
        <div className="igr:space-y-1">
            <Label htmlFor={id}>{label}</Label>
            <div className="igr:flex igr:items-center igr:gap-2">
                <Input id={id} inputMode="decimal" className="igr:w-32" value={value} onChange={(e) => onChange(e.target.value.replace(/[^0-9.,]/g, ''))} />
                {suffix ? <span className="igr:text-[13px] igr:text-muted-foreground">{suffix}</span> : null}
            </div>
            {help ? <p className="igr:text-xs igr:text-muted-foreground">{help}</p> : null}
        </div>
    );

    return (
        <div className="igr:grid igr:items-start igr:gap-4 igr:lg:grid-cols-[220px_minmax(0,1fr)]">
            <ol className="igr:space-y-1" aria-label={t('loyalty.wizard.steps')}>
                {STEPS.map((s, i) => (
                    <li key={s}>
                        <button
                            type="button"
                            onClick={() => i <= index && setStep(s)}
                            className={cn(
                                'igr:flex igr:w-full igr:items-center igr:gap-2 igr:rounded-md igr:px-2 igr:py-1.5 igr:text-left igr:text-[13px]',
                                s === step ? 'igr:bg-accent igr:font-medium' : i < index ? 'igr:text-foreground' : 'igr:text-muted-foreground',
                            )}
                            aria-current={s === step ? 'step' : undefined}
                        >
                            <span className={cn('igr:flex igr:size-5 igr:items-center igr:justify-center igr:rounded-full igr:border igr:text-[11px]', i < index && 'igr:border-primary igr:bg-primary igr:text-primary-foreground')}>
                                {i < index ? <Check className="igr:size-3" /> : i + 1}
                            </span>
                            {t(`loyalty.wizard.step.${s}`)}
                        </button>
                    </li>
                ))}
            </ol>
            <Card>
                <CardHeader>
                    <CardTitle>{t(`loyalty.wizard.title.${step}`)}</CardTitle>
                    <CardDescription>{t(`loyalty.wizard.help.${step}`)}</CardDescription>
                </CardHeader>
                <CardContent className="igr:space-y-4">
                    {step === 'basics' ? (
                        <>
                            <div className="igr:space-y-1">
                                <Label htmlFor="wz-name">{t('loyalty.rules.currencyName')}</Label>
                                <Input id="wz-name" className="igr:max-w-sm" maxLength={60} value={draft.currency_name} onChange={(e) => setDraft({ ...draft, currency_name: e.target.value })} />
                                <FieldError message={problems.includes('currency_name') ? t('loyalty.rules.errors.currencyName') : undefined} />
                            </div>
                            <div className="igr:flex igr:flex-wrap igr:items-end igr:gap-3">
                                {num('wz-points', t('loyalty.rules.points'), draft.points_per, (v) => setDraft({ ...draft, points_per: v }))}
                                {num('wz-amount', t('loyalty.rules.perAmount', { currency: data.currency }), draft.amount_per, (v) => setDraft({ ...draft, amount_per: v }))}
                            </div>
                            <p className="igr:rounded-md igr:bg-muted/50 igr:p-3 igr:text-[13px]">
                                {t('loyalty.rules.example', { amount: money(example), points: formatNumber(pointsFor(example, draft), workspace.locale), name: draft.currency_name })}
                            </p>
                        </>
                    ) : null}
                    {step === 'extras' ? (
                        <div className="igr:grid igr:gap-3 igr:sm:grid-cols-2">
                            {FIXED_REASONS.map((r) => (
                                <div key={r}>{num(`wz-${r}`, t(`loyalty.rules.fixed.${r}`), draft.fixed[r], (v) => setDraft({ ...draft, fixed: { ...draft.fixed, [r]: v } }), undefined, draft.currency_name)}</div>
                            ))}
                        </div>
                    ) : null}
                    {step === 'reward' ? (
                        <>
                            <div className="igr:space-y-1">
                                <Label htmlFor="wz-reward">{t('loyalty.rewards.name')}</Label>
                                <Input id="wz-reward" className="igr:max-w-sm" maxLength={120} value={reward.name} onChange={(e) => setReward({ ...reward, name: e.target.value })} />
                            </div>
                            <div className="igr:flex igr:flex-wrap igr:gap-3">
                                {num('wz-cost', t('loyalty.rewards.cost'), reward.points_cost, (v) => setReward({ ...reward, points_cost: v }), undefined, draft.currency_name)}
                                {num('wz-value', t('loyalty.rewards.valueFixed', { currency: data.currency }), reward.value, (v) => setReward({ ...reward, value: v }))}
                            </div>
                            {giveBack > 0 ? (
                                <p className="igr:rounded-md igr:bg-muted/50 igr:p-3 igr:text-[13px]">{t('loyalty.wizard.returnRate', { pct: formatPercent(giveBack, workspace.locale, 1) })}</p>
                            ) : null}
                        </>
                    ) : null}
                    {step === 'tiers' ? (
                        <>
                            <label className="igr:flex igr:items-center igr:gap-2 igr:text-[13px]">
                                <input type="checkbox" className="igr:size-4" checked={withTiers} onChange={(e) => setWithTiers(e.target.checked)} />
                                {t('loyalty.wizard.useTiers')}
                            </label>
                            {withTiers ? (
                                <ul className="igr:grid igr:gap-2 igr:sm:grid-cols-3">
                                    {presetTiers('spend_12m', [t('loyalty.tiers.presetNames.0'), t('loyalty.tiers.presetNames.1'), t('loyalty.tiers.presetNames.2')]).map((tier) => (
                                        <li key={tier.uid} className="igr:rounded-md igr:border igr:p-3 igr:text-[13px]">
                                            <p className="igr:font-medium">{tier.name}</p>
                                            <p className="igr:text-xs igr:text-muted-foreground">{Number(tier.threshold) > 0 ? t('loyalty.wizard.tierFrom', { amount: money(Number(tier.threshold)) }) : t('loyalty.wizard.tierBase')}</p>
                                            <p className="igr:mt-1 igr:text-xs">{t('loyalty.wizard.tierMultiplier', { m: tier.multiplier })}</p>
                                        </li>
                                    ))}
                                </ul>
                            ) : null}
                            <p className="igr:text-xs igr:text-muted-foreground">{t('loyalty.wizard.tiersLater')}</p>
                        </>
                    ) : null}
                    {step === 'review' ? (
                        <ul className="igr:space-y-2 igr:text-[13px]">
                            <li>{t('loyalty.rules.example', { amount: money(example), points: formatNumber(pointsFor(example, draft), workspace.locale), name: draft.currency_name })}</li>
                            <li>{t('loyalty.wizard.reviewReward', { reward: reward.name, points: formatNumber(Number(reward.points_cost), workspace.locale), name: draft.currency_name })}</li>
                            <li>{t('loyalty.wizard.reviewPending', { days: draft.pending_days })}</li>
                            <li>{t('loyalty.wizard.reviewExpiry', { months: draft.expiry_months })}</li>
                            <li>{withTiers ? t('loyalty.wizard.reviewTiers') : t('loyalty.wizard.reviewNoTiers')}</li>
                        </ul>
                    ) : null}
                </CardContent>
                <CardFooter className="igr:justify-between">
                    <Button variant="ghost" disabled={index === 0 || saving} onClick={() => setStep(STEPS[index - 1] ?? 'basics')}>
                        <ArrowLeft />
                        {t('loyalty.wizard.back')}
                    </Button>
                    {step === 'review' ? (
                        <div className="igr:flex igr:gap-2">
                            <Button variant="outline" disabled={saving} onClick={() => void finish(false)}>
                                {t('loyalty.wizard.saveDraft')}
                            </Button>
                            <Button loading={saving} onClick={() => void finish(true)}>
                                {t('loyalty.wizard.activate')}
                            </Button>
                        </div>
                    ) : (
                        <Button disabled={step === 'basics' && problems.length > 0} onClick={() => setStep(STEPS[index + 1] ?? 'review')}>
                            {t('loyalty.wizard.next')}
                            <ArrowRight />
                        </Button>
                    )}
                </CardFooter>
            </Card>
        </div>
    );
}
