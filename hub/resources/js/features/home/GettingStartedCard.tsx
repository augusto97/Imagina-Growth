import { ArrowRight, Check, PartyPopper } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import { Skeleton } from '@/components/ui/skeleton';
import { useWorkspace } from '@/hooks/useWorkspace';
import { cn } from '@/lib/cn';
import { formatDate, formatMoney } from '@/lib/format';
import { useUi } from '@/stores/ui';
import { guideVisible, isComplete, nextStep, stepAction, stepOwnerHint, useGettingStarted, type GettingStarted, type GuideStep } from './gettingStarted';

/** Lo que dice el paso según su estado real (home.guide.steps.<key>.*). */
function StepDetail({ step, guide }: { step: GuideStep; guide: GettingStarted }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const money = (v: string | null) => formatMoney(v ?? '0', guide.currency, workspace.locale);
    const v = guide.variant;
    switch (step.key) {
        case 'connect_site':
            return <>{step.done ? t('home.guide.steps.connect_site.done') : step.pending ? t('home.guide.steps.connect_site.pending') : t('home.guide.steps.connect_site.help')}</>;
        case 'import':
            if (step.done) {
                return <>{t('home.guide.steps.import.done')}</>;
            }
            if (step.status === 'running' || step.status === 'queued') {
                return (
                    <span className="igr:flex igr:items-center igr:gap-2">
                        <span>{t('home.guide.steps.import.running', { pct: Math.round((step.progress ?? 0) * 100) })}</span>
                        <Progress value={step.progress} className="igr:h-1 igr:w-24" label={t('home.guide.steps.import.title')} />
                    </span>
                );
            }
            return <>{step.status === 'failed' || step.status === 'paused' ? t('home.guide.steps.import.stopped') : t('home.guide.steps.import.help')}</>;
        case 'channel':
            return (
                <>
                    {step.done
                        ? t('home.guide.steps.channel.done', { channels: step.channels.map((c) => t(`home.guide.channel.${c}`)).join(t('home.guide.and')) })
                        : t('home.guide.steps.channel.help')}
                </>
            );
        case 'flow':
            if (step.done) {
                return <>{t(`home.guide.steps.flow.${v}.done`)}</>;
            }
            return <>{step.status === 'missing' ? t(`home.guide.steps.flow.${v}.help`) : t(`home.guide.steps.flow.status.${step.status}`)}</>;
        case 'first_sale':
            return step.done ? (
                <>
                    {t(`home.guide.steps.first_sale.${v}.done`, { count: step.count, amount: money(step.revenue), first: money(step.first_revenue) })}
                    {step.first_at ? ` ${t('home.guide.steps.first_sale.when', { date: formatDate(step.first_at, workspace.locale, workspace.timezone) })}` : ''}
                </>
            ) : (
                <>{t(`home.guide.steps.first_sale.${v}.help`)}</>
            );
    }
}

function StepRow({ step, index, current, guide }: { step: GuideStep; index: number; current: boolean; guide: GettingStarted }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const base = `/w/${workspace.id}`;
    const action = step.done ? null : stepAction(step, workspace.abilities);
    const hint = step.done || action ? null : stepOwnerHint(step);
    const title = step.key === 'flow' || step.key === 'first_sale' ? t(`home.guide.steps.${step.key}.${guide.variant}.title`) : t(`home.guide.steps.${step.key}.title`);

    return (
        <li
            className={cn(
                'igr:flex igr:flex-col igr:gap-2 igr:rounded-lg igr:px-3 igr:py-3 igr:sm:flex-row igr:sm:items-center igr:sm:gap-3',
                current && 'igr:bg-accent/60 igr:ring-1 igr:ring-primary/30',
            )}
        >
            <div className="igr:flex igr:min-w-0 igr:flex-1 igr:items-start igr:gap-3">
                <span
                    className={cn(
                        'igr:mt-0.5 igr:flex igr:size-6 igr:shrink-0 igr:items-center igr:justify-center igr:rounded-full igr:border igr:text-[11px] igr:font-semibold igr:tabular-nums',
                        step.done
                            ? 'igr:border-success igr:bg-success igr:text-white'
                            : current
                              ? 'igr:border-primary igr:text-primary'
                              : 'igr:border-input igr:text-muted-foreground',
                    )}
                    aria-hidden
                >
                    {step.done ? <Check className="igr:size-3.5" /> : index + 1}
                </span>
                <div className="igr:min-w-0 igr:flex-1">
                    <p className={cn('igr:text-[13px] igr:font-medium', step.done && 'igr:text-muted-foreground')}>
                        {title}
                        <span className="igr:sr-only"> · {step.done ? t('home.guide.stateDone') : t('home.guide.statePending')}</span>
                    </p>
                    <div className="igr:text-xs igr:text-muted-foreground">
                        <StepDetail step={step} guide={guide} />
                    </div>
                </div>
            </div>
            {action ? (
                <div className="igr:shrink-0 igr:pl-9 igr:sm:pl-0">
                    <Button asChild size="sm" variant={current ? 'default' : 'outline'}>
                        <Link to={action.to.startsWith('/') ? action.to : `${base}/${action.to}`}>
                            {t(`home.guide.actions.${action.label}`)}
                            {current ? <ArrowRight /> : null}
                        </Link>
                    </Button>
                </div>
            ) : hint ? (
                <p className="igr:shrink-0 igr:pl-9 igr:text-xs igr:text-muted-foreground igr:sm:max-w-48 igr:sm:pl-0 igr:sm:text-right">{t(`home.guide.${hint}`)}</p>
            ) : null}
        </li>
    );
}

/** El camino guiado hasta la primera venta recuperada, con el estado real de cada paso. */
export function GettingStartedCard() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const guide = useGettingStarted(workspace.id);
    const hidden = useUi((s) => s.guideHidden.includes(workspace.id));
    const setHidden = useUi((s) => s.setGuideHidden);

    if (guide.isPending) {
        return <Skeleton className="igr:mb-6 igr:h-64" />;
    }
    if (guide.isError || !guideVisible(guide.data, hidden)) {
        return null;
    }
    const data = guide.data;
    const complete = isComplete(data);
    const current = nextStep(data.steps);
    const sale = data.steps.find((s) => s.key === 'first_sale');

    if (complete) {
        return (
            <Card className="igr:mb-6">
                <CardContent className="igr:flex igr:flex-col igr:gap-3 igr:pt-4 igr:sm:flex-row igr:sm:items-center">
                    <PartyPopper className="igr:size-6 igr:shrink-0 igr:text-success" aria-hidden />
                    <div className="igr:min-w-0 igr:flex-1">
                        <p className="igr:text-sm igr:font-medium">{t(`home.guide.complete.${data.variant}`)}</p>
                        <p className="igr:text-xs igr:text-muted-foreground">{sale ? <StepDetail step={sale} guide={data} /> : null}</p>
                    </div>
                    <Button size="sm" variant="outline" onClick={() => setHidden(workspace.id, true)}>
                        {t('home.guide.hide')}
                    </Button>
                </CardContent>
            </Card>
        );
    }

    return (
        <Card className="igr:mb-6">
            <CardHeader className="igr:pb-2">
                <div className="igr:flex igr:flex-wrap igr:items-center igr:justify-between igr:gap-2">
                    <CardTitle>{t(`home.guide.title.${data.variant}`)}</CardTitle>
                    <span className="igr:text-xs igr:font-medium igr:tabular-nums igr:text-muted-foreground">
                        {t('home.guide.progress', { done: data.completed, total: data.total })}
                    </span>
                </div>
                <CardDescription>{t(`home.guide.description.${data.variant}`)}</CardDescription>
                <Progress
                    value={data.total > 0 ? data.completed / data.total : 0}
                    className="igr:mt-2 igr:h-1"
                    label={t('home.guide.progress', { done: data.completed, total: data.total })}
                />
            </CardHeader>
            <CardContent className="igr:pt-0">
                <ol className="igr:space-y-1">
                    {data.steps.map((step, i) => (
                        <StepRow key={step.key} step={step} index={i} current={step.key === current} guide={data} />
                    ))}
                </ol>
            </CardContent>
        </Card>
    );
}
