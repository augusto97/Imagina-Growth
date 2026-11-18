import { Info, Save } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input, Select, Textarea } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ErrorState, FieldError } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { formatMoney } from '@/lib/format';
import { toast } from '@/stores/toast';
import { useProgram, useSaveProgram } from './api';
import type { DiscountKind, Program, ProgramInput, ProgramStatus, ReferrerRewardType } from './types';

type Form = {
    status: ProgramStatus;
    friendKind: DiscountKind;
    friendAmount: string;
    friendDays: string;
    friendMinimum: string;
    rewardType: ReferrerRewardType;
    rewardPoints: string;
    rewardAmount: string;
    rewardCouponKind: DiscountKind;
    rewardDays: string;
    minOrder: string;
    monthlyLimit: string;
    returnDays: string;
    qualifyOn: 'completed' | 'paid';
    reviewSuspicious: boolean;
    shareText: string;
};

function toForm(p: Program): Form {
    return {
        status: p.status,
        friendKind: p.friend_reward.kind,
        friendAmount: String(Number(p.friend_reward.amount)),
        friendDays: String(p.friend_reward.expires_days),
        friendMinimum: p.friend_reward.minimum_amount ? String(Number(p.friend_reward.minimum_amount)) : '',
        rewardType: p.referrer_reward.type,
        rewardPoints: p.referrer_reward.points ? String(p.referrer_reward.points) : '500',
        rewardAmount: p.referrer_reward.amount ? String(Number(p.referrer_reward.amount)) : '',
        rewardCouponKind: p.referrer_reward.coupon_kind ?? 'percent',
        rewardDays: String(p.referrer_reward.expires_days ?? 60),
        minOrder: p.rules.min_order ? String(Number(p.rules.min_order)) : '',
        monthlyLimit: p.rules.monthly_limit ? String(p.rules.monthly_limit) : '',
        returnDays: String(p.rules.return_days),
        qualifyOn: p.rules.qualify_on,
        reviewSuspicious: p.rules.review_suspicious,
        shareText: p.share_text ?? '',
    };
}

const num = (v: string): number | null => (v.trim() === '' ? null : Number(v));

function toInput(f: Form): ProgramInput {
    return {
        status: f.status,
        friend_reward: { kind: f.friendKind, amount: f.friendKind === 'free_shipping' ? null : num(f.friendAmount), expires_days: Number(f.friendDays), minimum_amount: num(f.friendMinimum) },
        referrer_reward: {
            type: f.rewardType,
            points: f.rewardType === 'points' ? num(f.rewardPoints) : null,
            amount: f.rewardType === 'points' || (f.rewardType === 'coupon' && f.rewardCouponKind === 'free_shipping') ? null : num(f.rewardAmount),
            coupon_kind: f.rewardType === 'coupon' ? f.rewardCouponKind : null,
            expires_days: f.rewardType === 'coupon' ? num(f.rewardDays) : null,
        },
        rules: { min_order: num(f.minOrder), monthly_limit: num(f.monthlyLimit), return_days: Number(f.returnDays), qualify_on: f.qualifyOn, review_suspicious: f.reviewSuspicious, signal_window_days: 30 },
        share_text: f.shareText.trim() === '' ? null : f.shareText.trim(),
    };
}

function Field({ id, label, help, error, children }: { id: string; label: string; help?: string; error?: string | undefined; children: ReactNode }) {
    return (
        <div className="igr:space-y-1.5">
            <Label htmlFor={id}>{label}</Label>
            {children}
            {help ? <p className="igr:text-xs igr:text-muted-foreground">{help}</p> : null}
            <FieldError message={error} />
        </div>
    );
}

/** Configuración del programa de referidos: regalo del amigo, recompensa, reglas antifraude y texto para compartir. */
export function ProgramTab() {
    const { workspace } = useWorkspace();
    const program = useProgram(workspace.id);

    if (program.isPending) {
        return <Skeleton className="igr:h-96" />;
    }
    if (program.isError) {
        return <ErrorState error={program.error} onRetry={() => void program.refetch()} />;
    }

    return <ProgramForm program={program.data} />;
}

function ProgramForm({ program: p }: { program: Program }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageMarketing');
    const save = useSaveProgram(workspace.id);
    const [form, setForm] = useState<Form>(() => toForm(p));
    const [errors, setErrors] = useState<Record<string, string>>({});

    const set = <K extends keyof Form>(key: K, value: Form[K]) => setForm({ ...form, [key]: value });
    const money = t('referrals.program.inCurrency', { currency: p.currency });
    const preview = (form.shareText.trim() || p.default_share_text)
        .replaceAll('{regalo}', form.friendKind === 'free_shipping' ? t('referrals.program.freeShipping') : form.friendKind === 'percent' ? `${form.friendAmount}%` : formatMoney(form.friendAmount || 0, p.currency, workspace.locale))
        .replaceAll('{tienda}', workspace.name)
        .replaceAll('{enlace}', p.labels.example_link)
        .replaceAll('{codigo}', 'ANA7K2');

    const submit = () => {
        setErrors({});
        save.mutate(toInput(form), {
            onSuccess: (saved) => {
                setForm(toForm(saved));
                toast.success(t('referrals.program.saved'));
            },
            onError: (error) => {
                if (isApiError(error)) {
                    setErrors(error.fieldErrors);
                }
                toast.error(t('referrals.program.saveFailed'), isApiError(error) ? error.message : undefined);
            },
        });
    };

    return (
        <form
            className="igr:space-y-4"
            onSubmit={(e) => {
                e.preventDefault();
                submit();
            }}
        >
            <fieldset disabled={!canManage} className="igr:space-y-4">
                <Card>
                    <CardHeader>
                        <CardTitle>{t('referrals.program.statusTitle')}</CardTitle>
                        <CardDescription>{t('referrals.program.statusDescription')}</CardDescription>
                    </CardHeader>
                    <CardContent className="igr:grid igr:gap-4 igr:md:grid-cols-3">
                        <Field id="rp-status" label={t('referrals.program.status')} error={errors.status}>
                            <Select id="rp-status" value={form.status} onChange={(e) => set('status', e.target.value as ProgramStatus)}>
                                {(['draft', 'active', 'paused'] as const).map((s) => (
                                    <option key={s} value={s}>
                                        {t(`referrals.program.statuses.${s}`)}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                    </CardContent>
                </Card>

                <div className="igr:grid igr:gap-4 igr:lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>{t('referrals.program.friendTitle')}</CardTitle>
                            <CardDescription>{t('referrals.program.friendDescription')}</CardDescription>
                        </CardHeader>
                        <CardContent className="igr:grid igr:gap-4 igr:sm:grid-cols-2">
                            <Field id="rp-friend-kind" label={t('referrals.program.discountKind')}>
                                <Select id="rp-friend-kind" value={form.friendKind} onChange={(e) => set('friendKind', e.target.value as DiscountKind)}>
                                    {(['percent', 'fixed_cart', 'free_shipping'] as const).map((k) => (
                                        <option key={k} value={k}>
                                            {t(`referrals.program.kinds.${k}`)}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                            {form.friendKind !== 'free_shipping' ? (
                                <Field id="rp-friend-amount" label={form.friendKind === 'percent' ? t('referrals.program.percent') : money} error={errors['friend_reward.amount']}>
                                    <Input id="rp-friend-amount" type="number" min={0} step="any" value={form.friendAmount} onChange={(e) => set('friendAmount', e.target.value)} />
                                </Field>
                            ) : null}
                            <Field id="rp-friend-days" label={t('referrals.program.expiresDays')} error={errors['friend_reward.expires_days']}>
                                <Input id="rp-friend-days" type="number" min={1} max={365} value={form.friendDays} onChange={(e) => set('friendDays', e.target.value)} />
                            </Field>
                            <Field id="rp-friend-min" label={t('referrals.program.couponMinimum')} help={t('referrals.program.couponMinimumHelp')} error={errors['friend_reward.minimum_amount']}>
                                <Input id="rp-friend-min" type="number" min={0} step="any" value={form.friendMinimum} onChange={(e) => set('friendMinimum', e.target.value)} />
                            </Field>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>{t('referrals.program.referrerTitle')}</CardTitle>
                            <CardDescription>{t('referrals.program.referrerDescription')}</CardDescription>
                        </CardHeader>
                        <CardContent className="igr:grid igr:gap-4 igr:sm:grid-cols-2">
                            <Field id="rp-reward-type" label={t('referrals.program.rewardType')} error={errors['referrer_reward.type']}>
                                <Select id="rp-reward-type" value={form.rewardType} onChange={(e) => set('rewardType', e.target.value as ReferrerRewardType)}>
                                    {(['wallet', 'points', 'coupon'] as const).map((k) => (
                                        <option key={k} value={k}>
                                            {t(`referrals.program.rewardTypes.${k}`)}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                            {form.rewardType === 'points' ? (
                                <Field id="rp-reward-points" label={t('referrals.program.points')} error={errors['referrer_reward.points']}>
                                    <Input id="rp-reward-points" type="number" min={1} value={form.rewardPoints} onChange={(e) => set('rewardPoints', e.target.value)} />
                                </Field>
                            ) : null}
                            {form.rewardType === 'coupon' ? (
                                <Field id="rp-reward-ckind" label={t('referrals.program.discountKind')}>
                                    <Select id="rp-reward-ckind" value={form.rewardCouponKind} onChange={(e) => set('rewardCouponKind', e.target.value as DiscountKind)}>
                                        {(['percent', 'fixed_cart', 'free_shipping'] as const).map((k) => (
                                            <option key={k} value={k}>
                                                {t(`referrals.program.kinds.${k}`)}
                                            </option>
                                        ))}
                                    </Select>
                                </Field>
                            ) : null}
                            {form.rewardType === 'wallet' || (form.rewardType === 'coupon' && form.rewardCouponKind !== 'free_shipping') ? (
                                <Field id="rp-reward-amount" label={form.rewardType === 'coupon' && form.rewardCouponKind === 'percent' ? t('referrals.program.percent') : money} error={errors['referrer_reward.amount']}>
                                    <Input id="rp-reward-amount" type="number" min={0} step="any" value={form.rewardAmount} onChange={(e) => set('rewardAmount', e.target.value)} />
                                </Field>
                            ) : null}
                            {form.rewardType === 'coupon' ? (
                                <Field id="rp-reward-days" label={t('referrals.program.expiresDays')}>
                                    <Input id="rp-reward-days" type="number" min={1} max={365} value={form.rewardDays} onChange={(e) => set('rewardDays', e.target.value)} />
                                </Field>
                            ) : null}
                            {form.rewardType === 'points' && !p.loyalty_active ? (
                                <p className="igr:flex igr:items-start igr:gap-1.5 igr:text-xs igr:text-muted-foreground igr:sm:col-span-2">
                                    <Info className="igr:mt-0.5 igr:size-3.5 igr:shrink-0" aria-hidden />
                                    {t('referrals.program.loyaltyInactive')}
                                </p>
                            ) : null}
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>{t('referrals.program.rulesTitle')}</CardTitle>
                        <CardDescription>{t('referrals.program.rulesDescription')}</CardDescription>
                    </CardHeader>
                    <CardContent className="igr:grid igr:gap-4 igr:md:grid-cols-4">
                        <Field id="rp-min-order" label={t('referrals.program.minOrder')} help={money} error={errors['rules.min_order']}>
                            <Input id="rp-min-order" type="number" min={0} step="any" value={form.minOrder} onChange={(e) => set('minOrder', e.target.value)} />
                        </Field>
                        <Field id="rp-limit" label={t('referrals.program.monthlyLimit')} help={t('referrals.program.monthlyLimitHelp')} error={errors['rules.monthly_limit']}>
                            <Input id="rp-limit" type="number" min={1} max={1000} value={form.monthlyLimit} onChange={(e) => set('monthlyLimit', e.target.value)} />
                        </Field>
                        <Field id="rp-qualify" label={t('referrals.program.qualifyOn')}>
                            <Select id="rp-qualify" value={form.qualifyOn} onChange={(e) => set('qualifyOn', e.target.value as 'completed' | 'paid')}>
                                <option value="completed">{t('referrals.program.qualify.completed')}</option>
                                <option value="paid">{t('referrals.program.qualify.paid')}</option>
                            </Select>
                        </Field>
                        <Field id="rp-return" label={t('referrals.program.returnDays')} help={t('referrals.program.returnDaysHelp')} error={errors['rules.return_days']}>
                            <Input id="rp-return" type="number" min={0} max={90} value={form.returnDays} onChange={(e) => set('returnDays', e.target.value)} />
                        </Field>
                        <label className="igr:flex igr:items-start igr:gap-2 igr:text-[13px] igr:md:col-span-4">
                            <input type="checkbox" className="igr:mt-0.5" checked={form.reviewSuspicious} onChange={(e) => set('reviewSuspicious', e.target.checked)} />
                            <span>
                                {t('referrals.program.reviewSuspicious')}
                                <span className="igr:block igr:text-xs igr:text-muted-foreground">{t('referrals.program.reviewSuspiciousHelp')}</span>
                            </span>
                        </label>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>{t('referrals.program.shareTitle')}</CardTitle>
                        <CardDescription>{t('referrals.program.shareDescription')}</CardDescription>
                    </CardHeader>
                    <CardContent className="igr:grid igr:gap-4 igr:lg:grid-cols-2">
                        <Field id="rp-share" label={t('referrals.program.shareText')} help={t('referrals.program.shareVariables')} error={errors.share_text}>
                            <Textarea id="rp-share" maxLength={500} placeholder={p.default_share_text} value={form.shareText} onChange={(e) => set('shareText', e.target.value)} />
                        </Field>
                        <div className="igr:space-y-1.5">
                            <p className="igr:text-[13px] igr:font-medium">{t('referrals.program.preview')}</p>
                            <div className="igr:rounded-lg igr:border igr:bg-muted/40 igr:p-3 igr:text-[13px] igr:whitespace-pre-wrap">{preview}</div>
                            <p className="igr:text-xs igr:text-muted-foreground">{t('referrals.program.previewHelp')}</p>
                        </div>
                    </CardContent>
                </Card>
            </fieldset>
            {canManage ? (
                <div className="igr:flex igr:justify-end">
                    <Button type="submit" loading={save.isPending}>
                        <Save />
                        {t('referrals.program.save')}
                    </Button>
                </div>
            ) : (
                <p className="igr:text-xs igr:text-muted-foreground">{t('referrals.program.readOnly')}</p>
            )}
        </form>
    );
}
