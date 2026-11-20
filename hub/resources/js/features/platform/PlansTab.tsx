import { Archive, ArchiveRestore, Layers, Pencil, Plus, RefreshCw } from 'lucide-react';
import { useState, type SyntheticEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input, Select } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { EmptyState, ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { isApiError } from '@/lib/api';
import { formatMoney, formatNumber } from '@/lib/format';
import { toast } from '@/stores/toast';
import { type PlanInput, type PlatformPlan, usePlanArchive, usePlatformPlans, useSavePlan } from './api';
import { useSyncPlans } from './settings-api';

/** Planes de la plataforma: crear, editar, archivar y restaurar (no se borran: hay cuentas y facturas que los nombran). */
export function PlansTab() {
    const { t } = useTranslation();
    const plans = usePlatformPlans();
    const archive = usePlanArchive();
    const sync = useSyncPlans();
    const [editing, setEditing] = useState<PlatformPlan | 'new' | null>(null);

    if (plans.isError) return <ErrorState error={plans.error} onRetry={() => void plans.refetch()} />;
    if (plans.isPending) return <Skeleton className="igr:h-64" />;

    const toggleArchive = (plan: PlatformPlan) =>
        archive.mutate(
            { plan: plan.id, archive: !plan.archived },
            {
                onSuccess: () => toast.success(t(plan.archived ? 'platform.plans.restored' : 'platform.plans.archivedToast')),
                onError: (e) => toast.error(t('errors.title'), isApiError(e) ? e.message : undefined),
            },
        );

    return (
        <div className="igr:space-y-3">
            <div className="igr:flex igr:items-center igr:justify-between igr:gap-3">
                <p className="igr:text-sm igr:text-muted-foreground">{t('platform.plans.help')}</p>
                <div className="igr:flex igr:flex-wrap igr:gap-2">
                    <Button
                        variant="outline"
                        loading={sync.isPending}
                        onClick={() =>
                            sync.mutate(undefined, {
                                onSuccess: (r) => toast.success(r.created > 0 ? t('platformSettings.plansSync.done', { count: r.created }) : t('platformSettings.plansSync.none')),
                                onError: (e) => toast.error(t('errors.title'), isApiError(e) ? e.message : undefined),
                            })
                        }
                    >
                        <RefreshCw />
                        {t('platformSettings.plansSync.button')}
                    </Button>
                    <Button onClick={() => setEditing('new')}>
                        <Plus />
                        {t('platform.plans.new')}
                    </Button>
                </div>
            </div>
            {plans.data.plans.length === 0 ? (
                <EmptyState icon={Layers} title={t('platform.plans.empty')} />
            ) : (
                <Card>
                    <CardContent className="igr:overflow-x-auto igr:pt-4">
                        <Table>
                            <THead>
                                <TR>
                                    <TH>{t('platform.plans.name')}</TH>
                                    <TH>{t('platform.plans.included')}</TH>
                                    <TH className="igr:text-right">{t('platform.plans.price')}</TH>
                                    <TH>{t('platform.plans.modules')}</TH>
                                    <TH className="igr:text-right">{t('platform.plans.accounts')}</TH>
                                    <TH>
                                        <span className="igr:sr-only">{t('platform.accounts.actions')}</span>
                                    </TH>
                                </TR>
                            </THead>
                            <TBody>
                                {plans.data.plans.map((p) => (
                                    <TR key={p.id} className={p.archived ? 'igr:opacity-60' : undefined}>
                                        <TD>
                                            <div className="igr:font-medium">{p.name}</div>
                                            <div className="igr:flex igr:flex-wrap igr:gap-1 igr:pt-1">
                                                <code className="igr:text-[11px] igr:text-muted-foreground">{p.id}</code>
                                                {p.archived && <Badge variant="outline">{t('platform.plans.archived')}</Badge>}
                                                {!p.public && !p.archived && <Badge variant="outline">{t('platform.plans.private')}</Badge>}
                                            </div>
                                        </TD>
                                        <TD>
                                            {t(`platform.plans.metric.${p.metric}`, {
                                                count: p.included,
                                                value: formatNumber(p.included),
                                            })}
                                        </TD>
                                        <TD className="igr:text-right igr:whitespace-nowrap">
                                            {p.price_cop !== null && <div>{formatMoney(Number(p.price_cop), 'COP')}</div>}
                                            <div className="igr:text-muted-foreground">{formatMoney(Number(p.price_usd), 'USD')}</div>
                                        </TD>
                                        <TD className="igr:max-w-72 igr:text-[13px] igr:text-muted-foreground">
                                            {p.modules.length === 0 ? t('platform.plans.coreOnly') : p.modules.map((m) => t(`billing.module.${m}`)).join(', ')}
                                        </TD>
                                        <TD className="igr:text-right">{formatNumber(p.accounts ?? 0)}</TD>
                                        <TD className="igr:text-right igr:whitespace-nowrap">
                                            <Button variant="ghost" size="sm" onClick={() => setEditing(p)}>
                                                <Pencil />
                                                {t('platform.plans.edit')}
                                            </Button>
                                            <Button variant="ghost" size="sm" onClick={() => toggleArchive(p)} loading={archive.isPending && archive.variables.plan === p.id}>
                                                {p.archived ? <ArchiveRestore /> : <Archive />}
                                                {p.archived ? t('platform.plans.restore') : t('platform.plans.archive')}
                                            </Button>
                                        </TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                    </CardContent>
                </Card>
            )}
            {editing !== null && <PlanDialog plan={editing === 'new' ? null : editing} modules={plans.data.modules} onClose={() => setEditing(null)} />}
        </div>
    );
}

const LIMITS = ['emails', 'users', 'ai_budget_usd', 'api_requests_per_minute'] as const;

function PlanDialog({ plan, modules, onClose }: { plan: PlatformPlan | null; modules: string[]; onClose: () => void }) {
    const { t } = useTranslation();
    const save = useSavePlan();
    const [form, setForm] = useState<PlanInput>(() => ({
        id: plan?.id ?? '',
        name: plan?.name ?? '',
        metric: plan?.metric ?? 'orders',
        included: plan?.included ?? 500,
        modules: plan?.modules ?? [],
        limits: Object.fromEntries(LIMITS.map((k) => [k, plan?.limits[k] ?? null])),
        price_usd: plan ? Number(plan.price_usd) : 0,
        price_cop: plan?.price_cop != null ? Number(plan.price_cop) : null,
        public: plan?.public ?? true,
    }));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const set = <K extends keyof PlanInput>(key: K, value: PlanInput[K]) => setForm((f) => ({ ...f, [key]: value }));
    const num = (v: string): number | null => (v.trim() === '' ? null : Number(v));

    const submit = (e: SyntheticEvent) => {
        e.preventDefault();
        const body: PlanInput = { ...form };
        if (plan !== null) delete body.id;
        save.mutate(
            { editing: plan?.id ?? null, plan: body },
            {
                onSuccess: () => {
                    toast.success(t('platform.plans.saved'));
                    onClose();
                },
                onError: (err) => {
                    if (isApiError(err) && Object.keys(err.fieldErrors).length > 0) setErrors(err.fieldErrors);
                    else toast.error(t('errors.title'), isApiError(err) ? err.message : undefined);
                },
            },
        );
    };
    const fieldError = (key: string) => errors[key];

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="igr:max-w-2xl">
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>
                            {plan
                                ? t('platform.plans.editTitle', {
                                      name: plan.name,
                                  })
                                : t('platform.plans.newTitle')}
                        </DialogTitle>
                        <DialogDescription>{t('platform.plans.dialogHelp')}</DialogDescription>
                    </DialogHeader>
                    <div className="igr:grid igr:max-h-[60vh] igr:gap-4 igr:overflow-y-auto igr:py-4 igr:sm:grid-cols-2">
                        <div className="igr:space-y-1.5">
                            <Label htmlFor="plan-name">{t('platform.plans.name')}</Label>
                            <Input id="plan-name" required value={form.name} onChange={(e) => set('name', e.target.value)} placeholder={t('platform.plans.namePlaceholder')} />
                            {fieldError('name') && <p className="igr:text-xs igr:text-destructive">{fieldError('name')}</p>}
                        </div>
                        <div className="igr:space-y-1.5">
                            <Label htmlFor="plan-id">{t('platform.plans.id')}</Label>
                            <Input
                                id="plan-id"
                                required
                                disabled={plan !== null}
                                value={form.id ?? ''}
                                onChange={(e) => set('id', e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, '_'))}
                                placeholder="tienda_pro"
                            />
                            <p className="igr:text-xs igr:text-muted-foreground">{t('platform.plans.idHelp')}</p>
                            {fieldError('id') && <p className="igr:text-xs igr:text-destructive">{fieldError('id')}</p>}
                        </div>
                        <div className="igr:space-y-1.5">
                            <Label htmlFor="plan-metric">{t('platform.plans.metricLabel')}</Label>
                            <Select id="plan-metric" value={form.metric} onChange={(e) => set('metric', e.target.value as PlanInput['metric'])}>
                                <option value="orders">{t('platform.plans.metricOrders')}</option>
                                <option value="contacts">{t('platform.plans.metricContacts')}</option>
                            </Select>
                        </div>
                        <div className="igr:space-y-1.5">
                            <Label htmlFor="plan-included">{t('platform.plans.includedLabel')}</Label>
                            <Input id="plan-included" type="number" min={0} required value={form.included} onChange={(e) => set('included', Number(e.target.value))} />
                        </div>
                        <div className="igr:space-y-1.5">
                            <Label htmlFor="plan-cop">{t('platform.plans.priceCop')}</Label>
                            <Input id="plan-cop" type="number" min={0} step="1000" value={form.price_cop ?? ''} onChange={(e) => set('price_cop', num(e.target.value))} />
                        </div>
                        <div className="igr:space-y-1.5">
                            <Label htmlFor="plan-usd">{t('platform.plans.priceUsd')}</Label>
                            <Input id="plan-usd" type="number" min={0} step="1" required value={form.price_usd} onChange={(e) => set('price_usd', Number(e.target.value))} />
                        </div>
                        <fieldset className="igr:space-y-2 igr:sm:col-span-2">
                            <legend className="igr:text-sm igr:font-medium">{t('platform.plans.modules')}</legend>
                            <p className="igr:text-xs igr:text-muted-foreground">{t('platform.plans.modulesHelp')}</p>
                            <div className="igr:grid igr:gap-1.5 igr:sm:grid-cols-2">
                                {modules.map((m) => (
                                    <label key={m} className="igr:flex igr:items-center igr:gap-2 igr:text-sm">
                                        <input
                                            type="checkbox"
                                            checked={form.modules.includes(m)}
                                            onChange={(e) => set('modules', e.target.checked ? [...form.modules, m] : form.modules.filter((x) => x !== m))}
                                        />
                                        {t(`billing.module.${m}`)}
                                    </label>
                                ))}
                            </div>
                        </fieldset>
                        <fieldset className="igr:grid igr:gap-3 igr:sm:col-span-2 igr:sm:grid-cols-2">
                            <legend className="igr:pb-1 igr:text-sm igr:font-medium">{t('platform.plans.limits')}</legend>
                            {LIMITS.map((k) => (
                                <div key={k} className="igr:space-y-1.5">
                                    <Label htmlFor={`plan-limit-${k}`}>{t(`platform.plans.limit.${k}`)}</Label>
                                    <Input
                                        id={`plan-limit-${k}`}
                                        type="number"
                                        min={0}
                                        step={k === 'ai_budget_usd' ? '0.5' : '1'}
                                        value={form.limits[k] ?? ''}
                                        placeholder={t('platform.plans.unlimited')}
                                        onChange={(e) =>
                                            set('limits', {
                                                ...form.limits,
                                                [k]: num(e.target.value),
                                            })
                                        }
                                    />
                                </div>
                            ))}
                        </fieldset>
                        <label className="igr:flex igr:items-center igr:gap-2 igr:text-sm igr:sm:col-span-2">
                            <input type="checkbox" checked={form.public} onChange={(e) => set('public', e.target.checked)} />
                            {t('platform.plans.publicLabel')}
                        </label>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            {t('common.cancel')}
                        </Button>
                        <Button type="submit" loading={save.isPending}>
                            {t('platform.plans.save')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
