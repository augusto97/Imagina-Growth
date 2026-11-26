import { ChevronRight, Lightbulb, SlidersHorizontal } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link, useSearchParams } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Select } from '@/components/ui/input';
import { EmptyState, ErrorState, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { StatTile, TileGrid } from '@/features/analytics/components/StatTile';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatRelative } from '@/lib/format';
import { CATEGORIES, type Insight, type InsightCategory, LIST_STATUSES, useInsights } from './api';
import type { InsightListStatus } from './api';
import { InsightActionButton, useInsightFormatters } from './components';
import { SEVERITIES, SeverityBadge } from './severity';
import { StatCardSkeleton } from '@/components/ui/stat-card';

export function InsightsPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const fmt = useInsightFormatters();
    const [search, setSearch] = useSearchParams();
    const raw = search.get('estado');
    const status: InsightListStatus = LIST_STATUSES.includes(raw as InsightListStatus) ? (raw as InsightListStatus) : 'open';
    const rawCategory = search.get('categoria') ?? '';
    const category = (CATEGORIES as readonly string[]).includes(rawCategory) ? (rawCategory as InsightCategory) : '';
    const severity = (SEVERITIES as readonly string[]).includes(search.get('severidad') ?? '') ? (search.get('severidad') ?? '') : '';
    const insights = useInsights(workspace.id, { status, category, severity });
    const base = `/w/${workspace.id}`;
    const setParam = (key: string, value: string) => {
        const next = new URLSearchParams(search);
        if (value === '' || (key === 'estado' && value === 'open')) next.delete(key);
        else next.set(key, value);
        setSearch(next, { replace: true });
    };
    const groups = CATEGORIES.map((c) => ({ category: c, items: (insights.data?.data ?? []).filter((i) => i.category === c) })).filter((g) => g.items.length > 0);
    const summary = insights.data?.meta;

    return (
        <>
            <PageHeader
                title={t('insights.title')}
                description={t('insights.description')}
                actions={
                    <Button asChild variant="outline" size="sm">
                        <Link to={`${base}/hallazgos/ajustes`}>
                            <SlidersHorizontal />
                            {t('insights.settings.link')}
                        </Link>
                    </Button>
                }
            />
            {summary ? (
                <TileGrid className="igr:mb-5 igr:lg:grid-cols-3">
                    <StatTile
                        label={t('insights.summary.impactOpen')}
                        value={summary.impact_open}
                        unit="money"
                        fmt={fmt}
                        help={t('insights.summary.impactOpenHelp')}
                        better="neutral"
                    />
                    <StatTile
                        label={t('insights.summary.open')}
                        value={summary.open}
                        unit="count"
                        fmt={fmt}
                        better="neutral"
                        hint={
                            CATEGORIES.filter((c) => summary.by_category[c] > 0)
                                .map((c) => `${t(`insights.category.${c}`)} ${fmt.number(summary.by_category[c])}`)
                                .join(' · ') || undefined
                        }
                    />
                    <StatTile
                        label={t('insights.summary.actionedMonth')}
                        value={summary.actioned_month}
                        unit="count"
                        fmt={fmt}
                        better="neutral"
                        hint={summary.impact_actioned_month > 0 ? t('insights.summary.actionedImpact', { value: fmt.money(summary.impact_actioned_month) }) : undefined}
                    />
                </TileGrid>
            ) : insights.isPending ? (
                <TileGrid className="igr:mb-5 igr:lg:grid-cols-3">
                    <StatCardSkeleton />
                    <StatCardSkeleton />
                    <StatCardSkeleton />
                </TileGrid>
            ) : null}
            <div className="igr:mb-4 igr:flex igr:flex-wrap igr:items-center igr:gap-2">
                <Tabs value={status} onValueChange={(v) => setParam('estado', v)}>
                    <TabsList>
                        {LIST_STATUSES.map((s) => (
                            <TabsTrigger key={s} value={s}>
                                {t(`insights.tabs.${s}`)}
                            </TabsTrigger>
                        ))}
                    </TabsList>
                </Tabs>
                <Select aria-label={t('insights.filters.category')} value={category} onChange={(e) => setParam('categoria', e.target.value)} className="igr:w-52">
                    <option value="">{t('insights.filters.allCategories')}</option>
                    {CATEGORIES.map((c) => (
                        <option key={c} value={c}>
                            {t(`insights.category.${c}`)}
                        </option>
                    ))}
                </Select>
                <Select aria-label={t('insights.filters.severity')} value={severity} onChange={(e) => setParam('severidad', e.target.value)} className="igr:w-52">
                    <option value="">{t('insights.filters.allSeverities')}</option>
                    {SEVERITIES.map((s) => (
                        <option key={s} value={s}>
                            {t(`insights.severity.${s}`)}
                        </option>
                    ))}
                </Select>
            </div>
            {insights.isError ? (
                <ErrorState error={insights.error} onRetry={() => void insights.refetch()} />
            ) : insights.isPending ? (
                <div className="igr:space-y-2">
                    {Array.from({ length: 4 }, (_, i) => (
                        <Skeleton key={i} className="igr:h-[76px]" />
                    ))}
                </div>
            ) : groups.length === 0 ? (
                <EmptyState icon={Lightbulb} title={t(`insights.empty.${status}`)} description={status === 'open' ? t('insights.emptyHelp') : undefined} />
            ) : (
                <div className="igr:space-y-5">
                    {groups.map((g) => (
                        <section key={g.category} aria-labelledby={`cat-${g.category}`}>
                            <h2 id={`cat-${g.category}`} className="igr:mb-2 igr:text-[13px] igr:font-semibold">
                                {t(`insights.category.${g.category}`)}
                                <span className="igr:ml-1.5 igr:font-normal igr:text-muted-foreground">{fmt.number(g.items.length)}</span>
                            </h2>
                            <Card>
                                <ul className="igr:divide-y">
                                    {g.items.map((i) => (
                                        <InsightRow key={i.id} insight={i} base={base} />
                                    ))}
                                </ul>
                            </Card>
                        </section>
                    ))}
                </div>
            )}
        </>
    );
}

function InsightRow({ insight: i, base }: { insight: Insight; base: string }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const fmt = useInsightFormatters();
    const open = i.status === 'new' || i.status === 'seen';
    return (
        <li className="igr:flex igr:items-center igr:gap-3 igr:px-4 igr:py-3 igr:hover:bg-muted/40">
            <SeverityBadge severity={i.severity} />
            <Link to={`${base}/hallazgos/${i.id}`} className="igr:min-w-0 igr:flex-1 igr:outline-none igr:focus-visible:underline">
                <p className="igr:text-[13px] igr:font-medium">{i.title}</p>
                <p className="igr:mt-0.5 igr:truncate igr:text-xs igr:text-muted-foreground">
                    {i.number ? `#${i.number} · ` : ''}
                    {t(`insights.detector.${i.detector}`, { defaultValue: i.detector })} · {formatRelative(i.updated_at, workspace.locale)}
                    {i.formula ? ` · ${i.formula.text}` : ''}
                </p>
            </Link>
            {i.status === 'new' ? <Badge>{t('insights.new')}</Badge> : null}
            {i.impact !== null && Number(i.impact) > 0 ? (
                <span className="igr:hidden igr:shrink-0 igr:text-right igr:text-xs igr:text-muted-foreground igr:sm:block">
                    {t('insights.impactShort')}
                    <span className="igr:block igr:text-[13px] igr:font-semibold igr:text-foreground igr:tabular-nums">{fmt.money(i.impact)}</span>
                </span>
            ) : null}
            {open ? <InsightActionButton insight={i} /> : null}
            <Link to={`${base}/hallazgos/${i.id}`} aria-label={t('insights.openDetail')} className="igr:shrink-0 igr:text-muted-foreground">
                <ChevronRight className="igr:size-4" />
            </Link>
        </li>
    );
}
