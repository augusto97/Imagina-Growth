import { ChevronRight, Lightbulb } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link, useSearchParams } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { EmptyState, ErrorState, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatMoney, formatRelative } from '@/lib/format';
import { LIST_STATUSES, useInsights } from './api';
import type { InsightListStatus } from './api';
import { SeverityBadge } from './severity';

export function InsightsPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const [search, setSearch] = useSearchParams();
    const raw = search.get('estado');
    const status: InsightListStatus = LIST_STATUSES.includes(raw as InsightListStatus) ? (raw as InsightListStatus) : 'open';
    const insights = useInsights(workspace.id, status);
    const base = `/w/${workspace.id}`;

    return (
        <>
            <PageHeader title={t('insights.title')} description={t('insights.description')} />
            <Tabs value={status} onValueChange={(v) => setSearch(v === 'open' ? {} : { estado: v }, { replace: true })}>
                <TabsList className="igr:mb-4">
                    {LIST_STATUSES.map((s) => (
                        <TabsTrigger key={s} value={s}>
                            {t(`insights.tabs.${s}`)}
                        </TabsTrigger>
                    ))}
                </TabsList>
            </Tabs>
            {insights.isError ? (
                <ErrorState error={insights.error} onRetry={() => void insights.refetch()} />
            ) : insights.isPending ? (
                <div className="igr:space-y-2">
                    {Array.from({ length: 4 }, (_, i) => (
                        <Skeleton key={i} className="igr:h-[68px]" />
                    ))}
                </div>
            ) : insights.data.length === 0 ? (
                <EmptyState icon={Lightbulb} title={t(`insights.empty.${status}`)} description={status === 'open' ? t('insights.emptyHelp') : undefined} />
            ) : (
                <Card>
                    <ul className="igr:divide-y">
                        {insights.data.map((i) => (
                            <li key={i.id}>
                                <Link to={`${base}/hallazgos/${i.id}`} className="igr:flex igr:items-center igr:gap-3 igr:px-4 igr:py-3 igr:hover:bg-muted/40">
                                    <SeverityBadge severity={i.severity} />
                                    <div className="igr:min-w-0 igr:flex-1">
                                        <p className="igr:text-[13px] igr:font-medium">{i.title}</p>
                                        <p className="igr:mt-0.5 igr:text-xs igr:text-muted-foreground">
                                            {t(`insights.detector.${i.detector}`, { defaultValue: i.detector })} · {formatRelative(i.updated_at, workspace.locale)}
                                        </p>
                                    </div>
                                    {i.status === 'new' ? <Badge>{t('insights.new')}</Badge> : null}
                                    {i.impact !== null && Number(i.impact) > 0 ? (
                                        <span className="igr:shrink-0 igr:text-right igr:text-xs igr:text-muted-foreground">
                                            {t('insights.impactShort')}
                                            <span className="igr:block igr:text-[13px] igr:font-semibold igr:text-foreground igr:tabular-nums">{formatMoney(i.impact, workspace.currency, workspace.locale)}</span>
                                        </span>
                                    ) : null}
                                    <ChevronRight className="igr:size-4 igr:shrink-0 igr:text-muted-foreground" />
                                </Link>
                            </li>
                        ))}
                    </ul>
                </Card>
            )}
        </>
    );
}
