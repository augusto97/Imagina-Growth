import { Camera, Check, Inbox, Search, Send, Star, X } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useSearchParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { Callout } from '@/components/ui/callout';
import { Card } from '@/components/ui/card';
import { Input, Select } from '@/components/ui/input';
import { EmptyState, ErrorState, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { StatCard, StatCardSkeleton, StatGrid } from '@/components/ui/stat-card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { toast } from '@/stores/toast';
import { errorMessage } from '@/features/contacts/errors';
import { BarList } from '@/features/analytics/components/charts';
import { useFormatters } from '@/features/analytics/format';
import { useModeration, useReviewSummary, useReviews } from './api';
import { ImportTab } from './ImportTab';
import { useAudience } from '@/features/onsite/audience';
import { GoogleReviews } from './GoogleReviews';
import { RequestsTab } from './RequestsTab';
import { ReviewCard } from './ReviewCard';
import { SettingsTab } from './SettingsTab';
import { SurveysTab } from './SurveysTab';
import { REJECT_REASONS, type RejectReason, type ReviewFilters, type ReviewSource, type ReviewStatus } from './types';

const TABS = ['moderacion', 'todas', 'solicitudes', 'nps', 'ajustes', 'importar'] as const;
type Tab = (typeof TABS)[number];

/**
 * Reseñas (modulos.md M15): moderación, todas las reseñas, solicitudes, NPS/CSAT, ajustes e importación. Un negocio sin
 * tienda no tiene pedidos que disparen la solicitud: ve las reseñas de Google (su enlace y cómo pedirlas).
 */
export function ReviewsPage() {
    const audience = useAudience();
    return audience.store ? <StoreReviews /> : <ServicesReviews />;
}

function ServicesReviews() {
    const { t } = useTranslation();
    return (
        <>
            <PageHeader title={t('reviews.title')} description={t('reviews.descriptionServices')} />
            <GoogleReviews />
        </>
    );
}

function StoreReviews() {
    const { t } = useTranslation();
    const canManage = useCan('manageMarketing');
    const [params, setParams] = useSearchParams();
    const tabs = TABS.filter((k) => canManage || (k !== 'ajustes' && k !== 'importar'));
    const raw = params.get('tab');
    const tab: Tab = (tabs as readonly string[]).includes(raw ?? '') ? (raw as Tab) : 'moderacion';
    const setTab = (next: string) => {
        const p = new URLSearchParams(params);
        if (next === 'moderacion') p.delete('tab');
        else p.set('tab', next);
        setParams(p, { replace: true });
    };

    return (
        <>
            <PageHeader title={t('reviews.title')} description={t('reviews.description')} />
            <Summary onSettings={canManage ? () => setTab('ajustes') : undefined} />
            <Tabs value={tab} onValueChange={setTab} className="igr:mt-6">
                <TabsList>
                    {tabs.map((k) => (
                        <TabsTrigger key={k} value={k}>
                            {t(`reviews.tabs.${k}`)}
                        </TabsTrigger>
                    ))}
                </TabsList>
                <TabsContent value="moderacion" className="igr:mt-4">
                    <ModerationQueue />
                </TabsContent>
                <TabsContent value="todas" className="igr:mt-4">
                    <AllReviews />
                </TabsContent>
                <TabsContent value="solicitudes" className="igr:mt-4">
                    <RequestsTab />
                </TabsContent>
                <TabsContent value="nps" className="igr:mt-4">
                    <SurveysTab />
                </TabsContent>
                {canManage ? (
                    <>
                        <TabsContent value="ajustes" className="igr:mt-4">
                            <SettingsTab />
                        </TabsContent>
                        <TabsContent value="importar" className="igr:mt-4">
                            <ImportTab />
                        </TabsContent>
                    </>
                ) : null}
            </Tabs>
        </>
    );
}

function Summary({ onSettings }: { onSettings?: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const fmt = useFormatters();
    const summary = useReviewSummary(workspace.id);
    if (summary.isError) {
        return <ErrorState error={summary.error} onRetry={() => void summary.refetch()} />;
    }
    if (summary.isPending) {
        return (
            <div className="igr:grid igr:items-start igr:gap-3 igr:lg:grid-cols-[minmax(0,1fr)_340px]">
                <StatGrid className="igr:lg:grid-cols-2">
                    {[0, 1, 2, 3].map((i) => (
                        <StatCardSkeleton key={i} />
                    ))}
                </StatGrid>
                <Skeleton className="igr:h-[276px] igr:rounded-xl" />
            </div>
        );
    }
    const { reviews, requests } = summary.data;
    const dist = (['5', '4', '3', '2', '1'] as const).map((stars) => ({ stars, count: reviews.distribution[stars] }));
    const nothingYet = reviews.published === 0 && reviews.pending === 0 && requests.sent === 0;
    return (
        <>
            {nothingYet ? (
                <Callout
                    tone="brand"
                    className="igr:mb-4"
                    title={t('reviews.start.title')}
                    action={
                        onSettings ? (
                            <Button size="sm" onClick={onSettings}>
                                {t('reviews.start.action')}
                            </Button>
                        ) : undefined
                    }
                >
                    {t('reviews.start.body')}
                </Callout>
            ) : null}
            <div className="igr:grid igr:items-start igr:gap-3 igr:lg:grid-cols-[minmax(0,1fr)_340px]">
                <StatGrid className="igr:lg:grid-cols-2">
                    <StatCard
                        label={t('reviews.summary.avg')}
                        value={reviews.avg_rating === null ? '—' : fmt.decimal(reviews.avg_rating, 2)}
                        hint={t('reviews.summary.published', { count: reviews.published })}
                        icon={Star}
                        tone="amber"
                    />
                    <StatCard
                        label={t('reviews.summary.pending')}
                        value={fmt.number(reviews.pending)}
                        hint={reviews.flagged_pending > 0 ? t('reviews.summary.flagged', { count: reviews.flagged_pending }) : undefined}
                        icon={Inbox}
                        tone="sky"
                    />
                    <StatCard label={t('reviews.summary.withMedia')} value={fmt.number(reviews.with_media)} help={t('reviews.summary.withMediaHelp')} icon={Camera} tone="violet" />
                    <StatCard
                        label={t('reviews.summary.completion')}
                        value={fmt.percent(requests.completion_rate, 0)}
                        hint={t('reviews.summary.requests', { count: requests.sent, days: requests.days })}
                        help={t('reviews.summary.completionHelp')}
                        icon={Send}
                        tone="emerald"
                    />
                </StatGrid>
                <Card className="igr:p-5">
                    <p className="igr:mb-3 igr:text-support igr:font-medium igr:text-muted-foreground">{t('reviews.summary.distribution')}</p>
                    <BarList rows={dist} label={(r) => t('reviews.stars', { count: Number(r.stars) })} value={(r) => r.count} format={(v) => fmt.number(v)} />
                </Card>
            </div>
        </>
    );
}

function ModerationQueue() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canModerate = useCan('manageMarketing');
    const [page, setPage] = useState(1);
    const list = useReviews(workspace.id, { status: 'pending', page });
    const { bulk } = useModeration(workspace.id);
    const [selected, setSelected] = useState<string[]>([]);
    const [reason, setReason] = useState<RejectReason>('spam');

    if (list.isError) return <ErrorState error={list.error} onRetry={() => void list.refetch()} />;
    if (list.isPending) return <ListSkeleton />;
    const rows = list.data.data;
    if (rows.length === 0) {
        return <EmptyState illustration="reviews" size="compact" title={t('reviews.queue.emptyTitle')} description={t('reviews.queue.emptyDescription')} />;
    }
    const run = (action: 'approve' | 'reject') =>
        bulk.mutate(
            { action, ids: selected, reason: action === 'reject' ? reason : undefined },
            {
                onSuccess: (r) => {
                    toast.success(t(action === 'approve' ? 'reviews.queue.bulkApproved' : 'reviews.queue.bulkRejected', { count: r.updated }));
                    setSelected([]);
                },
                onError: (e) => toast.error(errorMessage(e, t)),
            },
        );
    return (
        <div className="igr:space-y-3">
            <p className="igr:text-[13px] igr:text-muted-foreground">{t('reviews.queue.hint')}</p>
            {canModerate ? (
                <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-2" role="toolbar" aria-label={t('reviews.queue.bulk')}>
                    <label className="igr:flex igr:items-center igr:gap-2 igr:text-[13px]">
                        <input
                            type="checkbox"
                            className="igr:size-4"
                            checked={selected.length === rows.length}
                            onChange={(e) => setSelected(e.target.checked ? rows.map((r) => r.id) : [])}
                        />
                        {t('reviews.queue.selectAll', { count: rows.length })}
                    </label>
                    <Button size="sm" disabled={selected.length === 0} loading={bulk.isPending} onClick={() => run('approve')}>
                        <Check aria-hidden />
                        {t('reviews.queue.approveSelected', { count: selected.length })}
                    </Button>
                    <Select
                        aria-label={t('reviews.reject.reason')}
                        className="igr:h-7 igr:w-auto igr:text-xs"
                        value={reason}
                        onChange={(e) => setReason(e.target.value as RejectReason)}
                    >
                        {REJECT_REASONS.map((r) => (
                            <option key={r} value={r}>
                                {t(`reviews.reasons.${r}`)}
                            </option>
                        ))}
                    </Select>
                    <Button size="sm" variant="outline" disabled={selected.length === 0} loading={bulk.isPending} onClick={() => run('reject')}>
                        <X aria-hidden />
                        {t('reviews.queue.rejectSelected', { count: selected.length })}
                    </Button>
                </div>
            ) : null}
            {rows.map((review) => (
                <ReviewCard
                    key={review.id}
                    review={review}
                    selected={selected.includes(review.id)}
                    onSelect={canModerate ? (checked) => setSelected((s) => (checked ? [...s, review.id] : s.filter((id) => id !== review.id))) : undefined}
                />
            ))}
            <Pager page={page} total={list.data.meta.total} perPage={list.data.meta.per_page} onPage={setPage} />
        </div>
    );
}

function AllReviews() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const [filters, setFilters] = useState<ReviewFilters>({ page: 1 });
    const [q, setQ] = useState('');
    const list = useReviews(workspace.id, filters);
    const set = (patch: Partial<ReviewFilters>) => setFilters({ ...filters, ...patch, page: patch.page ?? 1 });

    return (
        <div className="igr:space-y-3">
            <form
                className="igr:flex igr:flex-wrap igr:items-end igr:gap-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    set({ q });
                }}
                aria-label={t('reviews.filters.label')}
            >
                <div className="igr:relative">
                    <Search className="igr:pointer-events-none igr:absolute igr:left-2 igr:top-2 igr:size-4 igr:text-muted-foreground" aria-hidden />
                    <Input
                        className="igr:w-56 igr:pl-8"
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        placeholder={t('reviews.filters.search')}
                        aria-label={t('reviews.filters.search')}
                    />
                </div>
                <Select
                    className="igr:w-auto"
                    aria-label={t('reviews.filters.status')}
                    value={filters.status ?? ''}
                    onChange={(e) => set({ status: e.target.value as ReviewStatus | '' })}
                >
                    <option value="">{t('reviews.filters.anyStatus')}</option>
                    {(['pending', 'published', 'rejected'] as const).map((s) => (
                        <option key={s} value={s}>
                            {t(`reviews.status.${s}`)}
                        </option>
                    ))}
                </Select>
                <Select
                    className="igr:w-auto"
                    aria-label={t('reviews.filters.rating')}
                    value={String(filters.rating ?? '')}
                    onChange={(e) => set({ rating: e.target.value === '' ? '' : Number(e.target.value) })}
                >
                    <option value="">{t('reviews.filters.anyRating')}</option>
                    {[5, 4, 3, 2, 1].map((r) => (
                        <option key={r} value={r}>
                            {t('reviews.stars', { count: r })}
                        </option>
                    ))}
                </Select>
                <Select
                    className="igr:w-auto"
                    aria-label={t('reviews.filters.source')}
                    value={filters.source ?? ''}
                    onChange={(e) => set({ source: e.target.value as ReviewSource | '' })}
                >
                    <option value="">{t('reviews.filters.anySource')}</option>
                    {(['request', 'woocommerce', 'import'] as const).map((s) => (
                        <option key={s} value={s}>
                            {t(`reviews.source.${s}`)}
                        </option>
                    ))}
                </Select>
                <label className="igr:flex igr:h-8 igr:items-center igr:gap-1.5 igr:text-[13px]">
                    <input type="checkbox" className="igr:size-4" checked={filters.media ?? false} onChange={(e) => set({ media: e.target.checked })} />
                    {t('reviews.filters.media')}
                </label>
                <label className="igr:flex igr:h-8 igr:items-center igr:gap-1.5 igr:text-[13px]">
                    <input type="checkbox" className="igr:size-4" checked={filters.flagged ?? false} onChange={(e) => set({ flagged: e.target.checked })} />
                    {t('reviews.filters.flagged')}
                </label>
            </form>
            {list.isError ? (
                <ErrorState error={list.error} onRetry={() => void list.refetch()} />
            ) : list.isPending ? (
                <ListSkeleton />
            ) : list.data.data.length === 0 ? (
                <EmptyState icon={Search} title={t('reviews.filters.emptyTitle')} description={t('reviews.filters.emptyDescription')} />
            ) : (
                <>
                    <p className="igr:text-[13px] igr:text-muted-foreground" aria-live="polite">
                        {t('reviews.filters.count', { count: list.data.meta.total })}
                    </p>
                    {list.data.data.map((review) => (
                        <ReviewCard key={review.id} review={review} />
                    ))}
                    <Pager page={filters.page ?? 1} total={list.data.meta.total} perPage={list.data.meta.per_page} onPage={(page) => set({ page })} />
                </>
            )}
        </div>
    );
}

export function Pager({ page, total, perPage, onPage }: { page: number; total: number; perPage: number; onPage: (p: number) => void }) {
    const { t } = useTranslation();
    const pages = Math.max(1, Math.ceil(total / perPage));
    if (pages <= 1) return null;
    return (
        <nav className="igr:flex igr:items-center igr:justify-end igr:gap-2 igr:text-[13px]" aria-label={t('reviews.pager.label')}>
            <Button size="sm" variant="outline" disabled={page <= 1} onClick={() => onPage(page - 1)}>
                {t('common.previous')}
            </Button>
            <span className="igr:tabular-nums igr:text-muted-foreground">{t('reviews.pager.of', { page, pages })}</span>
            <Button size="sm" variant="outline" disabled={page >= pages} onClick={() => onPage(page + 1)}>
                {t('common.next')}
            </Button>
        </nav>
    );
}

function ListSkeleton() {
    return (
        <div className="igr:space-y-3">
            {[0, 1, 2].map((i) => (
                <Skeleton key={i} className="igr:h-32" />
            ))}
        </div>
    );
}
