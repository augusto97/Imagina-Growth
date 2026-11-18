import { Copy, MousePointerClick, Plus } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, NavLink, useNavigate } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input, Select } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { EmptyState, ErrorState, FieldError, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { errorMessage } from '@/features/contacts/errors';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { cn } from '@/lib/cn';
import { formatMoney, formatNumber, formatPercent } from '@/lib/format';
import { useCreateWidget, useOnsiteOptions, useWidgetActions, useWidgets } from './api';
import { KIND_ICON, KindLabel, StatusBadge } from './labels';
import type { WidgetKind, WidgetSummary } from './types';

const KINDS: WidgetKind[] = ['popup', 'embedded_form', 'bar', 'spin_wheel', 'teaser', 'two_step', 'quiz', 'wa_widget', 'referral_welcome'];

/** Pestañas de «En el sitio»: widgets (M12) y, de M16, recomendaciones y avisos. */
export function OnsiteTabs() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const base = `/w/${workspace.id}/en-el-sitio`;
    const tabs = [
        { to: base, label: t('onsite.tabs.widgets'), end: true },
        { to: `${base}/recomendaciones`, label: t('onsite.tabs.recommendations'), end: false },
        { to: `${base}/avisos`, label: t('onsite.tabs.alerts'), end: false },
        { to: `${base}/banners`, label: t('onsite.tabs.banners'), end: false },
    ];
    return (
        <nav aria-label={t('onsite.tabs.label')} className="igr:mb-4 igr:inline-flex igr:h-9 igr:items-center igr:rounded-lg igr:bg-muted igr:p-1 igr:text-muted-foreground">
            {tabs.map((tab) => (
                <NavLink
                    key={tab.to}
                    to={tab.to}
                    end={tab.end}
                    className={({ isActive }) =>
                        cn('igr:inline-flex igr:h-7 igr:items-center igr:rounded-md igr:px-3 igr:text-[13px] igr:font-medium igr:transition-colors', isActive ? 'igr:bg-background igr:text-foreground igr:shadow-sm' : 'igr:hover:text-foreground')
                    }
                >
                    {tab.label}
                </NavLink>
            ))}
        </nav>
    );
}

export function OnsitePage() {
    const { t } = useTranslation();
    const canManage = useCan('manageMarketing');
    const [creating, setCreating] = useState(false);
    const [showArchived, setShowArchived] = useState(false);

    return (
        <>
            <PageHeader
                title={t('onsite.title')}
                description={t('onsite.description')}
                actions={
                    canManage ? (
                        <Button onClick={() => setCreating(true)}>
                            <Plus />
                            {t('onsite.new')}
                        </Button>
                    ) : null
                }
            />
            <OnsiteTabs />
            <WidgetList archived={showArchived} onCreate={canManage ? () => setCreating(true) : undefined} />
            <button type="button" className="igr:mt-3 igr:text-xs igr:text-muted-foreground igr:underline-offset-2 igr:hover:underline" onClick={() => setShowArchived((v) => !v)}>
                {showArchived ? t('onsite.list.hideArchived') : t('onsite.list.showArchived')}
            </button>
            {creating ? <NewWidgetDialog onClose={() => setCreating(false)} /> : null}
        </>
    );
}

function WidgetList({ archived, onCreate }: { archived: boolean; onCreate: (() => void) | undefined }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const widgets = useWidgets(workspace.id, archived ? 'archived' : '');

    if (widgets.isError) return <ErrorState error={widgets.error} onRetry={() => void widgets.refetch()} />;
    if (widgets.isPending) {
        return (
            <div className="igr:space-y-2">
                {Array.from({ length: 4 }, (_, i) => (
                    <Skeleton key={i} className="igr:h-12" />
                ))}
            </div>
        );
    }
    if (widgets.data.length === 0) {
        return (
            <EmptyState
                icon={MousePointerClick}
                title={archived ? t('onsite.empty.archived') : t('onsite.empty.title')}
                description={archived ? undefined : t('onsite.empty.description')}
                action={
                    onCreate && !archived ? (
                        <Button onClick={onCreate}>
                            <Plus />
                            {t('onsite.new')}
                        </Button>
                    ) : undefined
                }
            />
        );
    }

    return (
        <Card>
            <Table>
                <THead>
                    <TR>
                        <TH>{t('onsite.list.name')}</TH>
                        <TH>{t('onsite.list.kind')}</TH>
                        <TH>{t('onsite.list.status')}</TH>
                        <TH className="igr:text-right">{t('onsite.list.views')}</TH>
                        <TH className="igr:text-right">{t('onsite.list.submissions')}</TH>
                        <TH className="igr:text-right">{t('onsite.list.rate')}</TH>
                        <TH className="igr:text-right">{t('onsite.list.revenue')}</TH>
                        <TH className="igr:w-10" />
                    </TR>
                </THead>
                <TBody>
                    {widgets.data.map((w) => (
                        <WidgetRow key={w.id} widget={w} />
                    ))}
                </TBody>
            </Table>
            <p className="igr:border-t igr:px-4 igr:py-2 igr:text-xs igr:text-muted-foreground">{t('onsite.list.window')}</p>
        </Card>
    );
}

function WidgetRow({ widget: w }: { widget: WidgetSummary }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const navigate = useNavigate();
    const canManage = useCan('manageMarketing');
    const actions = useWidgetActions(workspace.id, w.id);
    const to = `/w/${workspace.id}/en-el-sitio/${w.id}`;
    const s = w.stats;

    return (
        <TR className="igr:cursor-pointer igr:hover:bg-muted/40" onClick={() => void navigate(to)}>
            <TD>
                <Link to={to} className="igr:font-medium igr:hover:underline" onClick={(e) => e.stopPropagation()}>
                    {w.name}
                </Link>
                {w.variants_count > 1 ? (
                    <Badge variant="outline" className="igr:ml-2">
                        {t('onsite.list.ab', { count: w.variants_count })}
                    </Badge>
                ) : null}
                {w.gives_coupon ? (
                    <Badge variant="outline" className="igr:ml-1">
                        {t('onsite.list.coupon')}
                    </Badge>
                ) : null}
            </TD>
            <TD>
                <KindLabel kind={w.kind} />
            </TD>
            <TD>
                <StatusBadge status={w.status} live={w.live} />
            </TD>
            <TD className="igr:text-right igr:tabular-nums">{s ? formatNumber(s.views, workspace.locale) : '—'}</TD>
            <TD className="igr:text-right igr:tabular-nums">{s ? formatNumber(s.submissions, workspace.locale) : '—'}</TD>
            <TD className="igr:text-right igr:tabular-nums">{s?.submit_rate !== null && s?.submit_rate !== undefined ? formatPercent(s.submit_rate, workspace.locale, 1) : '—'}</TD>
            <TD className="igr:text-right igr:tabular-nums">{s ? formatMoney(s.revenue, workspace.currency, workspace.locale) : '—'}</TD>
            <TD>
                {canManage ? (
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label={t('onsite.actions.duplicate')}
                        title={t('onsite.actions.duplicate')}
                        onClick={(e) => {
                            e.stopPropagation();
                            actions.duplicate.mutate(undefined, { onSuccess: (copy) => void navigate(`/w/${workspace.id}/en-el-sitio/${copy.id}`) });
                        }}
                    >
                        <Copy />
                    </Button>
                ) : null}
            </TD>
        </TR>
    );
}

function NewWidgetDialog({ onClose }: { onClose: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const navigate = useNavigate();
    const options = useOnsiteOptions(workspace.id);
    const create = useCreateWidget(workspace.id);
    const [kind, setKind] = useState<WidgetKind>('popup');
    const [name, setName] = useState('');
    const [site, setSite] = useState('');
    const sites = options.data?.sites ?? [];

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="igr:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{t('onsite.create.title')}</DialogTitle>
                    <DialogDescription>{t('onsite.create.description')}</DialogDescription>
                </DialogHeader>
                <form
                    className="igr:space-y-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        create.mutate(
                            { name: name.trim() || t(`onsite.kinds.${kind}.name`), kind, site_id: site || null },
                            { onSuccess: (w) => void navigate(`/w/${workspace.id}/en-el-sitio/${w.id}`) },
                        );
                    }}
                >
                    <fieldset>
                        <legend className="igr:mb-2 igr:text-[13px] igr:font-medium">{t('onsite.create.kind')}</legend>
                        <div className="igr:grid igr:gap-2 igr:sm:grid-cols-3">
                            {KINDS.map((k) => {
                                const Icon = KIND_ICON[k];
                                return (
                                    <label
                                        key={k}
                                        className={cn(
                                            'igr:flex igr:cursor-pointer igr:flex-col igr:gap-1 igr:rounded-lg igr:border igr:p-3 igr:text-left igr:transition-colors igr:has-[:focus-visible]:ring-2 igr:has-[:focus-visible]:ring-ring',
                                            kind === k ? 'igr:border-primary igr:bg-primary/5' : 'igr:hover:bg-muted/50',
                                        )}
                                    >
                                        <input type="radio" name="kind" value={k} checked={kind === k} onChange={() => setKind(k)} className="igr:sr-only" />
                                        <span className="igr:flex igr:items-center igr:gap-1.5 igr:text-[13px] igr:font-medium">
                                            <Icon className="igr:size-4" aria-hidden />
                                            {t(`onsite.kinds.${k}.name`)}
                                        </span>
                                        <span className="igr:text-xs igr:text-muted-foreground">{t(`onsite.kinds.${k}.description`)}</span>
                                    </label>
                                );
                            })}
                        </div>
                    </fieldset>
                    <div className="igr:grid igr:gap-3 igr:sm:grid-cols-2">
                        <div className="igr:space-y-1.5">
                            <Label htmlFor="wg-name">{t('onsite.create.name')}</Label>
                            <Input id="wg-name" value={name} onChange={(e) => setName(e.target.value)} placeholder={t(`onsite.kinds.${kind}.name`)} maxLength={160} />
                        </div>
                        <div className="igr:space-y-1.5">
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
                    </div>
                    {create.isError ? <FieldError message={errorMessage(create.error, t)} /> : null}
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>
                            {t('common.cancel')}
                        </Button>
                        <Button type="submit" loading={create.isPending}>
                            {t('onsite.create.submit')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
