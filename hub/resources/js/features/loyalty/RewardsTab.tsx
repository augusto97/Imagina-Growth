import { Archive, Gift, Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input, Select } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { EmptyState, FieldError } from '@/components/ui/misc';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { formatMoney, formatNumber } from '@/lib/format';
import { toast } from '@/stores/toast';
import { errorMessage } from '@/features/contacts/errors';
import { useLoyaltyMutations } from './api';
import { type RewardDraft, toRewardDraft, toRewardPayload } from './program';
import { REWARD_KINDS, type LoyaltyOverview, type Reward } from './types';

/** Catálogo de canje: cada recompensa se entrega como cupón único de WooCommerce o como saldo. */
export function RewardsTab({ data }: { data: LoyaltyOverview }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canEdit = useCan('manageMarketing');
    const { archiveReward } = useLoyaltyMutations(workspace.id);
    const [editing, setEditing] = useState<Reward | 'new' | null>(null);
    const active = data.rewards.filter((r) => r.status === 'active');
    const archived = data.rewards.filter((r) => r.status === 'archived');

    return (
        <div className="igr:space-y-3">
            {canEdit ? (
                <div className="igr:flex igr:justify-end">
                    <Button onClick={() => setEditing('new')}>
                        <Plus />
                        {t('loyalty.rewards.add')}
                    </Button>
                </div>
            ) : null}
            {active.length === 0 ? (
                <EmptyState icon={Gift} title={t('loyalty.rewards.emptyTitle')} description={t('loyalty.rewards.emptyHelp')} />
            ) : (
                <div className="igr:grid igr:gap-3 igr:md:grid-cols-2 igr:xl:grid-cols-3">
                    {active.map((r) => (
                        <Card key={r.id}>
                            <CardContent className="igr:space-y-2 igr:py-4">
                                <div className="igr:flex igr:items-start igr:justify-between igr:gap-2">
                                    <div>
                                        <p className="igr:text-sm igr:font-medium">{r.name}</p>
                                        <p className="igr:text-xs igr:text-muted-foreground">{rewardValue(t, r, data.currency, workspace.locale)}</p>
                                    </div>
                                    <Badge variant="secondary">{t(`loyalty.rewards.kinds.${r.kind}`)}</Badge>
                                </div>
                                <p className="igr:text-lg igr:font-semibold igr:tabular-nums">
                                    {formatNumber(r.points_cost, workspace.locale)} <span className="igr:text-xs igr:font-normal igr:text-muted-foreground">{data.program.currency_name}</span>
                                </p>
                                <div className="igr:flex igr:items-center igr:justify-between">
                                    <span className="igr:text-xs igr:text-muted-foreground">{t('loyalty.rewards.redeemed', { count: r.redeemed ?? 0, n: formatNumber(r.redeemed ?? 0, workspace.locale) })}</span>
                                    {canEdit ? (
                                        <div className="igr:flex igr:gap-1">
                                            <Button variant="ghost" size="icon" aria-label={t('loyalty.rewards.edit')} onClick={() => setEditing(r)}>
                                                <Pencil />
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                aria-label={t('loyalty.rewards.archive')}
                                                onClick={() => archiveReward.mutate(r.id, { onSuccess: () => toast.success(t('loyalty.rewards.archived')), onError: (e) => toast.error(errorMessage(e, t)) })}
                                            >
                                                <Archive />
                                            </Button>
                                        </div>
                                    ) : null}
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>
            )}
            {archived.length > 0 ? <p className="igr:text-xs igr:text-muted-foreground">{t('loyalty.rewards.archivedCount', { count: archived.length })}</p> : null}
            <p className="igr:text-xs igr:text-muted-foreground">{t('loyalty.rewards.help')}</p>
            {editing !== null ? <RewardDialog reward={editing === 'new' ? null : editing} data={data} onClose={() => setEditing(null)} /> : null}
        </div>
    );
}

export function rewardValue(t: (k: string, o?: Record<string, unknown>) => string, r: Pick<Reward, 'kind' | 'value'>, currency: string, locale: string): string {
    switch (r.kind) {
        case 'coupon_percent':
            return t('loyalty.rewards.valuePercent', { pct: Number(r.value) });
        case 'free_shipping':
            return t('loyalty.rewards.valueShipping');
        case 'wallet':
            return t('loyalty.rewards.valueWallet', { amount: formatMoney(r.value, currency, locale) });
        case 'free_product':
            return t('loyalty.rewards.valueProduct', { amount: formatMoney(r.value, currency, locale) });
        default:
            return t('loyalty.rewards.valueFixedText', { amount: formatMoney(r.value, currency, locale) });
    }
}

function RewardDialog({ reward, data, onClose }: { reward: Reward | null; data: LoyaltyOverview; onClose: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const { createReward, updateReward } = useLoyaltyMutations(workspace.id);
    const [draft, setDraft] = useState<RewardDraft>(() => toRewardDraft(reward ?? undefined));
    const mutation = reward ? updateReward : createReward;
    const server = isApiError(mutation.error) ? mutation.error.fieldErrors : {};
    const set = (patch: Partial<RewardDraft>) => setDraft({ ...draft, ...patch });
    const submit = () => {
        const payload = toRewardPayload(draft);
        const options = { onSuccess: () => { toast.success(t('loyalty.rewards.saved')); onClose(); } };
        if (reward) updateReward.mutate({ id: reward.id, data: payload }, options);
        else createReward.mutate(payload, options);
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{reward ? t('loyalty.rewards.editTitle') : t('loyalty.rewards.newTitle')}</DialogTitle>
                    <DialogDescription>{t('loyalty.rewards.dialogHelp')}</DialogDescription>
                </DialogHeader>
                <div className="igr:space-y-3">
                    <div className="igr:space-y-1">
                        <Label htmlFor="rw-name">{t('loyalty.rewards.name')}</Label>
                        <Input id="rw-name" maxLength={120} value={draft.name} onChange={(e) => set({ name: e.target.value })} />
                        <FieldError message={server.name} />
                    </div>
                    <div className="igr:grid igr:grid-cols-2 igr:gap-3">
                        <div className="igr:space-y-1">
                            <Label htmlFor="rw-kind">{t('loyalty.rewards.kind')}</Label>
                            <Select id="rw-kind" value={draft.kind} onChange={(e) => set({ kind: e.target.value as RewardDraft['kind'] })}>
                                {REWARD_KINDS.map((k) => (
                                    <option key={k} value={k}>
                                        {t(`loyalty.rewards.kinds.${k}`)}
                                    </option>
                                ))}
                            </Select>
                        </div>
                        <div className="igr:space-y-1">
                            <Label htmlFor="rw-cost">{t('loyalty.rewards.cost')}</Label>
                            <Input id="rw-cost" inputMode="numeric" value={draft.points_cost} onChange={(e) => set({ points_cost: e.target.value.replace(/[^0-9]/g, '') })} />
                            <FieldError message={server.points_cost} />
                        </div>
                        {draft.kind !== 'free_shipping' ? (
                            <div className="igr:space-y-1">
                                <Label htmlFor="rw-value">{draft.kind === 'coupon_percent' ? t('loyalty.rewards.valuePct') : t('loyalty.rewards.valueFixed', { currency: data.currency })}</Label>
                                <Input id="rw-value" inputMode="decimal" value={draft.value} onChange={(e) => set({ value: e.target.value.replace(/[^0-9.]/g, '') })} />
                                <FieldError message={server.value} />
                            </div>
                        ) : null}
                        {draft.kind === 'free_product' ? (
                            <div className="igr:space-y-1">
                                <Label htmlFor="rw-product">{t('loyalty.rewards.productId')}</Label>
                                <Input id="rw-product" maxLength={64} value={draft.product_id} onChange={(e) => set({ product_id: e.target.value.trim() })} />
                                <FieldError message={server['config.product_id']} />
                            </div>
                        ) : null}
                        {draft.kind !== 'wallet' ? (
                            <>
                                <div className="igr:space-y-1">
                                    <Label htmlFor="rw-min">{t('loyalty.rewards.minOrder', { currency: data.currency })}</Label>
                                    <Input id="rw-min" inputMode="decimal" value={draft.min_order} onChange={(e) => set({ min_order: e.target.value.replace(/[^0-9.]/g, '') })} />
                                </div>
                                <div className="igr:space-y-1">
                                    <Label htmlFor="rw-days">{t('loyalty.rewards.expires')}</Label>
                                    <Input id="rw-days" inputMode="numeric" placeholder={String(data.program.redeem_rules.coupon_expiry_days)} value={draft.expires_in_days} onChange={(e) => set({ expires_in_days: e.target.value.replace(/[^0-9]/g, '') })} />
                                </div>
                            </>
                        ) : null}
                    </div>
                    {Object.keys(server).length === 0 && mutation.isError ? <FieldError message={errorMessage(mutation.error, t)} /> : null}
                </div>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    <Button onClick={submit} loading={mutation.isPending} disabled={draft.name.trim() === ''}>
                        {t('loyalty.rewards.save')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
