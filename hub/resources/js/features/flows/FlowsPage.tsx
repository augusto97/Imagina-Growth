import { ChevronRight, CirclePause, CirclePlay, FileEdit, FlaskConical, Library, Plus, Rocket, Workflow } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useSearchParams } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { HelpTip } from '@/components/ui/help-tip';
import { IconTile, type IconTone } from '@/components/ui/icon-tile';
import { Input, Textarea } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { EmptyState, ErrorState, FieldError, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { StatCard, StatCardSkeleton, StatGrid } from '@/components/ui/stat-card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { cn } from '@/lib/cn';
import { formatMoney, formatNumber } from '@/lib/format';
import { toast } from '@/stores/toast';
import { CreateWithAiButton } from '@/features/copilot/CopilotDialog';
import { useCreateFlow, useFlowOptions, useFlows } from './api';
import { businessKind } from './business';
import { triggerText } from './catalog';
import { LibraryGallery } from './LibraryGallery';
import type { FlowListMeta, FlowStatus, FlowSummary } from './types';

const STATUS_ICON = { active: CirclePlay, paused: CirclePause, draft: FileEdit, archived: FileEdit } as const;
const STATUS_VARIANT = { active: 'success', paused: 'warning', draft: 'secondary', archived: 'outline' } as const;
const STATUS_TONE: Record<FlowStatus, IconTone> = { active: 'emerald', paused: 'amber', draft: 'slate', archived: 'slate' };

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

/** El evento de la meta → la palabra de lo que se logró («compraron», «agendaron»…). */
const GOAL_WORDS = ['order_paid', 'appointment_booked', 'appointment_confirmed', 'lead_stage_changed', 'lead_won', 'points_redeemed', 'referral_shared'];

export function goalWord(event: string | null | undefined): string {
    const key = (event ?? '').replace(/\./g, '_');
    return GOAL_WORDS.includes(key) ? key : 'default';
}

/**
 * Mensajes → Automatizaciones (modulos.md M09): lo que hace cada una en una frase, su estado, lo que logró este mes (en
 * pesos en una tienda, metas en un negocio de servicios) y su grupo de control. «Nueva automatización» abre la
 * biblioteca (`?tab=biblioteca`) como galería por objetivo y tipo de negocio, con «desde cero».
 */
export function FlowsPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const [params, setParams] = useSearchParams();
    const tab = params.get('tab') === 'biblioteca' ? 'library' : 'mine';
    const canManage = useCan('manageMarketing');
    const [creating, setCreating] = useState(false);
    const kind = businessKind(workspace);
    const openLibrary = () => setParams({ tab: 'biblioteca' }, { replace: true });

    return (
        <div>
            <PageHeader
                title={t('flows.title')}
                description={t('flows.description', { context: kind === 'services' ? 'services' : undefined })}
                actions={
                    canManage ? (
                        <>
                            <CreateWithAiButton kind="flow" />
                            <Button onClick={openLibrary}>
                                <Plus />
                                {t('flows.new')}
                            </Button>
                        </>
                    ) : null
                }
            />
            <Tabs value={tab} onValueChange={(v) => setParams(v === 'library' ? { tab: 'biblioteca' } : {}, { replace: true })}>
                <TabsList>
                    <TabsTrigger value="mine" className="igr:inline-flex igr:items-center igr:gap-1.5">
                        <Workflow className="igr:size-3.5" aria-hidden />
                        {t('flows.tabs.mine')}
                    </TabsTrigger>
                    <TabsTrigger value="library" className="igr:inline-flex igr:items-center igr:gap-1.5">
                        <Library className="igr:size-3.5" aria-hidden />
                        {t('flows.tabs.library')}
                    </TabsTrigger>
                </TabsList>
                <TabsContent value="mine" className="igr:mt-5">
                    <MyFlows onLibrary={openLibrary} />
                </TabsContent>
                <TabsContent value="library" className="igr:mt-5">
                    <LibraryGallery onFromScratch={canManage ? () => setCreating(true) : undefined} />
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
    const kind = businessKind(workspace);
    const store = kind !== 'services';

    if (flows.isError) {
        return <ErrorState error={flows.error} onRetry={() => void flows.refetch()} />;
    }
    if (flows.isPending) {
        return (
            <div className="igr:space-y-5" aria-busy="true">
                <StatGrid className={store ? undefined : 'igr:lg:grid-cols-3'}>
                    {Array.from({ length: store ? 4 : 3 }, (_, i) => (
                        <StatCardSkeleton key={i} />
                    ))}
                </StatGrid>
                <Skeleton className="igr:h-64 igr:rounded-xl" />
            </div>
        );
    }
    const list = flows.data.data;
    if (list.length === 0) {
        return (
            <EmptyState
                illustration="messages"
                title={t('flows.empty.title', { context: kind === 'services' ? 'services' : undefined })}
                description={t('flows.empty.description', { context: kind === 'services' ? 'services' : undefined })}
                action={
                    <Button onClick={onLibrary}>
                        <Library />
                        {t('flows.empty.action')}
                    </Button>
                }
                steps={[t('flows.empty.step1'), t('flows.empty.step2'), t('flows.empty.step3')]}
            />
        );
    }
    const meta = flows.data.meta;
    const sum = (pick: (f: FlowSummary) => number) => list.reduce((acc, f) => acc + pick(f), 0);
    const revenue = sum((f) => Number(f.month?.revenue ?? 0));
    const active = list.filter((f) => f.status === 'active').length;

    return (
        <div className="igr:space-y-5">
            <StatGrid className={store ? undefined : 'igr:lg:grid-cols-3'}>
                <StatCard
                    icon={Rocket}
                    tone="emerald"
                    label={t('flows.list.summary.active')}
                    value={formatNumber(active, workspace.locale)}
                    hint={t('flows.list.summary.activeHint', { count: list.length })}
                />
                {store ? (
                    <StatCard
                        label={t('flows.list.summary.revenue')}
                        value={formatMoney(revenue, meta?.currency ?? workspace.currency, workspace.locale)}
                        help={t('flows.list.summary.revenueHelp')}
                        hint={t('flows.list.summary.revenueHint', { count: sum((f) => f.month?.orders ?? 0) })}
                    />
                ) : null}
                <StatCard
                    label={t('flows.list.summary.conversions')}
                    value={formatNumber(sum((f) => f.month?.conversions ?? 0), workspace.locale)}
                    help={t('flows.list.summary.conversionsHelp')}
                    hint={t('flows.list.summary.thisMonth')}
                />
                <StatCard
                    label={t('flows.list.summary.messages')}
                    value={formatNumber(sum((f) => f.month?.messages ?? 0), workspace.locale)}
                    help={t('flows.list.summary.messagesHelp')}
                    hint={t('flows.list.summary.thisMonth')}
                />
            </StatGrid>
            <Card className="igr:overflow-hidden">
                <div className="igr:hidden igr:grid-cols-[minmax(0,1fr)_200px_210px_20px] igr:gap-4 igr:border-b igr:bg-muted/40 igr:px-4 igr:py-2 igr:text-caption igr:font-medium igr:text-muted-foreground igr:lg:grid">
                    <span>{t('flows.list.columns.automation')}</span>
                    <span>{t('flows.list.columns.month')}</span>
                    <span className="igr:inline-flex igr:items-center igr:gap-1">
                        {t('flows.list.columns.control')}
                        <HelpTip content={t('flows.list.control.help', { days: meta?.control_days ?? 90 })} label={t('flows.list.columns.control')} />
                    </span>
                    <span />
                </div>
                <ul className="igr:divide-y">
                    {list.map((f) => (
                        <FlowRow key={f.id} flow={f} meta={meta} store={store} />
                    ))}
                </ul>
                {flows.data.archived_count > 0 ? <p className="igr:border-t igr:px-4 igr:py-2 igr:text-caption igr:text-muted-foreground">{t('flows.list.archived', { count: flows.data.archived_count })}</p> : null}
            </Card>
        </div>
    );
}

function FlowRow({ flow: f, meta, store }: { flow: FlowSummary; meta: FlowListMeta | undefined; store: boolean }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const options = useFlowOptions(workspace.id);
    const navigate = useNavigate();
    const to = `/w/${workspace.id}/flujos/${f.id}`;
    const month = f.month ?? null;
    const sentence = f.description ?? triggerText(t, f.trigger, options.data);
    const revenue = Number(month?.revenue ?? 0);
    const word = goalWord(f.goal?.event);

    return (
        <li
            className="igr:grid igr:cursor-pointer igr:gap-3 igr:px-4 igr:py-3.5 igr:transition-colors igr:hover:bg-accent/40 igr:lg:grid-cols-[minmax(0,1fr)_200px_210px_20px] igr:lg:items-center igr:lg:gap-4"
            onClick={() => void navigate(to)}
        >
            <div className="igr:flex igr:min-w-0 igr:gap-3">
                <IconTile icon={Workflow} tone={STATUS_TONE[f.status]} size="md" className="igr:mt-0.5" />
                <div className="igr:min-w-0">
                    <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-x-2 igr:gap-y-1">
                        <Link to={to} className="igr:font-medium igr:text-foreground igr:hover:underline" onClick={(e) => e.stopPropagation()}>
                            {f.name}
                        </Link>
                        <StatusBadge status={f.status} />
                    </div>
                    <p className="igr:mt-0.5 igr:line-clamp-2 igr:text-support igr:text-muted-foreground">{sentence}</p>
                    {f.strategy ? (
                        <Link
                            to={`/w/${workspace.id}/estrategias?estrategia=${encodeURIComponent(f.strategy)}`}
                            className="igr:mt-1 igr:inline-flex igr:items-center igr:gap-1 igr:text-caption igr:font-medium igr:text-primary igr:hover:underline"
                            onClick={(e) => e.stopPropagation()}
                        >
                            <Rocket className="igr:size-3" aria-hidden />
                            {t('flows.list.strategy')}
                        </Link>
                    ) : null}
                </div>
            </div>
            <div className="igr:pl-12 igr:lg:pl-0">
                {store && revenue > 0 ? (
                    <p className="igr:font-semibold igr:text-foreground igr:tabular-nums">{t('flows.list.revenue', { amount: formatMoney(revenue, meta?.currency ?? workspace.currency, workspace.locale) })}</p>
                ) : null}
                <p className={cn('igr:text-support igr:tabular-nums', store && revenue > 0 ? 'igr:text-muted-foreground' : 'igr:text-foreground')}>
                    {month && month.conversions > 0
                        ? t(`flows.list.goalDone.${word}`, { count: month.conversions })
                        : store && revenue > 0
                          ? t('flows.list.summary.revenueHint', { count: month?.orders ?? 0 })
                          : month && month.messages > 0
                          ? t('flows.list.sent', { count: month.messages })
                          : f.status === 'active'
                            ? t('flows.list.waiting')
                            : f.status === 'draft'
                              ? t('flows.list.notStarted')
                              : f.status === 'paused'
                                ? t('flows.list.pausedNow')
                                : t('flows.list.nothingYet')}
                </p>
            </div>
            <div className="igr:pl-12 igr:text-support igr:lg:pl-0">
                <ControlText flow={f} meta={meta} />
            </div>
            <ChevronRight className="igr:hidden igr:size-4 igr:text-muted-foreground igr:lg:block" aria-hidden />
        </li>
    );
}

/** El grupo de control en palabras: sin él, midiendo o la diferencia frente a quienes no recibieron mensajes. */
function ControlText({ flow: f, meta }: { flow: FlowSummary; meta: FlowListMeta | undefined }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const c = f.month?.control;
    if (f.holdout_pct === 0) {
        return <span className="igr:text-muted-foreground">{t('flows.list.control.none')}</span>;
    }
    if (!c || !c.enough || c.difference === null) {
        return (
            <span className="igr:inline-flex igr:items-center igr:gap-1.5 igr:text-muted-foreground">
                <FlaskConical className="igr:size-3.5 igr:shrink-0" aria-hidden />
                {t('flows.list.control.measuring', { pct: f.holdout_pct, n: formatNumber(c?.holdout_n ?? 0, workspace.locale), min: meta?.min_group ?? 30 })}
            </span>
        );
    }
    const points = (c.difference * 100).toLocaleString(workspace.locale, { maximumFractionDigits: 1, signDisplay: 'always' });
    return (
        <span className={cn('igr:inline-flex igr:items-center igr:gap-1.5', c.difference > 0 ? 'igr:text-success' : 'igr:text-muted-foreground')}>
            <FlaskConical className="igr:size-3.5 igr:shrink-0" aria-hidden />
            {t('flows.list.control.result', { points, pct: f.holdout_pct })}
        </span>
    );
}

export function NewFlowDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
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
                        <Textarea id="flow-description" maxLength={500} className="igr:min-h-16" value={description} placeholder={t('flows.descriptionPlaceholder')} onChange={(e) => setDescription(e.target.value)} />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                            {t('common.cancel')}
                        </Button>
                        <Button type="submit" loading={create.isPending} disabled={name.trim() === ''}>
                            {t('flows.create')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
