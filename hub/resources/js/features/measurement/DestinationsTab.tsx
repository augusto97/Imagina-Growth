import { ArrowRight, FlaskConical, Link2, MousePointerClick, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { TFunction } from 'i18next';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Badge, StatusDot } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Callout } from '@/components/ui/callout';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { EmptyState, ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { formatRelative } from '@/lib/format';
import { toast } from '@/stores/toast';
import { type CatalogEvent, type Destination, useDeleteDestination, useDestinations, useSaveDestination, useTestDestination } from './api';
import { ModeBadge, PlatformMark, PlatformName } from './bits';
import { DestinationDialog } from './DestinationDialog';
import { CTA_EVENT, destinationState, withCta, type DestinationState } from './logic';

const STATE_TONE: Record<DestinationState, 'success' | 'warning' | 'destructive' | 'muted' | 'info'> = {
    sending: 'success',
    waiting: 'info',
    test: 'warning',
    missing: 'warning',
    error: 'destructive',
    paused: 'muted',
};

export function DestinationsTab() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageWorkspace');
    const destinations = useDestinations(workspace.id);
    const [editing, setEditing] = useState<Destination | 'new' | null>(null);
    const [deleting, setDeleting] = useState<Destination | null>(null);
    const connections = `/w/${workspace.id}/conexiones`;

    if (destinations.isPending) {
        return (
            <div className="igr:grid igr:gap-3">
                <Skeleton className="igr:h-40 igr:rounded-xl" />
                <Skeleton className="igr:h-40 igr:rounded-xl" />
            </div>
        );
    }
    if (destinations.isError) {
        return <ErrorState error={destinations.error} onRetry={() => void destinations.refetch()} />;
    }
    const list = destinations.data.destinations;

    return (
        <div className="igr:space-y-4">
            {list.length === 0 ? (
                <EmptyState
                    illustration="results"
                    title={t('measurement.empty.title')}
                    description={t('measurement.empty.description')}
                    action={
                        canManage ? (
                            <Button asChild>
                                <Link to={connections}>
                                    <Link2 aria-hidden />
                                    {t('measurement.empty.connect')}
                                </Link>
                            </Button>
                        ) : undefined
                    }
                    secondary={
                        canManage ? (
                            <Button variant="outline" onClick={() => setEditing('new')}>
                                <Plus aria-hidden />
                                {t('measurement.empty.manual')}
                            </Button>
                        ) : undefined
                    }
                    steps={[t('measurement.empty.step1'), t('measurement.empty.step2'), t('measurement.empty.step3')]}
                />
            ) : (
                <>
                    <Callout
                        tone="brand"
                        icon={Link2}
                        title={t('connections.shortcut.title')}
                        action={
                            canManage ? (
                                <Button asChild size="sm" variant="outline">
                                    <Link to={connections}>
                                        {t('connections.shortcut.go')}
                                        <ArrowRight aria-hidden />
                                    </Link>
                                </Button>
                            ) : undefined
                        }
                    >
                        {t('measurement.destinations.connectionsHelp')}
                    </Callout>
                    <div className="igr:grid igr:gap-3">
                        {list.map((d) => (
                            <DestinationCard key={d.id} destination={d} events={destinations.data.events} canManage={canManage} onEdit={() => setEditing(d)} onDelete={() => setDeleting(d)} />
                        ))}
                    </div>
                    {canManage ? (
                        <Button variant="outline" onClick={() => setEditing('new')}>
                            <Plus aria-hidden />
                            {t('measurement.destinations.add')}
                        </Button>
                    ) : null}
                </>
            )}
            <ModesExplainer />
            {editing !== null ? <DestinationDialog destination={editing === 'new' ? null : editing} events={destinations.data.events} onClose={() => setEditing(null)} /> : null}
            <DeleteDialog destination={deleting} onClose={() => setDeleting(null)} />
        </div>
    );
}

function ModesExplainer() {
    const { t } = useTranslation();
    return (
        <section aria-labelledby="modes-title" className="igr:pt-2">
            <h2 id="modes-title" className="igr:text-[13px] igr:font-semibold">
                {t('measurement.modesTitle')}
            </h2>
            <p className="igr:mt-0.5 igr:mb-3 igr:text-support igr:text-muted-foreground">{t('measurement.modesHelp')}</p>
            <div className="igr:grid igr:gap-3 igr:md:grid-cols-3">
                {(['server_only', 'with_pixel', 'full'] as const).map((mode) => (
                    <div key={mode} className="igr:rounded-lg igr:border igr:bg-card igr:p-3">
                        <p className="igr:text-[13px] igr:font-medium">{t(`measurement.mode.${mode}.title`)}</p>
                        <p className="igr:mt-1 igr:text-xs igr:text-muted-foreground">{t(`measurement.mode.${mode}.help`)}</p>
                    </div>
                ))}
            </div>
        </section>
    );
}

/** Un evento del sitio con el nombre que recibe la plataforma («Compra → Purchase»). */
function eventLabel(t: TFunction, event: string, catalog: CatalogEvent[], platform: Destination['platform']): string {
    const name = t(`measurement.events.${event.replace('.', '_')}`, { defaultValue: event });
    const entry = catalog.find((e) => e.event === event) as (CatalogEvent & Record<string, unknown>) | undefined;
    const target = entry ? (entry[platform] as string | undefined) : undefined;
    return target ? `${name} → ${target}` : name;
}

function DestinationCard({
    destination: d,
    events,
    canManage,
    onEdit,
    onDelete,
}: {
    destination: Destination;
    events: CatalogEvent[];
    canManage: boolean;
    onEdit: () => void;
    onDelete: () => void;
}) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const test = useTestDestination(workspace.id);
    const save = useSaveDestination(workspace.id);
    const state = destinationState(d);
    const ctaOn = d.events_enabled.includes(CTA_EVENT);
    const ctaAvailable = events.some((e) => e.event === CTA_EVENT);
    const sent = d.events_enabled.filter((e) => e !== CTA_EVENT);
    const runTest = () => {
        test.mutate(d.id, {
            onSuccess: (result) => {
                if (result.ok) {
                    toast.success(t('measurement.test.ok'));
                } else {
                    toast.error(
                        t('measurement.test.failed'),
                        result.results
                            .filter((r) => !r.ok)
                            .map((r) => r.message)
                            .join(' '),
                    );
                }
            },
            onError: (error) => toast.error(t('measurement.test.failed'), isApiError(error) ? error.message : undefined),
        });
    };
    const toggleCta = () =>
        save.mutate(
            { id: d.id, input: { events_enabled: withCta(d.events_enabled, !ctaOn) } },
            {
                onSuccess: () => toast.success(t(ctaOn ? 'measurement.cta.turnedOff' : 'measurement.cta.turnedOn')),
                onError: (error) => toast.error(isApiError(error) ? error.message : t('errors.generic')),
            },
        );

    return (
        <Card>
            <CardContent className="igr:space-y-4 igr:pt-5">
                <div className="igr:flex igr:flex-wrap igr:items-start igr:gap-3">
                    <PlatformMark platform={d.platform} />
                    <div className="igr:min-w-0 igr:flex-1 igr:basis-56">
                        <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-2">
                            <span className="igr:text-[15px] igr:font-semibold">{d.name ?? <PlatformName platform={d.platform} />}</span>
                            <ModeBadge mode={d.mode} />
                            {d.connection ? (
                                <Badge variant={d.connection.status === 'active' ? 'success' : 'warning'}>
                                    {t('connections.destination.connected', { account: d.connection.account_name ?? d.connection.provider })}
                                </Badge>
                            ) : null}
                        </div>
                        <p className="igr:mt-1 igr:flex igr:items-start igr:gap-1.5 igr:text-support">
                            <StatusDot tone={STATE_TONE[state]} />
                            <span className="igr:min-w-0">
                                <span className="igr:font-medium">{t(`measurement.state.${state}.title`)}</span>{' '}
                                <span className="igr:text-muted-foreground">
                                    {state === 'error'
                                        ? d.last_error
                                        : t(`measurement.state.${state}.body`, { when: d.last_delivered_at ? formatRelative(d.last_delivered_at, workspace.locale) : '' })}
                                </span>
                            </span>
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
                </div>
                <div>
                    <p className="igr:text-caption igr:font-medium igr:text-muted-foreground">{t('measurement.destinations.whatWeSend')}</p>
                    {sent.length > 0 ? (
                        <ul className="igr:mt-1.5 igr:flex igr:flex-wrap igr:gap-1.5">
                            {sent.map((e) => (
                                <li key={e}>
                                    <Badge variant="outline">{eventLabel(t, e, events, d.platform)}</Badge>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="igr:mt-1 igr:text-support igr:text-muted-foreground">{t('measurement.destinations.nothing')}</p>
                    )}
                    {d.spend_import ? <p className="igr:mt-1.5 igr:text-caption igr:text-muted-foreground">{t('measurement.destinations.spendOnLong')}</p> : null}
                </div>
                {ctaAvailable ? (
                    <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-3 igr:rounded-lg igr:border igr:bg-muted/30 igr:p-3">
                        <MousePointerClick className="igr:size-4 igr:shrink-0 igr:text-primary" aria-hidden />
                        <div className="igr:min-w-0 igr:flex-1 igr:basis-56">
                            <p className="igr:text-[13px] igr:font-medium">
                                {t('measurement.cta.title')}{' '}
                                <Badge variant={ctaOn ? 'success' : 'outline'} className="igr:ml-1 igr:align-middle">
                                    {ctaOn ? t('measurement.cta.on') : t('measurement.cta.off')}
                                </Badge>
                            </p>
                            <p className="igr:mt-0.5 igr:text-caption igr:text-muted-foreground">{t(`measurement.cta.help.${d.platform}`)}</p>
                        </div>
                        {canManage ? (
                            <Button size="sm" variant={ctaOn ? 'outline' : 'default'} loading={save.isPending} onClick={toggleCta} aria-pressed={ctaOn}>
                                {ctaOn ? t('measurement.cta.turnOff') : t('measurement.cta.turnOn')}
                            </Button>
                        ) : null}
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
