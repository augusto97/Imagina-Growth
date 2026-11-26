import {
    ArrowLeft,
    Bot,
    CalendarCheck,
    Check,
    CircleAlert,
    Clock,
    Hand,
    MessageCircleQuestion,
    MessagesSquare,
    RotateCcw,
    Send,
    Sparkles,
    TicketPercent,
    ThumbsDown,
    UserCheck,
    Wrench,
} from 'lucide-react';
import type { TFunction } from 'i18next';
import { type ReactNode, type SyntheticEvent, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Link, useSearchParams } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Callout } from '@/components/ui/callout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { IconTile, type IconTone } from '@/components/ui/icon-tile';
import { Input, Select, Textarea } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ErrorState, FieldError, PageHeader } from '@/components/ui/misc';
import { Skeleton, SkeletonCard } from '@/components/ui/skeleton';
import { StatCard, StatCardSkeleton } from '@/components/ui/stat-card';
import { Switch } from '@/components/ui/switch';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { itemVisible, navContext } from '@/components/layout/nav';
import { useActivateStrategy } from '@/features/strategies/api';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { cn } from '@/lib/cn';
import { formatDateTime, formatMoney, formatPercent } from '@/lib/format';
import { toast } from '@/stores/toast';
import { errorMessage } from '@/features/contacts/errors';
import { agentKeys, useAgentReview, useAgentSettings, useAgentTurn, useReviewTurn, useSandbox, useSaveAgentSettings } from './api';
import { appendSandboxReply, hoursSummary, parseKeywords, reasonKey, settingsPayload, statusBadge } from './logic';
import { type AgentSettings, type AgentSettingsResponse, type ChatMessage, POLICY_KEYS, type PolicyKey, type ReviewFilter, type SandboxResult, type TurnRow } from './types';

const TABS = ['configurar', 'revision'] as const;
type Tab = (typeof TABS)[number];

/** Las políticas de una tienda (envíos y devoluciones) no aplican a un negocio de servicios. */
const STORE_POLICIES: PolicyKey[] = ['shipping', 'returns'];

/**
 * Agente con IA de la bandeja (M11, inteligencia.md §6.7): qué hace y si está prendido, la configuración en palabras
 * (qué responde, cómo habla, cuándo atiende, cuándo pasa a tu equipo, descuentos en tienda y, en servicios, calificar
 * y agendar en el chat) con una prueba en vivo al lado, y lo que respondió para revisarlo.
 */
export function AgentPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const settings = useAgentSettings(workspace.id);
    const [params, setParams] = useSearchParams();
    const canManage = settings.data?.can_manage ?? false;
    const raw = params.get('tab');
    // Las URL de antes (?tab=ajustes, ?tab=probar) abren la configuración con la prueba al lado.
    const tab: Tab = raw === 'revision' || (!canManage && settings.data !== undefined) ? 'revision' : 'configurar';
    const setTab = (next: string) => {
        const p = new URLSearchParams(params);
        if (next === 'configurar') p.delete('tab');
        else p.set('tab', next);
        setParams(p, { replace: true });
    };

    return (
        <>
            <PageHeader
                title={t('agent.page.title')}
                description={t('agent.page.description')}
                actions={
                    <Button asChild variant="outline">
                        <Link to={`/w/${workspace.id}/bandeja`}>
                            <ArrowLeft aria-hidden />
                            {t('agent.backToInbox')}
                        </Link>
                    </Button>
                }
            />
            {settings.isError ? <ErrorState error={settings.error} onRetry={() => void settings.refetch()} /> : null}
            {settings.isPending ? (
                <div className="igr:space-y-4">
                    <Skeleton className="igr:h-44 igr:rounded-2xl" />
                    <SkeletonCard />
                </div>
            ) : null}
            {settings.data ? (
                <Tabs value={tab} onValueChange={setTab}>
                    <TabsList>
                        {TABS.filter((k) => canManage || k === 'revision').map((k) => (
                            <TabsTrigger key={k} value={k}>
                                {t(`agent.page.tabs.${k}`)}
                            </TabsTrigger>
                        ))}
                    </TabsList>
                    {canManage ? (
                        <TabsContent value="configurar" className="igr:mt-5">
                            <SettingsEditor key={settings.data.settings.updated_at ?? 'nuevo'} data={settings.data} />
                        </TabsContent>
                    ) : null}
                    <TabsContent value="revision" className="igr:mt-5">
                        {canManage ? null : <StatusSummary data={settings.data} />}
                        <ReviewTab />
                    </TabsContent>
                </Tabs>
            ) : null}
        </>
    );
}

/** Para quien no administra la bandeja: el estado del agente en una línea. */
function StatusSummary({ data }: { data: AgentSettingsResponse }) {
    const { t } = useTranslation();
    const s = data.settings;
    const on = s.enabled && s.default_mode !== 'off';
    return (
        <Callout tone={on ? 'brand' : 'neutral'} icon={Bot} className="igr:mb-4" title={on ? t(`agent.page.state.${s.default_mode}`) : t('agent.page.state.off')}>
            {t('agent.page.askAdmin')}
        </Callout>
    );
}

/** ¿Vende en línea? Los descuentos, el envío y las devoluciones son de tienda. */
function useSellsOnline(): boolean {
    const { workspace } = useWorkspace();
    return itemVisible({ audience: 'store' }, navContext({ ...workspace, abilities: [] }));
}

/** ¿Atiende servicios? (citas, leads): el agente califica y agenda en el chat. */
function useServesClients(): boolean {
    const { workspace } = useWorkspace();
    return itemVisible({ audience: 'services' }, navContext({ ...workspace, abilities: [] }));
}

function Section({ icon, tone, title, description, children }: { icon: typeof Bot; tone: IconTone; title: ReactNode; description?: ReactNode; children: ReactNode }) {
    return (
        <Card>
            <CardHeader className="igr:flex-row igr:items-start igr:gap-3">
                <IconTile icon={icon} tone={tone} />
                <div className="igr:min-w-0">
                    <CardTitle>{title}</CardTitle>
                    {description ? <CardDescription className="igr:mt-0.5">{description}</CardDescription> : null}
                </div>
            </CardHeader>
            <CardContent className="igr:space-y-4 igr:pt-3">{children}</CardContent>
        </Card>
    );
}

function SettingsEditor({ data }: { data: AgentSettingsResponse }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const save = useSaveAgentSettings(workspace.id);
    const store = useSellsOnline();
    const services = useServesClients();
    const [s, setS] = useState<AgentSettings>(data.settings);
    const [keywords, setKeywords] = useState(data.settings.handoff_rules.keywords.join(', '));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [dirty, setDirty] = useState(false);
    const set = (patch: Partial<AgentSettings>) => {
        setDirty(true);
        setS((prev) => ({ ...prev, ...patch }));
    };
    const hours = s.handoff_rules.hours;
    const setHours = (patch: Partial<AgentSettings['handoff_rules']['hours']>) => set({ handoff_rules: { ...s.handoff_rules, hours: { ...hours, ...patch } } });
    const discount = s.discount_rules;
    const setDiscount = (patch: Partial<AgentSettings['discount_rules']>) => set({ discount_rules: { ...discount, ...patch } });
    const on = s.enabled && s.default_mode !== 'off';
    const schedule = data.business_hours ?? hours;
    const policies = POLICY_KEYS.filter((k) => store || !STORE_POLICIES.includes(k));

    const submit = (e?: SyntheticEvent) => {
        e?.preventDefault();
        setErrors({});
        const payload = settingsPayload({ ...s, handoff_rules: { ...s.handoff_rules, keywords: parseKeywords(keywords) } });
        save.mutate(payload, {
            onSuccess: () => {
                setDirty(false);
                toast.success(t('agent.settings.saved'));
            },
            onError: (err) => {
                if (isApiError(err, 'validation')) setErrors(err.fieldErrors);
                toast.error(errorMessage(err, t));
            },
        });
    };
    // Prender sin modo deja al agente respondiendo solo (lo que la persona espera al prenderlo).
    const toggle = (value: boolean) => set({ enabled: value, default_mode: value && s.default_mode === 'off' ? 'auto' : s.default_mode });

    return (
        <form onSubmit={submit} className="igr:space-y-5 igr:pb-20">
            <Notices data={data} on={on} />
            <section className={cn('igr:overflow-hidden igr:rounded-2xl igr:border igr:shadow-sm', on ? 'igr:hero-surface igr:border-primary/15' : 'igr:bg-card')}>
                <div className="igr:grid igr:gap-6 igr:p-5 igr:sm:p-7 igr:lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]">
                    <div className="igr:min-w-0">
                        <div className="igr:flex igr:items-center igr:gap-3">
                            <IconTile icon={Bot} tone={on ? 'brand' : 'slate'} size="lg" />
                            <div className="igr:min-w-0">
                                <p className="igr:text-caption igr:font-semibold igr:tracking-wide igr:text-muted-foreground igr:uppercase">{t('agent.page.eyebrow')}</p>
                                <h2 className="igr:text-section igr:font-semibold igr:tracking-tight">{on ? t(`agent.page.state.${s.default_mode}`) : t('agent.page.state.off')}</h2>
                            </div>
                        </div>
                        <p className="igr:mt-3 igr:text-body igr:text-muted-foreground">{t(store ? 'agent.page.what' : 'agent.page.whatServices')}</p>
                        <ul className="igr:mt-3 igr:space-y-1.5 igr:text-support">
                            {(['answers', store ? 'sells' : 'books', 'handoff'] as const).map((k) => (
                                <li key={k} className="igr:flex igr:gap-2">
                                    <Check className="igr:mt-0.5 igr:size-4 igr:shrink-0 igr:text-success" aria-hidden />
                                    <span>{t(`agent.page.does.${k}`)}</span>
                                </li>
                            ))}
                        </ul>
                        <p className="igr:mt-4 igr:flex igr:items-start igr:gap-2 igr:text-support igr:text-muted-foreground">
                            <MessagesSquare className="igr:mt-0.5 igr:size-4 igr:shrink-0" aria-hidden />
                            {(data.channels ?? []).length > 0
                                ? t('agent.page.channels', { list: (data.channels ?? []).map((c) => t(`agent.page.channel.${c}`, { defaultValue: c })).join(', ') })
                                : t('agent.page.noChannels')}
                        </p>
                    </div>
                    <div className="igr:space-y-4 igr:rounded-xl igr:border igr:bg-card/80 igr:p-4 igr:sm:p-5">
                        <Switch checked={s.enabled} onCheckedChange={toggle} label={t('agent.page.enable')} description={t('agent.page.enableHelp')} />
                        <fieldset disabled={!s.enabled} className="igr:space-y-2 igr:disabled:opacity-60">
                            <legend className="igr:mb-2 igr:text-support igr:font-medium">{t('agent.page.modeTitle')}</legend>
                            <div className="igr:grid igr:gap-2 igr:sm:grid-cols-2">
                                {(['auto', 'assist'] as const).map((mode) => (
                                    <label
                                        key={mode}
                                        className={cn(
                                            'igr:flex igr:cursor-pointer igr:gap-2.5 igr:rounded-lg igr:border igr:bg-card igr:p-3 igr:transition-colors igr:has-[:focus-visible]:ring-2 igr:has-[:focus-visible]:ring-ring',
                                            s.default_mode === mode ? 'igr:border-primary igr:bg-brand-soft/50' : 'igr:hover:bg-accent/50',
                                        )}
                                    >
                                        <input type="radio" name="agent-mode" value={mode} checked={s.default_mode === mode} onChange={() => set({ default_mode: mode })} className="igr:mt-0.5 igr:accent-primary" />
                                        <span className="igr:min-w-0">
                                            <span className="igr:flex igr:items-center igr:gap-1.5 igr:text-support igr:font-medium">
                                                {mode === 'auto' ? <Bot className="igr:size-3.5" aria-hidden /> : <Sparkles className="igr:size-3.5" aria-hidden />}
                                                {t(`agent.page.mode.${mode}`)}
                                            </span>
                                            <span className="igr:mt-0.5 igr:block igr:text-xs igr:text-muted-foreground">{t(`agent.page.modeHelp.${mode}`)}</span>
                                        </span>
                                    </label>
                                ))}
                            </div>
                        </fieldset>
                    </div>
                </div>
            </section>

            <div className="igr:grid igr:items-start igr:gap-5 igr:xl:grid-cols-[minmax(0,1fr)_400px]">
                <div className="igr:min-w-0 igr:space-y-5">
                    <Section icon={MessageCircleQuestion} tone="indigo" title={t('agent.page.knows.title')} description={t(store ? 'agent.page.knows.description' : 'agent.page.knows.descriptionServices')}>
                        <div>
                            <Label htmlFor="agent-scope">{t('agent.page.knows.scope')}</Label>
                            <Textarea id="agent-scope" className="igr:mt-1.5" rows={2} maxLength={500} placeholder={t(store ? 'agent.settings.scopePlaceholder' : 'agent.page.knows.scopePlaceholderServices')} value={s.scope ?? ''} onChange={(e) => set({ scope: e.target.value })} />
                            <FieldError message={errors.scope} />
                        </div>
                        <div className="igr:grid igr:gap-3 igr:md:grid-cols-2">
                            {policies.map((key) => (
                                <div key={key} className={key === 'other' && policies.length % 2 === 1 ? 'igr:md:col-span-2' : undefined}>
                                    <Label htmlFor={`agent-policy-${key}`}>{t(`agent.policies.${key}`)}</Label>
                                    <Textarea
                                        id={`agent-policy-${key}`}
                                        className="igr:mt-1.5"
                                        rows={2}
                                        maxLength={1500}
                                        placeholder={t(store ? `agent.policyPlaceholders.${key}` : `agent.page.knows.placeholders.${key}`, { defaultValue: t(`agent.policyPlaceholders.${key}`) })}
                                        value={s.policies[key]}
                                        onChange={(e) => set({ policies: { ...s.policies, [key]: e.target.value } })}
                                    />
                                </div>
                            ))}
                        </div>
                        <p className="igr:text-xs igr:text-muted-foreground">{t('agent.settings.policiesDescription')}</p>
                    </Section>

                    <Section icon={Sparkles} tone="violet" title={t('agent.page.tone.title')} description={t('agent.page.tone.description')}>
                        <div>
                            <Label htmlFor="agent-tone">{t('agent.settings.tone')}</Label>
                            <Input id="agent-tone" className="igr:mt-1.5" maxLength={255} placeholder={t('agent.settings.tonePlaceholder')} value={s.tone ?? ''} onChange={(e) => set({ tone: e.target.value })} />
                            <div className="igr:mt-2 igr:flex igr:flex-wrap igr:gap-1.5">
                                {(['warm', 'formal', 'brief'] as const).map((k) => (
                                    <Button key={k} type="button" size="sm" variant="outline" className="igr:h-7 igr:rounded-full" onClick={() => set({ tone: t(`agent.page.tone.examples.${k}`) })}>
                                        {t(`agent.page.tone.examples.${k}`)}
                                    </Button>
                                ))}
                            </div>
                        </div>
                    </Section>

                    <Section icon={Clock} tone="sky" title={t('agent.page.hours.title')} description={t('agent.page.hours.description')}>
                        <div className="igr:grid igr:gap-2 igr:sm:grid-cols-2" role="radiogroup" aria-label={t('agent.page.hours.title')}>
                            {(['always', 'out_of_hours'] as const).map((mode) => (
                                <label
                                    key={mode}
                                    className={cn(
                                        'igr:flex igr:cursor-pointer igr:gap-2.5 igr:rounded-lg igr:border igr:p-3 igr:transition-colors igr:has-[:focus-visible]:ring-2 igr:has-[:focus-visible]:ring-ring',
                                        hours.mode === mode ? 'igr:border-primary igr:bg-brand-soft/50' : 'igr:hover:bg-accent/50',
                                    )}
                                >
                                    <input type="radio" name="agent-hours" checked={hours.mode === mode} onChange={() => setHours({ mode })} className="igr:mt-0.5 igr:accent-primary" />
                                    <span>
                                        <span className="igr:block igr:text-support igr:font-medium">{t(`agent.page.hours.${mode}`)}</span>
                                        <span className="igr:mt-0.5 igr:block igr:text-xs igr:text-muted-foreground">{t(`agent.page.hours.${mode}Help`)}</span>
                                    </span>
                                </label>
                            ))}
                        </div>
                        <div className="igr:flex igr:flex-wrap igr:items-center igr:justify-between igr:gap-2 igr:rounded-lg igr:bg-muted/60 igr:px-3 igr:py-2.5 igr:text-support">
                            <span>
                                <span className="igr:font-medium">{t('agent.page.hours.yours')}</span> {hoursSummary(t, schedule)}
                                {data.business_hours ? null : <span className="igr:text-muted-foreground"> · {t('agent.page.hours.notSet')}</span>}
                            </span>
                            <Link to={`/w/${workspace.id}/ajustes#horario`} className="igr:font-medium igr:text-primary igr:underline-offset-4 igr:hover:underline">
                                {t('agent.page.hours.change')}
                            </Link>
                        </div>
                        <FieldError message={errors['handoff_rules.hours.end']} />
                    </Section>

                    <Section icon={Hand} tone="amber" title={t('agent.page.handoff.title')} description={t('agent.page.handoff.description')}>
                        <ul className="igr:grid igr:gap-1.5 igr:text-support igr:sm:grid-cols-2">
                            {(['asks', 'unknown', 'legal', 'window'] as const).map((k) => (
                                <li key={k} className="igr:flex igr:gap-2">
                                    <UserCheck className="igr:mt-0.5 igr:size-4 igr:shrink-0 igr:text-muted-foreground" aria-hidden />
                                    {t(`agent.page.handoff.always.${k}`)}
                                </li>
                            ))}
                        </ul>
                        <Switch
                            checked={s.handoff_rules.intents.includes('complaint')}
                            onCheckedChange={(v) => set({ handoff_rules: { ...s.handoff_rules, intents: v ? ['complaint'] : [] } })}
                            label={t('agent.settings.complaints')}
                            description={t('agent.page.handoff.complaintsHelp')}
                        />
                        <div>
                            <Label htmlFor="agent-keywords">{t('agent.settings.keywords')}</Label>
                            <Input id="agent-keywords" className="igr:mt-1.5" value={keywords} onChange={(e) => { setKeywords(e.target.value); setDirty(true); }} />
                            <p className="igr:mt-1 igr:text-xs igr:text-muted-foreground">{t('agent.settings.keywordsHelp')}</p>
                        </div>
                    </Section>

                    {services && data.services ? <ServicesSection data={data} /> : null}

                    {store ? (
                        <Section icon={TicketPercent} tone="rose" title={t('agent.settings.discountTitle')} description={t('agent.settings.discountDescription')}>
                            <Switch checked={discount.enabled} onCheckedChange={(v) => setDiscount({ enabled: v })} label={t('agent.settings.discountEnabled')} />
                            {discount.enabled ? (
                                <div className="igr:grid igr:gap-3 igr:md:grid-cols-2">
                                    <div>
                                        <Label htmlFor="agent-coupon">{t('agent.settings.couponCode')}</Label>
                                        <Input id="agent-coupon" className="igr:mt-1.5" maxLength={40} value={discount.coupon_code ?? ''} onChange={(e) => setDiscount({ coupon_code: e.target.value })} />
                                        <FieldError message={errors['discount_rules.coupon_code']} />
                                        <p className="igr:mt-1 igr:text-xs igr:text-muted-foreground">{t('agent.settings.couponHelp')}</p>
                                    </div>
                                    <div>
                                        <Label htmlFor="agent-min">{t('agent.settings.minSubtotal')}</Label>
                                        <Input id="agent-min" className="igr:mt-1.5" type="number" min={0} value={discount.min_subtotal ?? ''} onChange={(e) => setDiscount({ min_subtotal: e.target.value })} />
                                    </div>
                                    <div className="igr:md:col-span-2">
                                        <Label htmlFor="agent-discount-desc">{t('agent.settings.discountText')}</Label>
                                        <Input id="agent-discount-desc" className="igr:mt-1.5" maxLength={160} placeholder={t('agent.settings.discountPlaceholder')} value={discount.description ?? ''} onChange={(e) => setDiscount({ description: e.target.value })} />
                                        <FieldError message={errors['discount_rules.description']} />
                                    </div>
                                </div>
                            ) : null}
                        </Section>
                    ) : null}

                    <details className="igr:group igr:rounded-xl igr:border igr:bg-card igr:px-5 igr:py-3">
                        <summary className="igr:cursor-pointer igr:text-support igr:font-medium igr:text-muted-foreground igr:outline-none igr:focus-visible:text-foreground">{t('agent.page.advanced')}</summary>
                        <div className="igr:mt-3">
                            <Label htmlFor="agent-steps">{t('agent.settings.maxSteps')}</Label>
                            <Input id="agent-steps" className="igr:mt-1.5 igr:w-24" type="number" min={2} max={10} value={s.max_steps} onChange={(e) => set({ max_steps: Number(e.target.value) })} />
                            <p className="igr:mt-1 igr:text-xs igr:text-muted-foreground">{t('agent.settings.maxStepsHelp')}</p>
                        </div>
                    </details>
                </div>
                <div className="igr:xl:sticky igr:xl:top-4">
                    <SandboxCard data={data} dirty={dirty} />
                </div>
            </div>

            {/* La barra de guardar queda a la vista (y al alcance del pulgar) mientras hay cambios. */}
            <div className={cn('igr:sticky igr:bottom-3 igr:z-10 igr:flex igr:justify-end', !dirty && 'igr:pointer-events-none igr:opacity-0')} aria-hidden={!dirty}>
                <div className="igr:flex igr:w-full igr:flex-wrap igr:items-center igr:justify-between igr:gap-3 igr:rounded-xl igr:border igr:bg-card igr:px-4 igr:py-3 igr:shadow-lg igr:sm:w-auto">
                    <span className="igr:text-support igr:text-muted-foreground">{t('agent.page.unsaved')}</span>
                    <Button type="submit" loading={save.isPending} tabIndex={dirty ? undefined : -1}>
                        {t('agent.page.save')}
                    </Button>
                </div>
            </div>
        </form>
    );
}

/** Avisos que impiden que el agente responda (sin IA, sin datos de la tienda) o que está apagado. */
function Notices({ data, on }: { data: AgentSettingsResponse; on: boolean }) {
    const { t } = useTranslation();
    const store = useSellsOnline();
    return (
        <>
            {!data.ai_available ? (
                <Callout tone="warning" title={t('agent.page.notices.noAiTitle')}>
                    {t('agent.notices.noAi')}
                </Callout>
            ) : null}
            {store && !data.store_connected ? (
                <Callout tone="warning" title={t('agent.page.notices.noStoreTitle')}>
                    {t('agent.notices.noStore')}
                </Callout>
            ) : null}
            {!on && data.ai_available ? (
                <Callout tone="info" title={t('agent.page.notices.offTitle')}>
                    {t('agent.page.notices.off')}
                </Callout>
            ) : null}
        </>
    );
}

/** Servicios: el agente hace tus preguntas para calificar al cliente y ofrece citas con tu agenda, sin salir del chat. */
function ServicesSection({ data }: { data: AgentSettingsResponse }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const qc = useQueryClient();
    const canLeads = useCan('manageServices');
    const activate = useActivateStrategy(workspace.id);
    const q = data.services?.qualification ?? { sets: 0, active: 0 };
    const b = data.services?.booking ?? { module: false, services: 0 };
    const turnOn = () =>
        activate.mutate(
            { key: 'chat_qualification' },
            {
                onSuccess: () => {
                    void qc.invalidateQueries({ queryKey: agentKeys.settings(workspace.id) });
                    toast.success(t('agent.page.services.qualificationOn'));
                },
                onError: (e) => toast.error(errorMessage(e, t)),
            },
        );
    return (
        <Section icon={CalendarCheck} tone="teal" title={t('agent.page.services.title')} description={t('agent.page.services.description')}>
            <div className="igr:grid igr:gap-3 igr:md:grid-cols-2">
                <div className="igr:flex igr:flex-col igr:gap-2 igr:rounded-lg igr:border igr:p-3">
                    <div className="igr:flex igr:items-center igr:justify-between igr:gap-2">
                        <p className="igr:text-support igr:font-medium">{t('agent.page.services.qualification')}</p>
                        <Badge variant={q.active > 0 ? 'success' : 'outline'}>{q.active > 0 ? t('agent.page.services.on') : t('agent.page.services.off')}</Badge>
                    </div>
                    <p className="igr:flex-1 igr:text-xs igr:text-muted-foreground">
                        {q.active > 0 ? t('agent.page.services.qualificationActive', { count: q.active }) : t('agent.page.services.qualificationHelp')}
                    </p>
                    <div className="igr:flex igr:flex-wrap igr:gap-2">
                        {q.active === 0 && canLeads ? (
                            <Button type="button" size="sm" loading={activate.isPending} onClick={turnOn}>
                                {t('agent.page.services.qualificationTurnOn')}
                            </Button>
                        ) : null}
                        <Button asChild size="sm" variant="outline">
                            <Link to={`/w/${workspace.id}/leads?tab=calificacion`}>{t(q.sets > 0 ? 'agent.page.services.editQuestions' : 'agent.page.services.writeQuestions')}</Link>
                        </Button>
                    </div>
                </div>
                <div className="igr:flex igr:flex-col igr:gap-2 igr:rounded-lg igr:border igr:p-3">
                    <div className="igr:flex igr:items-center igr:justify-between igr:gap-2">
                        <p className="igr:text-support igr:font-medium">{t('agent.page.services.booking')}</p>
                        <Badge variant={b.module && b.services > 0 ? 'success' : 'outline'}>{b.module && b.services > 0 ? t('agent.page.services.on') : t('agent.page.services.off')}</Badge>
                    </div>
                    <p className="igr:flex-1 igr:text-xs igr:text-muted-foreground">
                        {!b.module ? t('agent.page.services.bookingNoPlan') : b.services > 0 ? t('agent.page.services.bookingActive', { count: b.services }) : t('agent.page.services.bookingHelp')}
                    </p>
                    <div>
                        <Button asChild size="sm" variant="outline">
                            <Link to={`/w/${workspace.id}/${b.module ? 'agenda?pestana=servicios' : 'facturacion'}`}>
                                {t(!b.module ? 'agent.page.services.seePlans' : b.services > 0 ? 'agent.page.services.editServices' : 'agent.page.services.addServices')}
                            </Link>
                        </Button>
                    </div>
                </div>
            </div>
        </Section>
    );
}

function SandboxCard({ data, dirty }: { data: AgentSettingsResponse; dirty: boolean }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const store = useSellsOnline();
    const sandbox = useSandbox(workspace.id);
    const [history, setHistory] = useState<ChatMessage[]>([]);
    const [draft, setDraft] = useState('');
    const [last, setLast] = useState<SandboxResult | null>(null);
    const examples: readonly string[] = store ? ['price', 'shipping', 'human'] : ['price', 'book', 'human'];

    const ask = (text: string) => {
        if (text.trim() === '' || sandbox.isPending) return;
        const next: ChatMessage[] = [...history, { role: 'user', content: text.trim() }];
        setHistory(next);
        setDraft('');
        sandbox.mutate(next.slice(-20), {
            onSuccess: (r) => {
                setLast(r);
                setHistory(appendSandboxReply(next, r, t('agent.sandbox.handoffReply', { reason: reasonLabel(t, r.handoff_reason ?? '') })));
            },
            onError: (err) => toast.error(errorMessage(err, t)),
        });
    };

    return (
        <Card className="igr:flex igr:flex-col igr:overflow-hidden">
            <CardHeader className="igr:border-b igr:pb-4">
                <CardTitle className="igr:flex igr:items-center igr:gap-2">
                    <span className="igr:relative igr:flex igr:size-2">
                        <span className="igr:absolute igr:inline-flex igr:size-full igr:animate-ping igr:rounded-full igr:bg-success/60 igr:motion-reduce:animate-none" />
                        <span className="igr:relative igr:inline-flex igr:size-2 igr:rounded-full igr:bg-success" />
                    </span>
                    {t('agent.page.test.title')}
                </CardTitle>
                <CardDescription>{t(store ? 'agent.sandbox.description' : 'agent.page.test.descriptionServices')}</CardDescription>
            </CardHeader>
            <CardContent className="igr:flex igr:flex-1 igr:flex-col igr:gap-3 igr:pt-4">
                {dirty ? (
                    <p className="igr:rounded-md igr:bg-warning/10 igr:px-2.5 igr:py-1.5 igr:text-xs igr:text-warning">{t('agent.page.test.unsaved')}</p>
                ) : null}
                <ol className="igr:flex igr:min-h-64 igr:flex-col igr:gap-2 igr:rounded-lg igr:bg-muted/40 igr:p-3 igr:xl:max-h-[50vh] igr:xl:overflow-y-auto" aria-live="polite" aria-label={t('agent.sandbox.conversation')}>
                    {history.length === 0 ? (
                        <li className="igr:m-auto igr:max-w-60 igr:text-center igr:text-support igr:text-muted-foreground">{t('agent.page.test.empty')}</li>
                    ) : null}
                    {history.map((m, i) => (
                        <li
                            key={i}
                            className={
                                m.role === 'user'
                                    ? 'igr:max-w-[85%] igr:self-end igr:rounded-2xl igr:rounded-br-md igr:bg-primary igr:px-3 igr:py-2 igr:text-[13px] igr:text-primary-foreground'
                                    : 'igr:max-w-[85%] igr:self-start igr:rounded-2xl igr:rounded-bl-md igr:border igr:bg-card igr:px-3 igr:py-2 igr:text-[13px] igr:whitespace-pre-wrap'
                            }
                        >
                            {m.content}
                        </li>
                    ))}
                    {sandbox.isPending ? <li className="igr:self-start igr:text-xs igr:text-muted-foreground">{t('agent.sandbox.thinking')}</li> : null}
                </ol>
                {last ? (
                    <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-1.5 igr:text-xs igr:text-muted-foreground">
                        <Badge variant={statusBadge(last.status)}>{t(`agent.status.${last.status}`)}</Badge>
                        <span className={last.validation?.ok === false || last.flagged ? 'igr:text-destructive' : 'igr:text-success'}>
                            {last.validation?.ok === false || last.flagged ? t('agent.page.test.unverified') : t('agent.page.test.verified')}
                        </span>
                        {last.tool_calls.length > 0 ? <span>· {t('agent.page.test.used', { list: last.tool_calls.map((c) => t(`agent.tools.${c.name}`, { defaultValue: c.name })).join(', ') })}</span> : null}
                    </div>
                ) : null}
                {history.length === 0 ? (
                    <div className="igr:flex igr:flex-wrap igr:gap-1.5">
                        {examples.map((k) => (
                            <Button key={k} type="button" size="sm" variant="outline" className="igr:h-auto igr:min-h-7 igr:rounded-full igr:py-1 igr:whitespace-normal igr:text-left" disabled={!data.ai_available} onClick={() => ask(t(`agent.page.test.examples.${store ? 'store' : 'services'}.${k}`))}>
                                {t(`agent.page.test.examples.${store ? 'store' : 'services'}.${k}`)}
                            </Button>
                        ))}
                    </div>
                ) : null}
                <div className="igr:flex igr:gap-2">
                    <Input
                        aria-label={t('agent.sandbox.message')}
                        placeholder={t(store ? 'agent.sandbox.placeholder' : 'agent.page.test.placeholderServices')}
                        value={draft}
                        maxLength={1000}
                        onChange={(e) => setDraft(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                ask(draft);
                            }
                        }}
                        disabled={!data.ai_available}
                    />
                    <Button type="button" size="icon" loading={sandbox.isPending} disabled={!data.ai_available || draft.trim() === ''} onClick={() => ask(draft)} aria-label={t('agent.sandbox.send')}>
                        <Send aria-hidden />
                    </Button>
                    <Button
                        type="button"
                        size="icon"
                        variant="outline"
                        onClick={() => {
                            setHistory([]);
                            setLast(null);
                        }}
                        disabled={history.length === 0}
                        aria-label={t('agent.sandbox.reset')}
                    >
                        <RotateCcw aria-hidden />
                    </Button>
                </div>
                {!data.ai_available ? <p className="igr:text-xs igr:text-muted-foreground">{t('agent.page.test.noAi')}</p> : null}
            </CardContent>
        </Card>
    );
}

function ReviewTab() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const store = useSellsOnline();
    const [filter, setFilter] = useState<ReviewFilter>('all');
    const [days, setDays] = useState(30);
    const [page, setPage] = useState(1);
    const [open, setOpen] = useState<string | null>(null);
    const review = useAgentReview(workspace.id, filter, days, page);
    const mark = useReviewTurn(workspace.id);
    const locale = workspace.locale;

    if (review.isError) return <ErrorState error={review.error} onRetry={() => void review.refetch()} />;
    const s = review.data?.summary;
    const onMark = (row: TurnRow, status: 'ok' | 'wrong') =>
        mark.mutate({ id: row.id, status }, { onSuccess: () => toast.success(t('agent.review.marked')), onError: (e) => toast.error(errorMessage(e, t)) });

    return (
        <div className="igr:space-y-5">
            <div className="igr:grid igr:gap-3 igr:sm:grid-cols-2 igr:lg:grid-cols-4">
                {s ? (
                    <>
                        <StatCard label={t('agent.review.kpis.conversations')} value={s.conversations.toLocaleString(locale)} hint={t('agent.review.kpis.turns', { count: s.turns })} />
                        <StatCard label={t('agent.review.kpis.replied')} value={s.replied.toLocaleString(locale)} hint={t('agent.review.kpis.suggested', { count: s.suggested })} />
                        <StatCard
                            label={t('agent.review.kpis.handoffRate')}
                            value={s.handoff_rate === null ? '—' : formatPercent(s.handoff_rate, locale, 1)}
                            hint={t('agent.review.kpis.handoffs', { count: s.handoff })}
                            help={t('agent.page.review.handoffHelp')}
                        />
                        {store ? (
                            <StatCard label={t('agent.review.kpis.sales')} value={formatMoney(s.revenue, s.currency, locale)} hint={t('agent.review.kpis.orders', { count: s.orders })} />
                        ) : (
                            <StatCard label={t('agent.review.kpis.flagged')} value={s.flagged.toLocaleString(locale)} hint={t('agent.review.kpis.flaggedHint')} />
                        )}
                    </>
                ) : (
                    Array.from({ length: 4 }, (_, i) => <StatCardSkeleton key={i} />)
                )}
            </div>
            <div className="igr:flex igr:flex-wrap igr:items-end igr:gap-3">
                <div>
                    <Label htmlFor="agent-filter">{t('agent.review.filter')}</Label>
                    <Select
                        id="agent-filter"
                        className="igr:mt-1.5 igr:w-52"
                        value={filter}
                        onChange={(e) => {
                            setFilter(e.target.value as ReviewFilter);
                            setPage(1);
                        }}
                    >
                        {(['all', 'flagged', 'handoff', 'replied', 'suggested', 'test'] as const).map((f) => (
                            <option key={f} value={f}>
                                {t(`agent.review.filters.${f}`)}
                            </option>
                        ))}
                    </Select>
                </div>
                <div>
                    <Label htmlFor="agent-days">{t('agent.review.period')}</Label>
                    <Select
                        id="agent-days"
                        className="igr:mt-1.5 igr:w-44"
                        value={days}
                        onChange={(e) => {
                            setDays(Number(e.target.value));
                            setPage(1);
                        }}
                    >
                        {[7, 30, 90].map((d) => (
                            <option key={d} value={d}>
                                {t('agent.review.lastDays', { count: d })}
                            </option>
                        ))}
                    </Select>
                </div>
            </div>
            {review.isPending ? (
                <Skeleton className="igr:h-64" />
            ) : review.data.data.length === 0 ? (
                <EmptyState illustration="conversations" title={t('agent.review.emptyTitle')} description={t('agent.review.emptyDescription')} />
            ) : (
                <Card>
                    <Table>
                        <THead>
                            <TR>
                                <TH>{t('agent.review.cols.when')}</TH>
                                <TH>{t('agent.review.cols.contact')}</TH>
                                <TH>{t('agent.review.cols.status')}</TH>
                                <TH>{t('agent.review.cols.tools')}</TH>
                                <TH>{t('agent.review.cols.numbers')}</TH>
                                <TH className="igr:text-right">{t('agent.review.cols.review')}</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {review.data.data.map((row) => (
                                <TR key={row.id}>
                                    <TD className="igr:text-xs igr:whitespace-nowrap igr:text-muted-foreground">{formatDateTime(row.created_at, locale, workspace.timezone)}</TD>
                                    <TD>
                                        <span className="igr:text-[13px]">{row.is_test ? t('agent.review.test') : (row.contact_name ?? '—')}</span>
                                        <span className="igr:ml-1.5 igr:text-xs igr:text-muted-foreground">{t(`agent.modes.${row.mode}`)}</span>
                                    </TD>
                                    <TD>
                                        <Badge variant={statusBadge(row.status)}>{t(`agent.status.${row.status}`)}</Badge>
                                        {row.handoff_reason ? <span className="igr:ml-1.5 igr:text-xs igr:text-muted-foreground">{reasonLabel(t, row.handoff_reason)}</span> : null}
                                    </TD>
                                    <TD className="igr:text-xs igr:text-muted-foreground">{row.tools.length > 0 ? row.tools.map((n) => t(`agent.tools.${n}`, { defaultValue: n })).join(', ') : '—'}</TD>
                                    <TD>
                                        {row.validation_ok ? (
                                            <span className="igr:inline-flex igr:items-center igr:gap-1 igr:text-xs igr:text-success">
                                                <Check className="igr:size-3.5" aria-hidden />
                                                {t('agent.review.verified')}
                                            </span>
                                        ) : (
                                            <span className="igr:inline-flex igr:items-center igr:gap-1 igr:text-xs igr:text-destructive">
                                                <CircleAlert className="igr:size-3.5" aria-hidden />
                                                {t('agent.review.unverified')}
                                            </span>
                                        )}
                                    </TD>
                                    <TD className="igr:text-right">
                                        <div className="igr:inline-flex igr:gap-1">
                                            <Button size="sm" variant="ghost" onClick={() => setOpen(row.id)}>
                                                {t('agent.review.details')}
                                            </Button>
                                            <Button size="icon" variant={row.review_status === 'ok' ? 'secondary' : 'ghost'} aria-label={t('agent.review.markOk')} onClick={() => onMark(row, 'ok')}>
                                                <Check aria-hidden />
                                            </Button>
                                            <Button size="icon" variant={row.review_status === 'wrong' ? 'secondary' : 'ghost'} aria-label={t('agent.review.markWrong')} onClick={() => onMark(row, 'wrong')}>
                                                <ThumbsDown aria-hidden />
                                            </Button>
                                        </div>
                                    </TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                    {review.data.meta.last_page > 1 ? (
                        <div className="igr:flex igr:items-center igr:justify-end igr:gap-2 igr:border-t igr:p-3 igr:text-xs igr:text-muted-foreground">
                            <Button size="sm" variant="outline" disabled={page <= 1} onClick={() => setPage(page - 1)}>
                                {t('agent.review.prev')}
                            </Button>
                            <span>{t('agent.review.page', { page: review.data.meta.page, last: review.data.meta.last_page })}</span>
                            <Button size="sm" variant="outline" disabled={page >= review.data.meta.last_page} onClick={() => setPage(page + 1)}>
                                {t('agent.review.next')}
                            </Button>
                        </div>
                    ) : null}
                </Card>
            )}
            <TurnDialog id={open} onClose={() => setOpen(null)} />
        </div>
    );
}

function reasonLabel(t: TFunction, reason: string): string {
    const key = reasonKey(reason);
    return key === null ? reason : t(`agent.reasons.${key}`);
}

function TurnDialog({ id, onClose }: { id: string | null; onClose: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const turn = useAgentTurn(workspace.id, id);
    return (
        <Dialog open={id !== null} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="igr:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{t('agent.review.detailTitle')}</DialogTitle>
                    <DialogDescription>{t('agent.review.detailDescription')}</DialogDescription>
                </DialogHeader>
                {turn.isPending ? (
                    <Skeleton className="igr:h-40" />
                ) : turn.isError ? (
                    <ErrorState error={turn.error} />
                ) : (
                    <div className="igr:max-h-[60vh] igr:space-y-3 igr:overflow-y-auto igr:text-[13px]">
                        {turn.data.suggestion ? (
                            <div>
                                <p className="igr:text-xs igr:font-medium igr:text-muted-foreground">{t('agent.review.suggestion')}</p>
                                <p className="igr:mt-1 igr:rounded-md igr:bg-muted igr:p-2 igr:whitespace-pre-wrap">{turn.data.suggestion}</p>
                            </div>
                        ) : null}
                        {(turn.data.validation?.attempts ?? []).some((a) => !a.ok) ? (
                            <p className="igr:text-destructive">
                                {t('agent.review.unknownNumbers', {
                                    list:
                                        (turn.data.validation?.attempts ?? [])
                                            .flatMap((a) => a.unknown)
                                            .join(', ') || '—',
                                })}
                            </p>
                        ) : null}
                        <div>
                            <p className="igr:text-xs igr:font-medium igr:text-muted-foreground">{t('agent.review.toolCalls')}</p>
                            {turn.data.tool_calls.length === 0 ? (
                                <p className="igr:mt-1 igr:text-muted-foreground">{t('agent.review.noTools')}</p>
                            ) : (
                                <ul className="igr:mt-1 igr:space-y-2">
                                    {turn.data.tool_calls.map((c, i) => (
                                        <li key={`${c.name}-${i}`} className="igr:rounded-md igr:border igr:p-2">
                                            <p className="igr:flex igr:items-center igr:gap-1.5 igr:font-medium">
                                                <Wrench className="igr:size-3.5" aria-hidden />
                                                {t(`agent.tools.${c.name}`, { defaultValue: c.name })}
                                            </p>
                                            <pre className="igr:mt-1 igr:overflow-x-auto igr:text-[11px] igr:text-muted-foreground">{JSON.stringify(c.input)}</pre>
                                            {c.error ? <p className="igr:mt-1 igr:text-xs igr:text-destructive">{c.error}</p> : <pre className="igr:mt-1 igr:overflow-x-auto igr:text-[11px] igr:whitespace-pre-wrap">{c.output}</pre>}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                        <p className="igr:text-xs igr:text-muted-foreground">{t('agent.review.tokens', { input: turn.data.tokens_in, output: turn.data.tokens_out, ms: turn.data.latency_ms ?? 0 })}</p>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}
