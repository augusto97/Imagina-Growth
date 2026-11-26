import { ArrowUpRight, Copy, Eye, Gift, Plus, Send, ShoppingBag, UserPlus } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, Navigate, useNavigate, useSearchParams } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input, Select } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ErrorState, FieldError } from '@/components/ui/misc';
import { PageHeader } from '@/components/ui/page-header';
import { SegmentedControl } from '@/components/ui/segmented';
import { Skeleton } from '@/components/ui/skeleton';
import { StatCard, StatCardSkeleton, StatGrid } from '@/components/ui/stat-card';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { errorMessage } from '@/features/contacts/errors';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { cn } from '@/lib/cn';
import { formatMoney, formatNumber, formatPercent } from '@/lib/format';
import { toast } from '@/stores/toast';
import { useCreateWidget, useOnsiteOptions, useWidgetActions, useWidgets } from './api';
import { useAudience } from './audience';
import { KIND_ICON, KindLabel, StatusBadge } from './labels';
import { templatesFor, type TemplateObjective } from './templates';
import type { WidgetKind, WidgetSummary, WidgetTemplate } from './types';

const KINDS: WidgetKind[] = ['popup', 'embedded_form', 'bar', 'spin_wheel', 'teaser', 'two_step', 'quiz', 'wa_widget', 'referral_welcome'];

/**
 * Popups y formularios (modulos.md M12): lo que consiguió cada widget en 30 días, la galería de plantillas por objetivo
 * (se crean con un clic) y la lista. Una tienda ve primero los de cupón; un negocio sin tienda, el formulario de
 * contacto y el botón de WhatsApp, sin cupones ni carrito.
 */
export function OnsitePage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const [params] = useSearchParams();
    const canManage = useCan('manageMarketing');
    const audience = useAudience();
    const [gallery, setGallery] = useState(false);
    const [showArchived, setShowArchived] = useState(false);
    const widgets = useWidgets(workspace.id);
    // Las estrategias enlazan aquí con ?widget=wg_…: se abre ese widget.
    const linked = params.get('widget');
    if (linked) {
        return <Navigate to={`/w/${workspace.id}/en-el-sitio/${encodeURIComponent(linked)}`} replace />;
    }
    const rows = widgets.data ?? [];
    const servicesOnly = audience.services && !audience.store;

    return (
        <>
            <PageHeader
                title={t('onsite.title')}
                description={servicesOnly ? t('onsite.descriptionServices') : t('onsite.description')}
                actions={
                    canManage && rows.length > 0 ? (
                        <Button onClick={() => setGallery(true)}>
                            <Plus aria-hidden />
                            {t('onsite.new')}
                        </Button>
                    ) : null
                }
            />
            {widgets.isError ? (
                <ErrorState error={widgets.error} onRetry={() => void widgets.refetch()} />
            ) : widgets.isPending ? (
                <>
                    <StatGrid className="igr:mb-6">
                        {[0, 1, 2, 3].map((i) => (
                            <StatCardSkeleton key={i} />
                        ))}
                    </StatGrid>
                    <Skeleton className="igr:h-64 igr:rounded-xl" />
                </>
            ) : rows.length === 0 ? (
                <Card>
                    <CardHeader>
                        <CardTitle className="igr:text-section">{t('onsite.gallery.emptyTitle')}</CardTitle>
                        <CardDescription>{servicesOnly ? t('onsite.gallery.emptyServices') : t('onsite.gallery.emptyStore')}</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Gallery canManage={canManage} />
                    </CardContent>
                </Card>
            ) : (
                <>
                    <Totals widgets={rows} store={audience.store} />
                    <WidgetList archived={false} />
                </>
            )}
            {rows.length > 0 ? (
                <div className="igr:mt-4">
                    <button
                        type="button"
                        className="igr:rounded-sm igr:text-caption igr:font-medium igr:text-muted-foreground igr:underline-offset-2 igr:hover:text-foreground igr:hover:underline"
                        onClick={() => setShowArchived((v) => !v)}
                        aria-expanded={showArchived}
                    >
                        {showArchived ? t('onsite.list.hideArchived') : t('onsite.list.showArchived')}
                    </button>
                    {showArchived ? (
                        <div className="igr:mt-3">
                            <WidgetList archived />
                        </div>
                    ) : null}
                </div>
            ) : null}
            {gallery ? (
                <Dialog open onOpenChange={(open) => !open && setGallery(false)}>
                    <DialogContent className="igr:top-[6vh] igr:max-h-[88dvh] igr:max-w-4xl igr:overflow-y-auto">
                        <DialogHeader>
                            <DialogTitle>{t('onsite.gallery.title')}</DialogTitle>
                            <DialogDescription>{t('onsite.gallery.description')}</DialogDescription>
                        </DialogHeader>
                        <Gallery canManage={canManage} />
                    </DialogContent>
                </Dialog>
            ) : null}
        </>
    );
}

function Totals({ widgets, store }: { widgets: WidgetSummary[]; store: boolean }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const sum = widgets.reduce(
        (acc, w) => ({
            views: acc.views + (w.stats?.views ?? 0),
            submissions: acc.submissions + (w.stats?.submissions ?? 0),
            fresh: acc.fresh + (w.stats?.new_contacts ?? 0),
            revenue: acc.revenue + Number(w.stats?.revenue ?? 0),
        }),
        { views: 0, submissions: 0, fresh: 0, revenue: 0 },
    );
    const active = widgets.filter((w) => w.status === 'active').length;
    const n = (v: number) => formatNumber(v, workspace.locale);
    return (
        <StatGrid className="igr:mb-6">
            <StatCard label={t('onsite.totals.views')} value={n(sum.views)} hint={t('onsite.totals.active', { count: active })} icon={Eye} tone="sky" />
            <StatCard
                label={t('onsite.totals.captures')}
                value={n(sum.submissions)}
                hint={t('onsite.totals.window')}
                help={t('onsite.totals.capturesHelp')}
                icon={Send}
                tone="emerald"
            />
            <StatCard
                label={t('onsite.totals.rate')}
                value={sum.views > 0 ? formatPercent(sum.submissions / sum.views, workspace.locale, 1) : '—'}
                hint={t('onsite.totals.window')}
                help={t('onsite.totals.rateHelp')}
                icon={ArrowUpRight}
                tone="violet"
            />
            {store ? (
                <StatCard
                    label={t('onsite.totals.revenue')}
                    value={formatMoney(sum.revenue, workspace.currency, workspace.locale)}
                    hint={t('onsite.totals.window')}
                    help={t('onsite.totals.revenueHelp')}
                    icon={ShoppingBag}
                    tone="amber"
                />
            ) : (
                <StatCard
                    label={t('onsite.totals.newContacts')}
                    value={n(sum.fresh)}
                    hint={t('onsite.totals.window')}
                    help={t('onsite.totals.newContactsHelp')}
                    icon={UserPlus}
                    tone="amber"
                />
            )}
        </StatGrid>
    );
}

const OBJECTIVES: ('all' | TemplateObjective)[] = ['all', 'capture', 'exit', 'quiz', 'survey', 'contact', 'announce'];

/** Galería de plantillas por objetivo: una tarjeta por plantilla con lo que logra y «Usar», que la crea en borrador. */
function Gallery({ canManage }: { canManage: boolean }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const navigate = useNavigate();
    const audience = useAudience();
    const options = useOnsiteOptions(workspace.id);
    const create = useCreateWidget(workspace.id);
    const [objective, setObjective] = useState<'all' | TemplateObjective>('all');
    const [blank, setBlank] = useState(false);
    if (options.isError) return <ErrorState error={options.error} onRetry={() => void options.refetch()} />;
    if (options.isPending) {
        return (
            <div className="igr:grid igr:gap-3 igr:sm:grid-cols-2 igr:lg:grid-cols-3">
                {[0, 1, 2].map((i) => (
                    <Skeleton key={i} className="igr:h-44 igr:rounded-xl" />
                ))}
            </div>
        );
    }
    const templates = templatesFor(options.data.templates, audience);
    const present = new Set(templates.map((tpl) => tpl.objective));
    const filters = OBJECTIVES.filter((o) => o === 'all' || present.has(o));
    const shown = templates.filter((tpl) => objective === 'all' || tpl.objective === objective);
    const use = (tpl: WidgetTemplate) =>
        create.mutate(
            { name: t(`onsite.templates.${tpl.key}.name`), template: tpl.key, site_id: null },
            {
                onSuccess: (w) => {
                    toast.success(t('onsite.gallery.created'), t('onsite.gallery.createdBody'));
                    void navigate(`/w/${workspace.id}/en-el-sitio/${w.id}`);
                },
                onError: (e) => toast.error(errorMessage(e, t)),
            },
        );
    return (
        <div className="igr:space-y-4">
            {filters.length > 2 ? (
                <SegmentedControl
                    size="sm"
                    label={t('onsite.gallery.objective')}
                    value={objective}
                    onChange={setObjective}
                    options={filters.map((o) => ({ value: o, label: t(`onsite.gallery.objectives.${o}`) }))}
                />
            ) : null}
            <ul className="igr:grid igr:gap-3 igr:sm:grid-cols-2 igr:lg:grid-cols-3">
                {shown.map((tpl) => {
                    const Icon = KIND_ICON[tpl.kind];
                    const busy = create.isPending && create.variables.template === tpl.key;
                    return (
                        <li
                            key={tpl.key}
                            className="igr:flex igr:min-w-0 igr:flex-col igr:rounded-xl igr:border igr:bg-card igr:p-4 igr:transition-[border-color,box-shadow] igr:hover:border-primary/25 igr:hover:shadow-sm"
                        >
                            <div className="igr:flex igr:items-start igr:gap-3">
                                <span className="igr:flex igr:size-9 igr:shrink-0 igr:items-center igr:justify-center igr:rounded-lg igr:bg-brand-soft igr:text-brand-soft-foreground">
                                    <Icon className="igr:size-4.5" aria-hidden />
                                </span>
                                <div className="igr:min-w-0 igr:flex-1">
                                    <h3 className="igr:text-[14px] igr:font-semibold igr:leading-snug">{t(`onsite.templates.${tpl.key}.name`)}</h3>
                                    <p className="igr:mt-0.5 igr:text-caption igr:text-muted-foreground">{t(`onsite.gallery.objectives.${tpl.objective}`)}</p>
                                </div>
                                {tpl.gives_coupon ? (
                                    <Badge variant="brand" className="igr:shrink-0">
                                        <Gift className="igr:size-3" aria-hidden />
                                        {t('onsite.list.coupon')}
                                    </Badge>
                                ) : null}
                            </div>
                            <p className="igr:mt-3 igr:flex-1 igr:text-support igr:text-muted-foreground">{t(`onsite.templates.${tpl.key}.description`)}</p>
                            <p className="igr:mt-2 igr:text-caption igr:font-medium igr:text-foreground">{t(`onsite.templates.${tpl.key}.result`)}</p>
                            {canManage ? (
                                <Button
                                    className="igr:mt-4 igr:self-start"
                                    size="sm"
                                    variant={tpl === shown[0] ? 'default' : 'outline'}
                                    loading={busy}
                                    disabled={create.isPending && !busy}
                                    onClick={() => use(tpl)}
                                >
                                    {t('onsite.gallery.use')}
                                </Button>
                            ) : null}
                        </li>
                    );
                })}
            </ul>
            {canManage ? (
                <div className="igr:border-t igr:pt-4">
                    {blank ? (
                        <BlankForm />
                    ) : (
                        <button
                            type="button"
                            onClick={() => setBlank(true)}
                            className="igr:rounded-sm igr:text-support igr:font-medium igr:text-primary igr:underline-offset-2 igr:hover:underline"
                        >
                            {t('onsite.gallery.blank')}
                        </button>
                    )}
                </div>
            ) : null}
        </div>
    );
}

/** Empezar en blanco: el tipo de widget con sus valores por defecto (lo de antes de la galería). */
function BlankForm() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const navigate = useNavigate();
    const options = useOnsiteOptions(workspace.id);
    const create = useCreateWidget(workspace.id);
    const audience = useAudience();
    const kinds = KINDS.filter((k) => audience.store || !['spin_wheel', 'referral_welcome', 'bar', 'teaser', 'quiz'].includes(k));
    const [kind, setKind] = useState<WidgetKind>(kinds[0] ?? 'popup');
    const [name, setName] = useState('');
    const [site, setSite] = useState('');
    const sites = options.data?.sites ?? [];
    return (
        <form
            className="igr:grid igr:gap-3 igr:sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] igr:sm:items-end"
            onSubmit={(e) => {
                e.preventDefault();
                create.mutate(
                    { name: name.trim() || t(`onsite.kinds.${kind}.name`), kind, site_id: site || null },
                    { onSuccess: (w) => void navigate(`/w/${workspace.id}/en-el-sitio/${w.id}`) },
                );
            }}
        >
            <div className="igr:space-y-1.5">
                <Label htmlFor="wg-kind">{t('onsite.create.kind')}</Label>
                <Select id="wg-kind" value={kind} onChange={(e) => setKind(e.target.value as WidgetKind)}>
                    {kinds.map((k) => (
                        <option key={k} value={k}>
                            {t(`onsite.kinds.${k}.name`)}
                        </option>
                    ))}
                </Select>
            </div>
            <div className="igr:space-y-1.5">
                <Label htmlFor="wg-name">{t('onsite.create.name')}</Label>
                <Input id="wg-name" value={name} onChange={(e) => setName(e.target.value)} placeholder={t(`onsite.kinds.${kind}.name`)} maxLength={160} />
            </div>
            <Button type="submit" variant="outline" loading={create.isPending}>
                {t('onsite.create.submit')}
            </Button>
            {sites.length > 1 ? (
                <div className="igr:space-y-1.5 igr:sm:col-span-2">
                    <Label htmlFor="wg-site">{t('onsite.create.site')}</Label>
                    <Select id="wg-site" value={site} onChange={(e) => setSite(e.target.value)}>
                        <option value="">{t('onsite.create.allSites')}</option>
                        {sites.map((s) => (
                            <option key={s.id} value={s.id}>
                                {s.name}
                            </option>
                        ))}
                    </Select>
                </div>
            ) : null}
            {create.isError ? <FieldError message={errorMessage(create.error, t)} /> : null}
        </form>
    );
}

function WidgetList({ archived }: { archived: boolean }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const audience = useAudience();
    const widgets = useWidgets(workspace.id, archived ? 'archived' : '');

    if (widgets.isError) return <ErrorState error={widgets.error} onRetry={() => void widgets.refetch()} />;
    if (widgets.isPending) return <Skeleton className="igr:h-40 igr:rounded-xl" />;
    if (widgets.data.length === 0) {
        return <p className="igr:text-support igr:text-muted-foreground">{t('onsite.empty.archived')}</p>;
    }
    return (
        <Card className="igr:overflow-hidden">
            <Table>
                <THead>
                    <TR>
                        <TH>{t('onsite.list.name')}</TH>
                        <TH>{t('onsite.list.status')}</TH>
                        <TH className="igr:text-right">{t('onsite.list.views')}</TH>
                        <TH className="igr:text-right">{t('onsite.list.submissions')}</TH>
                        <TH className="igr:text-right">{t('onsite.list.rate')}</TH>
                        {audience.store ? <TH className="igr:text-right">{t('onsite.list.revenue')}</TH> : null}
                        <TH className="igr:w-10">
                            <span className="igr:sr-only">{t('onsite.actions.more')}</span>
                        </TH>
                    </TR>
                </THead>
                <TBody>
                    {widgets.data.map((w) => (
                        <WidgetRow key={w.id} widget={w} store={audience.store} />
                    ))}
                </TBody>
            </Table>
            <p className="igr:border-t igr:px-4 igr:py-2.5 igr:text-caption igr:text-muted-foreground">
                {audience.store ? t('onsite.list.window') : t('onsite.list.windowServices')}
            </p>
        </Card>
    );
}

function WidgetRow({ widget: w, store }: { widget: WidgetSummary; store: boolean }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const navigate = useNavigate();
    const canManage = useCan('manageMarketing');
    const actions = useWidgetActions(workspace.id, w.id);
    const to = `/w/${workspace.id}/en-el-sitio/${w.id}`;
    const s = w.stats;

    return (
        <TR>
            <TD className="igr:min-w-56">
                <Link to={to} className="igr:font-medium igr:hover:underline">
                    {w.name}
                </Link>
                <span className="igr:mt-0.5 igr:flex igr:flex-wrap igr:items-center igr:gap-1.5 igr:text-caption">
                    <KindLabel kind={w.kind} />
                    {w.variants_count > 1 ? <Badge variant="outline">{t('onsite.list.ab', { count: w.variants_count })}</Badge> : null}
                    {w.gives_coupon ? <Badge variant="outline">{t('onsite.list.coupon')}</Badge> : null}
                </span>
            </TD>
            <TD>
                <StatusBadge status={w.status} live={w.live} />
            </TD>
            <TD className="igr:text-right igr:tabular-nums">{s ? formatNumber(s.views, workspace.locale) : '—'}</TD>
            <TD className="igr:text-right igr:tabular-nums">{s ? formatNumber(s.submissions, workspace.locale) : '—'}</TD>
            <TD className="igr:text-right igr:tabular-nums">{s?.submit_rate !== null && s?.submit_rate !== undefined ? formatPercent(s.submit_rate, workspace.locale, 1) : '—'}</TD>
            {store ? (
                <TD className={cn('igr:text-right igr:tabular-nums', s && Number(s.revenue) > 0 && 'igr:font-medium')}>
                    {s ? formatMoney(s.revenue, workspace.currency, workspace.locale) : '—'}
                </TD>
            ) : null}
            <TD>
                {canManage && w.status !== 'archived' ? (
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label={t('onsite.actions.duplicateNamed', { name: w.name })}
                        title={t('onsite.actions.duplicate')}
                        loading={actions.duplicate.isPending}
                        onClick={() => actions.duplicate.mutate(undefined, { onSuccess: (copy) => void navigate(`/w/${workspace.id}/en-el-sitio/${copy.id}`) })}
                    >
                        <Copy aria-hidden />
                    </Button>
                ) : null}
            </TD>
        </TR>
    );
}
