import { ChevronDown, CircleCheck, Lock } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Callout } from '@/components/ui/callout';
import { IconTile } from '@/components/ui/icon-tile';
import { Switch } from '@/components/ui/switch';
import { blockingRequirements, isOn } from '@/features/strategies/api';
import { strategyStyle } from '@/features/strategies/presentation';
import { RequirementList } from '@/features/strategies/StrategySheet';
import { StrategyPreview } from '@/features/strategies/StrategyPreview';
import type { StrategyKey, StrategyRequirement } from '@/features/strategies/types';
import { useWorkspace } from '@/hooks/useWorkspace';
import { cn } from '@/lib/cn';
import { summarizeActivation, useActivateRecommended } from '../api';
import { stepForRequirement } from '../flow';
import { StepFrame } from '../StepFrame';
import type { ActivationResult, Onboarding, OnboardingStepKey, RecommendedStrategy } from '../types';

/*
 * Paso 5 (docs/diseno/rediseno-v2.md §4.2): las 3 estrategias recomendadas para el negocio (según su vertical), cada
 * una con su promesa, la vista previa del mensaje y un interruptor. «Prender las elegidas» las deja funcionando de una
 * vez (POST onboarding/strategies); lo que no se pudo prender dice qué le falta y dónde resolverlo.
 */

function Row({
    strategy,
    checked,
    onChange,
    result,
    base,
    disabled,
    resolve,
}: {
    strategy: RecommendedStrategy;
    checked: boolean;
    onChange: (on: boolean) => void;
    result: ActivationResult | undefined;
    base: string;
    disabled: boolean;
    resolve: (requirement: StrategyRequirement) => (() => void) | null;
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const style = strategyStyle(strategy);
    const on = isOn(strategy.status) || result?.result === 'activated' || result?.result === 'already_active';
    const locked = strategy.status === 'not_in_plan';
    // Lo que le falta antes de poder prenderla (p. ej. un canal): el interruptor espera y se dice qué falta.
    const blocking = on ? [] : blockingRequirements(strategy);
    const id = `wz-str-${strategy.key}`;
    return (
        <li className={cn('igr:rounded-2xl igr:border igr:bg-card igr:p-4 igr:sm:p-5', on && 'igr:border-success/30')}>
            <div className="igr:flex igr:items-start igr:gap-3">
                <IconTile icon={style.icon} tone={style.tone} />
                <div className="igr:min-w-0 igr:flex-1">
                    <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-2">
                        <label htmlFor={id} className="igr:text-body igr:font-semibold igr:text-foreground">
                            {strategy.name}
                        </label>
                        {on ? (
                            <Badge variant="success">
                                <CircleCheck className="igr:size-3" aria-hidden />
                                {t('onboarding.wizard.strategies.on')}
                            </Badge>
                        ) : locked ? (
                            <Badge variant="info">
                                <Lock className="igr:size-3" aria-hidden />
                                {strategy.plans[0] ? t('strategies.card.inPlan', { plan: strategy.plans[0].name }) : t('strategies.status.not_in_plan')}
                            </Badge>
                        ) : null}
                    </div>
                    <p className="igr:mt-1 igr:text-support igr:text-muted-foreground">{strategy.promise}</p>
                    {strategy.potential ? <p className="igr:mt-1.5 igr:text-caption igr:font-medium igr:text-foreground">{strategy.potential.summary}</p> : null}
                </div>
                <Switch id={id} checked={on || (checked && blocking.length === 0)} disabled={on || locked || disabled || blocking.length > 0} onCheckedChange={onChange} className="igr:mt-1" />
            </div>
            <div className="igr:mt-3 igr:sm:pl-12">
                <button
                    type="button"
                    aria-expanded={open}
                    aria-controls={`${id}-preview`}
                    onClick={() => setOpen(!open)}
                    className="igr:inline-flex igr:items-center igr:gap-1 igr:rounded-md igr:text-caption igr:font-medium igr:text-primary igr:hover:underline igr:focus-visible:outline-none igr:focus-visible:ring-2 igr:focus-visible:ring-ring"
                >
                    <ChevronDown className={cn('igr:size-3.5 igr:transition-transform', open && 'igr:rotate-180')} aria-hidden />
                    {open ? t('onboarding.wizard.strategies.hidePreview') : t('onboarding.wizard.strategies.showPreview')}
                </button>
                {open ? (
                    <div id={`${id}-preview`} className="igr:mt-3 igr:grid igr:gap-4 igr:md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                        <ol className="igr:space-y-2">
                            {strategy.steps.map((step, i) => (
                                <li key={i} className="igr:flex igr:gap-2 igr:text-support">
                                    <span className="igr:flex igr:size-5 igr:shrink-0 igr:items-center igr:justify-center igr:rounded-full igr:bg-brand-soft igr:text-[11px] igr:font-semibold igr:text-brand-soft-foreground">
                                        {i + 1}
                                    </span>
                                    <span className="igr:min-w-0 igr:text-foreground">{step}</span>
                                </li>
                            ))}
                        </ol>
                        <StrategyPreview preview={strategy.preview} compact />
                    </div>
                ) : null}
                {result && (result.result === 'needs_setup' || result.result === 'coming_soon') ? (
                    <div className="igr:mt-3 igr:space-y-2">
                        <p className="igr:text-support igr:text-warning">{result.message}</p>
                        {result.requirements ? <RequirementList requirements={result.requirements.filter((r) => !r.met)} base={base} compact resolve={resolve} /> : null}
                    </div>
                ) : !result && blocking.length > 0 && !locked ? (
                    <div className="igr:mt-3 igr:space-y-2">
                        <p className="igr:text-caption igr:font-medium igr:text-muted-foreground">{t('onboarding.wizard.strategies.needs')}</p>
                        <RequirementList requirements={blocking} base={base} compact resolve={resolve} />
                    </div>
                ) : result && (result.result === 'not_in_plan' || result.result === 'forbidden') ? (
                    <p className="igr:mt-3 igr:text-support igr:text-muted-foreground">{result.message}</p>
                ) : null}
            </div>
        </li>
    );
}

export function StrategiesStep({
    state,
    base,
    onBack,
    onNext,
    onGoTo,
    busy,
}: {
    state: Onboarding;
    base: string;
    onBack: () => void;
    onNext: () => void;
    onGoTo: (step: OnboardingStepKey) => void;
    busy: boolean;
}) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const activate = useActivateRecommended(workspace.id);
    // Las que se pueden prender ya: ni prendidas, ni fuera del plan, ni esperando algo (un canal, la web…).
    const pending = state.recommended.filter((s) => !isOn(s.status) && s.status !== 'not_in_plan' && s.status !== 'coming_soon' && blockingRequirements(s).length === 0);
    const waiting = state.recommended.filter((s) => !isOn(s.status) && s.status !== 'not_in_plan' && blockingRequirements(s).length > 0);
    const resolve = (r: StrategyRequirement) => {
        const step = stepForRequirement(r.key);
        return step ? () => onGoTo(step) : null;
    };
    const [chosen, setChosen] = useState<Set<StrategyKey>>(() => new Set(pending.map((s) => s.key)));
    const [results, setResults] = useState<ActivationResult[] | null>(null);
    const canActivate = workspace.abilities.includes('manageMarketing');
    const toActivate = pending.filter((s) => chosen.has(s.key)).map((s) => s.key);
    const summary = results ? summarizeActivation(results) : null;

    const run = () => {
        activate.mutate(toActivate, { onSuccess: (res) => setResults(res.results) });
    };

    return (
        <StepFrame
            step="strategies"
            title={t('onboarding.wizard.strategies.title')}
            description={t('onboarding.wizard.strategies.description')}
            onBack={onBack}
            onNext={results !== null || toActivate.length === 0 || !canActivate ? onNext : run}
            nextLabel={
                results !== null || toActivate.length === 0 || !canActivate
                    ? t('onboarding.wizard.next')
                    : t('onboarding.wizard.strategies.activate', { count: toActivate.length })
            }
            onLater={results === null && toActivate.length > 0 && canActivate ? onNext : undefined}
            laterLabel={t('onboarding.wizard.strategies.later')}
            busy={busy || activate.isPending}
        >
            {summary ? (
                <Callout
                    tone={summary.on.length === 0 ? 'warning' : summary.pending.length > 0 ? 'info' : 'success'}
                    className="igr:mb-5"
                    title={summary.on.length === 0 ? t('onboarding.wizard.strategies.resultNone') : t('onboarding.wizard.strategies.resultTitle', { count: summary.on.length })}
                >
                    {summary.pending.length > 0 ? t('onboarding.wizard.strategies.resultPending', { count: summary.pending.length }) : t('onboarding.wizard.strategies.resultAll')}
                </Callout>
            ) : waiting.length > 0 && pending.length === 0 ? (
                <Callout tone="brand" className="igr:mb-5" title={t('onboarding.wizard.strategies.waitingTitle', { count: waiting.length })}>
                    {t('onboarding.wizard.strategies.waitingBody')}
                </Callout>
            ) : null}
            {activate.isError ? (
                <Callout tone="danger" className="igr:mb-5" title={t('errors.generic')}>
                    <Button size="sm" variant="outline" onClick={run} className="igr:mt-2">
                        {t('common.retry')}
                    </Button>
                </Callout>
            ) : null}
            {!canActivate ? (
                <Callout tone="neutral" className="igr:mb-5">
                    {t('onboarding.wizard.strategies.ask')}
                </Callout>
            ) : null}
            <ul className="igr:space-y-3">
                {state.recommended.map((s) => (
                    <Row
                        key={s.key}
                        strategy={s}
                        checked={chosen.has(s.key)}
                        onChange={(on) => {
                            const next = new Set(chosen);
                            if (on) next.add(s.key);
                            else next.delete(s.key);
                            setChosen(next);
                        }}
                        result={results?.find((r) => r.key === s.key)}
                        base={base}
                        disabled={!canActivate || results !== null}
                        resolve={resolve}
                    />
                ))}
            </ul>
            <p className="igr:mt-4 igr:text-caption igr:text-muted-foreground">{t('onboarding.wizard.strategies.note')}</p>
        </StepFrame>
    );
}
