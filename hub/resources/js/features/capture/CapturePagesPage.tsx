import { ArrowUpRight, Eye, LayoutTemplate, Plus, QrCode, Send } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate } from 'react-router';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { CopyButton } from '@/components/ui/copy-field';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ErrorState, FieldError } from '@/components/ui/misc';
import { PageHeader } from '@/components/ui/page-header';
import { Skeleton } from '@/components/ui/skeleton';
import { StatCard, StatCardSkeleton, StatGrid } from '@/components/ui/stat-card';
import { errorMessage } from '@/features/contacts/errors';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { formatNumber, formatPercent } from '@/lib/format';
import { useLandingMutations, useLandingPages } from './api';
import { conversion } from './model';
import { PageStatusBadge } from './shared';
import type { LandingPage } from './types';

/**
 * Páginas de captura (modulos.md M12 «Páginas de captura y QR»): la lista con lo que trajo cada una en 30 días y el
 * alta. Sirven sin web conectada: para el negocio sin buena web, para las redes o para el QR del local.
 */
export function CapturePagesPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageMarketing');
    const pages = useLandingPages(workspace.id);
    const [creating, setCreating] = useState(false);
    const rows = pages.data?.data ?? [];

    return (
        <>
            <PageHeader
                title={t('capture.pages.title')}
                description={t('capture.pages.description')}
                actions={
                    canManage && rows.length > 0 ? (
                        <Button onClick={() => setCreating(true)}>
                            <Plus aria-hidden />
                            {t('capture.pages.new')}
                        </Button>
                    ) : null
                }
            />
            {pages.isError ? (
                <ErrorState error={pages.error} onRetry={() => void pages.refetch()} />
            ) : pages.isPending ? (
                <>
                    <StatGrid className="igr:mb-6">
                        {[0, 1, 2, 3].map((i) => (
                            <StatCardSkeleton key={i} />
                        ))}
                    </StatGrid>
                    <div className="igr:grid igr:gap-4 igr:md:grid-cols-2 igr:xl:grid-cols-3">
                        {[0, 1, 2].map((i) => (
                            <Skeleton key={i} className="igr:h-52 igr:rounded-xl" />
                        ))}
                    </div>
                </>
            ) : rows.length === 0 ? (
                <EmptyState
                    illustration="web"
                    title={t('capture.pages.empty.title')}
                    description={t('capture.pages.empty.description')}
                    action={
                        canManage ? (
                            <Button size="lg" onClick={() => setCreating(true)}>
                                <Plus aria-hidden />
                                {t('capture.pages.empty.action')}
                            </Button>
                        ) : undefined
                    }
                    steps={[t('capture.pages.empty.step1'), t('capture.pages.empty.step2'), t('capture.pages.empty.step3')]}
                />
            ) : (
                <>
                    <Totals pages={rows} />
                    <div className="igr:grid igr:gap-4 igr:md:grid-cols-2 igr:xl:grid-cols-3">
                        {rows.map((page) => (
                            <PageCard key={page.id} page={page} />
                        ))}
                    </div>
                </>
            )}
            {creating ? <NewPageDialog onClose={() => setCreating(false)} /> : null}
        </>
    );
}

function Totals({ pages }: { pages: LandingPage[] }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const sum = pages.reduce(
        (acc, p) => ({ views: acc.views + (p.stats?.views ?? 0), submissions: acc.submissions + (p.stats?.submissions ?? 0), qr: acc.qr + (p.stats?.qr_views ?? 0) }),
        {
            views: 0,
            submissions: 0,
            qr: 0,
        },
    );
    const rate = conversion(sum.submissions, sum.views);
    const published = pages.filter((p) => p.status === 'published').length;
    return (
        <StatGrid className="igr:mb-6">
            <StatCard label={t('capture.pages.totals.views')} value={formatNumber(sum.views, workspace.locale)} hint={t('capture.pages.totals.window')} icon={Eye} tone="sky" />
            <StatCard
                label={t('capture.pages.totals.submissions')}
                value={formatNumber(sum.submissions, workspace.locale)}
                hint={t('capture.pages.totals.window')}
                icon={Send}
                tone="emerald"
            />
            <StatCard
                label={t('capture.pages.totals.conversion')}
                value={rate === null ? '—' : formatPercent(rate, workspace.locale, 1)}
                help={t('capture.pages.totals.conversionHelp')}
                hint={t('capture.pages.totals.window')}
                icon={ArrowUpRight}
                tone="violet"
            />
            <StatCard
                label={t('capture.pages.totals.qr')}
                value={formatNumber(sum.qr, workspace.locale)}
                help={t('capture.pages.totals.qrHelp')}
                hint={t('capture.pages.totals.published', { count: published })}
                icon={QrCode}
                tone="amber"
            />
        </StatGrid>
    );
}

function PageCard({ page }: { page: LandingPage }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const to = `/w/${workspace.id}/paginas-de-captura/${page.id}`;
    const s = page.stats;
    const rate = s ? conversion(s.submissions, s.views) : null;
    const fmt = (n: number | undefined) => formatNumber(n ?? 0, workspace.locale);
    return (
        <Card className="igr:group igr:flex igr:min-w-0 igr:flex-col igr:transition-[box-shadow,border-color] igr:hover:border-primary/25 igr:hover:shadow-md">
            <div className="igr:flex igr:items-start igr:gap-3 igr:p-5 igr:pb-3">
                <div className="igr:flex igr:size-10 igr:shrink-0 igr:items-center igr:justify-center igr:rounded-lg igr:bg-brand-soft igr:text-brand-soft-foreground">
                    <LayoutTemplate className="igr:size-5" aria-hidden />
                </div>
                <div className="igr:min-w-0 igr:flex-1">
                    <Link to={to} className="igr:block igr:truncate igr:text-[15px] igr:font-semibold igr:tracking-tight igr:hover:underline igr:focus-visible:underline">
                        {page.name}
                    </Link>
                    <p className="igr:mt-0.5 igr:truncate igr:text-caption igr:text-muted-foreground" title={page.url}>
                        {page.url.replace(/^https?:\/\//, '')}
                    </p>
                </div>
                <PageStatusBadge status={page.status} />
            </div>
            <dl className="igr:grid igr:grid-cols-3 igr:gap-2 igr:px-5 igr:pb-4">
                <Metric label={t('capture.pages.card.views')} value={fmt(s?.views)} />
                <Metric label={t('capture.pages.card.submissions')} value={fmt(s?.submissions)} />
                <Metric label={t('capture.pages.card.conversion')} value={rate === null ? '—' : formatPercent(rate, workspace.locale, 1)} />
            </dl>
            <div className="igr:mt-auto igr:flex igr:flex-wrap igr:items-center igr:gap-2 igr:border-t igr:px-5 igr:py-3">
                <Button asChild size="sm" variant={page.status === 'published' ? 'outline' : 'default'}>
                    <Link to={to}>{page.status === 'published' ? t('capture.pages.card.open') : t('capture.pages.card.finish')}</Link>
                </Button>
                {page.status === 'published' ? (
                    <>
                        <Button asChild size="sm" variant="ghost">
                            <Link to={`${to}?vista=compartir`}>
                                <QrCode aria-hidden />
                                {t('capture.pages.card.share')}
                            </Link>
                        </Button>
                        <CopyButton value={page.url} size="sm" variant="ghost" label={t('capture.pages.card.copy', { name: page.name })} className="igr:ml-auto" />
                    </>
                ) : (
                    <span className="igr:ml-auto igr:text-caption igr:text-muted-foreground">{t('capture.pages.card.draftHint')}</span>
                )}
            </div>
        </Card>
    );
}

function Metric({ label, value }: { label: string; value: string }) {
    return (
        <div className="igr:min-w-0 igr:rounded-lg igr:bg-muted/50 igr:px-3 igr:py-2">
            <dt className="igr:truncate igr:text-caption igr:text-muted-foreground">{label}</dt>
            <dd className="igr:text-[15px] igr:font-semibold igr:tabular-nums">{value}</dd>
        </div>
    );
}

export function NewPageDialog({ onClose }: { onClose: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const navigate = useNavigate();
    const { create } = useLandingMutations(workspace.id);
    const [name, setName] = useState('');
    const [title, setTitle] = useState('');
    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('capture.pages.create.title')}</DialogTitle>
                    <DialogDescription>{t('capture.pages.create.description')}</DialogDescription>
                </DialogHeader>
                <form
                    className="igr:space-y-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        create.mutate(
                            { name: name.trim(), ...(title.trim() !== '' ? { title: title.trim() } : {}) },
                            { onSuccess: (page) => void navigate(`/w/${workspace.id}/paginas-de-captura/${page.id}`) },
                        );
                    }}
                >
                    <div className="igr:space-y-1.5">
                        <Label htmlFor="lp-name">{t('capture.pages.create.name')}</Label>
                        <Input
                            id="lp-name"
                            required
                            minLength={2}
                            maxLength={160}
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            placeholder={t('capture.pages.create.namePlaceholder')}
                            autoFocus
                        />
                        <p className="igr:text-caption igr:text-muted-foreground">{t('capture.pages.create.nameHelp')}</p>
                    </div>
                    <div className="igr:space-y-1.5">
                        <Label htmlFor="lp-title">{t('capture.pages.create.headline')}</Label>
                        <Input id="lp-title" maxLength={120} value={title} onChange={(e) => setTitle(e.target.value)} placeholder={t('capture.pages.create.headlinePlaceholder')} />
                    </div>
                    {create.isError ? <FieldError message={errorMessage(create.error, t)} /> : null}
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>
                            {t('common.cancel')}
                        </Button>
                        <Button type="submit" loading={create.isPending} disabled={name.trim().length < 2}>
                            {t('capture.pages.create.submit')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
