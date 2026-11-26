import { Check, ChevronDown, Info, Mail, MessageCircle, ShieldCheck, Smartphone, Users } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Callout } from '@/components/ui/callout';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Input, Select } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { EmptyState, ErrorState, FieldError, PageHeader } from '@/components/ui/misc';
import { AutoBreadcrumbs } from '@/components/ui/page-header';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { errorMessage } from '@/features/contacts/errors';
import { businessKind } from '@/features/flows/business';
import { humanizeLiquid } from '@/features/templates/content';
import { TemplatePreview } from '@/features/templates/editor/TemplatePreview';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { cn } from '@/lib/cn';
import { formatDate, formatDateTime, formatMoney, formatNumber } from '@/lib/format';
import { toast } from '@/stores/toast';
import { type CampaignPatch, useAudienceReach, useCampaign, useCampaignActions, useCampaignOptions, useSendWindow } from './api';
import { StatusBadge } from './labels';
import { describeWindow, type WindowSentence } from './sendWindow';
import { isoToWall, wallToIso } from './time';
import type { AbConfig, Audience, AudienceReach, AudienceRule, CampaignChannel, CampaignDetail, CampaignOptions, SendMode, Variant, VariantKey } from './types';

/** Los cuatro pasos: a quién → qué → cuándo → revisar. `?paso=channel` (de antes) abre «¿Qué les dices?». */
export const STEPS = ['audience', 'content', 'schedule', 'review'] as const;
type Step = (typeof STEPS)[number];
const KEYS: VariantKey[] = ['A', 'B', 'C', 'D'];
const SEND_MODES: SendMode[] = ['now', 'scheduled', 'contact_timezone', 'best_time'];
const CHANNELS: CampaignChannel[] = ['whatsapp', 'email', 'sms'];
export const CHANNEL_ICON = { whatsapp: MessageCircle, email: Mail, sms: Smartphone } as const;

export function stepFromParam(value: string | null): Step {
    if (value === 'channel') return 'content';
    return STEPS.find((s) => s === value) ?? 'audience';
}

/** Reglas de la audiencia a partir de los controles del paso «¿A quién?». */
export interface AudienceForm {
    all: boolean;
    include: string[];
    exclude: string[];
    excludeBuyersDays: number | null;
    excludeMessagedDays: number | null;
    onlyConsent: boolean;
    onlyNeverPurchased: boolean;
}

export function audienceToForm(a: Audience | null | undefined): AudienceForm {
    const rule = (list: AudienceRule[] | undefined, type: AudienceRule['type']) => (list ?? []).find((r) => r.type === type);
    return {
        all: a?.all_contacts ?? false,
        include: a?.segments_include ?? [],
        exclude: a?.segments_exclude ?? [],
        excludeBuyersDays: rule(a?.rules_exclude, 'purchased_within_days')?.days ?? null,
        excludeMessagedDays: rule(a?.rules_exclude, 'messaged_within_days')?.days ?? null,
        onlyConsent: rule(a?.rules_include, 'has_consent') !== undefined,
        onlyNeverPurchased: rule(a?.rules_include, 'never_purchased') !== undefined,
    };
}

export function formToAudience(f: AudienceForm, channel: CampaignChannel): Audience {
    const rulesExclude: AudienceRule[] = [];
    if (f.excludeBuyersDays !== null) rulesExclude.push({ type: 'purchased_within_days', days: f.excludeBuyersDays });
    if (f.excludeMessagedDays !== null) rulesExclude.push({ type: 'messaged_within_days', days: f.excludeMessagedDays });
    const rulesInclude: AudienceRule[] = [];
    if (f.onlyConsent) rulesInclude.push({ type: 'has_consent', channel });
    if (f.onlyNeverPurchased) rulesInclude.push({ type: 'never_purchased' });
    return { all_contacts: f.all, segments_include: f.all ? [] : f.include, segments_exclude: f.exclude, rules_include: rulesInclude, rules_exclude: rulesExclude };
}

export function CampaignWizardPage() {
    const { workspace } = useWorkspace();
    const { campaignId = '' } = useParams();
    const campaign = useCampaign(workspace.id, campaignId);
    const options = useCampaignOptions(workspace.id);
    const back = <AutoBreadcrumbs />;

    if (campaign.isError || options.isError) {
        return (
            <>
                {back}
                <ErrorState error={campaign.error ?? options.error} onRetry={() => void (campaign.isError ? campaign.refetch() : options.refetch())} />
            </>
        );
    }
    if (campaign.isPending || options.isPending) {
        return (
            <>
                {back}
                <Skeleton className="igr:mb-4 igr:h-10 igr:w-72" />
                <Skeleton className="igr:mb-5 igr:h-12" />
                <Skeleton className="igr:h-96 igr:rounded-xl" />
            </>
        );
    }
    return (
        <>
            {back}
            <Wizard campaign={campaign.data} options={options.data} />
        </>
    );
}

function Wizard({ campaign, options }: { campaign: CampaignDetail; options: CampaignOptions }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageMarketing');
    const [params, setParams] = useSearchParams();
    const step = stepFromParam(params.get('paso'));
    const index = STEPS.indexOf(step);
    const go = (s: Step) => setParams(s === 'audience' ? {} : { paso: s }, { replace: true });
    const actions = useCampaignActions(workspace.id, campaign.id);

    if (!campaign.editable) {
        return (
            <EmptyState
                icon={Info}
                title={t('campaigns.wizard.notEditable')}
                description={t('campaigns.wizard.notEditableHelp')}
                action={
                    <Button asChild>
                        <Link to={`/w/${workspace.id}/campanas/${campaign.id}`}>{t('campaigns.wizard.seeReport')}</Link>
                    </Button>
                }
            />
        );
    }

    const save = (patch: CampaignPatch, next?: Step) =>
        actions.update.mutate(patch, {
            onSuccess: () => {
                if (next) go(next);
            },
        });

    return (
        <>
            <PageHeader title={campaign.name} description={t('campaigns.wizard.subtitle')} meta={<StatusBadge status={campaign.status} />} breadcrumb={false} />
            <nav aria-label={t('campaigns.wizard.steps')} className="igr:mb-5">
                <ol className="igr:grid igr:grid-cols-4 igr:gap-1.5 igr:sm:gap-2">
                    {STEPS.map((s, i) => (
                        <li key={s} className="igr:min-w-0">
                            <button
                                type="button"
                                onClick={() => go(s)}
                                aria-current={s === step ? 'step' : undefined}
                                className={cn(
                                    'igr:flex igr:w-full igr:min-w-0 igr:flex-col igr:items-start igr:gap-1 igr:rounded-lg igr:border igr:px-2.5 igr:py-2 igr:text-left igr:transition-colors igr:focus-visible:outline-none igr:focus-visible:ring-2 igr:focus-visible:ring-ring igr:sm:flex-row igr:sm:items-center igr:sm:gap-2',
                                    s === step ? 'igr:border-primary/50 igr:bg-brand-soft/60' : 'igr:bg-card igr:hover:bg-accent',
                                )}
                            >
                                <span
                                    aria-hidden
                                    className={cn(
                                        'igr:flex igr:size-6 igr:shrink-0 igr:items-center igr:justify-center igr:rounded-full igr:border igr:text-caption igr:font-semibold igr:tabular-nums',
                                        i < index ? 'igr:border-primary igr:bg-primary igr:text-primary-foreground' : s === step ? 'igr:border-primary igr:text-primary' : 'igr:text-muted-foreground',
                                    )}
                                >
                                    {i < index ? <Check className="igr:size-3.5" /> : i + 1}
                                </span>
                                <span className="igr:w-full igr:min-w-0 igr:truncate igr:text-caption igr:font-medium igr:sm:text-support">
                                    <span className="igr:sm:hidden">{t(`campaigns.wizard.stepShort.${s}`)}</span>
                                    <span className="igr:hidden igr:sm:inline">{t(`campaigns.wizard.step.${s}`)}</span>
                                </span>
                            </button>
                        </li>
                    ))}
                </ol>
            </nav>
            <FieldError message={actions.update.isError ? errorMessage(actions.update.error, t) : undefined} />
            {step === 'audience' && <AudienceStep campaign={campaign} options={options} saving={actions.update.isPending} canManage={canManage} onSave={(p) => save(p, 'content')} />}
            {step === 'content' && <ContentStep campaign={campaign} options={options} saving={actions.update.isPending} canManage={canManage} onSave={(p) => save(p, 'schedule')} onBack={() => go('audience')} />}
            {step === 'schedule' && <ScheduleStep campaign={campaign} saving={actions.update.isPending} canManage={canManage} onSave={(p) => save(p, 'review')} onBack={() => go('content')} />}
            {step === 'review' && <ReviewStep campaign={campaign} options={options} canManage={canManage} onEdit={go} />}
        </>
    );
}

interface StepProps {
    campaign: CampaignDetail;
    options: CampaignOptions;
    saving: boolean;
    canManage: boolean;
    onSave: (patch: CampaignPatch) => void;
    onBack?: () => void;
}

function StepCard({ title, description, children, footer }: { title: string; description?: string; children: React.ReactNode; footer?: React.ReactNode }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="igr:text-section">{title}</CardTitle>
                {description ? <CardDescription>{description}</CardDescription> : null}
            </CardHeader>
            <CardContent className="igr:space-y-5">{children}</CardContent>
            {footer ? <CardFooter className="igr:justify-end igr:gap-2 igr:border-t igr:pt-4">{footer}</CardFooter> : null}
        </Card>
    );
}

function StepFooter({ saving, canManage, disabled, onBack }: { saving: boolean; canManage: boolean; disabled?: boolean; onBack?: (() => void) | undefined }) {
    const { t } = useTranslation();
    return (
        <>
            {onBack ? (
                <Button type="button" variant="ghost" onClick={onBack}>
                    {t('common.back')}
                </Button>
            ) : null}
            {canManage ? (
                <Button type="submit" loading={saving} disabled={disabled}>
                    {t('campaigns.wizard.saveAndNext')}
                </Button>
            ) : null}
        </>
    );
}

function SegmentChecklist({ options, selected, onToggle, disabled, idPrefix }: { options: CampaignOptions; selected: string[]; onToggle: (id: string) => void; disabled?: boolean; idPrefix: string }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const [q, setQ] = useState('');
    const list = options.segments.filter((s) => q === '' || s.name.toLocaleLowerCase('es').includes(q.toLocaleLowerCase('es')));
    return (
        <div>
            <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('campaigns.audience.searchSegments')} aria-label={t('campaigns.audience.searchSegments')} className="igr:mb-2" />
            <ul className="igr:max-h-60 igr:divide-y igr:overflow-y-auto igr:rounded-lg igr:border">
                {list.length === 0 ? <li className="igr:p-3 igr:text-caption igr:text-muted-foreground">{t('campaigns.audience.noSegments')}</li> : null}
                {list.map((s) => (
                    <li key={s.id}>
                        <label htmlFor={`${idPrefix}-${s.id}`} className="igr:flex igr:cursor-pointer igr:items-center igr:gap-2 igr:px-3 igr:py-2 igr:text-support igr:hover:bg-muted/40">
                            <input id={`${idPrefix}-${s.id}`} type="checkbox" checked={selected.includes(s.id)} onChange={() => onToggle(s.id)} disabled={disabled} />
                            <span className="igr:min-w-0 igr:flex-1 igr:truncate">{s.name}</span>
                            {s.is_system ? <Badge variant="outline">{t('campaigns.audience.system')}</Badge> : null}
                            <span className="igr:text-caption igr:tabular-nums igr:text-muted-foreground">{formatNumber(s.member_count, workspace.locale)}</span>
                        </label>
                    </li>
                ))}
            </ul>
        </div>
    );
}

/** A quién llega por canal: los que aceptaron cada uno y el costo aproximado de WhatsApp. */
export function ReachPanel({ reach, loading, defined, selected }: { reach: AudienceReach | undefined; loading: boolean; defined: boolean; selected?: CampaignChannel }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const total = reach?.count ?? 0;
    return (
        <Card className="igr:p-4" aria-live="polite">
            <div className="igr:flex igr:items-center igr:gap-2 igr:text-support igr:font-medium igr:text-muted-foreground">
                <Users className="igr:size-4" aria-hidden />
                {t('campaigns.audience.count')}
            </div>
            <p className="igr:mt-1 igr:text-figure igr:font-semibold igr:tabular-nums">{!defined ? '—' : loading && !reach ? '…' : formatNumber(total, workspace.locale)}</p>
            <p className="igr:mt-3 igr:text-caption igr:font-medium igr:tracking-wide igr:text-muted-foreground igr:uppercase">{t('campaigns.audience.reachTitle')}</p>
            <ul className="igr:mt-2 igr:space-y-2.5">
                {CHANNELS.map((c) => {
                    const Icon = CHANNEL_ICON[c];
                    const row = reach?.by_channel?.[c];
                    const ratio = total > 0 && row ? row.consented / total : 0;
                    return (
                        <li key={c} className={cn('igr:rounded-md igr:px-1.5 igr:py-1', selected === c && 'igr:bg-brand-soft/50')}>
                            <div className="igr:flex igr:items-center igr:gap-2 igr:text-support">
                                <Icon className="igr:size-3.5 igr:text-muted-foreground" aria-hidden />
                                <span className="igr:flex-1">{t(`campaigns.channel.${c}`)}</span>
                                <span className="igr:tabular-nums">{defined && row ? formatNumber(row.consented, workspace.locale) : '—'}</span>
                            </div>
                            <div className="igr:mt-1 igr:h-1.5 igr:rounded-full igr:bg-muted">
                                <div className="igr:h-1.5 igr:rounded-full igr:bg-primary/70" style={{ width: `${Math.round(ratio * 100)}%` }} />
                            </div>
                            {row && !row.connected ? <p className="igr:mt-0.5 igr:text-caption igr:text-muted-foreground">{t('campaigns.audience.notConnected')}</p> : null}
                        </li>
                    );
                })}
            </ul>
            <p className="igr:mt-3 igr:text-caption igr:text-muted-foreground">{t('campaigns.audience.reachHelp')}</p>
            {reach?.whatsapp_cost ? (
                <p className="igr:mt-2 igr:rounded-md igr:bg-muted/50 igr:p-2 igr:text-caption igr:text-muted-foreground">
                    {t('campaigns.audience.waCost', {
                        amount:
                            reach.whatsapp_cost.local !== null && reach.whatsapp_cost.currency !== 'USD'
                                ? formatMoney(reach.whatsapp_cost.local, reach.whatsapp_cost.currency, workspace.locale)
                                : formatMoney(reach.whatsapp_cost.usd, 'USD', workspace.locale),
                    })}
                </p>
            ) : null}
        </Card>
    );
}

function AudienceStep({ campaign, options, saving, canManage, onSave }: StepProps) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const store = businessKind(workspace) !== 'services';
    const [form, setForm] = useState<AudienceForm>(() => audienceToForm(campaign.audience));
    const audience = useMemo(() => formToAudience(form, campaign.channel), [form, campaign.channel]);
    const counter = useCampaignActions(workspace.id, campaign.id).count;
    const serialized = JSON.stringify(audience);
    const defined = form.all || form.include.length > 0;

    useEffect(() => {
        if (!defined) return;
        const id = window.setTimeout(() => counter.mutate(JSON.parse(serialized) as Audience), 400);
        return () => window.clearTimeout(id);
        // eslint-disable-next-line react-hooks/exhaustive-deps -- solo cuando cambia la audiencia
    }, [serialized, defined]);

    const toggle = (key: 'include' | 'exclude') => (id: string) => setForm((f) => ({ ...f, [key]: f[key].includes(id) ? f[key].filter((x) => x !== id) : [...f[key], id] }));
    const daysInput = (value: number | null, set: (v: number | null) => void, label: string, id: string) => (
        <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-2 igr:text-support">
            <input id={id} type="checkbox" checked={value !== null} onChange={(e) => set(e.target.checked ? 7 : null)} disabled={!canManage} />
            <label htmlFor={id}>{label}</label>
            {value !== null ? (
                <>
                    <Input type="number" min={1} max={730} value={value} onChange={(e) => set(Math.max(1, Number(e.target.value) || 1))} className="igr:w-20" aria-label={t('campaigns.audience.days')} disabled={!canManage} />
                    <span className="igr:text-muted-foreground">{t('campaigns.audience.days')}</span>
                </>
            ) : null}
        </div>
    );

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                onSave({ audience });
            }}
        >
            <div className="igr:grid igr:gap-4 igr:lg:grid-cols-[minmax(0,1fr)_300px] igr:lg:items-start">
                <StepCard title={t('campaigns.audience.title')} description={t('campaigns.audience.help')} footer={<StepFooter saving={saving} canManage={canManage} disabled={!defined} />}>
                    <label className="igr:flex igr:items-center igr:gap-2 igr:rounded-lg igr:border igr:p-3 igr:text-support igr:font-medium">
                        <input type="checkbox" checked={form.all} onChange={(e) => setForm((f) => ({ ...f, all: e.target.checked }))} disabled={!canManage} />
                        {t('campaigns.audience.all')}
                    </label>
                    <div className="igr:grid igr:gap-4 igr:md:grid-cols-2">
                        {!form.all ? (
                            <div>
                                <h3 className="igr:mb-1.5 igr:text-support igr:font-medium">{t('campaigns.audience.include')}</h3>
                                <SegmentChecklist options={options} selected={form.include} onToggle={toggle('include')} disabled={!canManage} idPrefix="inc" />
                            </div>
                        ) : null}
                        <div>
                            <h3 className="igr:mb-1.5 igr:text-support igr:font-medium">{t('campaigns.audience.exclude')}</h3>
                            <SegmentChecklist options={options} selected={form.exclude} onToggle={toggle('exclude')} disabled={!canManage} idPrefix="exc" />
                        </div>
                    </div>
                    <fieldset className="igr:space-y-2.5">
                        <legend className="igr:mb-1.5 igr:text-support igr:font-medium">{t('campaigns.audience.rules')}</legend>
                        {store ? daysInput(form.excludeBuyersDays, (v) => setForm((f) => ({ ...f, excludeBuyersDays: v })), t('campaigns.audience.excludeBuyers'), 'r-buyers') : null}
                        {daysInput(form.excludeMessagedDays, (v) => setForm((f) => ({ ...f, excludeMessagedDays: v })), t('campaigns.audience.excludeMessaged'), 'r-messaged')}
                        <label className="igr:flex igr:items-center igr:gap-2 igr:text-support">
                            <input type="checkbox" checked={form.onlyConsent} onChange={(e) => setForm((f) => ({ ...f, onlyConsent: e.target.checked }))} disabled={!canManage} />
                            {t('campaigns.audience.onlyConsent', { channel: t(`campaigns.channel.${campaign.channel}`) })}
                        </label>
                        {store ? (
                            <label className="igr:flex igr:items-center igr:gap-2 igr:text-support">
                                <input type="checkbox" checked={form.onlyNeverPurchased} onChange={(e) => setForm((f) => ({ ...f, onlyNeverPurchased: e.target.checked }))} disabled={!canManage} />
                                {t('campaigns.audience.onlyNeverPurchased')}
                            </label>
                        ) : null}
                    </fieldset>
                </StepCard>
                <div className="igr:lg:sticky igr:lg:top-4">
                    <ReachPanel reach={counter.data} loading={counter.isPending} defined={defined} selected={campaign.channel} />
                </div>
            </div>
        </form>
    );
}

function ContentStep({ campaign, options, saving, canManage, onSave, onBack }: StepProps) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const reach = useAudienceReach(workspace.id, campaign.id);
    const [name, setName] = useState(campaign.name);
    const [channel, setChannel] = useState<CampaignChannel>(campaign.channel);
    const templates = options.templates.filter((tpl) => tpl.channel === channel);
    const initial: Variant[] = channel === campaign.channel ? (campaign.variants.length > 1 ? campaign.variants : [{ key: 'A', template_id: campaign.template_id, weight: 1 }]) : [{ key: 'A', template_id: null, weight: 1 }];
    const [variants, setVariants] = useState<Variant[]>(initial);
    const [ab, setAb] = useState<AbConfig>(campaign.ab_config ?? { sample_pct: 20, metric: 'clicks', wait_hours: 4 });
    const [holdout, setHoldout] = useState(campaign.holdout_pct);
    const [previewKey, setPreviewKey] = useState<VariantKey>('A');
    const isAb = variants.length > 1;
    const complete = name.trim() !== '' && variants.every((v) => v.template_id);
    const setTemplate = (key: VariantKey, id: string) => setVariants((vs) => vs.map((v) => (v.key === key ? { ...v, template_id: id || null } : v)));
    const pickChannel = (c: CampaignChannel) => {
        setChannel(c);
        setVariants([{ key: 'A', template_id: null, weight: 1 }]);
        setPreviewKey('A');
    };
    const previewTemplate = variants.find((v) => v.key === previewKey)?.template_id ?? variants[0]?.template_id ?? null;
    const active = options.active_channels.includes(channel);

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                onSave({
                    name: name.trim(),
                    ...(channel !== campaign.channel ? { channel } : {}),
                    variants: variants.map((v) => ({ ...v, template_id: v.template_id ?? '' })),
                    ab_config: isAb ? ab : null,
                    holdout_pct: holdout,
                });
            }}
        >
            <div className="igr:grid igr:gap-4 igr:lg:grid-cols-[minmax(0,1fr)_340px] igr:lg:items-start">
                <StepCard title={t('campaigns.content.title')} description={t('campaigns.content.help')} footer={<StepFooter saving={saving} canManage={canManage} disabled={!complete} onBack={onBack} />}>
                    <div>
                        <Label htmlFor="w-name">{t('campaigns.fields.name')}</Label>
                        <Input id="w-name" className="igr:mt-1 igr:max-w-md" value={name} maxLength={160} onChange={(e) => setName(e.target.value)} disabled={!canManage} placeholder={t('campaigns.fields.namePlaceholder')} />
                    </div>
                    <fieldset>
                        <legend className="igr:text-support igr:font-medium">{t('campaigns.content.channelQuestion')}</legend>
                        <div className="igr:mt-2 igr:grid igr:gap-2 igr:sm:grid-cols-3">
                            {CHANNELS.map((c) => {
                                const Icon = CHANNEL_ICON[c];
                                const on = options.active_channels.includes(c);
                                const row = reach.data?.by_channel?.[c];
                                return (
                                    <label
                                        key={c}
                                        className={cn(
                                            'igr:flex igr:cursor-pointer igr:flex-col igr:gap-1 igr:rounded-lg igr:border igr:p-3 igr:transition-colors igr:has-[:focus-visible]:ring-2 igr:has-[:focus-visible]:ring-ring',
                                            channel === c ? 'igr:border-primary/60 igr:bg-brand-soft/50' : 'igr:hover:bg-accent/50',
                                        )}
                                    >
                                        <input type="radio" name="channel" className="igr:sr-only" checked={channel === c} onChange={() => pickChannel(c)} disabled={!canManage} />
                                        <span className="igr:flex igr:items-center igr:gap-2 igr:text-support igr:font-semibold">
                                            <Icon className="igr:size-4" aria-hidden />
                                            {t(`campaigns.channel.${c}`)}
                                        </span>
                                        <span className="igr:text-caption igr:tabular-nums igr:text-foreground">
                                            {row ? t('campaigns.content.reach', { count: row.consented, n: formatNumber(row.consented, workspace.locale) }) : '…'}
                                        </span>
                                        <span className="igr:text-caption igr:text-muted-foreground">{t(`campaigns.channelHelp.${c}`)}</span>
                                        {!on ? (
                                            <Badge variant="outline" className="igr:mt-1 igr:self-start">
                                                {t('campaigns.wizard.channelMissing')}
                                            </Badge>
                                        ) : null}
                                    </label>
                                );
                            })}
                        </div>
                    </fieldset>
                    {!active ? (
                        <Callout
                            tone="warning"
                            action={
                                <Button asChild size="sm" variant="outline">
                                    <Link to={`/w/${workspace.id}/conexiones`}>{t('campaigns.wizard.goChannels')}</Link>
                                </Button>
                            }
                        >
                            {t('campaigns.wizard.connectChannel')}
                        </Callout>
                    ) : null}
                    {templates.length === 0 ? (
                        <Callout
                            tone="info"
                            action={
                                <Button asChild size="sm" variant="outline">
                                    <Link to={`/w/${workspace.id}/plantillas?tab=galeria`}>{t('campaigns.content.goTemplates')}</Link>
                                </Button>
                            }
                        >
                            {t('campaigns.content.noTemplates', { channel: t(`campaigns.channel.${channel}`) })}
                        </Callout>
                    ) : null}
                    <div className="igr:space-y-3">
                        {variants.map((v) => (
                            <div key={v.key} className="igr:flex igr:flex-wrap igr:items-end igr:gap-2">
                                <div className="igr:min-w-0 igr:max-w-lg igr:flex-1">
                                    <Label htmlFor={`tpl-${v.key}`}>{isAb ? t('campaigns.content.variant', { key: v.key }) : t('campaigns.content.template')}</Label>
                                    <Select
                                        id={`tpl-${v.key}`}
                                        className="igr:mt-1"
                                        value={v.template_id ?? ''}
                                        onChange={(e) => {
                                            setTemplate(v.key, e.target.value);
                                            setPreviewKey(v.key);
                                        }}
                                        disabled={!canManage}
                                    >
                                        <option value="">{t('campaigns.content.chooseTemplate')}</option>
                                        {templates.map((tpl) => (
                                            <option key={tpl.id} value={tpl.id}>
                                                {tpl.name}
                                                {tpl.subject ? ` — ${humanizeLiquid(tpl.subject, workspace.name)}` : ''}
                                            </option>
                                        ))}
                                    </Select>
                                </div>
                                {isAb && v.template_id ? (
                                    <Button type="button" variant="ghost" size="sm" aria-pressed={previewKey === v.key} onClick={() => setPreviewKey(v.key)}>
                                        {t('campaigns.content.seeVariant')}
                                    </Button>
                                ) : null}
                                {isAb && variants.length > 2 && canManage ? (
                                    <Button type="button" variant="ghost" size="sm" onClick={() => setVariants((vs) => vs.filter((x) => x.key !== v.key))}>
                                        {t('campaigns.content.removeVariant')}
                                    </Button>
                                ) : null}
                            </div>
                        ))}
                    </div>
                    <details className="igr:group igr:rounded-lg igr:border">
                        <summary className="igr:flex igr:cursor-pointer igr:list-none igr:items-center igr:justify-between igr:gap-2 igr:px-3 igr:py-2.5 igr:text-support igr:font-medium">
                            {t('campaigns.content.advanced')}
                            <ChevronDown className="igr:size-4 igr:text-muted-foreground igr:transition-transform igr:group-open:rotate-180" aria-hidden />
                        </summary>
                        <div className="igr:space-y-4 igr:border-t igr:p-3">
                            <div className="igr:flex igr:flex-wrap igr:gap-2">
                                {canManage && variants.length < 4 ? (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() => setVariants((vs) => [...vs, { key: KEYS.find((k) => !vs.some((v) => v.key === k)) ?? 'D', template_id: null, weight: 1 }])}
                                    >
                                        {isAb ? t('campaigns.content.addVariant') : t('campaigns.content.startAb')}
                                    </Button>
                                ) : null}
                                {isAb && canManage ? (
                                    <Button type="button" variant="ghost" size="sm" onClick={() => setVariants((vs) => vs.slice(0, 1))}>
                                        {t('campaigns.content.stopAb')}
                                    </Button>
                                ) : null}
                            </div>
                            {isAb ? (
                                <div className="igr:grid igr:gap-3 igr:sm:grid-cols-3">
                                    <div>
                                        <Label htmlFor="ab-sample">{t('campaigns.content.samplePct')}</Label>
                                        <Input id="ab-sample" type="number" min={5} max={50} className="igr:mt-1" value={ab.sample_pct} onChange={(e) => setAb((a) => ({ ...a, sample_pct: Number(e.target.value) || 20 }))} disabled={!canManage} />
                                    </div>
                                    <div>
                                        <Label htmlFor="ab-metric">{t('campaigns.content.metric')}</Label>
                                        <Select id="ab-metric" className="igr:mt-1" value={ab.metric} onChange={(e) => setAb((a) => ({ ...a, metric: e.target.value === 'revenue' ? 'revenue' : 'clicks' }))} disabled={!canManage}>
                                            <option value="clicks">{t('campaigns.content.metricClicks')}</option>
                                            <option value="revenue">{t('campaigns.content.metricRevenue')}</option>
                                        </Select>
                                    </div>
                                    <div>
                                        <Label htmlFor="ab-wait">{t('campaigns.content.waitHours')}</Label>
                                        <Input id="ab-wait" type="number" min={1} max={72} className="igr:mt-1" value={ab.wait_hours} onChange={(e) => setAb((a) => ({ ...a, wait_hours: Number(e.target.value) || 4 }))} disabled={!canManage} />
                                    </div>
                                    <p className="igr:text-caption igr:text-muted-foreground igr:sm:col-span-3">{t('campaigns.content.abHelp', { pct: ab.sample_pct, hours: ab.wait_hours })}</p>
                                </div>
                            ) : (
                                <p className="igr:text-caption igr:text-muted-foreground">{t('campaigns.content.abIntro')}</p>
                            )}
                            <div className="igr:max-w-xs">
                                <Label htmlFor="holdout">{t('campaigns.content.holdout')}</Label>
                                <Input id="holdout" type="number" min={0} max={50} className="igr:mt-1" value={holdout} onChange={(e) => setHoldout(Math.max(0, Math.min(50, Number(e.target.value) || 0)))} disabled={!canManage} />
                                <p className="igr:mt-1 igr:text-caption igr:text-muted-foreground">{t('campaigns.content.holdoutHelp')}</p>
                            </div>
                        </div>
                    </details>
                </StepCard>
                <div className="igr:space-y-2 igr:lg:sticky igr:lg:top-4">
                    <p className="igr:text-support igr:font-medium">{t('campaigns.content.previewTitle')}</p>
                    {previewTemplate ? (
                        <TemplatePreview templateId={previewTemplate} channel={channel} />
                    ) : (
                        <div className="igr:grid igr:min-h-48 igr:place-items-center igr:rounded-xl igr:border igr:border-dashed igr:p-4 igr:text-center igr:text-support igr:text-muted-foreground">{t('campaigns.content.previewEmpty')}</div>
                    )}
                    <p className="igr:text-caption igr:text-muted-foreground">{t('campaigns.content.previewHelp')}</p>
                </div>
            </div>
        </form>
    );
}

/** La explicación de la ventana legal en palabras, a partir de `describeWindow`. */
export function WindowExplanation({ sentence }: { sentence: WindowSentence }) {
    const { t } = useTranslation();
    return (
        <Callout tone={sentence.moved ? 'warning' : 'success'} icon={ShieldCheck} title={t(sentence.titleKey, sentence.titleVars)}>
            {sentence.because.length > 0 ? <p>{t('campaigns.window.because', { reasons: sentence.because.join(' ') })}</p> : null}
            {sentence.perContact ? <p className="igr:mt-1">{t('campaigns.window.perContact')}</p> : null}
        </Callout>
    );
}

function ScheduleStep({ campaign, saving, canManage, onSave, onBack }: Omit<StepProps, 'options'>) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const [mode, setMode] = useState<SendMode>(campaign.send_mode);
    const [wall, setWall] = useState(isoToWall(campaign.send_at, workspace.timezone));
    const sendAt = mode === 'now' ? null : wallToIso(wall, workspace.timezone);
    const invalid = mode !== 'now' && sendAt === null;
    const [debounced, setDebounced] = useState<string | null>(sendAt);
    useEffect(() => {
        const id = window.setTimeout(() => setDebounced(sendAt), 300);
        return () => window.clearTimeout(id);
    }, [sendAt]);
    const window_ = useSendWindow(workspace.id, mode, mode === 'now' ? null : debounced, !invalid);
    const sentence = window_.data ? describeWindow(t, window_.data, workspace.locale, mode) : null;

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                onSave({ send_mode: mode, send_at: sendAt });
            }}
        >
            <StepCard title={t('campaigns.schedule.title')} description={t('campaigns.schedule.help')} footer={<StepFooter saving={saving} canManage={canManage} disabled={invalid} onBack={onBack} />}>
                <div role="radiogroup" aria-label={t('campaigns.schedule.title')} className="igr:grid igr:gap-2 igr:sm:grid-cols-2">
                    {SEND_MODES.map((m) => (
                        <label key={m} className={cn('igr:flex igr:cursor-pointer igr:gap-3 igr:rounded-lg igr:border igr:p-3 igr:transition-colors', mode === m ? 'igr:border-primary/60 igr:bg-brand-soft/50' : 'igr:hover:bg-accent/50')}>
                            <input type="radio" name="mode" className="igr:mt-0.5" checked={mode === m} onChange={() => setMode(m)} disabled={!canManage} />
                            <span>
                                <span className="igr:block igr:text-support igr:font-medium">{t(`campaigns.schedule.mode.${m}`)}</span>
                                <span className="igr:block igr:text-caption igr:text-muted-foreground">{t(`campaigns.schedule.modeHelp.${m}`)}</span>
                            </span>
                        </label>
                    ))}
                </div>
                {mode !== 'now' ? (
                    <div className="igr:max-w-xs">
                        <Label htmlFor="send-at">{mode === 'best_time' ? t('campaigns.schedule.day') : t('campaigns.schedule.at')}</Label>
                        <Input id="send-at" type="datetime-local" className="igr:mt-1" value={wall} onChange={(e) => setWall(e.target.value)} disabled={!canManage} aria-invalid={invalid} />
                        <p className="igr:mt-1 igr:text-caption igr:text-muted-foreground">{t('campaigns.schedule.timezone', { tz: workspace.timezone })}</p>
                    </div>
                ) : null}
                <div className="igr:min-h-16">{sentence ? <WindowExplanation sentence={sentence} /> : !invalid ? <Skeleton className="igr:h-16 igr:rounded-xl" /> : null}</div>
                <p className="igr:flex igr:items-start igr:gap-1.5 igr:text-caption igr:text-muted-foreground">
                    <Info className="igr:mt-0.5 igr:size-3.5 igr:shrink-0" aria-hidden />
                    {t('campaigns.schedule.legal')}
                </p>
            </StepCard>
        </form>
    );
}

function ReviewStep({ campaign, options, canManage, onEdit }: { campaign: CampaignDetail; options: CampaignOptions; canManage: boolean; onEdit: (s: Step) => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const navigate = useNavigate();
    const actions = useCampaignActions(workspace.id, campaign.id);
    const compliance = campaign.compliance_check;
    const cost = campaign.cost_estimate;
    const ran = compliance !== null && cost !== null;
    const window_ = useSendWindow(workspace.id, campaign.send_mode, campaign.send_mode === 'now' ? null : campaign.send_at);
    const sentence = window_.data ? describeWindow(t, window_.data, workspace.locale, campaign.send_mode) : null;
    const segmentNames = campaign.audience.all_contacts ? [t('campaigns.audience.all')] : campaign.audience.segments_include.map((id) => options.segments.find((s) => s.id === id)?.name ?? id);
    const template = options.templates.find((tpl) => tpl.id === (campaign.template_id ?? campaign.variants[0]?.template_id));

    useEffect(() => {
        if (!ran && canManage && !actions.review.isPending && !actions.review.isError) actions.review.mutate();
        // eslint-disable-next-line react-hooks/exhaustive-deps -- revisa una vez al entrar si no hay revisión
    }, [ran, canManage]);

    if (!canManage && !ran) {
        return <EmptyState icon={ShieldCheck} title={t('campaigns.review.notReviewed')} />;
    }
    const Icon = CHANNEL_ICON[campaign.channel];

    return (
        <div className="igr:space-y-4">
            <FieldError message={actions.review.isError ? errorMessage(actions.review.error, t) : undefined} />
            <div className="igr:grid igr:gap-4 igr:lg:grid-cols-[minmax(0,1fr)_340px] igr:lg:items-start">
                <div className="igr:space-y-4">
                    <Card>
                        <CardHeader>
                            <CardTitle className="igr:text-section">{t('campaigns.review.summary')}</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="igr:divide-y igr:text-support">
                                <SummaryRow label={t('campaigns.wizard.step.audience')} onEdit={() => onEdit('audience')}>
                                    {segmentNames.join(', ') || '—'}
                                </SummaryRow>
                                <SummaryRow label={t('campaigns.wizard.step.content')} onEdit={() => onEdit('content')}>
                                    <span className="igr:inline-flex igr:items-center igr:gap-1.5">
                                        <Icon className="igr:size-3.5 igr:text-muted-foreground" aria-hidden />
                                        {t(`campaigns.channel.${campaign.channel}`)} · {template?.name ?? '—'}
                                        {campaign.is_ab ? ` · ${t('campaigns.list.ab')}` : ''}
                                    </span>
                                </SummaryRow>
                                <SummaryRow label={t('campaigns.wizard.step.schedule')} onEdit={() => onEdit('schedule')}>
                                    {sentence ? t(sentence.titleKey, sentence.titleVars) : t(`campaigns.schedule.mode.${campaign.send_mode}`)}
                                </SummaryRow>
                            </dl>
                        </CardContent>
                    </Card>
                    {compliance === null || cost === null || actions.review.isPending ? (
                        <div className="igr:space-y-3" aria-busy="true">
                            <p className="igr:text-support igr:text-muted-foreground">{t('campaigns.review.running')}</p>
                            <Skeleton className="igr:h-28 igr:rounded-xl" />
                            <Skeleton className="igr:h-40 igr:rounded-xl" />
                        </div>
                    ) : (
                        <>
                            {!compliance.channel_available ? (
                                <Callout
                                    tone="danger"
                                    action={
                                        <Button asChild size="sm" variant="outline">
                                            <Link to={`/w/${workspace.id}/conexiones`}>{t('campaigns.wizard.goChannels')}</Link>
                                        </Button>
                                    }
                                >
                                    {t('campaigns.review.noChannel')}
                                </Callout>
                            ) : null}
                            <div className="igr:grid igr:grid-cols-2 igr:gap-3 igr:sm:grid-cols-4">
                                <Tile label={t('campaigns.review.audience')} value={formatNumber(compliance.audience, workspace.locale)} />
                                <Tile label={t('campaigns.review.willSend')} value={formatNumber(compliance.will_send, workspace.locale)} strong />
                                <Tile label={t('campaigns.review.excluded')} value={formatNumber(compliance.excluded_total, workspace.locale)} />
                                <Tile label={t('campaigns.review.holdout')} value={formatNumber(compliance.holdout, workspace.locale)} />
                            </div>
                            {compliance.estimated ? <p className="igr:text-caption igr:text-muted-foreground">{t('campaigns.review.estimated', { n: formatNumber(compliance.checked, workspace.locale) })}</p> : null}
                            <Card>
                                <CardHeader>
                                    <CardTitle className="igr:flex igr:items-center igr:gap-2">
                                        <ShieldCheck className="igr:size-4 igr:text-muted-foreground" aria-hidden />
                                        {t('campaigns.review.compliance')}
                                    </CardTitle>
                                    <CardDescription>{t('campaigns.review.complianceHelp')}</CardDescription>
                                </CardHeader>
                                <CardContent className="igr:grid igr:gap-4 igr:sm:grid-cols-2">
                                    <ReasonList title={t('campaigns.review.excludedWhy')} items={compliance.excluded} empty={t('campaigns.review.noneExcluded')} />
                                    <ReasonList title={t('campaigns.review.deferredWhy')} items={compliance.deferred} empty={t('campaigns.review.noneDeferred')} />
                                    {compliance.first_send_at ? (
                                        <p className="igr:text-caption igr:text-muted-foreground igr:sm:col-span-2">
                                            {compliance.last_send_at && compliance.last_send_at !== compliance.first_send_at
                                                ? t('campaigns.review.window', {
                                                      from: formatDateTime(compliance.first_send_at, workspace.locale, workspace.timezone),
                                                      to: formatDateTime(compliance.last_send_at, workspace.locale, workspace.timezone),
                                                  })
                                                : t('campaigns.review.windowSingle', { at: formatDateTime(compliance.first_send_at, workspace.locale, workspace.timezone) })}
                                        </p>
                                    ) : null}
                                </CardContent>
                            </Card>
                            <Card>
                                <CardHeader>
                                    <CardTitle>{t('campaigns.review.cost')}</CardTitle>
                                    <CardDescription>{cost.note}</CardDescription>
                                </CardHeader>
                                <CardContent className="igr:space-y-3">
                                    <p className="igr:text-figure igr:font-semibold igr:tabular-nums">
                                        {cost.total_local !== null && cost.currency !== 'USD' ? formatMoney(cost.total_local, cost.currency, workspace.locale) : formatMoney(cost.total_usd, 'USD', workspace.locale)}
                                        {cost.total_local !== null && cost.currency !== 'USD' ? (
                                            <span className="igr:ml-2 igr:text-support igr:font-normal igr:text-muted-foreground">≈ {formatMoney(cost.total_usd, 'USD', workspace.locale)}</span>
                                        ) : null}
                                    </p>
                                    {cost.by_country.length > 0 ? (
                                        <Table>
                                            <THead>
                                                <TR>
                                                    <TH>{t('campaigns.review.country')}</TH>
                                                    <TH className="igr:text-right">{t('campaigns.review.recipients')}</TH>
                                                    <TH className="igr:text-right">{t('campaigns.review.rate')}</TH>
                                                    <TH className="igr:text-right">{t('campaigns.review.subtotal')}</TH>
                                                </TR>
                                            </THead>
                                            <TBody>
                                                {cost.by_country.map((r) => (
                                                    <TR key={r.country}>
                                                        <TD>{t(`campaigns.countries.${r.country}`, { defaultValue: r.country })}</TD>
                                                        <TD className="igr:text-right igr:tabular-nums">{formatNumber(r.recipients, workspace.locale)}</TD>
                                                        <TD className="igr:text-right igr:tabular-nums">
                                                            {r.rate_usd !== null ? `US$ ${r.rate_usd.toLocaleString(workspace.locale, { minimumFractionDigits: 4, maximumFractionDigits: 4 })}` : '—'}
                                                        </TD>
                                                        <TD className="igr:text-right igr:tabular-nums">{formatMoney(r.total_usd, 'USD', workspace.locale)}</TD>
                                                    </TR>
                                                ))}
                                            </TBody>
                                        </Table>
                                    ) : null}
                                    {cost.channel === 'whatsapp' ? (
                                        <p className={cn('igr:text-caption', cost.rates_stale ? 'igr:text-warning' : 'igr:text-muted-foreground')}>
                                            {cost.rates_stale
                                                ? t('campaigns.review.ratesStale', { date: formatDate(cost.rates_effective_from, workspace.locale, 'UTC') })
                                                : t('campaigns.review.ratesFrom', { date: formatDate(cost.rates_effective_from, workspace.locale, 'UTC') })}
                                        </p>
                                    ) : null}
                                </CardContent>
                            </Card>
                        </>
                    )}
                </div>
                <div className="igr:space-y-2 igr:lg:sticky igr:lg:top-4">
                    <p className="igr:text-support igr:font-medium">{t('campaigns.content.previewTitle')}</p>
                    {template ? <TemplatePreview templateId={template.id} channel={campaign.channel} /> : null}
                </div>
            </div>
            {compliance !== null && cost !== null && !actions.review.isPending ? (
                <div className="igr:sticky igr:bottom-0 igr:z-10 igr:-mx-4 igr:flex igr:flex-wrap igr:items-center igr:justify-end igr:gap-2 igr:border-t igr:bg-background/95 igr:px-4 igr:py-3 igr:backdrop-blur igr:sm:static igr:sm:mx-0 igr:sm:border-0 igr:sm:bg-transparent igr:sm:p-0">
                    <span className="igr:mr-auto igr:text-caption igr:text-muted-foreground">{t('campaigns.review.checkedAt', { when: formatDateTime(compliance.checked_at, workspace.locale, workspace.timezone) })}</span>
                    {canManage ? (
                        <>
                            <Button variant="outline" loading={actions.review.isPending} onClick={() => actions.review.mutate()}>
                                {t('campaigns.review.rerun')}
                            </Button>
                            <Button
                                loading={actions.schedule.isPending}
                                disabled={!compliance.channel_available || compliance.will_send + compliance.deferred.reduce((s, d) => s + d.count, 0) === 0}
                                onClick={() =>
                                    actions.schedule.mutate(undefined, {
                                        onSuccess: () => {
                                            toast.success(campaign.send_mode === 'now' ? t('campaigns.review.sentToast') : t('campaigns.review.scheduledToast'));
                                            void navigate(`/w/${workspace.id}/campanas/${campaign.id}`);
                                        },
                                    })
                                }
                            >
                                {campaign.send_mode === 'now' ? t('campaigns.review.approveNow') : t('campaigns.review.approve')}
                            </Button>
                        </>
                    ) : null}
                </div>
            ) : null}
            <FieldError message={actions.schedule.isError ? errorMessage(actions.schedule.error, t) : undefined} />
        </div>
    );
}

function SummaryRow({ label, onEdit, children }: { label: string; onEdit: () => void; children: React.ReactNode }) {
    const { t } = useTranslation();
    return (
        <div className="igr:flex igr:items-start igr:gap-3 igr:py-2.5 igr:first:pt-0 igr:last:pb-0">
            <dt className="igr:w-24 igr:shrink-0 igr:font-medium igr:text-muted-foreground igr:sm:w-36">{label}</dt>
            <dd className="igr:min-w-0 igr:flex-1 igr:text-foreground">{children}</dd>
            <button type="button" onClick={onEdit} className="igr:shrink-0 igr:rounded-sm igr:text-caption igr:font-medium igr:text-primary igr:hover:underline igr:focus-visible:outline-none igr:focus-visible:ring-2 igr:focus-visible:ring-ring">
                {t('campaigns.review.change')}
            </button>
        </div>
    );
}

function Tile({ label, value, strong }: { label: string; value: string; strong?: boolean }) {
    return (
        <Card className="igr:p-3">
            <p className="igr:text-caption igr:text-muted-foreground">{label}</p>
            <p className={cn('igr:mt-1 igr:text-xl igr:tabular-nums', strong ? 'igr:font-semibold' : 'igr:font-medium')}>{value}</p>
        </Card>
    );
}

function ReasonList({ title, items, empty }: { title: string; items: { reason: string; label: string; count: number }[]; empty: string }) {
    const { workspace } = useWorkspace();
    return (
        <div>
            <h4 className="igr:mb-1 igr:text-caption igr:font-medium igr:text-muted-foreground">{title}</h4>
            {items.length === 0 ? (
                <p className="igr:text-support">{empty}</p>
            ) : (
                <ul className="igr:space-y-1">
                    {items.map((i) => (
                        <li key={i.reason} className="igr:flex igr:items-center igr:justify-between igr:gap-2 igr:text-support">
                            <span>{i.label}</span>
                            <span className="igr:tabular-nums">{formatNumber(i.count, workspace.locale)}</span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
