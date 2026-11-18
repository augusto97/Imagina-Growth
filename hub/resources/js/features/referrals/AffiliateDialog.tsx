import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input, Select, Textarea } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { FieldError } from '@/components/ui/misc';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { toast } from '@/stores/toast';
import { useAffiliateActions } from './api';
import type { Affiliate, AffiliateInput, AffiliateKind } from './types';

/** Crear o editar un afiliado: datos, cupones, enlace, comisión, material y datos de pago. */
export function AffiliateDialog({ affiliate, onClose }: { affiliate: Affiliate | null; onClose: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canContacts = useCan('viewAllContacts');
    const canPayment = useCan('viewPaymentData');
    const actions = useAffiliateActions(workspace.id);
    const [name, setName] = useState(affiliate?.name ?? '');
    const [kind, setKind] = useState<AffiliateKind>(affiliate?.kind ?? 'influencer');
    const [email, setEmail] = useState(affiliate?.email ?? '');
    const [coupons, setCoupons] = useState((affiliate?.coupon_codes ?? []).join(', '));
    const [linkCode, setLinkCode] = useState(affiliate?.link_code ?? '');
    const [commissionType, setCommissionType] = useState<'percent' | 'fixed'>(affiliate?.commission.type ?? 'percent');
    const [commissionValue, setCommissionValue] = useState(affiliate ? String(Number(affiliate.commission.value)) : '10');
    const [basis, setBasis] = useState<'net' | 'total'>(affiliate?.commission.basis ?? 'net');
    const [material, setMaterial] = useState(affiliate?.material ?? []);
    const [payout, setPayout] = useState(affiliate?.payout_info ?? '');
    const [status, setStatus] = useState<'active' | 'paused'>(affiliate?.status === 'paused' ? 'paused' : 'active');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const saving = actions.create.isPending || actions.update.isPending;

    const submit = () => {
        const input: AffiliateInput = {
            name: name.trim(),
            kind,
            coupon_codes: coupons.split(/[\s,;]+/).map((c) => c.trim()).filter((c) => c !== ''),
            commission: { type: commissionType, value: Number(commissionValue), basis },
            material: material.filter((m) => m.label.trim() !== '' || m.url.trim() !== ''),
            status,
        };
        if (canContacts) input.email = email.trim() === '' ? null : email.trim();
        if (canPayment) input.payout_info = payout.trim() === '' ? null : payout.trim();
        if (linkCode.trim() !== '' && linkCode.trim() !== affiliate?.link_code) input.link_code = linkCode.trim();
        setErrors({});
        const options = {
            onSuccess: () => {
                toast.success(affiliate ? t('referrals.affiliates.saved') : t('referrals.affiliates.created'));
                onClose();
            },
            onError: (error: unknown) => {
                if (isApiError(error)) setErrors(error.fieldErrors);
                toast.error(t('referrals.affiliates.saveFailed'), isApiError(error) ? error.message : undefined);
            },
        };
        if (affiliate) {
            actions.update.mutate({ id: affiliate.id, input }, options);
        } else {
            actions.create.mutate(input, options);
        }
    };

    return (
        <Dialog open onOpenChange={(open) => (!open ? onClose() : undefined)}>
            <DialogContent className="igr:max-h-[90vh] igr:overflow-y-auto igr:sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>{affiliate ? t('referrals.affiliates.editTitle') : t('referrals.affiliates.newTitle')}</DialogTitle>
                    <DialogDescription>{t('referrals.affiliates.dialogDescription')}</DialogDescription>
                </DialogHeader>
                <form
                    id="affiliate-form"
                    className="igr:grid igr:gap-4 igr:sm:grid-cols-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        submit();
                    }}
                >
                    <div className="igr:space-y-1.5">
                        <Label htmlFor="af-name">{t('referrals.affiliates.name')}</Label>
                        <Input id="af-name" value={name} onChange={(e) => setName(e.target.value)} required minLength={2} maxLength={160} />
                        <FieldError message={errors.name} />
                    </div>
                    <div className="igr:space-y-1.5">
                        <Label htmlFor="af-kind">{t('referrals.affiliates.kind')}</Label>
                        <Select id="af-kind" value={kind} onChange={(e) => setKind(e.target.value as AffiliateKind)}>
                            {(['influencer', 'affiliate', 'partner'] as const).map((k) => (
                                <option key={k} value={k}>
                                    {t(`referrals.affiliates.kinds.${k}`)}
                                </option>
                            ))}
                        </Select>
                    </div>
                    {canContacts ? (
                        <div className="igr:space-y-1.5 igr:sm:col-span-2">
                            <Label htmlFor="af-email">{t('referrals.affiliates.email')}</Label>
                            <Input id="af-email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} />
                            <p className="igr:text-xs igr:text-muted-foreground">{t('referrals.affiliates.emailHelp')}</p>
                            <FieldError message={errors.email} />
                        </div>
                    ) : null}
                    <div className="igr:space-y-1.5">
                        <Label htmlFor="af-coupons">{t('referrals.affiliates.coupons')}</Label>
                        <Input id="af-coupons" value={coupons} onChange={(e) => setCoupons(e.target.value)} placeholder="LAURA15, LAURA-NAVIDAD" />
                        <p className="igr:text-xs igr:text-muted-foreground">{t('referrals.affiliates.couponsHelp')}</p>
                        <FieldError message={errors.coupon_codes ?? errors['coupon_codes.0']} />
                    </div>
                    <div className="igr:space-y-1.5">
                        <Label htmlFor="af-link">{t('referrals.affiliates.linkCode')}</Label>
                        <Input id="af-link" value={linkCode} onChange={(e) => setLinkCode(e.target.value.toUpperCase())} placeholder={t('referrals.affiliates.linkCodeAuto')} maxLength={32} />
                        <p className="igr:text-xs igr:text-muted-foreground">{t('referrals.affiliates.linkCodeHelp')}</p>
                        <FieldError message={errors.link_code} />
                    </div>
                    <div className="igr:space-y-1.5">
                        <Label htmlFor="af-ctype">{t('referrals.affiliates.commission')}</Label>
                        <div className="igr:flex igr:gap-2">
                            <Select id="af-ctype" className="igr:w-36" value={commissionType} onChange={(e) => setCommissionType(e.target.value as 'percent' | 'fixed')}>
                                <option value="percent">{t('referrals.affiliates.commissionPercent')}</option>
                                <option value="fixed">{t('referrals.affiliates.commissionFixed', { currency: workspace.currency })}</option>
                            </Select>
                            <Input aria-label={t('referrals.affiliates.commissionValue')} type="number" min={0} step="any" value={commissionValue} onChange={(e) => setCommissionValue(e.target.value)} />
                        </div>
                        <FieldError message={errors['commission.value']} />
                    </div>
                    {commissionType === 'percent' ? (
                        <div className="igr:space-y-1.5">
                            <Label htmlFor="af-basis">{t('referrals.affiliates.basis')}</Label>
                            <Select id="af-basis" value={basis} onChange={(e) => setBasis(e.target.value as 'net' | 'total')}>
                                <option value="net">{t('referrals.affiliates.basisNet')}</option>
                                <option value="total">{t('referrals.affiliates.basisTotal')}</option>
                            </Select>
                        </div>
                    ) : (
                        <div />
                    )}
                    <div className="igr:space-y-1.5 igr:sm:col-span-2">
                        <Label>{t('referrals.affiliates.material')}</Label>
                        {material.map((m, i) => (
                            <div key={i} className="igr:flex igr:gap-2">
                                <Input aria-label={t('referrals.affiliates.materialLabel')} placeholder={t('referrals.affiliates.materialLabel')} value={m.label} onChange={(e) => setMaterial(material.map((x, j) => (j === i ? { ...x, label: e.target.value } : x)))} />
                                <Input aria-label={t('referrals.affiliates.materialUrl')} placeholder="https://" type="url" value={m.url} onChange={(e) => setMaterial(material.map((x, j) => (j === i ? { ...x, url: e.target.value } : x)))} />
                                <Button type="button" size="icon" variant="ghost" aria-label={t('referrals.affiliates.removeMaterial')} onClick={() => setMaterial(material.filter((_, j) => j !== i))}>
                                    <Trash2 />
                                </Button>
                            </div>
                        ))}
                        <Button type="button" size="sm" variant="outline" onClick={() => setMaterial([...material, { label: '', url: '' }])} disabled={material.length >= 20}>
                            <Plus />
                            {t('referrals.affiliates.addMaterial')}
                        </Button>
                        <FieldError message={Object.entries(errors).find(([key]) => key.startsWith('material'))?.[1]} />
                    </div>
                    {canPayment ? (
                        <div className="igr:space-y-1.5 igr:sm:col-span-2">
                            <Label htmlFor="af-payout">{t('referrals.affiliates.payout')}</Label>
                            <Textarea id="af-payout" className="igr:min-h-14" maxLength={500} value={payout} onChange={(e) => setPayout(e.target.value)} placeholder={t('referrals.affiliates.payoutPlaceholder')} />
                            <p className="igr:text-xs igr:text-muted-foreground">{t('referrals.affiliates.payoutHelp')}</p>
                        </div>
                    ) : null}
                    {affiliate ? (
                        <div className="igr:space-y-1.5">
                            <Label htmlFor="af-status">{t('referrals.affiliates.statusLabel')}</Label>
                            <Select id="af-status" value={status} onChange={(e) => setStatus(e.target.value as 'active' | 'paused')}>
                                <option value="active">{t('referrals.affiliates.statuses.active')}</option>
                                <option value="paused">{t('referrals.affiliates.statuses.paused')}</option>
                            </Select>
                        </div>
                    ) : null}
                </form>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        {t('referrals.cancel')}
                    </Button>
                    <Button type="submit" form="affiliate-form" loading={saving}>
                        {affiliate ? t('referrals.affiliates.save') : t('referrals.affiliates.create')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
