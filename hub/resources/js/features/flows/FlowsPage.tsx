import { CircleAlert, CirclePause, CirclePlay, FileEdit, Library, Plus, Workflow } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useSearchParams } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input, Textarea } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { EmptyState, ErrorState, FieldError, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { formatNumber, formatRelative } from '@/lib/format';
import { toast } from '@/stores/toast';
import { CreateWithAiButton } from '@/features/copilot/CopilotDialog';
import { useCreateFlow, useCreateFromLibrary, useFlowLibrary, useFlowOptions, useFlows } from './api';
import { triggerText } from './catalog';
import type { FlowStatus, LibraryFlow } from './types';

const STATUS_ICON = { active: CirclePlay, paused: CirclePause, draft: FileEdit, archived: FileEdit } as const;
const STATUS_VARIANT = { active: 'success', paused: 'warning', draft: 'secondary', archived: 'outline' } as const;

export function StatusBadge({ status }: { status: FlowStatus }) {
    const { t } = useTranslation();
    const Icon = STATUS_ICON[status];
    return (
        <Badge variant={STATUS_VARIANT[status]}>
            <Icon className="igr:size-3" aria-hidden />
            {t(`flows.status.${status}`)}
        </Badge>
    );
}

export function FlowsPage() {
    const { t } = useTranslation();
    const [params, setParams] = useSearchParams();
    const tab = params.get('tab') === 'biblioteca' ? 'library' : 'mine';
    const canManage = useCan('manageMarketing');
    const [creating, setCreating] = useState(false);

    return (
        <div>
            <PageHeader
                title={t('flows.title')}
                description={t('flows.description')}
                actions={
                    canManage ? (
                        <div className="igr:flex igr:gap-2">
                            <CreateWithAiButton kind="flow" />
                            <Button onClick={() => setCreating(true)}>
                                <Plus />
                                {t('flows.new')}
                            </Button>
                        </div>
                    ) : null
                }
            />
            <Tabs value={tab} onValueChange={(v) => setParams(v === 'library' ? { tab: 'biblioteca' } : {}, { replace: true })}>
                <TabsList>
                    <TabsTrigger value="mine" className="igr:inline-flex igr:items-center igr:gap-1.5">
                        <Workflow className="igr:size-3.5" />
                        {t('flows.tabs.mine')}
                    </TabsTrigger>
                    <TabsTrigger value="library" className="igr:inline-flex igr:items-center igr:gap-1.5">
                        <Library className="igr:size-3.5" />
                        {t('flows.tabs.library')}
                    </TabsTrigger>
                </TabsList>
                <TabsContent value="mine" className="igr:mt-4">
                    <MyFlows onLibrary={() => setParams({ tab: 'biblioteca' }, { replace: true })} />
                </TabsContent>
                <TabsContent value="library" className="igr:mt-4">
                    <LibraryGallery />
                </TabsContent>
            </Tabs>
            <NewFlowDialog open={creating} onOpenChange={setCreating} />
        </div>
    );
}

function MyFlows({ onLibrary }: { onLibrary: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const flows = useFlows(workspace.id);
    const options = useFlowOptions(workspace.id);
    const navigate = useNavigate();

    if (flows.isPending) {
        return <Skeleton className="igr:h-60" />;
    }
    if (flows.isError) {
        return <ErrorState error={flows.error} onRetry={() => void flows.refetch()} />;
    }
    const list = flows.data.data;
    if (list.length === 0) {
        return (
            <EmptyState
                icon={Workflow}
                title={t('flows.empty.title')}
                description={t('flows.empty.description')}
                action={
                    <Button onClick={onLibrary}>
                        <Library />
                        {t('flows.empty.action')}
                    </Button>
                }
            />
        );
    }
    return (
        <Card>
            <Table>
                <THead>
                    <TR>
                        <TH>{t('flows.name')}</TH>
                        <TH className="igr:hidden igr:md:table-cell">{t('flows.list.trigger')}</TH>
                        <TH className="igr:whitespace-nowrap igr:text-right">{t('flows.list.entered')}</TH>
                        <TH className="igr:hidden igr:whitespace-nowrap igr:text-right igr:sm:table-cell">{t('flows.list.live')}</TH>
                        <TH className="igr:whitespace-nowrap igr:text-right">{t('flows.list.converted')}</TH>
                        <TH className="igr:hidden igr:whitespace-nowrap igr:text-right igr:lg:table-cell">{t('flows.list.updated')}</TH>
                    </TR>
                </THead>
                <TBody>
                    {list.map((f) => (
                        <TR key={f.id} className="igr:cursor-pointer igr:hover:bg-accent/50" onClick={() => void navigate(`/w/${workspace.id}/flujos/${f.id}`)}>
                            <TD>
                                <div className="igr:flex igr:items-center igr:gap-2">
                                    <Link to={`/w/${workspace.id}/flujos/${f.id}`} className="igr:font-medium igr:hover:underline" onClick={(e) => e.stopPropagation()}>
                                        {f.name}
                                    </Link>
                                    <StatusBadge status={f.status} />
                                </div>
                                {f.description ? <p className="igr:mt-0.5 igr:line-clamp-1 igr:text-xs igr:text-muted-foreground">{f.description}</p> : null}
                            </TD>
                            <TD className="igr:hidden igr:text-muted-foreground igr:md:table-cell">{triggerText(t, f.trigger, options.data)}</TD>
                            <TD className="igr:text-right igr:tabular-nums">{formatNumber(f.stats.entered, workspace.locale)}</TD>
                            <TD className="igr:hidden igr:text-right igr:tabular-nums igr:sm:table-cell">{formatNumber(f.stats.live, workspace.locale)}</TD>
                            <TD className="igr:text-right igr:tabular-nums">{formatNumber(f.stats.converted, workspace.locale)}</TD>
                            <TD className="igr:hidden igr:whitespace-nowrap igr:text-right igr:text-muted-foreground igr:lg:table-cell">{formatRelative(f.updated_at, workspace.locale)}</TD>
                        </TR>
                    ))}
                </TBody>
            </Table>
            {flows.data.archived_count > 0 ? <p className="igr:border-t igr:px-4 igr:py-2 igr:text-xs igr:text-muted-foreground">{t('flows.list.archived', { count: flows.data.archived_count })}</p> : null}
        </Card>
    );
}

function LibraryGallery() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const library = useFlowLibrary(workspace.id);
    const canManage = useCan('manageMarketing');
    const create = useCreateFromLibrary(workspace.id);
    const navigate = useNavigate();
    const [busy, setBusy] = useState<string | null>(null);

    if (library.isPending) {
        return <Skeleton className="igr:h-80" />;
    }
    if (library.isError) {
        return <ErrorState error={library.error} onRetry={() => void library.refetch()} />;
    }
    const run = (flow: LibraryFlow, activate: boolean) => {
        setBusy(`${flow.key}${String(activate)}`);
        create.mutate(
            { key: flow.key, activate },
            {
                onSuccess: (detail) => {
                    if (activate && detail.activated) {
                        toast.success(t('flows.library.activated', { name: detail.name }));
                    } else {
                        if (activate) {
                            // Sin el canal conectado, el motivo exacto («Conecta WhatsApp en Canales…»).
                            toast.info(t('flows.library.createdDraft', { name: detail.name }), detail.issues.channel?.[0]);
                        }
                        void navigate(`/w/${workspace.id}/flujos/${detail.id}`);
                    }
                },
                onError: (error) => toast.error(t('flows.builder.saveFailed'), isApiError(error) ? error.message : undefined),
                onSettled: () => setBusy(null),
            },
        );
    };
    const available = library.data.flows.filter((f) => f.available || f.module);
    const later = library.data.flows.filter((f) => !f.available && !f.module);

    return (
        <div className="igr:space-y-6">
            <p className="igr:text-sm igr:text-muted-foreground">{t('flows.library.description')}</p>
            {!library.data.whatsappConnected ? <p className="igr:rounded-md igr:border igr:bg-muted/40 igr:px-3 igr:py-2 igr:text-xs igr:text-muted-foreground">{t('flows.library.noWhatsapp')}</p> : null}
            <section>
                <h2 className="igr:mb-2 igr:text-[13px] igr:font-semibold">{t('flows.library.available')}</h2>
                <div className="igr:grid igr:gap-3 igr:md:grid-cols-2 igr:xl:grid-cols-3">
                    {available.map((f) => (
                        <Card key={f.key} className="igr:flex igr:flex-col">
                            <CardContent className="igr:flex igr:flex-1 igr:flex-col igr:gap-2 igr:pt-4">
                                <div className="igr:flex igr:items-start igr:justify-between igr:gap-2">
                                    <h3 className="igr:text-[13px] igr:font-semibold">
                                        <span className="igr:mr-1.5 igr:tabular-nums igr:text-muted-foreground">{f.number}.</span>
                                        {f.name}
                                    </h3>
                                    {f.module ? null : <Badge variant="outline">{t('flows.library.steps', { count: f.steps - 1 })}</Badge>}
                                </div>
                                <p className="igr:text-xs igr:text-muted-foreground">{f.description}</p>
                                {f.module ? <p className="igr:text-xs igr:text-muted-foreground">{t('flows.library.moduleHelp')}</p> : null}
                                {f.missing_templates.length > 0 ? (
                                    <p className="igr:flex igr:items-start igr:gap-1.5 igr:text-xs igr:text-warning" title={t('flows.library.missingHelp')}>
                                        <CircleAlert className="igr:mt-0.5 igr:size-3.5 igr:shrink-0" aria-hidden />
                                        <span>{t('flows.library.missing', { list: f.missing_templates.map((k) => t(`flows.templateKeys.${k}`, { defaultValue: k })).join(', ') })}</span>
                                    </p>
                                ) : null}
                                <div className="igr:mt-auto igr:flex igr:flex-wrap igr:gap-2 igr:pt-2">
                                    {f.module ? (
                                        <Button asChild size="sm" variant="outline">
                                            <Link to={`/w/${workspace.id}/${f.module}`}>{t('flows.library.configure')}</Link>
                                        </Button>
                                    ) : f.created_flow_id ? (
                                        <Button asChild size="sm" variant="outline">
                                            <Link to={`/w/${workspace.id}/flujos/${f.created_flow_id}`}>{t('flows.library.open')}</Link>
                                        </Button>
                                    ) : canManage ? (
                                        <>
                                            <Button size="sm" disabled={f.missing_templates.length > 0} loading={busy === `${f.key}true`} onClick={() => run(f, true)}>
                                                {t('flows.library.activate')}
                                            </Button>
                                            <Button size="sm" variant="outline" loading={busy === `${f.key}false`} onClick={() => run(f, false)}>
                                                {t('flows.library.customize')}
                                            </Button>
                                        </>
                                    ) : null}
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>
            </section>
            <section>
                <h2 className="igr:mb-2 igr:text-[13px] igr:font-semibold">{t('flows.library.later')}</h2>
                <div className="igr:grid igr:gap-3 igr:md:grid-cols-2 igr:xl:grid-cols-3">
                    {later.map((f) => (
                        <Card key={f.key} className="igr:opacity-75">
                            <CardContent className="igr:space-y-1.5 igr:pt-4">
                                <div className="igr:flex igr:items-start igr:justify-between igr:gap-2">
                                    <h3 className="igr:text-[13px] igr:font-semibold">
                                        <span className="igr:mr-1.5 igr:tabular-nums igr:text-muted-foreground">{f.number}.</span>
                                        {f.name}
                                    </h3>
                                    <Badge variant="secondary">{t('flows.library.phase', { phase: f.phase })}</Badge>
                                </div>
                                <p className="igr:text-xs igr:text-muted-foreground">{f.description}</p>
                            </CardContent>
                        </Card>
                    ))}
                </div>
            </section>
        </div>
    );
}

function NewFlowDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const create = useCreateFlow(workspace.id);
    const navigate = useNavigate();
    const [name, setName] = useState('');
    const [description, setDescription] = useState('');
    const [error, setError] = useState<string | undefined>();

    const submit = () => {
        setError(undefined);
        create.mutate(
            { name: name.trim(), description: description.trim() === '' ? null : description.trim() },
            {
                onSuccess: (flow) => {
                    toast.success(t('flows.created'));
                    onOpenChange(false);
                    void navigate(`/w/${workspace.id}/flujos/${flow.id}`);
                },
                onError: (e) => setError(isApiError(e, 'validation') ? (e.fieldErrors.name ?? e.message) : isApiError(e) ? e.message : t('flows.builder.saveFailed')),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('flows.newTitle')}</DialogTitle>
                    <DialogDescription>{t('flows.newHelp')}</DialogDescription>
                </DialogHeader>
                <form
                    className="igr:space-y-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        submit();
                    }}
                >
                    <div className="igr:space-y-1">
                        <Label htmlFor="flow-name">{t('flows.name')}</Label>
                        <Input id="flow-name" autoFocus required maxLength={160} value={name} placeholder={t('flows.namePlaceholder')} onChange={(e) => setName(e.target.value)} />
                        <FieldError message={error} />
                    </div>
                    <div className="igr:space-y-1">
                        <Label htmlFor="flow-description">{t('flows.descriptionLabel')}</Label>
                        <Textarea id="flow-description" maxLength={500} className="igr:min-h-16" value={description} onChange={(e) => setDescription(e.target.value)} />
                    </div>
                    <DialogFooter>
                        <Button type="submit" loading={create.isPending} disabled={name.trim() === ''}>
                            {t('flows.create')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
