import { Coins, Power, Rocket, Sparkles } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useNavigate, useParams, useSearchParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { IconTile } from '@/components/ui/icon-tile';
import { Select } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ErrorState } from '@/components/ui/misc';
import { PageHeader } from '@/components/ui/page-header';
import { Skeleton } from '@/components/ui/skeleton';
import { StatCard, StatCardSkeleton } from '@/components/ui/stat-card';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatMoney, formatNumber } from '@/lib/format';
import { forYou, useStrategies } from './api';
import { filterCatalog, GOAL_STYLE, hiddenCount, monthRevenue, relevantGoals, statusGroup, type CatalogFilter, type StatusFilter } from './presentation';
import { StrategyCard } from './StrategyCard';
import { StrategySheet } from './StrategySheet';
import type { StrategyGoal, StrategyKey } from './types';

/*
 * Estrategias (docs/diseno/rediseno-v2.md §6): el catálogo que traduce los módulos a resultados. Arriba lo que ya
 * generan y «Recomendadas para ti» (según la vertical); después todo el catálogo por objetivo, con filtros por
 * objetivo y estado en la URL (?objetivo=…&estado=…&todas=1). El detalle se abre en una hoja en
 * /estrategias/:clave, así se puede enlazar desde el Inicio, «Para hoy» y las recomendaciones.
 */

const STATUS_FILTERS: StatusFilter[] = ['all', 'on', 'ready', 'setup', 'soon'];

function PageSkeleton() {
    return (
        <div aria-busy>
            <Skeleton className="igr:h-8 igr:w-48" />
            <Skeleton className="igr:mt-2 igr:mb-6 igr:h-5 igr:w-96 igr:max-w-full" />
            <div className="igr:grid igr:gap-3 igr:sm:grid-cols-3">
                {Array.from({ length: 3 }, (_, i) => (
                    <StatCardSkeleton key={i} />
                ))}
            </div>
            <Skeleton className="igr:mt-8 igr:h-6 igr:w-56" />
            <div className="igr:mt-4 igr:grid igr:gap-4 igr:md:grid-cols-2 igr:xl:grid-cols-3">
                {Array.from({ length: 3 }, (_, i) => (
                    <Skeleton key={i} className="igr:h-48 igr:rounded-xl" />
                ))}
            </div>
        </div>
    );
}

export function StrategiesPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const navigate = useNavigate();
    const { key } = useParams();
    const [params, setParams] = useSearchParams();
    const catalog = useStrategies(workspace.id);
    const base = `/w/${workspace.id}`;

    const filter: CatalogFilter = {
        goal: (params.get('objetivo') as StrategyGoal | null) ?? 'all',
        status: STATUS_FILTERS.includes(params.get('estado') as StatusFilter) ? (params.get('estado') as StatusFilter) : 'all',
        showAll: params.get('todas') === '1',
    };
    const setFilter = (patch: Partial<CatalogFilter>) => {
        const next = { ...filter, ...patch };
        const out = new URLSearchParams();
        if (next.goal !== 'all') out.set('objetivo', next.goal);
        if (next.status !== 'all') out.set('estado', next.status);
        if (next.showAll) out.set('todas', '1');
        setParams(out, { replace: true });
    };

    const data = catalog.data;
    const groups = data ? filterCatalog(data, filter) : [];
    const query = params.toString();
    const open = (k: StrategyKey) => `${base}/estrategias/${k}${query ? `?${query}` : ''}`;
    const selected = key ? data?.data.find((s) => s.key === key) : undefined;

    if (catalog.isError) {
        return <ErrorState error={catalog.error} onRetry={() => void catalog.refetch()} />;
    }
    if (!data) {
        return <PageSkeleton />;
    }

    const on = data.data.filter((s) => statusGroup(s.status) === 'on');
    const ready = data.data.filter((s) => s.relevant && statusGroup(s.status) === 'ready');
    const revenue = monthRevenue(data.data);
    const recommended = forYou(data);
    const goals = relevantGoals(data, filter.showAll);
    const hidden = hiddenCount(data);
    const filtered = filter.goal !== 'all' || filter.status !== 'all';

    return (
        <>
            <PageHeader
                title={t('nav.sections.strategies')}
                description={t(data.business_type === 'services' ? 'strategies.page.descriptionServices' : 'strategies.page.description')}
                icon={Rocket}
                tone="violet"
            />

            <div className="igr:grid igr:gap-3 igr:sm:grid-cols-3">
                <StatCard label={t('strategies.page.on')} value={formatNumber(on.length, workspace.locale)} icon={Power} tone="emerald" hint={t('strategies.page.onHint', { count: on.length })} />
                <StatCard
                    label={t('strategies.page.generated')}
                    value={formatMoney(revenue, data.currency, workspace.locale)}
                    icon={Coins}
                    tone="violet"
                    help={t('strategies.page.generatedHelp')}
                    hint={t('strategies.page.generatedHint')}
                />
                <StatCard label={t('strategies.page.ready')} value={formatNumber(ready.length, workspace.locale)} icon={Sparkles} tone="indigo" hint={t('strategies.page.readyHint')} />
            </div>

            {recommended.length > 0 && !filtered ? (
                <section className="igr:mt-8" aria-labelledby="str-for-you">
                    <div className="igr:mb-4">
                        <h2 id="str-for-you" className="igr:text-section igr:font-semibold igr:text-foreground">
                            {t('strategies.page.forYou')}
                        </h2>
                        <p className="igr:mt-0.5 igr:text-support igr:text-muted-foreground">
                            {t('strategies.page.forYouHint', { vertical: t(`strategies.verticals.${data.vertical}`) })}
                        </p>
                    </div>
                    <div className="igr:grid igr:gap-4 igr:md:grid-cols-2 igr:xl:grid-cols-3">
                        {recommended.map((s) => (
                            <StrategyCard key={s.key} strategy={s} currency={data.currency} to={open(s.key)} showRecommended={false} />
                        ))}
                    </div>
                </section>
            ) : null}

            <section className="igr:mt-10" aria-labelledby="str-all">
                <div className="igr:mb-4 igr:flex igr:flex-col igr:gap-3 igr:lg:flex-row igr:lg:items-end igr:lg:justify-between">
                    <div className="igr:min-w-0">
                        <h2 id="str-all" className="igr:text-section igr:font-semibold igr:text-foreground">
                            {t('strategies.page.all')}
                        </h2>
                        <p className="igr:mt-0.5 igr:text-support igr:text-muted-foreground">{t('strategies.page.allHint')}</p>
                    </div>
                    <div className="igr:flex igr:items-center igr:gap-2">
                        <Label htmlFor="str-status" className="igr:shrink-0 igr:text-support igr:text-muted-foreground">
                            {t('strategies.filters.status')}
                        </Label>
                        <Select id="str-status" value={filter.status} onChange={(e) => setFilter({ status: e.target.value as StatusFilter })} className="igr:w-auto igr:min-w-44">
                            {STATUS_FILTERS.map((s) => (
                                <option key={s} value={s}>
                                    {t(`strategies.filters.${s}`)}
                                </option>
                            ))}
                        </Select>
                    </div>
                </div>
                <Tabs value={filter.goal} onValueChange={(v) => setFilter({ goal: v as CatalogFilter['goal'] })}>
                    <TabsList aria-label={t('strategies.filters.goal')}>
                        <TabsTrigger value="all">{t('strategies.filters.allGoals')}</TabsTrigger>
                        {goals.map((g) => (
                            <TabsTrigger key={g.key} value={g.key}>
                                {g.name}
                            </TabsTrigger>
                        ))}
                    </TabsList>
                </Tabs>

                {groups.length === 0 ? (
                    <EmptyState
                        size="compact"
                        illustration="search"
                        title={t('strategies.filters.emptyTitle')}
                        description={t('strategies.filters.emptyBody')}
                        action={
                            <Button variant="outline" onClick={() => setFilter({ goal: 'all', status: 'all' })}>
                                {t('strategies.filters.clear')}
                            </Button>
                        }
                        className="igr:mt-6"
                    />
                ) : (
                    <div className="igr:mt-6 igr:space-y-10">
                        {groups.map(({ goal, items }) => (
                            <section key={goal.key} aria-labelledby={`str-goal-${goal.key}`}>
                                <div className="igr:mb-3 igr:flex igr:items-center igr:gap-3">
                                    <IconTile icon={GOAL_STYLE[goal.key].icon} tone={GOAL_STYLE[goal.key].tone} size="sm" />
                                    <div className="igr:min-w-0">
                                        <h3 id={`str-goal-${goal.key}`} className="igr:text-body igr:font-semibold igr:text-foreground">
                                            {goal.name}
                                        </h3>
                                        <p className="igr:text-caption igr:text-muted-foreground">{goal.description}</p>
                                    </div>
                                </div>
                                <div className="igr:grid igr:gap-4 igr:md:grid-cols-2 igr:xl:grid-cols-3">
                                    {items.map((s) => (
                                        <StrategyCard key={s.key} strategy={s} currency={data.currency} to={open(s.key)} />
                                    ))}
                                </div>
                            </section>
                        ))}
                    </div>
                )}

                {hidden > 0 ? (
                    <div className="igr:mt-8 igr:flex igr:justify-center">
                        <Button variant="ghost" onClick={() => setFilter({ showAll: !filter.showAll })}>
                            {filter.showAll ? t('strategies.filters.hideOthers') : t('strategies.filters.showOthers', { count: hidden })}
                        </Button>
                    </div>
                ) : null}
            </section>

            <StrategySheet
                strategyKey={(key as StrategyKey | undefined) ?? null}
                summary={selected}
                open={key !== undefined}
                onOpenChange={(next) => {
                    if (!next) void navigate(`${base}/estrategias${query ? `?${query}` : ''}`, { replace: true });
                }}
                base={base}
            />
        </>
    );
}
