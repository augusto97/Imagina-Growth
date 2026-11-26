import { ArrowRight, Check, CircleDashed, Pause, Play, Power, Settings2, X } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Callout } from '@/components/ui/callout';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { IconTile } from '@/components/ui/icon-tile';
import { Illustration } from '@/components/ui/illustrations';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ErrorState } from '@/components/ui/misc';
import { Sheet, SheetBody, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Skeleton, SkeletonText } from '@/components/ui/skeleton';
import { useWorkspace } from '@/hooks/useWorkspace';
import { cn } from '@/lib/cn';
import { formatNumber } from '@/lib/format';
import { toast } from '@/stores/toast';
import { activationProblem, canActivate, canManage, isOn, nextFix, useActivateStrategy, usePauseStrategy, useStrategy } from './api';
import { statusBadge, strategyStyle } from './presentation';
import { ResultLine } from './StrategyCard';
import { StrategyPreview } from './StrategyPreview';
import type { ActivateInput, ActivateResponse, StrategyDetail, StrategyKey, StrategyRequirement, StrategySummary } from './types';

/*
 * El detalle de una estrategia en una hoja lateral (docs/diseno/rediseno-v2.md §6; en el celular ocupa toda la
 * pantalla): qué logra, cómo funciona en 3 pasos, la vista previa real, lo que necesita (con el botón para resolver
 * cada cosa), lo que va a crear y prender o pausar con confirmación. Si falta algo (422) dice qué; si el plan no la
 * incluye (402), qué plan sí. Después de prenderla: qué pasa ahora y dónde verlo.
 */

function Block({ title, children, className }: { title: ReactNode; children: ReactNode; className?: string }) {
    return (
        <section className={cn('igr:space-y-2.5', className)}>
            <h3 className="igr:text-caption igr:font-semibold igr:tracking-wide igr:text-muted-foreground igr:uppercase">{title}</h3>
            {children}
        </section>
    );
}

/** Lo que necesita, cada cosa con su estado y el botón para resolverla. */
export function RequirementList({
    requirements,
    base,
    compact = false,
    resolve,
}: {
    requirements: StrategyRequirement[];
    base: string;
    compact?: boolean;
    /** Para resolver un requisito sin salir de la pantalla (el asistente lleva a su paso); null: el enlace de siempre. */
    resolve?: (requirement: StrategyRequirement) => (() => void) | null;
}) {
    const { t } = useTranslation();
    return (
        <ul className="igr:divide-y igr:rounded-xl igr:border igr:bg-card">
            {requirements.map((r) => (
                <li key={`${r.key}-${r.label}`} className={cn('igr:flex igr:flex-wrap igr:items-center igr:gap-x-3 igr:gap-y-2', compact ? 'igr:px-3 igr:py-2' : 'igr:px-3.5 igr:py-3')}>
                    <span
                        aria-hidden
                        className={cn(
                            'igr:flex igr:size-5 igr:shrink-0 igr:items-center igr:justify-center igr:rounded-full',
                            r.met ? 'igr:bg-success/12 igr:text-success' : r.required ? 'igr:bg-warning/12 igr:text-warning' : 'igr:bg-muted igr:text-muted-foreground',
                        )}
                    >
                        {r.met ? <Check className="igr:size-3.5" strokeWidth={3} /> : <CircleDashed className="igr:size-3.5" />}
                    </span>
                    <div className="igr:min-w-0 igr:flex-1 igr:basis-40">
                        <p className="igr:text-support igr:font-medium igr:text-foreground">
                            {r.label}
                            <span className="igr:sr-only"> · {t(r.met ? 'strategies.requirement.met' : r.required ? 'strategies.requirement.missing' : 'strategies.requirement.optional')}</span>
                        </p>
                        {!r.met && r.hint ? <p className="igr:mt-0.5 igr:text-caption igr:text-muted-foreground">{r.hint}</p> : null}
                    </div>
                    {!r.met && r.action && resolve?.(r) ? (
                        <Button size="sm" variant={r.required ? 'default' : 'outline'} className="igr:max-sm:w-full" onClick={resolve(r) ?? undefined}>
                            {r.action}
                            <ArrowRight aria-hidden />
                        </Button>
                    ) : !r.met && r.to && r.action ? (
                        <Button asChild size="sm" variant={r.required ? 'default' : 'outline'} className="igr:max-sm:w-full">
                            <Link to={`${base}/${r.to}`}>
                                {r.action}
                                <ArrowRight aria-hidden />
                            </Link>
                        </Button>
                    ) : null}
                </li>
            ))}
        </ul>
    );
}

/** Lo que pide escribir antes de prender (el enlace de Google o el número del botón de WhatsApp). */
function inlineInputs(strategy: Pick<StrategySummary, 'requirements'>): ('google_review_url' | 'wa_number')[] {
    const out: ('google_review_url' | 'wa_number')[] = [];
    for (const r of strategy.requirements) {
        if (!r.met && r.key === 'google_url') out.push('google_review_url');
        if (!r.met && r.key === 'wa_number') out.push('wa_number');
    }
    return out;
}

function SuccessView({ result, base, onClose }: { result: ActivateResponse; base: string; onClose: () => void }) {
    const { t } = useTranslation();
    return (
        <div className="igr:space-y-5">
            <div className="igr:flex igr:flex-col igr:items-center igr:text-center">
                <Illustration name="celebration" className="igr:w-36" />
                <p className="igr:mt-2 igr:text-section igr:font-semibold igr:text-foreground">{t('strategies.success.title', { name: result.name })}</p>
                <p className="igr:mt-1 igr:max-w-sm igr:text-support igr:text-muted-foreground">{t('strategies.success.body')}</p>
            </div>
            <Block title={t('strategies.success.now')}>
                <ol className="igr:space-y-2">
                    {result.steps.map((step, i) => (
                        <li key={i} className="igr:flex igr:gap-2.5 igr:text-support">
                            <span className="igr:flex igr:size-5 igr:shrink-0 igr:items-center igr:justify-center igr:rounded-full igr:bg-brand-soft igr:text-[11px] igr:font-semibold igr:text-brand-soft-foreground igr:tabular-nums">
                                {i + 1}
                            </span>
                            <span className="igr:min-w-0 igr:text-foreground">{step}</span>
                        </li>
                    ))}
                </ol>
            </Block>
            {result.skipped.length > 0 ? (
                <Callout tone="info" title={t('strategies.success.skippedTitle')}>
                    {t('strategies.success.skipped', { count: result.skipped.length })}
                </Callout>
            ) : null}
            <Block title={t('strategies.success.where')}>
                <ul className="igr:space-y-2 igr:text-support">
                    <li>
                        <Link to={base} onClick={onClose} className="igr:font-medium igr:text-primary igr:underline-offset-2 igr:hover:underline">
                            {t('strategies.success.home')}
                        </Link>{' '}
                        <span className="igr:text-muted-foreground">{t('strategies.success.homeHint')}</span>
                    </li>
                    {result.manage_to ? (
                        <li>
                            <Link to={`${base}/${result.manage_to}`} className="igr:font-medium igr:text-primary igr:underline-offset-2 igr:hover:underline">
                                {t('strategies.success.adjust')}
                            </Link>{' '}
                            <span className="igr:text-muted-foreground">{t('strategies.success.adjustHint')}</span>
                        </li>
                    ) : null}
                </ul>
            </Block>
        </div>
    );
}

function DetailBody({ strategy, detail, base, inputs, setInputs }: { strategy: StrategySummary; detail: StrategyDetail | undefined; base: string; inputs: ActivateInput; setInputs: (i: ActivateInput) => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const currency = detail?.currency ?? workspace.currency;
    const on = isOn(strategy.status);
    const fields = inlineInputs(strategy);
    return (
        <div className="igr:space-y-6">
            {strategy.status === 'attention' && strategy.problems.length > 0 ? (
                <Callout tone="warning" title={t('strategies.detail.attention')}>
                    {strategy.problems.map((p) => p.message).join(' ')}
                </Callout>
            ) : null}
            {strategy.status === 'coming_soon' ? (
                <Callout tone="neutral" title={t('strategies.status.coming_soon')}>
                    {t('strategies.detail.comingSoon')}
                </Callout>
            ) : null}
            {strategy.status === 'not_in_plan' ? (
                <Callout
                    tone="brand"
                    title={strategy.plans[0] ? t('strategies.detail.planTitle', { plan: strategy.plans[0].name }) : t('strategies.status.not_in_plan')}
                    action={
                        workspace.abilities.includes('manageBilling') ? (
                            <Button asChild size="sm">
                                <Link to={`${base}/facturacion`}>{t('strategies.detail.seePlans')}</Link>
                            </Button>
                        ) : undefined
                    }
                >
                    {workspace.abilities.includes('manageBilling') ? t('strategies.detail.planBody') : t('strategies.detail.planAsk')}
                </Callout>
            ) : null}

            {on && strategy.result ? (
                <div className="igr:rounded-xl igr:border igr:bg-success/6 igr:px-4 igr:py-3">
                    <p className="igr:text-caption igr:font-medium igr:text-muted-foreground">{t('strategies.detail.thisMonth')}</p>
                    <p className="igr:mt-0.5 igr:text-body igr:font-semibold igr:text-foreground igr:tabular-nums">
                        <ResultLine strategy={strategy} currency={currency} />
                    </p>
                    {strategy.result.messages !== null && strategy.result.messages > 0 && Number(strategy.result.revenue) > 0 ? (
                        <p className="igr:text-caption igr:text-muted-foreground">
                            {t('strategies.card.messages', { count: strategy.result.messages, formatted: formatNumber(strategy.result.messages, workspace.locale) })}
                        </p>
                    ) : null}
                </div>
            ) : strategy.potential ? (
                <div className="igr:rounded-xl igr:border igr:border-primary/15 igr:bg-brand-soft/50 igr:px-4 igr:py-3">
                    <p className="igr:text-caption igr:font-medium igr:text-brand-soft-foreground">{t('strategies.detail.potential')}</p>
                    <p className="igr:mt-0.5 igr:text-support igr:text-foreground">{strategy.potential.summary}</p>
                </div>
            ) : null}

            <Block title={t('strategies.detail.how')}>
                {detail ? (
                    <ol className="igr:space-y-2.5">
                        {detail.steps.map((step, i) => (
                            <li key={i} className="igr:flex igr:gap-3 igr:text-support">
                                <span className="igr:flex igr:size-6 igr:shrink-0 igr:items-center igr:justify-center igr:rounded-full igr:bg-brand-soft igr:text-caption igr:font-semibold igr:text-brand-soft-foreground igr:tabular-nums">
                                    {i + 1}
                                </span>
                                <span className="igr:min-w-0 igr:pt-0.5 igr:text-foreground">{step}</span>
                            </li>
                        ))}
                    </ol>
                ) : (
                    <SkeletonText lines={3} />
                )}
            </Block>

            <Block title={t('strategies.detail.preview')}>{detail ? <StrategyPreview preview={detail.preview} /> : <Skeleton className="igr:h-36 igr:rounded-xl" />}</Block>

            {strategy.requirements.length > 0 ? (
                <Block title={t('strategies.detail.needs')}>
                    <RequirementList requirements={strategy.requirements} base={base} />
                    {fields.map((field) => (
                        <div key={field} className="igr:space-y-1.5 igr:pt-1">
                            <Label htmlFor={`str-${field}`}>{t(`strategies.detail.inputs.${field}`)}</Label>
                            <Input
                                id={`str-${field}`}
                                inputMode={field === 'wa_number' ? 'tel' : 'url'}
                                placeholder={t(`strategies.detail.inputs.${field}Placeholder`)}
                                value={inputs[field] ?? ''}
                                onChange={(e) => setInputs({ ...inputs, [field]: e.target.value })}
                            />
                        </div>
                    ))}
                </Block>
            ) : null}

            {detail && detail.parts.length > 0 ? (
                <Block title={on ? t('strategies.detail.includes') : t('strategies.detail.creates')}>
                    <ul className="igr:space-y-2">
                        {detail.parts.map((part, i) => (
                            <li key={`${part.kind}-${part.key ?? i}`} className="igr:flex igr:items-center igr:gap-2.5 igr:text-support">
                                <span
                                    aria-hidden
                                    className={cn('igr:size-2 igr:shrink-0 igr:rounded-full', part.status === 'on' ? 'igr:bg-success' : part.status === 'paused' ? 'igr:bg-warning' : 'igr:bg-border')}
                                />
                                <span className="igr:min-w-0 igr:flex-1 igr:text-foreground">
                                    {part.name}
                                    {!part.primary ? <span className="igr:text-muted-foreground"> · {t('strategies.detail.support')}</span> : null}
                                </span>
                                <span className="igr:shrink-0 igr:text-caption igr:text-muted-foreground">{t(`strategies.parts.${part.status}`)}</span>
                            </li>
                        ))}
                    </ul>
                </Block>
            ) : null}
        </div>
    );
}

/**
 * La hoja del detalle. `summary` (del catálogo o del Inicio) pinta lo principal mientras llega el detalle.
 */
export function StrategySheet({ strategyKey, summary, open, onOpenChange, base }: { strategyKey: StrategyKey | null; summary?: StrategySummary; open: boolean; onOpenChange: (open: boolean) => void; base: string }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const detail = useStrategy(workspace.id, strategyKey ?? undefined);
    const activate = useActivateStrategy(workspace.id);
    const pause = usePauseStrategy(workspace.id);
    const [confirm, setConfirm] = useState<'activate' | 'pause' | null>(null);
    const [done, setDone] = useState<ActivateResponse | null>(null);
    const [inputs, setInputs] = useState<ActivateInput>({});
    const strategy: StrategySummary | undefined = detail.data ?? summary;
    const problem = activationProblem(activate.error);

    const close = (next: boolean) => {
        onOpenChange(next);
        if (!next) {
            setDone(null);
            setConfirm(null);
            setInputs({});
            activate.reset();
        }
    };

    const doActivate = () => {
        if (!strategy) return;
        const input = Object.fromEntries(Object.entries(inputs).filter(([, v]) => typeof v === 'string' && v.trim() !== '')) as ActivateInput;
        activate.mutate(
            { key: strategy.key, input },
            {
                onSuccess: (res) => {
                    setConfirm(null);
                    setDone(res);
                    if (!res.activated) toast.info(t('strategies.toast.alreadyOn'));
                },
                onError: () => setConfirm(null),
            },
        );
    };
    const doPause = () => {
        if (!strategy) return;
        pause.mutate(strategy.key, {
            onSuccess: () => {
                setConfirm(null);
                toast.success(t('strategies.toast.paused', { name: strategy.name }));
            },
            onError: () => {
                setConfirm(null);
                toast.error(t('errors.generic'));
            },
        });
    };

    const style = strategy ? strategyStyle(strategy) : null;
    const allowed = strategy ? canManage(strategy, workspace.abilities) : false;
    const on = strategy ? isOn(strategy.status) : false;
    const fix = strategy && strategy.status === 'needs_setup' ? nextFix(strategy) : null;

    return (
        <Sheet open={open} onOpenChange={close}>
            <SheetContent
                side="right"
                className="igr:sm:max-w-xl"
                aria-describedby={undefined}
                // El foco queda en la hoja (no en el primer botón): el lector de pantalla empieza por el título.
                onOpenAutoFocus={(e) => {
                    e.preventDefault();
                    (e.currentTarget as HTMLElement | null)?.focus();
                }}
            >
                {!strategy ? (
                    detail.isError ? (
                        <SheetBody>
                            <SheetTitle className="igr:sr-only">{t('strategies.title')}</SheetTitle>
                            <ErrorState error={detail.error} onRetry={() => void detail.refetch()} />
                        </SheetBody>
                    ) : (
                        <SheetBody aria-busy>
                            <SheetTitle className="igr:sr-only">{t('common.loading')}</SheetTitle>
                            <Skeleton className="igr:h-8 igr:w-2/3" />
                            <SkeletonText lines={3} className="igr:mt-4" />
                            <Skeleton className="igr:mt-6 igr:h-40 igr:rounded-xl" />
                        </SheetBody>
                    )
                ) : (
                    <>
                        <SheetHeader>
                            <div className="igr:flex igr:items-start igr:gap-3">
                                {style ? <IconTile icon={style.icon} tone={style.tone} size="lg" /> : null}
                                <div className="igr:min-w-0 igr:flex-1">
                                    <SheetTitle className="igr:leading-6">{strategy.name}</SheetTitle>
                                    <div className="igr:mt-1.5">
                                        <Badge variant={statusBadge(done ? 'active' : strategy.status)}>{t(`strategies.status.${done ? 'active' : strategy.status}`)}</Badge>
                                    </div>
                                </div>
                            </div>
                            <SheetDescription className="igr:mt-2 igr:text-support igr:text-muted-foreground">{strategy.promise}</SheetDescription>
                        </SheetHeader>
                        <SheetBody className="igr:overflow-y-auto">
                            {done ? (
                                <SuccessView result={done} base={base} onClose={() => close(false)} />
                            ) : (
                                <>
                                    {problem?.kind === 'needs_setup' ? (
                                        <Callout tone="warning" title={problem.body.message} className="igr:mb-5">
                                            <RequirementList requirements={problem.body.requirements.filter((r) => !r.met)} base={base} compact />
                                            {Object.values(problem.body.issues).flat().length > 0 ? (
                                                <ul className="igr:mt-2 igr:list-disc igr:pl-5">
                                                    {Object.values(problem.body.issues)
                                                        .flat()
                                                        .map((issue) => (
                                                            <li key={issue}>{issue}</li>
                                                        ))}
                                                </ul>
                                            ) : null}
                                            {problem.body.to ? (
                                                <Button asChild size="sm" variant="outline" className="igr:mt-3">
                                                    <Link to={`${base}/${problem.body.to}`}>{t('strategies.detail.review')}</Link>
                                                </Button>
                                            ) : null}
                                        </Callout>
                                    ) : null}
                                    {problem?.kind === 'plan_required' ? (
                                        <Callout tone="brand" title={problem.body.message} className="igr:mb-5">
                                            {problem.body.plans.length > 0 ? t('strategies.detail.plansWith', { plans: problem.body.plans.map((p) => p.name).join(', ') }) : null}
                                        </Callout>
                                    ) : null}
                                    {activate.isError && !problem ? (
                                        <Callout tone="danger" title={t('errors.generic')} className="igr:mb-5" />
                                    ) : null}
                                    <DetailBody strategy={strategy} detail={detail.data} base={base} inputs={inputs} setInputs={setInputs} />
                                </>
                            )}
                        </SheetBody>
                        <SheetFooter className="igr:sticky igr:bottom-0 igr:items-center">
                            {done ? (
                                <Button onClick={() => close(false)}>{t('strategies.success.close')}</Button>
                            ) : !allowed ? (
                                <p className="igr:mr-auto igr:text-caption igr:text-muted-foreground">{t('strategies.detail.askAdmin')}</p>
                            ) : (
                                <>
                                    {strategy.manage_to && (on || strategy.status === 'paused') ? (
                                        <Button asChild variant="ghost" className="igr:mr-auto">
                                            <Link to={`${base}/${strategy.manage_to}`}>
                                                <Settings2 aria-hidden />
                                                {t('strategies.detail.adjust')}
                                            </Link>
                                        </Button>
                                    ) : null}
                                    {on ? (
                                        <Button variant="outline" onClick={() => setConfirm('pause')} loading={pause.isPending}>
                                            <Pause aria-hidden />
                                            {t('strategies.detail.pause')}
                                        </Button>
                                    ) : fix && fix.to && fix.action && inlineInputs(strategy).length === 0 ? (
                                        // Le falta algo que se resuelve en otra pantalla: el botón principal lleva ahí (prender fallaría).
                                        <Button asChild className="igr:max-sm:flex-1">
                                            <Link to={`${base}/${fix.to}`}>
                                                {fix.action}
                                                <ArrowRight aria-hidden />
                                            </Link>
                                        </Button>
                                    ) : canActivate(strategy) ? (
                                        <Button onClick={() => setConfirm('activate')} loading={activate.isPending} className="igr:max-sm:flex-1">
                                            {strategy.status === 'paused' ? <Play aria-hidden /> : <Power aria-hidden />}
                                            {t(strategy.status === 'paused' ? 'strategies.detail.resume' : 'strategies.detail.activate')}
                                        </Button>
                                    ) : (
                                        <Button variant="outline" onClick={() => close(false)}>
                                            <X aria-hidden />
                                            {t('common.close')}
                                        </Button>
                                    )}
                                </>
                            )}
                        </SheetFooter>

                        <Dialog open={confirm !== null} onOpenChange={(next) => !next && setConfirm(null)}>
                            <DialogContent>
                                <DialogHeader>
                                    <DialogTitle>{t(confirm === 'pause' ? 'strategies.confirm.pauseTitle' : 'strategies.confirm.activateTitle', { name: strategy.name })}</DialogTitle>
                                    <DialogDescription>{t(confirm === 'pause' ? 'strategies.confirm.pauseBody' : 'strategies.confirm.activateBody')}</DialogDescription>
                                </DialogHeader>
                                {confirm === 'activate' && detail.data && detail.data.parts.length > 0 ? (
                                    <ul className="igr:space-y-1.5 igr:text-support">
                                        {detail.data.parts.map((part, i) => (
                                            <li key={`${part.kind}-${part.key ?? i}`} className="igr:flex igr:gap-2">
                                                <Check className="igr:mt-0.5 igr:size-4 igr:shrink-0 igr:text-success" aria-hidden />
                                                <span className="igr:min-w-0">{part.name}</span>
                                            </li>
                                        ))}
                                    </ul>
                                ) : null}
                                <DialogFooter>
                                    <Button variant="outline" onClick={() => setConfirm(null)}>
                                        {t('common.cancel')}
                                    </Button>
                                    {confirm === 'pause' ? (
                                        <Button variant="destructive" onClick={doPause} loading={pause.isPending}>
                                            {t('strategies.confirm.pause')}
                                        </Button>
                                    ) : (
                                        <Button onClick={doActivate} loading={activate.isPending}>
                                            {t(strategy.status === 'paused' ? 'strategies.confirm.resume' : 'strategies.confirm.activate')}
                                        </Button>
                                    )}
                                </DialogFooter>
                            </DialogContent>
                        </Dialog>
                    </>
                )}
            </SheetContent>
        </Sheet>
    );
}
