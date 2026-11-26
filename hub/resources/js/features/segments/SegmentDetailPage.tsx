import { Camera, Copy, Download, Lock, Mail, Megaphone, MessageCircle, MoreHorizontal, Pencil, RefreshCw, Trash2, Users, Wallet, Workflow } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useParams } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { ErrorState, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { StatCard, StatCardSkeleton, StatGrid } from '@/components/ui/stat-card';
import { useBusiness } from '@/features/contacts/business';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { formatDateTime, formatMoney, formatNumber } from '@/lib/format';
import { toast } from '@/stores/toast';
import { exportUrl, useSegment, useSegmentAction, useSegmentMembers, useSegmentOptions } from './api';
import { BuilderContext } from './builder/context';
import { GroupEditor } from './builder/GroupEditor';
import { DeleteSegmentDialog, SnapshotDialog } from './dialogs';
import { summarize } from './humanize';
import { explain, lookOf } from './look';
import { SampleTable } from './PreviewPanel';
import { UseGroupDialog } from './UseGroupDialog';
import type { DuplicateState } from './SegmentBuilderPage';
import type { SegmentDetail } from './types';

export function SegmentDetailPage() {
    const { segmentId = '' } = useParams();
    const { workspace } = useWorkspace();
    const segment = useSegment(workspace.id, segmentId);

    if (segment.isPending) {
        return (
            <div className="igr:space-y-5">
                <Skeleton className="igr:h-16 igr:w-80" />
                <StatGrid>
                    {[0, 1, 2, 3].map((i) => (
                        <StatCardSkeleton key={i} />
                    ))}
                </StatGrid>
                <Skeleton className="igr:h-64 igr:rounded-xl" />
            </div>
        );
    }
    if (segment.isError) {
        return <ErrorState error={segment.error} onRetry={() => void segment.refetch()} />;
    }
    return <Detail segment={segment.data} />;
}

function Detail({ segment }: { segment: SegmentDetail }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const navigate = useNavigate();
    const canManage = useCan('manageMarketing');
    const canExport = useCan('exportContacts');
    const actions = useSegmentAction(workspace.id, segment.id);
    const business = useBusiness();
    const [dialog, setDialog] = useState<'snapshot' | 'delete' | 'campaign' | 'flow' | null>(null);
    const look = lookOf(segment);
    const { what, todo } = explain(t, segment);
    const dynamic = segment.kind === 'dynamic';
    const computing = dynamic && segment.last_computed_at === null;
    const editable = canManage && dynamic && !segment.is_system;

    const recompute = () =>
        actions.recompute.mutate(undefined, {
            onSuccess: () => toast.success(t('segments.detail.recomputeQueued')),
            onError: (error) => toast.error(t('segments.detail.recomputeFailed'), isApiError(error) ? error.message : undefined),
        });
    const duplicate = () => {
        if (segment.definition) {
            const state: DuplicateState = { duplicate: { name: segment.name, description: segment.description, folder: segment.folder, definition: segment.definition } };
            void navigate(`/w/${workspace.id}/segmentos/nuevo`, { state });
        }
    };

    return (
        <div className="igr:space-y-5">
            <PageHeader
                title={segment.name}
                icon={look.icon}
                tone={look.tone}
                meta={
                    <>
                        {segment.is_system ? (
                            <Badge variant="secondary" title={t('segments.badges.systemHelp')}>
                                <Lock className="igr:size-3" />
                                {t('segments.badges.system')}
                            </Badge>
                        ) : null}
                        <Badge variant="outline">{t(`segments.kind.${segment.kind}`)}</Badge>
                    </>
                }
                description={
                    what || todo ? (
                        <>
                            {what}
                            {todo ? <span className="igr:mt-1 igr:block igr:text-support">{t('segments.list.todo', { action: todo })}</span> : null}
                        </>
                    ) : undefined
                }
                actions={
                    <>
                        {canManage ? (
                            <>
                                <Button variant="outline" onClick={() => setDialog('flow')}>
                                    <Workflow />
                                    {t('segments.list.automate')}
                                </Button>
                                <Button onClick={() => setDialog('campaign')} disabled={computing || segment.member_count === 0}>
                                    <Megaphone />
                                    {t('segments.list.sendCampaign')}
                                </Button>
                            </>
                        ) : null}
                        {canExport ? (
                            <Button asChild variant="outline">
                                <a href={exportUrl(workspace.id, segment.id)} download>
                                    <Download />
                                    {t('segments.detail.export')}
                                </a>
                            </Button>
                        ) : null}
                        {editable ? (
                            <Button asChild variant="outline">
                                <Link to="editar">
                                    <Pencil />
                                    {t('segments.detail.edit')}
                                </Link>
                            </Button>
                        ) : null}
                        {canManage ? (
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button variant="outline" size="icon" aria-label={t('segments.detail.more')}>
                                        <MoreHorizontal />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    {dynamic ? (
                                        <>
                                            <DropdownMenuItem onSelect={recompute} disabled={actions.recompute.isPending}>
                                                <RefreshCw />
                                                {t('segments.detail.recompute')}
                                            </DropdownMenuItem>
                                            <DropdownMenuItem onSelect={duplicate}>
                                                <Copy />
                                                {t('segments.detail.duplicate')}
                                            </DropdownMenuItem>
                                            <DropdownMenuItem onSelect={() => setDialog('snapshot')}>
                                                <Camera />
                                                {t('segments.detail.snapshot')}
                                            </DropdownMenuItem>
                                        </>
                                    ) : null}
                                    {segment.is_system ? null : (
                                        <DropdownMenuItem onSelect={() => setDialog('delete')} className="igr:text-destructive">
                                            <Trash2 />
                                            {t('segments.detail.delete')}
                                        </DropdownMenuItem>
                                    )}
                                </DropdownMenuContent>
                            </DropdownMenu>
                        ) : null}
                    </>
                }
            />

            <StatGrid className={business.store ? undefined : 'igr:lg:grid-cols-3'}>
                <StatCard
                    icon={Users}
                    tone="sky"
                    label={t('segments.detail.people')}
                    loading={computing}
                    value={formatNumber(segment.member_count, workspace.locale)}
                    trend={segment.trend && segment.trend.points.length > 1 ? segment.trend.points : undefined}
                    change={segment.trend?.change != null && segment.trend.points[0] ? segment.trend.change / segment.trend.points[0] : null}
                    changeLabel={segment.trend?.change != null ? `${segment.trend.change > 0 ? '+' : segment.trend.change < 0 ? '−' : ''}${formatNumber(Math.abs(segment.trend.change), workspace.locale)}` : undefined}
                    compareLabel={t('segments.detail.inDays', { days: segment.trend?.days ?? 30 })}
                    better="neutral"
                />
                <StatCard
                    icon={MessageCircle}
                    tone="emerald"
                    label={t('segments.detail.reachWhatsapp')}
                    help={t('segments.detail.reachHelp')}
                    loading={computing}
                    value={formatNumber(segment.channel_counts.whatsapp ?? 0, workspace.locale)}
                />
                <StatCard
                    icon={Mail}
                    tone="indigo"
                    label={t('segments.detail.reachEmail')}
                    help={t('segments.detail.reachHelp')}
                    loading={computing}
                    value={formatNumber(segment.channel_counts.email ?? 0, workspace.locale)}
                />
                {business.store ? (
                    <StatCard
                        icon={Wallet}
                        tone="amber"
                        label={t('segments.detail.value')}
                        help={t('segments.detail.valueHelp')}
                        loading={computing}
                        value={formatMoney(segment.revenue_ws, workspace.currency, workspace.locale)}
                    />
                ) : null}
            </StatGrid>
            <p className="igr:text-xs igr:text-muted-foreground">
                {computing
                    ? t('segments.detail.computing')
                    : dynamic
                      ? t('segments.detail.lastComputed', { when: formatDateTime(segment.last_computed_at, workspace.locale, workspace.timezone) })
                      : t('segments.detail.staticHelp')}
            </p>

            {segment.definition ? <Definition segment={segment} /> : null}

            <Members segment={segment} />

            {dialog === 'snapshot' ? <SnapshotDialog segment={segment} open onClose={() => setDialog(null)} /> : null}
            {dialog === 'campaign' || dialog === 'flow' ? <UseGroupDialog segment={segment} mode={dialog} onClose={() => setDialog(null)} /> : null}
            {dialog === 'delete' ? <DeleteSegmentDialog segment={segment} open onClose={() => setDialog(null)} /> : null}
        </div>
    );
}

function Definition({ segment }: { segment: SegmentDetail }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const options = useSegmentOptions(workspace.id);
    const [open, setOpen] = useState(false);
    const names: Record<string, string> = {};
    for (const s of options.data?.segments ?? []) {
        names[s.id] = s.name;
    }
    for (const s of options.data?.rfm_segments ?? []) {
        names[s.key] = s.name;
    }
    for (const s of [...(options.data?.flows ?? []), ...(options.data?.campaigns ?? []), ...(options.data?.loyalty_tiers ?? [])]) {
        names[s.id] = s.name;
    }
    const definition = segment.definition;
    if (definition === null) {
        return null;
    }

    return (
        <Card>
            <CardHeader className="igr:flex-row igr:items-center igr:justify-between">
                <CardTitle>{t('segments.detail.definition')}</CardTitle>
                <Button variant="ghost" size="sm" onClick={() => setOpen(!open)} aria-expanded={open}>
                    {open ? t('segments.detail.hideConditions') : t('segments.detail.showConditions')}
                </Button>
            </CardHeader>
            <CardContent>
                <p className="igr:text-[13px] igr:text-muted-foreground">{summarize(t, definition, names)}</p>
                {open && options.data ? (
                    <div className="igr:mt-3">
                        <BuilderContext.Provider
                            value={{
                                ws: workspace.id,
                                options: options.data,
                                readOnly: true,
                                errors: {},
                                selfId: segment.id,
                                currency: workspace.currency,
                                locale: workspace.locale,
                            }}
                        >
                            <GroupEditor group={definition} path={[]} root={definition} setRoot={() => undefined} />
                        </BuilderContext.Provider>
                    </div>
                ) : null}
            </CardContent>
        </Card>
    );
}

function Members({ segment }: { segment: SegmentDetail }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const business = useBusiness();
    const [page, setPage] = useState(1);
    const members = useSegmentMembers(workspace.id, segment.id, page);
    const meta = members.data?.meta;
    const pages = meta ? Math.max(1, Math.ceil(meta.total / meta.per_page)) : 1;

    return (
        <Card>
            <CardHeader className="igr:flex-row igr:items-center igr:justify-between">
                <CardTitle>{t('segments.members.title')}</CardTitle>
                {meta ? (
                    <span className="igr:text-xs igr:text-muted-foreground">
                        {t('segments.members.count', { count: meta.total, formatted: formatNumber(meta.total, workspace.locale) })}
                    </span>
                ) : null}
            </CardHeader>
            <CardContent>
                {members.isPending ? (
                    <div className="igr:space-y-2">
                        {[0, 1, 2, 3, 4].map((i) => (
                            <Skeleton key={i} className="igr:h-9" />
                        ))}
                    </div>
                ) : members.isError ? (
                    <ErrorState error={members.error} onRetry={() => void members.refetch()} />
                ) : (
                    <>
                        <SampleTable rows={members.data.data} dense={false} commerce={business.store} />
                        {pages > 1 ? (
                            <div className="igr:mt-3 igr:flex igr:items-center igr:justify-end igr:gap-2 igr:text-xs igr:text-muted-foreground">
                                <span>{t('segments.members.page', { page, pages })}</span>
                                <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>
                                    {t('common.previous')}
                                </Button>
                                <Button variant="outline" size="sm" disabled={page >= pages} onClick={() => setPage(page + 1)}>
                                    {t('common.next')}
                                </Button>
                            </div>
                        ) : null}
                    </>
                )}
            </CardContent>
        </Card>
    );
}
