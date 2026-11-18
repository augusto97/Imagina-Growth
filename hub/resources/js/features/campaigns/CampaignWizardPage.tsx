import { ArrowLeft, Check, Info, ShieldCheck, Sparkles, TriangleAlert } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Input, Select } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { EmptyState, ErrorState, FieldError, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { errorMessage } from '@/features/contacts/errors';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { cn } from '@/lib/cn';
import { formatDate, formatDateTime, formatMoney, formatNumber } from '@/lib/format';
import { toast } from '@/stores/toast';
import { type CampaignPatch, useCampaign, useCampaignActions, useCampaignOptions } from './api';
import { CHANNEL_ICON, StatusBadge } from './labels';
import { isoToWall, wallToIso } from './time';
import type { AbConfig, Audience, AudienceRule, CampaignChannel, CampaignDetail, CampaignOptions, SendMode, Variant, VariantKey } from './types';

export const STEPS = ['channel', 'audience', 'content', 'schedule', 'review'] as const;
type Step = (typeof STEPS)[number];
const KEYS: VariantKey[] = ['A', 'B', 'C', 'D'];
const SEND_MODES: SendMode[] = ['now', 'scheduled', 'contact_timezone', 'best_time'];

/** Reglas de la audiencia a partir de los controles del paso 2. */
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
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const { campaignId = '' } = useParams();
    const campaign = useCampaign(workspace.id, campaignId);
    const options = useCampaignOptions(workspace.id);
    const back = (
        <Link to={`/w/${workspace.id}/campanas`} className="igr:mb-3 igr:inline-flex igr:items-center igr:gap-1 igr:text-[13px] igr:text-muted-foreground igr:hover:text-foreground">
            <ArrowLeft className="igr:size-3.5" />
            {t('campaigns.title')}
        </Link>
    );

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
                <Skeleton className="igr:h-96" />
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
    const step: Step = STEPS.find((s) => s === params.get('paso')) ?? 'channel';
    const index = STEPS.indexOf(step);
    const go = (s: Step) => setParams(s === 'channel' ? {} : { paso: s }, { replace: true });
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
            <PageHeader
                title={campaign.name}
                description={
                    <span className="igr:inline-flex igr:items-center igr:gap-2">
                        <StatusBadge status={campaign.status} /> {t('campaigns.wizard.subtitle')}
                    </span>
                }
            />
            <ol className="igr:mb-5 igr:grid igr:grid-cols-5 igr:gap-2" aria-label={t('campaigns.wizard.steps')}>
                {STEPS.map((s, i) => (
                    <li key={s}>
                        <button
                            type="button"
                            onClick={() => go(s)}
                            aria-current={s === step ? 'step' : undefined}
                            className={cn(
                                'igr:flex igr:w-full igr:items-center igr:gap-2 igr:rounded-md igr:border igr:px-2.5 igr:py-2 igr:text-left igr:text-xs igr:transition-colors',
                                s === step ? 'igr:border-primary igr:bg-primary/5 igr:font-medium' : 'igr:hover:bg-accent',
                            )}
                        >
                            <span
                                className={cn(
                                    'igr:flex igr:size-5 igr:shrink-0 igr:items-center igr:justify-center igr:rounded-full igr:border igr:text-[11px]',
                                    i < index ? 'igr:border-primary igr:bg-primary igr:text-primary-foreground' : s === step ? 'igr:border-primary igr:text-primary' : '',
                                )}
                                aria-hidden="true"
                            >
                                {i < index ? <Check className="igr:size-3" /> : i + 1}
                            </span>
                            <span className="igr:truncate">{t(`campaigns.wizard.step.${s}`)}</span>
                        </button>
                    </li>
                ))}
            </ol>
            <FieldError message={actions.update.isError ? errorMessage(actions.update.error, t) : undefined} />
            {step === 'channel' && <ChannelStep campaign={campaign} options={options} saving={actions.update.isPending} canManage={canManage} onSave={(p) => save(p, 'audience')} />}
            {step === 'audience' && <AudienceStep campaign={campaign} options={options} saving={actions.update.isPending} canManage={canManage} onSave={(p) => save(p, 'content')} />}
            {step === 'content' && <ContentStep campaign={campaign} options={options} saving={actions.update.isPending} canManage={canManage} onSave={(p) => save(p, 'schedule')} />}
            {step === 'schedule' && <ScheduleStep campaign={campaign} saving={actions.update.isPending} canManage={canManage} onSave={(p) => save(p, 'review')} />}
            {step === 'review' && <ReviewStep campaign={campaign} canManage={canManage} />}
            <p className="igr:mt-6 igr:inline-flex igr:items-center igr:gap-1.5 igr:text-xs igr:text-muted-foreground">
                <Sparkles className="igr:size-3.5" aria-hidden="true" />
                {t('campaigns.copilotSoon')}
            </p>
        </>
    );
}

interface StepProps {
    campaign: CampaignDetail;
    options: CampaignOptions;
    saving: boolean;
    canManage: boolean;
    onSave: (patch: CampaignPatch) => void;
}

function StepCard({ title, description, children, footer }: { title: string; description?: string; children: React.ReactNode; footer?: React.ReactNode }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>{title}</CardTitle>
                {description ? <CardDescription>{description}</CardDescription> : null}
            </CardHeader>
            <CardContent className="igr:space-y-4">{children}</CardContent>
            {footer ? <CardFooter className="igr:justify-end igr:gap-2">{footer}</CardFooter> : null}
        </Card>
    );
}

function NextButton({ saving, canManage, disabled }: { saving: boolean; canManage: boolean; disabled?: boolean }) {
    const { t } = useTranslation();
    if (!canManage) return null;
    return (
        <Button type="submit" loading={saving} disabled={disabled}>
            {t('campaigns.wizard.saveAndNext')}
        </Button>
    );
}

function ChannelStep({ campaign, options, saving, canManage, onSave }: StepProps) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const [name, setName] = useState(campaign.name);
    const [channel, setChannel] = useState<CampaignChannel>(campaign.channel);
    const active = options.active_channels.includes(channel);

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                onSave(channel === campaign.channel ? { name } : { name, channel, template_id: null, variants: [] });
            }}
        >
            <StepCard title={t('campaigns.wizard.step.channel')} description={t('campaigns.wizard.channelHelp')} footer={<NextButton saving={saving} canManage={canManage} disabled={name.trim() === ''} />}>
                <div>
                    <Label htmlFor="w-name">{t('campaigns.fields.name')}</Label>
                    <Input id="w-name" className="igr:mt-1 igr:max-w-md" value={name} maxLength={160} onChange={(e) => setName(e.target.value)} disabled={!canManage} />
                </div>
                <fieldset>
                    <legend className="igr:text-[13px] igr:font-medium">{t('campaigns.fields.channel')}</legend>
                    <div className="igr:mt-2 igr:grid igr:gap-2 igr:sm:grid-cols-3">
                        {(['whatsapp', 'email', 'sms'] as CampaignChannel[]).map((c) => {
                            const Icon = CHANNEL_ICON[c];
                            const on = options.active_channels.includes(c);
                            return (
                                <label key={c} className={cn('igr:flex igr:cursor-pointer igr:flex-col igr:gap-1 igr:rounded-lg igr:border igr:p-3 igr:has-[:focus-visible]:ring-2 igr:has-[:focus-visible]:ring-ring', channel === c && 'igr:border-primary igr:bg-primary/5')}>
                                    <input type="radio" name="channel" className="igr:sr-only" checked={channel === c} onChange={() => setChannel(c)} disabled={!canManage} />
                                    <span className="igr:flex igr:items-center igr:gap-2 igr:text-[13px] igr:font-medium">
                                        <Icon className="igr:size-4" aria-hidden="true" />
                                        {t(`campaigns.channel.${c}`)}
                                    </span>
                                    <span className="igr:text-xs igr:text-muted-foreground">{t(`campaigns.channelHelp.${c}`)}</span>
                                    <Badge variant={on ? 'success' : 'outline'} className="igr:mt-1 igr:self-start">
                                        {on ? t('campaigns.wizard.channelConnected') : t('campaigns.wizard.channelMissing')}
                                    </Badge>
                                </label>
                            );
                        })}
                    </div>
                </fieldset>
                {!active ? (
                    <p className="igr:flex igr:items-start igr:gap-1.5 igr:text-xs igr:text-warning">
                        <TriangleAlert className="igr:mt-0.5 igr:size-3.5 igr:shrink-0" aria-hidden="true" />
                        <span>
                            {t('campaigns.wizard.connectChannel')}{' '}
                            <Link className="igr:underline" to={`/w/${workspace.id}/canales`}>
                                {t('campaigns.wizard.goChannels')}
                            </Link>
                        </span>
                    </p>
                ) : null}
            </StepCard>
        </form>
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
            <ul className="igr:max-h-56 igr:divide-y igr:overflow-y-auto igr:rounded-md igr:border">
                {list.length === 0 ? <li className="igr:p-3 igr:text-xs igr:text-muted-foreground">{t('campaigns.audience.noSegments')}</li> : null}
                {list.map((s) => (
                    <li key={s.id}>
                        <label htmlFor={`${idPrefix}-${s.id}`} className="igr:flex igr:cursor-pointer igr:items-center igr:gap-2 igr:px-3 igr:py-1.5 igr:text-[13px] igr:hover:bg-muted/40">
                            <input id={`${idPrefix}-${s.id}`} type="checkbox" checked={selected.includes(s.id)} onChange={() => onToggle(s.id)} disabled={disabled} />
                            <span className="igr:flex-1 igr:truncate">{s.name}</span>
                            {s.is_system ? <Badge variant="outline">{t('campaigns.audience.system')}</Badge> : null}
                            <span className="igr:text-xs igr:tabular-nums igr:text-muted-foreground">{formatNumber(s.member_count, workspace.locale)}</span>
                        </label>
                    </li>
                ))}
            </ul>
        </div>
    );
}

function AudienceStep({ campaign, options, saving, canManage, onSave }: StepProps) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
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
        <div className="igr:flex igr:items-center igr:gap-2 igr:text-[13px]">
            <input id={id} type="checkbox" checked={value !== null} onChange={(e) => set(e.target.checked ? 7 : null)} disabled={!canManage} />
            <label htmlFor={id}>{label}</label>
            {value !== null ? (
                <Input type="number" min={1} max={730} value={value} onChange={(e) => set(Math.max(1, Number(e.target.value) || 1))} className="igr:w-20" aria-label={t('campaigns.audience.days')} disabled={!canManage} />
            ) : null}
            {value !== null ? <span className="igr:text-muted-foreground">{t('campaigns.audience.days')}</span> : null}
        </div>
    );

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                onSave({ audience });
            }}
        >
            <StepCard title={t('campaigns.wizard.step.audience')} description={t('campaigns.audience.help')} footer={<NextButton saving={saving} canManage={canManage} disabled={!defined} />}>
                <div className="igr:flex igr:items-center igr:justify-between igr:gap-3 igr:rounded-md igr:bg-muted/50 igr:px-3 igr:py-2" aria-live="polite">
                    <span className="igr:text-[13px]">{t('campaigns.audience.count')}</span>
                    <strong className="igr:text-lg igr:tabular-nums">{!defined ? '—' : counter.isPending || !counter.data ? '…' : formatNumber(counter.data.count, workspace.locale)}</strong>
                </div>
                <label className="igr:flex igr:items-center igr:gap-2 igr:text-[13px]">
                    <input type="checkbox" checked={form.all} onChange={(e) => setForm((f) => ({ ...f, all: e.target.checked }))} disabled={!canManage} />
                    {t('campaigns.audience.all')}
                </label>
                <div className="igr:grid igr:gap-4 igr:md:grid-cols-2">
                    {!form.all ? (
                        <div>
                            <h3 className="igr:mb-1 igr:text-[13px] igr:font-medium">{t('campaigns.audience.include')}</h3>
                            <SegmentChecklist options={options} selected={form.include} onToggle={toggle('include')} disabled={!canManage} idPrefix="inc" />
                        </div>
                    ) : null}
                    <div>
                        <h3 className="igr:mb-1 igr:text-[13px] igr:font-medium">{t('campaigns.audience.exclude')}</h3>
                        <SegmentChecklist options={options} selected={form.exclude} onToggle={toggle('exclude')} disabled={!canManage} idPrefix="exc" />
                    </div>
                </div>
                <fieldset className="igr:space-y-2">
                    <legend className="igr:mb-1 igr:text-[13px] igr:font-medium">{t('campaigns.audience.rules')}</legend>
                    {daysInput(form.excludeBuyersDays, (v) => setForm((f) => ({ ...f, excludeBuyersDays: v })), t('campaigns.audience.excludeBuyers'), 'r-buyers')}
                    {daysInput(form.excludeMessagedDays, (v) => setForm((f) => ({ ...f, excludeMessagedDays: v })), t('campaigns.audience.excludeMessaged'), 'r-messaged')}
                    <label className="igr:flex igr:items-center igr:gap-2 igr:text-[13px]">
                        <input type="checkbox" checked={form.onlyConsent} onChange={(e) => setForm((f) => ({ ...f, onlyConsent: e.target.checked }))} disabled={!canManage} />
                        {t('campaigns.audience.onlyConsent', { channel: t(`campaigns.channel.${campaign.channel}`) })}
                    </label>
                    <label className="igr:flex igr:items-center igr:gap-2 igr:text-[13px]">
                        <input type="checkbox" checked={form.onlyNeverPurchased} onChange={(e) => setForm((f) => ({ ...f, onlyNeverPurchased: e.target.checked }))} disabled={!canManage} />
                        {t('campaigns.audience.onlyNeverPurchased')}
                    </label>
                </fieldset>
            </StepCard>
        </form>
    );
}

function ContentStep({ campaign, options, saving, canManage, onSave }: StepProps) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const templates = options.templates.filter((tpl) => tpl.channel === campaign.channel);
    const [variants, setVariants] = useState<Variant[]>(
        campaign.variants.length > 1 ? campaign.variants : [{ key: 'A', template_id: campaign.template_id, weight: 1 }],
    );
    const [ab, setAb] = useState<AbConfig>(campaign.ab_config ?? { sample_pct: 20, metric: 'clicks', wait_hours: 4 });
    const [holdout, setHoldout] = useState(campaign.holdout_pct);
    const isAb = variants.length > 1;
    const complete = variants.every((v) => v.template_id);
    const setTemplate = (key: VariantKey, id: string) => setVariants((vs) => vs.map((v) => (v.key === key ? { ...v, template_id: id || null } : v)));

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                onSave({
                    variants: variants.map((v) => ({ ...v, template_id: v.template_id ?? '' })),
                    ab_config: isAb ? ab : null,
                    holdout_pct: holdout,
                });
            }}
        >
            <StepCard title={t('campaigns.wizard.step.content')} description={t('campaigns.content.help')} footer={<NextButton saving={saving} canManage={canManage} disabled={!complete} />}>
                {templates.length === 0 ? (
                    <p className="igr:flex igr:items-start igr:gap-1.5 igr:text-xs igr:text-warning">
                        <TriangleAlert className="igr:mt-0.5 igr:size-3.5 igr:shrink-0" aria-hidden="true" />
                        <span>
                            {t('campaigns.content.noTemplates', { channel: t(`campaigns.channel.${campaign.channel}`) })}{' '}
                            <Link className="igr:underline" to={`/w/${workspace.id}/plantillas`}>
                                {t('campaigns.content.goTemplates')}
                            </Link>
                        </span>
                    </p>
                ) : null}
                <div className="igr:space-y-2">
                    {variants.map((v) => (
                        <div key={v.key} className="igr:flex igr:items-end igr:gap-2">
                            <div className="igr:flex-1 igr:max-w-lg">
                                <Label htmlFor={`tpl-${v.key}`}>{isAb ? t('campaigns.content.variant', { key: v.key }) : t('campaigns.content.template')}</Label>
                                <Select id={`tpl-${v.key}`} className="igr:mt-1" value={v.template_id ?? ''} onChange={(e) => setTemplate(v.key, e.target.value)} disabled={!canManage}>
                                    <option value="">{t('campaigns.content.chooseTemplate')}</option>
                                    {templates.map((tpl) => (
                                        <option key={tpl.id} value={tpl.id}>
                                            {tpl.name}
                                            {tpl.subject ? ` — ${tpl.subject}` : ''}
                                        </option>
                                    ))}
                                </Select>
                            </div>
                            {isAb && variants.length > 2 && canManage ? (
                                <Button type="button" variant="ghost" size="sm" onClick={() => setVariants((vs) => vs.filter((x) => x.key !== v.key))}>
                                    {t('campaigns.content.removeVariant')}
                                </Button>
                            ) : null}
                        </div>
                    ))}
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
                    <div className="igr:grid igr:gap-3 igr:rounded-md igr:border igr:p-3 igr:sm:grid-cols-3">
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
                        <p className="igr:text-xs igr:text-muted-foreground igr:sm:col-span-3">{t('campaigns.content.abHelp', { pct: ab.sample_pct, hours: ab.wait_hours })}</p>
                    </div>
                ) : null}
                <div className="igr:max-w-xs">
                    <Label htmlFor="holdout">{t('campaigns.content.holdout')}</Label>
                    <Input id="holdout" type="number" min={0} max={50} className="igr:mt-1" value={holdout} onChange={(e) => setHoldout(Math.max(0, Math.min(50, Number(e.target.value) || 0)))} disabled={!canManage} />
                    <p className="igr:mt-1 igr:text-xs igr:text-muted-foreground">{t('campaigns.content.holdoutHelp')}</p>
                </div>
            </StepCard>
        </form>
    );
}

function ScheduleStep({ campaign, saving, canManage, onSave }: Omit<StepProps, 'options'>) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const [mode, setMode] = useState<SendMode>(campaign.send_mode);
    const [wall, setWall] = useState(isoToWall(campaign.send_at, workspace.timezone));
    const sendAt = mode === 'now' ? null : wallToIso(wall, workspace.timezone);
    const invalid = mode !== 'now' && sendAt === null;

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                onSave({ send_mode: mode, send_at: sendAt });
            }}
        >
            <StepCard title={t('campaigns.wizard.step.schedule')} description={t('campaigns.schedule.help')} footer={<NextButton saving={saving} canManage={canManage} disabled={invalid} />}>
                <div role="radiogroup" aria-label={t('campaigns.wizard.step.schedule')} className="igr:grid igr:gap-2 igr:sm:grid-cols-2">
                    {SEND_MODES.map((m) => (
                        <label key={m} className={cn('igr:flex igr:cursor-pointer igr:gap-3 igr:rounded-lg igr:border igr:p-3', mode === m && 'igr:border-primary igr:bg-primary/5')}>
                            <input type="radio" name="mode" className="igr:mt-0.5" checked={mode === m} onChange={() => setMode(m)} disabled={!canManage} />
                            <span>
                                <span className="igr:block igr:text-[13px] igr:font-medium">{t(`campaigns.schedule.mode.${m}`)}</span>
                                <span className="igr:block igr:text-xs igr:text-muted-foreground">{t(`campaigns.schedule.modeHelp.${m}`)}</span>
                            </span>
                        </label>
                    ))}
                </div>
                {mode !== 'now' ? (
                    <div className="igr:max-w-xs">
                        <Label htmlFor="send-at">{mode === 'best_time' ? t('campaigns.schedule.day') : t('campaigns.schedule.at')}</Label>
                        <Input id="send-at" type="datetime-local" className="igr:mt-1" value={wall} onChange={(e) => setWall(e.target.value)} disabled={!canManage} aria-invalid={invalid} />
                        <p className="igr:mt-1 igr:text-xs igr:text-muted-foreground">{t('campaigns.schedule.timezone', { tz: workspace.timezone })}</p>
                    </div>
                ) : null}
                <p className="igr:flex igr:items-start igr:gap-1.5 igr:rounded-md igr:bg-muted/50 igr:p-3 igr:text-xs igr:text-muted-foreground">
                    <ShieldCheck className="igr:mt-0.5 igr:size-3.5 igr:shrink-0" aria-hidden="true" />
                    {t('campaigns.schedule.legal')}
                </p>
            </StepCard>
        </form>
    );
}

function ReviewStep({ campaign, canManage }: { campaign: CampaignDetail; canManage: boolean }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const navigate = useNavigate();
    const actions = useCampaignActions(workspace.id, campaign.id);
    const compliance = campaign.compliance_check;
    const cost = campaign.cost_estimate;
    const ran = compliance !== null && cost !== null;

    useEffect(() => {
        if (!ran && canManage && !actions.review.isPending && !actions.review.isError) actions.review.mutate();
        // eslint-disable-next-line react-hooks/exhaustive-deps -- revisa una vez al entrar si no hay revisión
    }, [ran, canManage]);

    if (!canManage && !ran) {
        return <EmptyState icon={ShieldCheck} title={t('campaigns.review.notReviewed')} />;
    }

    return (
        <div className="igr:space-y-4">
            <FieldError message={actions.review.isError ? errorMessage(actions.review.error, t) : undefined} />
            {compliance === null || cost === null || actions.review.isPending ? (
                <div className="igr:space-y-3" aria-busy="true">
                    <p className="igr:text-[13px] igr:text-muted-foreground">{t('campaigns.review.running')}</p>
                    <Skeleton className="igr:h-28" />
                    <Skeleton className="igr:h-40" />
                </div>
            ) : (
                <>
                    {!compliance.channel_available ? (
                        <p className="igr:flex igr:items-start igr:gap-1.5 igr:rounded-md igr:border igr:border-destructive/40 igr:bg-destructive/5 igr:p-3 igr:text-[13px] igr:text-destructive">
                            <TriangleAlert className="igr:mt-0.5 igr:size-4 igr:shrink-0" aria-hidden="true" />
                            {t('campaigns.review.noChannel')}
                        </p>
                    ) : null}
                    <div className="igr:grid igr:gap-3 igr:sm:grid-cols-4">
                        <Tile label={t('campaigns.review.audience')} value={formatNumber(compliance.audience, workspace.locale)} />
                        <Tile label={t('campaigns.review.willSend')} value={formatNumber(compliance.will_send, workspace.locale)} strong />
                        <Tile label={t('campaigns.review.excluded')} value={formatNumber(compliance.excluded_total, workspace.locale)} />
                        <Tile label={t('campaigns.review.holdout')} value={formatNumber(compliance.holdout, workspace.locale)} />
                    </div>
                    {compliance.estimated ? <p className="igr:text-xs igr:text-muted-foreground">{t('campaigns.review.estimated', { n: formatNumber(compliance.checked, workspace.locale) })}</p> : null}
                    <div className="igr:grid igr:gap-4 igr:lg:grid-cols-2">
                        <Card>
                            <CardHeader>
                                <CardTitle className="igr:flex igr:items-center igr:gap-2">
                                    <ShieldCheck className="igr:size-4 igr:text-muted-foreground" aria-hidden="true" />
                                    {t('campaigns.review.compliance')}
                                </CardTitle>
                                <CardDescription>{t('campaigns.review.complianceHelp')}</CardDescription>
                            </CardHeader>
                            <CardContent className="igr:space-y-3">
                                <ReasonList title={t('campaigns.review.excludedWhy')} items={compliance.excluded} empty={t('campaigns.review.noneExcluded')} />
                                <ReasonList title={t('campaigns.review.deferredWhy')} items={compliance.deferred} empty={t('campaigns.review.noneDeferred')} />
                                {compliance.first_send_at ? (
                                    <p className="igr:text-xs igr:text-muted-foreground">
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
                                <p className="igr:text-2xl igr:font-semibold igr:tabular-nums">
                                    {formatMoney(cost.total_usd, 'USD', workspace.locale)}
                                    {cost.total_local !== null && cost.currency !== 'USD' ? (
                                        <span className="igr:ml-2 igr:text-sm igr:font-normal igr:text-muted-foreground">≈ {formatMoney(cost.total_local, cost.currency, workspace.locale)}</span>
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
                                                    <TD className="igr:text-right igr:tabular-nums">{r.rate_usd !== null ? `US$ ${r.rate_usd.toLocaleString(workspace.locale, { minimumFractionDigits: 4, maximumFractionDigits: 4 })}` : '—'}</TD>
                                                    <TD className="igr:text-right igr:tabular-nums">{formatMoney(r.total_usd, 'USD', workspace.locale)}</TD>
                                                </TR>
                                            ))}
                                        </TBody>
                                    </Table>
                                ) : null}
                                {cost.channel === 'whatsapp' ? (
                                    <p className={cn('igr:text-xs', cost.rates_stale ? 'igr:text-warning' : 'igr:text-muted-foreground')}>
                                        {cost.rates_stale
                                            ? t('campaigns.review.ratesStale', { date: formatDate(cost.rates_effective_from, workspace.locale, 'UTC') })
                                            : t('campaigns.review.ratesFrom', { date: formatDate(cost.rates_effective_from, workspace.locale, 'UTC') })}
                                    </p>
                                ) : null}
                            </CardContent>
                        </Card>
                    </div>
                    <div className="igr:flex igr:flex-wrap igr:items-center igr:justify-end igr:gap-2">
                        <span className="igr:mr-auto igr:text-xs igr:text-muted-foreground">
                            {t('campaigns.review.checkedAt', { when: formatDateTime(compliance.checked_at, workspace.locale, workspace.timezone) })}
                        </span>
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
                    <FieldError message={actions.schedule.isError ? errorMessage(actions.schedule.error, t) : undefined} />
                </>
            )}
        </div>
    );
}

function Tile({ label, value, strong }: { label: string; value: string; strong?: boolean }) {
    return (
        <Card className="igr:p-3">
            <p className="igr:text-xs igr:text-muted-foreground">{label}</p>
            <p className={cn('igr:mt-1 igr:text-xl igr:tabular-nums', strong ? 'igr:font-semibold' : 'igr:font-medium')}>{value}</p>
        </Card>
    );
}

function ReasonList({ title, items, empty }: { title: string; items: { reason: string; label: string; count: number }[]; empty: string }) {
    const { workspace } = useWorkspace();
    return (
        <div>
            <h4 className="igr:mb-1 igr:text-xs igr:font-medium igr:text-muted-foreground">{title}</h4>
            {items.length === 0 ? (
                <p className="igr:text-[13px]">{empty}</p>
            ) : (
                <ul className="igr:space-y-1">
                    {items.map((i) => (
                        <li key={i.reason} className="igr:flex igr:items-center igr:justify-between igr:gap-2 igr:text-[13px]">
                            <span>{i.label}</span>
                            <span className="igr:tabular-nums">{formatNumber(i.count, workspace.locale)}</span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
