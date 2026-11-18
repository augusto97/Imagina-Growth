import { CircleAlert, FlaskConical, Pencil, Plus, Radar, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge, StatusDot } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { EmptyState, ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { formatRelative } from '@/lib/format';
import { toast } from '@/stores/toast';
import { type Destination, useDeleteDestination, useDestinations, useTestDestination } from './api';
import { ModeBadge, PlatformMark, PlatformName } from './bits';
import { DestinationDialog } from './DestinationDialog';

export function DestinationsTab() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageWorkspace');
    const destinations = useDestinations(workspace.id);
    const [editing, setEditing] = useState<Destination | 'new' | null>(null);
    const [deleting, setDeleting] = useState<Destination | null>(null);

    if (destinations.isPending) {
        return (
            <div className="igr:grid igr:gap-3">
                <Skeleton className="igr:h-24" />
                <Skeleton className="igr:h-24" />
            </div>
        );
    }
    if (destinations.isError) {
        return <ErrorState error={destinations.error} onRetry={() => void destinations.refetch()} />;
    }
    const list = destinations.data.destinations;

    return (
        <div className="igr:space-y-4">
            <ModesExplainer />
            {list.length === 0 ? (
                <EmptyState
                    icon={Radar}
                    title={t('measurement.empty.title')}
                    description={t('measurement.empty.description')}
                    action={canManage ? <Button onClick={() => setEditing('new')}><Plus />{t('measurement.destinations.add')}</Button> : undefined}
                />
            ) : (
                <>
                    {canManage ? (
                        <div className="igr:flex igr:justify-end">
                            <Button onClick={() => setEditing('new')}>
                                <Plus />
                                {t('measurement.destinations.add')}
                            </Button>
                        </div>
                    ) : null}
                    <div className="igr:grid igr:gap-3">
                        {list.map((d) => (
                            <DestinationCard key={d.id} destination={d} canManage={canManage} onEdit={() => setEditing(d)} onDelete={() => setDeleting(d)} />
                        ))}
                    </div>
                </>
            )}
            {editing !== null ? (
                <DestinationDialog
                    destination={editing === 'new' ? null : editing}
                    events={destinations.data.events}
                    onClose={() => setEditing(null)}
                />
            ) : null}
            <DeleteDialog destination={deleting} onClose={() => setDeleting(null)} />
        </div>
    );
}

function ModesExplainer() {
    const { t } = useTranslation();
    return (
        <div className="igr:grid igr:gap-3 igr:md:grid-cols-3">
            {(['server_only', 'with_pixel', 'full'] as const).map((mode) => (
                <div key={mode} className="igr:rounded-lg igr:border igr:p-3">
                    <p className="igr:text-[13px] igr:font-medium">{t(`measurement.mode.${mode}.title`)}</p>
                    <p className="igr:mt-1 igr:text-xs igr:text-muted-foreground">{t(`measurement.mode.${mode}.help`)}</p>
                </div>
            ))}
        </div>
    );
}

function DestinationCard({ destination: d, canManage, onEdit, onDelete }: { destination: Destination; canManage: boolean; onEdit: () => void; onDelete: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const test = useTestDestination(workspace.id);
    const runTest = () => {
        test.mutate(d.id, {
            onSuccess: (result) => {
                if (result.ok) {
                    toast.success(t('measurement.test.ok'));
                } else {
                    toast.error(t('measurement.test.failed'), result.results.filter((r) => !r.ok).map((r) => r.message).join(' '));
                }
            },
            onError: (error) => toast.error(t('measurement.test.failed'), isApiError(error) ? error.message : undefined),
        });
    };
    const endpoints = Object.entries(d.endpoints).filter(([, on]) => on).map(([key]) => key);

    return (
        <Card>
            <CardContent className="igr:flex igr:flex-col igr:gap-3 igr:py-4 igr:md:flex-row igr:md:items-start">
                <PlatformMark platform={d.platform} />
                <div className="igr:min-w-0 igr:flex-1 igr:space-y-1.5">
                    <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-2">
                        <span className="igr:text-sm igr:font-medium">{d.name ?? <PlatformName platform={d.platform} />}</span>
                        <ModeBadge mode={d.mode} />
                        {d.status === 'paused' ? <Badge variant="outline">{t('measurement.destinations.paused')}</Badge> : null}
                        {d.test_event_code ? <Badge variant="warning">{t('measurement.destinations.testMode')}</Badge> : null}
                    </div>
                    <p className="igr:text-xs igr:text-muted-foreground">
                        {endpoints.length > 0 ? endpoints.map((e) => t(`measurement.endpoint.${e}`)).join(' · ') : t('measurement.destinations.noEndpoints')}
                        {d.spend_import ? ` · ${t('measurement.destinations.spendOn')}` : ''}
                    </p>
                    <p className="igr:flex igr:items-center igr:gap-1.5 igr:text-xs igr:text-muted-foreground">
                        <StatusDot tone={d.last_error ? 'destructive' : d.last_delivered_at ? 'success' : 'muted'} />
                        {d.last_delivered_at ? t('measurement.destinations.lastDelivered', { when: formatRelative(d.last_delivered_at, workspace.locale) }) : t('measurement.destinations.neverDelivered')}
                    </p>
                    {d.last_error ? (
                        <p className="igr:flex igr:items-start igr:gap-1.5 igr:rounded-md igr:bg-destructive/8 igr:p-2 igr:text-xs igr:text-destructive">
                            <CircleAlert className="igr:mt-0.5 igr:size-3.5 igr:shrink-0" />
                            {d.last_error}
                        </p>
                    ) : null}
                    <p className="igr:text-xs igr:text-muted-foreground">
                        {t('measurement.destinations.events', { count: d.events_enabled.length })}: {d.events_enabled.map((e) => t(`measurement.events.${e.replace('.', '_')}`)).join(', ')}
                    </p>
                </div>
                {canManage ? (
                    <div className="igr:flex igr:shrink-0 igr:gap-1.5">
                        <Button variant="outline" size="sm" onClick={runTest} loading={test.isPending}>
                            <FlaskConical />
                            {t('measurement.test.action')}
                        </Button>
                        <Button variant="outline" size="sm" onClick={onEdit} aria-label={t('measurement.destinations.edit')}>
                            <Pencil />
                        </Button>
                        <Button variant="ghost" size="sm" onClick={onDelete} aria-label={t('measurement.destinations.delete')}>
                            <Trash2 />
                        </Button>
                    </div>
                ) : null}
            </CardContent>
        </Card>
    );
}

function DeleteDialog({ destination, onClose }: { destination: Destination | null; onClose: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const remove = useDeleteDestination(workspace.id);
    return (
        <Dialog open={destination !== null} onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('measurement.destinations.deleteTitle')}</DialogTitle>
                    <DialogDescription>{t('measurement.destinations.deleteHelp')}</DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    <Button
                        variant="destructive"
                        loading={remove.isPending}
                        onClick={() =>
                            destination &&
                            remove.mutate(destination.id, {
                                onSuccess: () => {
                                    toast.success(t('measurement.destinations.deleted'));
                                    onClose();
                                },
                                onError: () => toast.error(t('errors.generic')),
                            })
                        }
                    >
                        {t('measurement.destinations.delete')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
