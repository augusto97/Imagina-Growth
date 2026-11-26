import { Archive, CalendarClock, Copy, Monitor, MoreHorizontal, Pause, Play, Save, Smartphone, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate, useParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { Callout } from '@/components/ui/callout';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { Card, CardContent } from '@/components/ui/card';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input, Select } from '@/components/ui/input';
import { ErrorState, FieldError } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { SegmentedControl } from '@/components/ui/segmented';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { isoToWall, wallToIso } from '@/features/campaigns/time';
import { errorMessage } from '@/features/contacts/errors';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { toast } from '@/stores/toast';
import { useOnsiteOptions, useWidget, useWidgetActions } from './api';
import { ContentEditor } from './editors/ContentEditor';
import { Field, Section } from './editors/fields';
import { ConsentEditor, CouponEditor } from './editors/OfferEditors';
import { QuizEditor } from './editors/QuizEditor';
import { TargetingEditor } from './editors/TargetingEditor';
import { KindLabel, StatusBadge } from './labels';
import { draftFrom, isGift, payload } from './model';
import { useAudience } from './audience';
import { describeTargeting, rulesSentence } from './rules';
import { Preview, type PreviewDevice, type PreviewState } from './Preview';
import { StatsPanel } from './StatsPanel';
import type { WidgetDetail, WidgetDraft } from './types';
import { AutoBreadcrumbs } from '@/components/ui/page-header';

export function WidgetEditorPage() {
    const { widgetId } = useParams();
    const { workspace } = useWorkspace();
    const widget = useWidget(workspace.id, widgetId);

    if (widget.isError) return <ErrorState error={widget.error} onRetry={() => void widget.refetch()} />;
    if (widget.isPending) {
        return (
            <div className="igr:space-y-3" aria-busy>
                <Skeleton className="igr:h-8 igr:w-64" />
                <Skeleton className="igr:h-[480px]" />
            </div>
        );
    }
    return <Editor key={widget.data.id} widget={widget.data} />;
}

function Editor({ widget }: { widget: WidgetDetail }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const navigate = useNavigate();
    const canManage = useCan('manageMarketing') && widget.status !== 'archived';
    const options = useOnsiteOptions(workspace.id);
    const actions = useWidgetActions(workspace.id, widget.id);
    const initial = useMemo(() => draftFrom(widget), [widget]);
    const [draft, setDraft] = useState<WidgetDraft>(initial);
    // Lo guardado (o lo que cambió en el servidor) pasa a ser el nuevo punto de partida.
    const [from, setFrom] = useState(initial);
    if (from !== initial) {
        setFrom(initial);
        setDraft(initial);
    }
    const [tab, setTab] = useState(widget.status === 'draft' ? 'content' : 'stats');
    const [variant, setVariant] = useState(0);
    const [device, setDevice] = useState<PreviewDevice>('desktop');
    const [state, setState] = useState<PreviewState>(widget.kind === 'quiz' ? 'quiz' : 'form');
    const [confirmDelete, setConfirmDelete] = useState(false);
    const audience = useAudience();
    // Sin tienda no hay cupones (los crea WooCommerce): la pestaña solo se ve si el widget ya traía uno.
    const showCoupon = widget.kind !== 'wa_widget' && (audience.store || widget.kind === 'spin_wheel' || (isGift(draft.coupon_config) && draft.coupon_config.enabled));
    const rules = describeTargeting(widget.kind, draft.targeting, t, options.data);
    const dirty = JSON.stringify(payload(draft)) !== JSON.stringify(payload(initial));
    const error = actions.save.error ?? actions.activate.error;
    const base = `/w/${workspace.id}/en-el-sitio`;
    const current = draft.variants[variant] ?? draft.variants[0];
    const tz = workspace.timezone;

    useEffect(() => {
        if (!dirty) return;
        const warn = (e: BeforeUnloadEvent) => e.preventDefault();
        window.addEventListener('beforeunload', warn);
        return () => window.removeEventListener('beforeunload', warn);
    }, [dirty]);

    const save = (then?: () => void) =>
        actions.save.mutate(payload(draft), {
            onSuccess: () => {
                toast.success(t('onsite.editor.saved'));
                then?.();
            },
        });
    const activate = () =>
        dirty
            ? save(() => actions.activate.mutate(undefined, { onSuccess: () => toast.success(t('onsite.editor.activated')) }))
            : actions.activate.mutate(undefined, { onSuccess: () => toast.success(t('onsite.editor.activated')) });
    const states: PreviewState[] =
        widget.kind === 'quiz'
            ? ['quiz', 'form', 'success']
            : widget.kind === 'two_step'
              ? ['form', 'step2', 'success']
              : widget.kind === 'wa_widget'
                ? ['form']
                : ['form', 'success'];

    return (
        <>
            <div className="igr:mb-4 igr:flex igr:flex-wrap igr:items-start igr:justify-between igr:gap-3">
                <div className="igr:min-w-0">
                    <AutoBreadcrumbs className="igr:mb-1.5" />
                    <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-2">
                        {canManage ? (
                            <Input
                                value={draft.name}
                                aria-label={t('onsite.editor.name')}
                                maxLength={160}
                                className="igr:h-8 igr:w-72 igr:text-base igr:font-semibold"
                                onChange={(e) => setDraft({ ...draft, name: e.target.value })}
                            />
                        ) : (
                            <h1 className="igr:text-lg igr:font-semibold">{widget.name}</h1>
                        )}
                        <StatusBadge status={widget.status} live={widget.live} />
                        <KindLabel kind={widget.kind} />
                    </div>
                </div>
                {canManage ? (
                    <div className="igr:flex igr:items-center igr:gap-2">
                        <Button variant="outline" disabled={!dirty} loading={actions.save.isPending} onClick={() => save()}>
                            <Save />
                            {t('onsite.editor.save')}
                        </Button>
                        {widget.status === 'active' ? (
                            <Button variant="outline" loading={actions.pause.isPending} onClick={() => actions.pause.mutate()}>
                                <Pause />
                                {t('onsite.actions.pause')}
                            </Button>
                        ) : (
                            <Button loading={actions.activate.isPending} onClick={activate}>
                                <Play />
                                {dirty ? t('onsite.actions.saveActivate') : t('onsite.actions.activate')}
                            </Button>
                        )}
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button variant="ghost" size="icon" aria-label={t('onsite.actions.more')}>
                                    <MoreHorizontal />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem onSelect={() => actions.duplicate.mutate(undefined, { onSuccess: (copy) => void navigate(`${base}/${copy.id}`) })}>
                                    <Copy />
                                    {t('onsite.actions.duplicate')}
                                </DropdownMenuItem>
                                <DropdownMenuItem onSelect={() => actions.archive.mutate(undefined, { onSuccess: () => toast.info(t('onsite.editor.archived')) })}>
                                    <Archive />
                                    {t('onsite.actions.archive')}
                                </DropdownMenuItem>
                                <DropdownMenuItem className="igr:text-destructive" onSelect={() => setConfirmDelete(true)}>
                                    <Trash2 />
                                    {t('onsite.actions.delete')}
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                ) : null}
            </div>
            {error ? (
                <div className="igr:mb-3">
                    <FieldError message={errorMessage(error, t)} />
                </div>
            ) : null}
            <Callout
                tone="neutral"
                icon={CalendarClock}
                className="igr:mb-4"
                title={t('onsite.rules.title')}
                action={
                    tab !== 'targeting' ? (
                        <Button size="sm" variant="ghost" onClick={() => setTab('targeting')}>
                            {t('onsite.rules.change')}
                        </Button>
                    ) : undefined
                }
            >
                {rulesSentence(rules)}
            </Callout>
            <ConfirmDialog
                open={confirmDelete}
                onOpenChange={setConfirmDelete}
                title={t('onsite.actions.deleteTitle', { name: widget.name })}
                description={t('onsite.actions.confirmDelete')}
                confirmLabel={t('onsite.actions.delete')}
                tone="danger"
                loading={actions.remove.isPending}
                onConfirm={() =>
                    actions.remove.mutate(undefined, {
                        onSuccess: () => void navigate(base),
                        onError: (e) => {
                            setConfirmDelete(false);
                            toast.error(errorMessage(e, t));
                        },
                    })
                }
            />

            <div className="igr:grid igr:gap-4 igr:xl:grid-cols-[minmax(0,1fr)_420px]">
                <Tabs value={tab} onValueChange={setTab} className="igr:min-w-0">
                    <TabsList className="igr:mb-3">
                        <TabsTrigger value="content">{t('onsite.editor.tabs.content')}</TabsTrigger>
                        <TabsTrigger value="targeting">{t('onsite.editor.tabs.targeting')}</TabsTrigger>
                        {widget.kind !== 'wa_widget' ? <TabsTrigger value="consent">{t('onsite.editor.tabs.consent')}</TabsTrigger> : null}
                        {showCoupon ? <TabsTrigger value="coupon">{widget.kind === 'spin_wheel' ? t('onsite.editor.tabs.prizes') : t('onsite.editor.tabs.coupon')}</TabsTrigger> : null}
                        {widget.kind === 'quiz' ? <TabsTrigger value="quiz">{t('onsite.editor.tabs.quiz')}</TabsTrigger> : null}
                        <TabsTrigger value="schedule">{t('onsite.editor.tabs.schedule')}</TabsTrigger>
                        <TabsTrigger value="stats">{t('onsite.editor.tabs.stats')}</TabsTrigger>
                    </TabsList>
                    <Card>
                        <CardContent className="igr:pt-5">
                            <fieldset disabled={!canManage} className="igr:min-w-0">
                                <TabsContent value="content" className="igr:pt-0">
                                    <ContentEditor kind={widget.kind} draft={draft} variant={variant} onVariant={setVariant} onChange={setDraft} />
                                </TabsContent>
                                <TabsContent value="targeting" className="igr:pt-0">
                                    <TargetingEditor
                                        kind={widget.kind}
                                        store={audience.store}
                                        summary={rules}
                                        targeting={draft.targeting}
                                        options={options.data}
                                        onChange={(targeting) => setDraft({ ...draft, targeting })}
                                    />
                                </TabsContent>
                                <TabsContent value="consent" className="igr:pt-0">
                                    <ConsentEditor draft={draft} options={options.data} onChange={(consent_config) => setDraft({ ...draft, consent_config })} />
                                </TabsContent>
                                <TabsContent value="coupon" className="igr:pt-0">
                                    <CouponEditor kind={widget.kind} coupon={draft.coupon_config} onChange={(coupon_config) => setDraft({ ...draft, coupon_config })} />
                                </TabsContent>
                                {draft.quiz ? (
                                    <TabsContent value="quiz" className="igr:pt-0">
                                        <QuizEditor quiz={draft.quiz} options={options.data} onChange={(quiz) => setDraft({ ...draft, quiz })} />
                                    </TabsContent>
                                ) : null}
                                <TabsContent value="schedule" className="igr:pt-0">
                                    <Section title={t('onsite.schedule.title')} description={t('onsite.schedule.help')}>
                                        <div className="igr:grid igr:gap-3 igr:sm:grid-cols-2">
                                            <Field label={t('onsite.schedule.starts')}>
                                                {(id) => (
                                                    <Input
                                                        id={id}
                                                        type="datetime-local"
                                                        value={isoToWall(draft.starts_at, tz)}
                                                        onChange={(e) => setDraft({ ...draft, starts_at: e.target.value ? wallToIso(e.target.value, tz) : null })}
                                                    />
                                                )}
                                            </Field>
                                            <Field label={t('onsite.schedule.ends')}>
                                                {(id) => (
                                                    <Input
                                                        id={id}
                                                        type="datetime-local"
                                                        value={isoToWall(draft.ends_at, tz)}
                                                        onChange={(e) => setDraft({ ...draft, ends_at: e.target.value ? wallToIso(e.target.value, tz) : null })}
                                                    />
                                                )}
                                            </Field>
                                            <Field label={t('onsite.schedule.site')}>
                                                {(id) => (
                                                    <Select id={id} value={draft.site_id ?? ''} onChange={(e) => setDraft({ ...draft, site_id: e.target.value || null })}>
                                                        <option value="">{t('onsite.create.allSites')}</option>
                                                        {(options.data?.sites ?? []).map((s) => (
                                                            <option key={s.id} value={s.id}>
                                                                {s.name}
                                                            </option>
                                                        ))}
                                                    </Select>
                                                )}
                                            </Field>
                                            <Field label={t('onsite.schedule.priority')} help={t('onsite.schedule.priorityHelp')}>
                                                {(id) => (
                                                    <Input
                                                        id={id}
                                                        type="number"
                                                        min={0}
                                                        max={1000}
                                                        value={draft.priority}
                                                        onChange={(e) => setDraft({ ...draft, priority: Number(e.target.value) })}
                                                    />
                                                )}
                                            </Field>
                                        </div>
                                        <p className="igr:text-xs igr:text-muted-foreground">{t('onsite.schedule.timezone', { tz })}</p>
                                    </Section>
                                </TabsContent>
                            </fieldset>
                            <TabsContent value="stats" className="igr:pt-0">
                                <StatsPanel widget={widget} />
                            </TabsContent>
                        </CardContent>
                    </Card>
                </Tabs>

                {current ? (
                    <aside className="igr:space-y-2 igr:xl:sticky igr:xl:top-4 igr:xl:self-start" aria-label={t('onsite.preview.title')}>
                        <div className="igr:flex igr:flex-wrap igr:items-center igr:justify-between igr:gap-2">
                            <p className="igr:text-[13px] igr:font-medium">{t('onsite.preview.title')}</p>
                            <div className="igr:flex igr:items-center igr:gap-1">
                                {draft.variants.length > 1 ? (
                                    <Select
                                        aria-label={t('onsite.preview.variant')}
                                        value={variant}
                                        onChange={(e) => setVariant(Number(e.target.value))}
                                        className="igr:h-8 igr:w-auto"
                                    >
                                        {draft.variants.map((v, i) => (
                                            <option key={v.key} value={i}>
                                                {t('onsite.editor.variants.name', { key: v.key.toUpperCase() })}
                                            </option>
                                        ))}
                                    </Select>
                                ) : null}
                                {states.length > 1 ? (
                                    <Select
                                        aria-label={t('onsite.preview.state')}
                                        value={state}
                                        onChange={(e) => setState(e.target.value as PreviewState)}
                                        className="igr:h-8 igr:w-auto"
                                    >
                                        {states.map((s) => (
                                            <option key={s} value={s}>
                                                {t(`onsite.preview.states.${s}`)}
                                            </option>
                                        ))}
                                    </Select>
                                ) : null}
                                <SegmentedControl
                                    iconOnly
                                    label={t('onsite.preview.device')}
                                    value={device}
                                    onChange={setDevice}
                                    options={[
                                        { value: 'desktop', label: t('onsite.devices.desktop'), icon: Monitor },
                                        { value: 'mobile', label: t('onsite.devices.mobile'), icon: Smartphone },
                                    ]}
                                />
                            </div>
                        </div>
                        <Preview
                            kind={widget.kind}
                            design={current.design}
                            content={current.content}
                            channels={draft.consent_config.channels}
                            coupon={draft.coupon_config}
                            quiz={draft.quiz}
                            options={options.data}
                            device={device}
                            state={states.includes(state) ? state : 'form'}
                        />
                        <p className="igr:text-xs igr:text-muted-foreground">{audience.store ? t('onsite.preview.note') : t('onsite.preview.noteServices')}</p>
                    </aside>
                ) : null}
            </div>
        </>
    );
}
