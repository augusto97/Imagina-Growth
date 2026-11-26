import { ChevronRight, SlidersHorizontal } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link, useSearchParams } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Select } from '@/components/ui/input';
import { EmptyState, ErrorState, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { StatTile, TileGrid, TileGridSkeleton } from '@/features/analytics/components/StatTile';
import { PHONE_QUERY, useMedia } from '@/hooks/useMedia';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatDate, formatRelative } from '@/lib/format';
import { CATEGORIES, type Insight, type InsightCategory, LIST_STATUSES, useInsights } from './api';
import type { InsightListStatus } from './api';
import { InsightActionButton, InsightTriage, useInsightFormatters } from './components';
import { SEVERITIES, SeverityBadge } from './severity';

/*
 * Recomendaciones (antes «hallazgos», inteligencia.md §5): cada una con el dinero en juego, por qué (la cuenta) y el
 * botón de su acción; posponer y descartar a la mano. Las pospuestas vuelven solas en la fecha elegida.
 */
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
    const list = insights.data?.data ?? [];
    const summary = insights.data?.meta;

    return (
        <>
            <PageHeader
                title={t('insights.title')}
                description={t('insights.description')}
                actions={
                    <Button asChild variant="outline">
                        <Link to={`${base}/hallazgos/ajustes`}>
                            <SlidersHorizontal />
                            {t('insights.settings.link')}
                        </Link>
                    </Button>
                }
            />
            {summary ? (
                <TileGrid className="igr:mb-5 igr:lg:grid-cols-3">
                    <StatTile label={t('insights.summary.impactOpen')} value={summary.impact_open} unit="money" fmt={fmt} help={t('insights.summary.impactOpenHelp')} better="neutral" />
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
                <TileGridSkeleton count={3} className="igr:mb-5 igr:lg:grid-cols-3" />
            ) : null}
            <div className="igr:mb-4 igr:flex igr:flex-wrap igr:items-center igr:gap-2">
                <Tabs value={status} onValueChange={(v) => setParam('estado', v)} className="igr:max-w-full igr:min-w-0">
                    <TabsList>
                        {LIST_STATUSES.map((s) => (
                            <TabsTrigger key={s} value={s}>
                                {t(`insights.tabs.${s}`)}
                                {s === 'snoozed' && summary?.snoozed ? <span className="igr:ml-1 igr:text-muted-foreground igr:tabular-nums">{fmt.number(summary.snoozed)}</span> : null}
                            </TabsTrigger>
                        ))}
                    </TabsList>
                </Tabs>
                <div className="igr:flex igr:w-full igr:gap-2 igr:sm:ml-auto igr:sm:w-auto">
                    <Select aria-label={t('insights.filters.category')} value={category} onChange={(e) => setParam('categoria', e.target.value)} className="igr:min-w-0 igr:flex-1 igr:sm:w-48 igr:sm:flex-none">
                        <option value="">{t('insights.filters.allCategories')}</option>
                        {CATEGORIES.map((c) => (
                            <option key={c} value={c}>
                                {t(`insights.category.${c}`)}
                            </option>
                        ))}
                    </Select>
                    <Select aria-label={t('insights.filters.severity')} value={severity} onChange={(e) => setParam('severidad', e.target.value)} className="igr:min-w-0 igr:flex-1 igr:sm:w-52 igr:sm:flex-none">
                        <option value="">{t('insights.filters.allSeverities')}</option>
                        {SEVERITIES.map((s) => (
                            <option key={s} value={s}>
                                {t(`insights.severity.${s}`)}
                            </option>
                        ))}
                    </Select>
                </div>
            </div>
            {insights.isError ? (
                <ErrorState error={insights.error} onRetry={() => void insights.refetch()} />
            ) : insights.isPending ? (
                <div className="igr:space-y-3">
                    {Array.from({ length: 4 }, (_, i) => (
                        <Skeleton key={i} className="igr:h-36 igr:rounded-xl" />
                    ))}
                </div>
            ) : list.length === 0 ? (
                <EmptyState
                    illustration={status === 'open' ? 'celebration' : 'search'}
                    title={t(`insights.empty.${status}`)}
                    description={status === 'open' ? t('insights.emptyHelp') : undefined}
                    steps={status === 'open' ? [t('insights.how.step1'), t('insights.how.step2'), t('insights.how.step3')] : undefined}
                />
            ) : (
                <ul className="igr:space-y-3">
                    {list.map((i) => (
                        <InsightCard key={i.id} insight={i} base={base} />
                    ))}
                </ul>
            )}
        </>
    );
}

/** Una recomendación: qué pasa, cuánto hay en juego, por qué (la cuenta), la acción y posponer o descartar. */
function InsightCard({ insight: i, base }: { insight: Insight; base: string }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const fmt = useInsightFormatters();
    const phone = useMedia(PHONE_QUERY);
    const open = i.status === 'new' || i.status === 'seen' || i.status === 'snoozed';
    const impact = i.impact !== null && Number(i.impact) > 0 ? Number(i.impact) : null;
    return (
        <li>
            <Card className="igr:p-4 igr:sm:p-5">
                <div className="igr:flex igr:flex-wrap igr:items-start igr:gap-x-4 igr:gap-y-3">
                    <div className="igr:min-w-0 igr:flex-1 igr:basis-72">
                        <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-2 igr:text-caption igr:text-muted-foreground">
                            <SeverityBadge severity={i.severity} />
                            <span>{t(`insights.category.${i.category}`)}</span>
                            <span aria-hidden>·</span>
                            <span>{formatRelative(i.updated_at, workspace.locale)}</span>
                            {i.status === 'new' ? <Badge variant="brand">{t('insights.new')}</Badge> : null}
                            {i.status === 'snoozed' && i.snoozed_until ? <Badge variant="outline">{t('insights.snoozedUntil', { date: formatDate(i.snoozed_until, workspace.locale, workspace.timezone) })}</Badge> : null}
                        </div>
                        <Link to={`${base}/hallazgos/${i.id}`} className="igr:mt-2 igr:block igr:text-[15px] igr:leading-6 igr:font-semibold igr:text-foreground igr:outline-none igr:hover:text-primary igr:focus-visible:underline">
                            {i.title}
                        </Link>
                        {i.formula ? (
                            <p className="igr:mt-1 igr:line-clamp-2 igr:text-support igr:text-muted-foreground">
                                <span className="igr:font-medium igr:text-foreground/80">{t('insights.why')}</span> {i.formula.text}
                            </p>
                        ) : null}
                    </div>
                    {impact !== null ? (
                        <div className="igr:shrink-0 igr:text-left igr:sm:text-right">
                            <p className="igr:text-caption igr:text-muted-foreground">{t('insights.impactShort')}</p>
                            <p className="igr:text-[20px] igr:leading-7 igr:font-semibold igr:tabular-nums">{fmt.money(impact)}</p>
                        </div>
                    ) : null}
                </div>
                <div className="igr:mt-4 igr:flex igr:flex-wrap igr:items-center igr:gap-2 igr:border-t igr:pt-3">
                    {open ? <InsightActionButton insight={i} className="igr:max-w-full igr:sm:max-w-md" /> : null}
                    <InsightTriage insight={i} compact={phone} />
                    <Button asChild size="sm" variant="ghost" className="igr:ml-auto">
                        <Link to={`${base}/hallazgos/${i.id}`}>
                            {t('insights.details')}
                            <ChevronRight />
                        </Link>
                    </Button>
                </div>
            </Card>
        </li>
    );
}
