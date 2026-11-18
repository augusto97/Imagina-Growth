import { ArrowDown, ArrowUp, Crown, Plus, Sparkles, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input, Select } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { EmptyState } from '@/components/ui/misc';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { formatNumber } from '@/lib/format';
import { toast } from '@/stores/toast';
import { errorMessage } from '@/features/contacts/errors';
import { useLoyaltyMutations } from './api';
import { presetTiers, type TierDraft, tierProblems, toTierDrafts, toTiersPayload } from './program';
import type { LoyaltyOverview, ThresholdType } from './types';

const TYPES: ThresholdType[] = ['spend_12m', 'points_12m', 'orders_12m'];
const MAX_TIERS = 8;

/** Niveles VIP: cómo se miden, umbrales de menor a mayor, beneficios y rol de WooCommerce. */
export function TiersTab({ data }: { data: LoyaltyOverview }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canEdit = useCan('manageMarketing');
    const { saveTiers } = useLoyaltyMutations(workspace.id);
    const [type, setType] = useState<ThresholdType>(data.tiers[0]?.threshold_type ?? 'spend_12m');
    const [drafts, setDrafts] = useState<TierDraft[]>(() => toTierDrafts(data.tiers));
    const [showErrors, setShowErrors] = useState(false);
    const bad = tierProblems(drafts);
    const members = new Map(data.tiers.map((x) => [x.id, x.members ?? 0]));
    const readOnly = !canEdit;
    const update = (i: number, patch: Partial<TierDraft>) => setDrafts(drafts.map((d, j) => (j === i ? { ...d, ...patch } : d)));
    const move = (i: number, by: -1 | 1) => {
        const next = [...drafts];
        const [item] = next.splice(i, 1);
        if (item) next.splice(i + by, 0, item);
        setDrafts(next);
    };
    const names: [string, string, string] = [t('loyalty.tiers.presetNames.0'), t('loyalty.tiers.presetNames.1'), t('loyalty.tiers.presetNames.2')];

    const save = () => {
        if (bad.length > 0) {
            setShowErrors(true);
            return;
        }
        saveTiers.mutate(toTiersPayload(drafts, type), {
            onSuccess: () => {
                setShowErrors(false);
                toast.success(t('loyalty.tiers.saved'));
            },
            onError: (error) => toast.error(errorMessage(error, t)),
        });
    };

    return (
        <Card>
            <CardHeader className="igr:flex-row igr:items-start igr:justify-between igr:gap-3">
                <div>
                    <CardTitle>{t('loyalty.tiers.title')}</CardTitle>
                    <CardDescription>{t('loyalty.tiers.help')}</CardDescription>
                </div>
                <div className="igr:space-y-1">
                    <Label htmlFor="tier-type">{t('loyalty.tiers.measure')}</Label>
                    <Select id="tier-type" value={type} disabled={readOnly} onChange={(e) => setType(e.target.value as ThresholdType)}>
                        {TYPES.map((x) => (
                            <option key={x} value={x}>
                                {t(`loyalty.tiers.types.${x}`)}
                            </option>
                        ))}
                    </Select>
                </div>
            </CardHeader>
            <CardContent className="igr:space-y-3">
                {drafts.length === 0 ? (
                    <EmptyState
                        icon={Crown}
                        title={t('loyalty.tiers.emptyTitle')}
                        description={t('loyalty.tiers.emptyHelp')}
                        action={
                            canEdit ? (
                                <Button variant="outline" onClick={() => setDrafts(presetTiers(type, names))}>
                                    <Sparkles />
                                    {t('loyalty.tiers.usePreset')}
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    <ol className="igr:space-y-2">
                        {drafts.map((d, i) => (
                            <li key={d.uid} className={`igr:rounded-lg igr:border igr:p-3 ${showErrors && bad.includes(i) ? 'igr:border-destructive' : ''}`}>
                                <div className="igr:flex igr:flex-wrap igr:items-end igr:gap-3">
                                    <span className="igr:flex igr:size-6 igr:items-center igr:justify-center igr:rounded-full igr:bg-muted igr:text-xs igr:font-medium" aria-hidden>
                                        {i + 1}
                                    </span>
                                    <div className="igr:space-y-1">
                                        <Label htmlFor={`tier-name-${d.uid}`}>{t('loyalty.tiers.name')}</Label>
                                        <Input id={`tier-name-${d.uid}`} className="igr:w-40" maxLength={80} value={d.name} readOnly={readOnly} onChange={(e) => update(i, { name: e.target.value })} />
                                    </div>
                                    <div className="igr:space-y-1">
                                        <Label htmlFor={`tier-th-${d.uid}`}>{t(`loyalty.tiers.thresholdLabel.${type}`, { currency: data.currency })}</Label>
                                        <Input id={`tier-th-${d.uid}`} className="igr:w-36" inputMode="decimal" value={d.threshold} readOnly={readOnly} onChange={(e) => update(i, { threshold: e.target.value.replace(/[^0-9.]/g, '') })} />
                                    </div>
                                    <div className="igr:space-y-1">
                                        <Label htmlFor={`tier-mult-${d.uid}`}>{t('loyalty.tiers.multiplier')}</Label>
                                        <Input id={`tier-mult-${d.uid}`} className="igr:w-20" inputMode="decimal" value={d.multiplier} readOnly={readOnly} onChange={(e) => update(i, { multiplier: e.target.value.replace(/[^0-9.]/g, '') })} />
                                    </div>
                                    <div className="igr:space-y-1">
                                        <Label htmlFor={`tier-role-${d.uid}`}>{t('loyalty.tiers.role')}</Label>
                                        <Input id={`tier-role-${d.uid}`} className="igr:w-40" placeholder="cliente_vip" maxLength={60} value={d.wc_role} readOnly={readOnly} onChange={(e) => update(i, { wc_role: e.target.value.toLowerCase() })} />
                                    </div>
                                    {d.id ? <span className="igr:pb-2 igr:text-xs igr:text-muted-foreground">{t('loyalty.tiers.members', { count: members.get(d.id) ?? 0, n: formatNumber(members.get(d.id) ?? 0, workspace.locale) })}</span> : null}
                                    {!readOnly ? (
                                        <div className="igr:ml-auto igr:flex igr:gap-1">
                                            <Button variant="ghost" size="icon" disabled={i === 0} aria-label={t('loyalty.tiers.up')} onClick={() => move(i, -1)}>
                                                <ArrowUp />
                                            </Button>
                                            <Button variant="ghost" size="icon" disabled={i === drafts.length - 1} aria-label={t('loyalty.tiers.down')} onClick={() => move(i, 1)}>
                                                <ArrowDown />
                                            </Button>
                                            <Button variant="ghost" size="icon" aria-label={t('loyalty.tiers.remove')} onClick={() => setDrafts(drafts.filter((_, j) => j !== i))}>
                                                <Trash2 />
                                            </Button>
                                        </div>
                                    ) : null}
                                </div>
                                <div className="igr:mt-2 igr:flex igr:flex-wrap igr:items-center igr:gap-4 igr:text-[13px]">
                                    <label className="igr:flex igr:items-center igr:gap-1.5">
                                        <input type="checkbox" className="igr:size-4" checked={d.free_shipping} disabled={readOnly} onChange={(e) => update(i, { free_shipping: e.target.checked })} />
                                        {t('loyalty.tiers.freeShipping')}
                                    </label>
                                    <label className="igr:flex igr:items-center igr:gap-1.5">
                                        <input type="checkbox" className="igr:size-4" checked={d.early_access} disabled={readOnly} onChange={(e) => update(i, { early_access: e.target.checked })} />
                                        {t('loyalty.tiers.earlyAccess')}
                                    </label>
                                    <Input aria-label={t('loyalty.tiers.gifts')} placeholder={t('loyalty.tiers.gifts')} className="igr:w-72" maxLength={120} value={d.gifts} readOnly={readOnly} onChange={(e) => update(i, { gifts: e.target.value })} />
                                </div>
                            </li>
                        ))}
                    </ol>
                )}
                {showErrors && bad.length > 0 ? <p className="igr:text-xs igr:text-destructive">{t('loyalty.tiers.errors')}</p> : null}
                <p className="igr:text-xs igr:text-muted-foreground">{t('loyalty.tiers.roleHelp')}</p>
                {canEdit ? (
                    <div className="igr:flex igr:justify-between">
                        <Button variant="outline" size="sm" disabled={drafts.length >= MAX_TIERS} onClick={() => setDrafts([...drafts, { uid: `n${Date.now()}`, id: null, name: '', threshold: '', multiplier: '1', free_shipping: false, early_access: false, gifts: '', wc_role: '' }])}>
                            <Plus />
                            {t('loyalty.tiers.add')}
                        </Button>
                        <Button onClick={save} loading={saveTiers.isPending}>
                            {t('loyalty.tiers.save')}
                        </Button>
                    </div>
                ) : null}
            </CardContent>
        </Card>
    );
}
