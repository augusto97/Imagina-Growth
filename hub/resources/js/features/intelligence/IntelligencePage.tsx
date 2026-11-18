import { BrainCircuit, ChevronRight, FileText, Lightbulb, MessageSquareText, Pin, PinOff } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ErrorState, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { useWorkspace } from '@/hooks/useWorkspace';
import { useChartTheme } from '@/features/analytics/theme';
import { type AskAnswer, useAiUsage, useRunSaved, useSavedQueries, useUnsave } from './api';
import { AnswerResult } from './components';
import { budgetShare } from './logic';

/* Inteligencia (M17): la entrada a «Pregúntale», los informes del analista, las predicciones, los hallazgos y el uso de IA. */
export function IntelligencePage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const base = `/w/${workspace.id}`;
    const sections: { to: string; icon: LucideIcon; key: string }[] = [
        { to: `${base}/inteligencia/preguntale`, icon: MessageSquareText, key: 'ask' },
        { to: `${base}/inteligencia/informes`, icon: FileText, key: 'reports' },
        { to: `${base}/inteligencia/predicciones`, icon: BrainCircuit, key: 'predictions' },
        { to: `${base}/hallazgos`, icon: Lightbulb, key: 'insights' },
    ];

    return (
        <>
            <PageHeader title={t('intelligence.title')} description={t('intelligence.description')} />
            <nav aria-label={t('intelligence.title')} className="igr:grid igr:gap-3 igr:sm:grid-cols-2 igr:xl:grid-cols-4">
                {sections.map((s) => (
                    <Link key={s.key} to={s.to} className="igr:group igr:rounded-lg igr:outline-none igr:focus-visible:ring-2 igr:focus-visible:ring-ring">
                        <Card className="igr:h-full igr:transition-colors igr:group-hover:bg-muted/40">
                            <CardContent className="igr:flex igr:items-start igr:gap-3 igr:pt-4">
                                <s.icon className="igr:mt-0.5 igr:size-4 igr:shrink-0 igr:text-muted-foreground" aria-hidden />
                                <div className="igr:min-w-0 igr:flex-1">
                                    <p className="igr:text-[13px] igr:font-semibold">{t(`intelligence.sections.${s.key}.title`)}</p>
                                    <p className="igr:mt-0.5 igr:text-xs igr:text-muted-foreground">{t(`intelligence.sections.${s.key}.description`)}</p>
                                </div>
                                <ChevronRight className="igr:size-4 igr:shrink-0 igr:text-muted-foreground" aria-hidden />
                            </CardContent>
                        </Card>
                    </Link>
                ))}
            </nav>
            <div className="igr:mt-6 igr:grid igr:gap-4 igr:lg:grid-cols-3">
                <div className="igr:lg:col-span-2">
                    <SavedQueries />
                </div>
                <UsageCard />
            </div>
        </>
    );
}

function UsageCard() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const usage = useAiUsage(workspace.id);
    const theme = useChartTheme();
    const usd = (v: number) => new Intl.NumberFormat(workspace.locale, { style: 'currency', currency: 'USD', maximumFractionDigits: 2 }).format(v);

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('intelligence.usage.title')}</CardTitle>
                <CardDescription>{t('intelligence.usage.description')}</CardDescription>
            </CardHeader>
            <CardContent>
                {usage.isError ? (
                    <ErrorState error={usage.error} onRetry={() => void usage.refetch()} />
                ) : usage.isPending ? (
                    <Skeleton className="igr:h-24" />
                ) : (
                    <div className="igr:space-y-3">
                        {!usage.data.configured ? <Badge variant="outline">{t('intelligence.usage.notConfigured')}</Badge> : !usage.data.available ? <Badge variant="warning">{t('intelligence.usage.budgetReached')}</Badge> : null}
                        <div>
                            <p className="igr:text-xl igr:font-semibold igr:tabular-nums">{usd(usage.data.spent_usd)}</p>
                            <p className="igr:text-xs igr:text-muted-foreground">{t('intelligence.usage.ofBudget', { budget: usd(usage.data.budget_usd) })}</p>
                            <div
                                className="igr:mt-2 igr:h-2 igr:w-full igr:rounded-full igr:bg-muted"
                                role="meter"
                                aria-valuemin={0}
                                aria-valuemax={usage.data.budget_usd}
                                aria-valuenow={usage.data.spent_usd}
                                aria-label={t('intelligence.usage.title')}
                            >
                                <div className="igr:h-2 igr:rounded-full" style={{ width: `${Math.max(budgetShare(usage.data.spent_usd, usage.data.budget_usd) * 100, usage.data.spent_usd > 0 ? 2 : 0)}%`, background: theme.series[0] }} />
                            </div>
                        </div>
                        {usage.data.features.length > 0 ? (
                            <ul className="igr:space-y-1 igr:text-xs">
                                {usage.data.features.map((f) => (
                                    <li key={f.feature} className="igr:flex igr:justify-between igr:gap-2">
                                        <span className="igr:text-muted-foreground">{t(`intelligence.features.${f.feature}`, { defaultValue: f.feature })}</span>
                                        <span className="igr:tabular-nums">
                                            {t('intelligence.usage.requests', { count: f.requests })} · {usd(f.cost_usd)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="igr:text-xs igr:text-muted-foreground">{t('intelligence.usage.none')}</p>
                        )}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function SavedQueries() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const saved = useSavedQueries(workspace.id);

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('intelligence.saved.title')}</CardTitle>
                <CardDescription>{t('intelligence.saved.description')}</CardDescription>
            </CardHeader>
            <CardContent>
                {saved.isError ? (
                    <ErrorState error={saved.error} onRetry={() => void saved.refetch()} />
                ) : saved.isPending ? (
                    <Skeleton className="igr:h-24" />
                ) : saved.data.length === 0 ? (
                    <p className="igr:flex igr:items-center igr:gap-2 igr:text-[13px] igr:text-muted-foreground">
                        <Pin className="igr:size-4" aria-hidden />
                        {t('intelligence.saved.empty')}
                    </p>
                ) : (
                    <div className="igr:space-y-5">
                        {saved.data.map((q) => (
                            <SavedQuery key={q.id} query={q} />
                        ))}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function SavedQuery({ query }: { query: AskAnswer }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const run = useRunSaved(workspace.id, query.id);
    const unsave = useUnsave(workspace.id);
    const canUse = workspace.abilities.includes('useAi');

    return (
        <section aria-label={query.question}>
            <div className="igr:mb-2 igr:flex igr:items-start igr:justify-between igr:gap-2">
                <p className="igr:text-[13px] igr:font-medium">{query.question}</p>
                {canUse ? (
                    <Button variant="ghost" size="sm" loading={unsave.isPending} onClick={() => unsave.mutate(query.id)}>
                        <PinOff className="igr:size-3.5" aria-hidden />
                        {t('intelligence.saved.unpin')}
                    </Button>
                ) : null}
            </div>
            {run.isError ? <ErrorState error={run.error} onRetry={() => void run.refetch()} /> : run.isPending ? <Skeleton className="igr:h-20" /> : <AnswerResult result={run.data} granularity={query.query?.granularity} />}
        </section>
    );
}
