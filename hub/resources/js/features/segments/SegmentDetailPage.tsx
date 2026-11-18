import { ArrowLeft, Camera, Copy, Download, Lock, MoreHorizontal, Pencil, RefreshCw, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useParams } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { ErrorState, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { formatDateTime, formatMoney, formatNumber } from '@/lib/format';
import { toast } from '@/stores/toast';
import { exportUrl, useSegment, useSegmentAction, useSegmentMembers, useSegmentOptions } from './api';
import { BuilderContext } from './builder/context';
import { GroupEditor } from './builder/GroupEditor';
import { DeleteSegmentDialog, SnapshotDialog } from './dialogs';
import { summarize } from './humanize';
import { SampleTable } from './PreviewPanel';
import type { DuplicateState } from './SegmentBuilderPage';
import type { SegmentDetail } from './types';

export function SegmentDetailPage() {
    const { segmentId = '' } = useParams();
    const { workspace } = useWorkspace();
    const segment = useSegment(workspace.id, segmentId);

    if (segment.isPending) {
        return (
            <div className="igr:space-y-4">
                <Skeleton className="igr:h-10 igr:w-72" />
                <div className="igr:grid igr:gap-3 igr:md:grid-cols-4">
                    {[0, 1, 2, 3].map((i) => (
                        <Skeleton key={i} className="igr:h-20" />
                    ))}
                </div>
                <Skeleton className="igr:h-64" />
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
    const [dialog, setDialog] = useState<'snapshot' | 'delete' | null>(null);
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
                title={
                    <span className="igr:flex igr:flex-wrap igr:items-center igr:gap-2">
                        <Button asChild variant="ghost" size="icon" className="igr:size-7">
                            <Link to={`/w/${workspace.id}/segmentos`} aria-label={t('common.back')}>
                                <ArrowLeft />
                            </Link>
                        </Button>
                        {segment.name}
                        {segment.is_system ? (
                            <Badge variant="secondary">
                                <Lock className="igr:size-3" />
                                {t('segments.badges.system')}
                            </Badge>
                        ) : null}
                        <Badge variant="outline">{t(`segments.kind.${segment.kind}`)}</Badge>
                        {segment.folder ? <Badge variant="outline">{segment.folder}</Badge> : null}
                    </span>
                }
                description={segment.description ?? undefined}
                actions={
                    <>
                        {canExport ? (
                            <Button asChild variant="outline">
                                <a href={exportUrl(workspace.id, segment.id)} download>
                                    <Download />
                                    {t('segments.detail.export')}
                                </a>
                            </Button>
                        ) : null}
                        {editable ? (
                            <Button asChild>
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

            <div className="igr:grid igr:gap-3 igr:sm:grid-cols-2 igr:lg:grid-cols-4">
                <Kpi label={t('segments.columns.members')} value={computing ? null : formatNumber(segment.member_count, workspace.locale)} />
                <Kpi label={t('segments.detail.reachWhatsapp')} value={computing ? null : formatNumber(segment.channel_counts.whatsapp ?? 0, workspace.locale)} />
                <Kpi label={t('segments.detail.reachEmail')} value={computing ? null : formatNumber(segment.channel_counts.email ?? 0, workspace.locale)} />
                <Kpi label={t('segments.detail.value')} value={computing ? null : formatMoney(segment.revenue_ws, workspace.currency, workspace.locale)} />
            </div>
            <p className="igr:text-xs igr:text-muted-foreground">
                {computing ? t('segments.detail.computing') : dynamic ? t('segments.detail.lastComputed', { when: formatDateTime(segment.last_computed_at, workspace.locale, workspace.timezone) }) : t('segments.detail.staticHelp')}
            </p>

            {segment.definition ? <Definition segment={segment} /> : null}

            <Members segment={segment} />

            {dialog === 'snapshot' ? <SnapshotDialog segment={segment} open onClose={() => setDialog(null)} /> : null}
            {dialog === 'delete' ? <DeleteSegmentDialog segment={segment} open onClose={() => setDialog(null)} /> : null}
        </div>
    );
}

function Kpi({ label, value }: { label: string; value: string | null }) {
    return (
        <Card>
            <CardContent className="igr:py-3">
                <p className="igr:text-xs igr:text-muted-foreground">{label}</p>
                {value === null ? <Skeleton className="igr:mt-1 igr:h-7 igr:w-24" /> : <p className="igr:mt-0.5 igr:text-xl igr:font-semibold igr:tabular-nums">{value}</p>}
            </CardContent>
        </Card>
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
                        <BuilderContext.Provider value={{ ws: workspace.id, options: options.data, readOnly: true, errors: {}, selfId: segment.id, currency: workspace.currency, locale: workspace.locale }}>
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
    const [page, setPage] = useState(1);
    const members = useSegmentMembers(workspace.id, segment.id, page);
    const meta = members.data?.meta;
    const pages = meta ? Math.max(1, Math.ceil(meta.total / meta.per_page)) : 1;

    return (
        <Card>
            <CardHeader className="igr:flex-row igr:items-center igr:justify-between">
                <CardTitle>{t('segments.members.title')}</CardTitle>
                {meta ? <span className="igr:text-xs igr:text-muted-foreground">{t('segments.members.count', { count: meta.total, formatted: formatNumber(meta.total, workspace.locale) })}</span> : null}
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
                        <SampleTable rows={members.data.data} dense={false} />
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
