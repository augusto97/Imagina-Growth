import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input, Select, Textarea } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useWorkspace } from '@/hooks/useWorkspace';
import { toast } from '@/stores/toast';
import { errorMessage } from '@/features/contacts/errors';
import { useLeadSettings, usePipelines, useSaveLeadSettings, useSellers, useTeam } from './api';
import { parseList, pipelineErrors, stageKey } from './logic';
import type { AlertRules, LeadSettings, Pipeline, ScoringRules, Stage } from './types';

/** Ajustes de leads (M18): puntaje, avisos, asignación, vendedores y pipelines. */
export function SettingsTab() {
    const { workspace } = useWorkspace();
    const settings = useLeadSettings(workspace.id);
    if (settings.isError) return <ErrorState error={settings.error} onRetry={() => void settings.refetch()} />;
    if (settings.isPending) return <Skeleton className="igr:h-96" />;
    return (
        <div className="igr:space-y-4">
            <RulesForm key={JSON.stringify([settings.data.scoring, settings.data.alerts, settings.data.assignment, settings.data.whatsapp_leads, settings.data.ai_qualify, settings.data.ai_brief])} s={settings.data} />
            <AssistantCard key={JSON.stringify(settings.data.assistant)} s={settings.data} />
            <SellersCard s={settings.data} />
            <PipelinesCard pipelines={settings.data.pipelines} />
        </div>
    );
}

function RulesForm({ s }: { s: LeadSettings }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const save = useSaveLeadSettings(workspace.id);
    const [scoring, setScoring] = useState<ScoringRules>(s.scoring);
    const [alerts, setAlerts] = useState<AlertRules>(s.alerts);
    const [mode, setMode] = useState(s.assignment.mode);
    const [waLeads, setWaLeads] = useState(s.whatsapp_leads);
    const [ai, setAi] = useState(s.ai_qualify);
    const [brief, setBrief] = useState(s.ai_brief);
    const [paths, setPaths] = useState(s.scoring.pricing_paths.join(', '));
    const [cities, setCities] = useState(s.scoring.service_cities.join(', '));
    const [domains, setDomains] = useState(s.scoring.personal_domains.join(', '));
    const point = (signal: string, v: string) => setScoring({ ...scoring, points: { ...scoring.points, [signal]: Math.max(0, Math.min(100, Number(v) || 0)) } });

    return (
        <form
            className="igr:space-y-4"
            onSubmit={(e) => {
                e.preventDefault();
                save.mutate(
                    {
                        scoring: { ...scoring, pricing_paths: parseList(paths, 30), service_cities: parseList(cities), personal_domains: parseList(domains, 100) },
                        alerts,
                        assignment: { mode },
                        whatsapp_leads: waLeads,
                        ai_qualify: ai,
                        ai_brief: brief,
                    },
                    { onSuccess: () => toast.success(t('leads.settings.saved')), onError: (err) => toast.error(errorMessage(err, t)) },
                );
            }}
        >
            <Card>
                <CardHeader>
                    <CardTitle>{t('leads.settings.scoreTitle')}</CardTitle>
                    <CardDescription>{t('leads.settings.scoreHelp')}</CardDescription>
                </CardHeader>
                <CardContent className="igr:space-y-4">
                    <div className="igr:grid igr:gap-3 igr:sm:grid-cols-2 igr:lg:grid-cols-4">
                        {s.signals.map((signal) => (
                            <div key={signal} className="igr:space-y-1">
                                <Label htmlFor={`pts-${signal}`}>{t(`leads.settings.signals.${signal}`)}</Label>
                                <Input id={`pts-${signal}`} type="number" min={0} max={100} value={scoring.points[signal] ?? 0} onChange={(e) => point(signal, e.target.value)} />
                            </div>
                        ))}
                    </div>
                    <div className="igr:grid igr:gap-3 igr:sm:grid-cols-3">
                        <div className="igr:space-y-1">
                            <Label htmlFor="th-warm">{t('leads.settings.warm')}</Label>
                            <Input id="th-warm" type="number" min={1} max={99} value={scoring.thresholds.warm} onChange={(e) => setScoring({ ...scoring, thresholds: { ...scoring.thresholds, warm: Number(e.target.value) || 1 } })} />
                        </div>
                        <div className="igr:space-y-1">
                            <Label htmlFor="th-hot">{t('leads.settings.hot')}</Label>
                            <Input id="th-hot" type="number" min={2} max={100} value={scoring.thresholds.hot} onChange={(e) => setScoring({ ...scoring, thresholds: { ...scoring.thresholds, hot: Number(e.target.value) || 2 } })} />
                        </div>
                        <div className="igr:space-y-1">
                            <Label htmlFor="ai-max">{t('leads.settings.aiMax')}</Label>
                            <Input id="ai-max" type="number" min={0} max={20} value={scoring.ai_max_points} onChange={(e) => setScoring({ ...scoring, ai_max_points: Math.max(0, Math.min(20, Number(e.target.value) || 0)) })} />
                        </div>
                    </div>
                    <div className="igr:grid igr:gap-3 igr:sm:grid-cols-3">
                        <div className="igr:space-y-1">
                            <Label htmlFor="pricing-paths">{t('leads.settings.pricingPaths')}</Label>
                            <Textarea id="pricing-paths" rows={2} value={paths} onChange={(e) => setPaths(e.target.value)} />
                        </div>
                        <div className="igr:space-y-1">
                            <Label htmlFor="cities">{t('leads.settings.cities')}</Label>
                            <Textarea id="cities" rows={2} value={cities} onChange={(e) => setCities(e.target.value)} placeholder={t('leads.settings.citiesPlaceholder')} />
                        </div>
                        <div className="igr:space-y-1">
                            <Label htmlFor="domains">{t('leads.settings.personalDomains')}</Label>
                            <Textarea id="domains" rows={2} value={domains} onChange={(e) => setDomains(e.target.value)} placeholder="miempresa.com" />
                        </div>
                    </div>
                    <label className="igr:flex igr:items-start igr:gap-2 igr:text-[13px]">
                        <input type="checkbox" className="igr:mt-0.5 igr:size-4" checked={ai} onChange={(e) => setAi(e.target.checked)} />
                        <span>
                            {t('leads.settings.aiQualify')}
                            <span className="igr:block igr:text-xs igr:text-muted-foreground">{s.ai_available ? t('leads.settings.aiQualifyHelp') : t('leads.settings.aiUnavailable')}</span>
                        </span>
                    </label>
                    <label className="igr:flex igr:items-start igr:gap-2 igr:text-[13px]">
                        <input type="checkbox" className="igr:mt-0.5 igr:size-4" checked={brief} onChange={(e) => setBrief(e.target.checked)} />
                        <span>
                            {t('leads.settings.aiBrief')}
                            <span className="igr:block igr:text-xs igr:text-muted-foreground">{s.ai_available ? t('leads.settings.aiBriefHelp') : t('leads.settings.aiUnavailable')}</span>
                        </span>
                    </label>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{t('leads.settings.alertsTitle')}</CardTitle>
                    <CardDescription>{t('leads.settings.alertsHelp')}</CardDescription>
                </CardHeader>
                <CardContent className="igr:space-y-3">
                    <label className="igr:flex igr:items-center igr:gap-2 igr:text-[13px]">
                        <input type="checkbox" className="igr:size-4" checked={alerts.on_hot} onChange={(e) => setAlerts({ ...alerts, on_hot: e.target.checked })} />
                        {t('leads.settings.onHot')}
                    </label>
                    <label className="igr:flex igr:items-center igr:gap-2 igr:text-[13px]">
                        <input type="checkbox" className="igr:size-4" checked={alerts.on_new} onChange={(e) => setAlerts({ ...alerts, on_new: e.target.checked })} />
                        {t('leads.settings.onNew')}
                    </label>
                    <div className="igr:grid igr:gap-3 igr:sm:grid-cols-2">
                        <div className="igr:space-y-1">
                            <Label htmlFor="alert-channel">{t('leads.settings.channel')}</Label>
                            <Select id="alert-channel" value={alerts.channel} onChange={(e) => setAlerts({ ...alerts, channel: e.target.value as AlertRules['channel'] })}>
                                <option value="whatsapp">{t('leads.settings.channels.whatsapp')}</option>
                                <option value="email">{t('leads.settings.channels.email')}</option>
                            </Select>
                        </div>
                        <div className="igr:space-y-1">
                            <Label htmlFor="alert-template">{t('leads.settings.template')}</Label>
                            <Select id="alert-template" value={alerts.whatsapp_template_id ?? ''} disabled={alerts.channel !== 'whatsapp'} onChange={(e) => setAlerts({ ...alerts, whatsapp_template_id: e.target.value || null })}>
                                <option value="">{t('leads.settings.templateDefault')}</option>
                                {s.whatsapp_templates.map((tpl) => (
                                    <option key={tpl.id} value={tpl.id}>
                                        {tpl.name}
                                    </option>
                                ))}
                            </Select>
                        </div>
                    </div>
                    <div className="igr:grid igr:gap-3 igr:sm:grid-cols-2">
                        <div className="igr:space-y-1">
                            <Label htmlFor="assign-mode">{t('leads.settings.assignment')}</Label>
                            <Select id="assign-mode" value={mode} onChange={(e) => setMode(e.target.value as typeof mode)}>
                                {(['round_robin', 'rules', 'none'] as const).map((m) => (
                                    <option key={m} value={m}>
                                        {t(`leads.settings.modes.${m}`)}
                                    </option>
                                ))}
                            </Select>
                        </div>
                    </div>
                    <label className="igr:flex igr:items-start igr:gap-2 igr:text-[13px]">
                        <input type="checkbox" className="igr:mt-0.5 igr:size-4" checked={waLeads} onChange={(e) => setWaLeads(e.target.checked)} />
                        <span>
                            {t('leads.settings.whatsappLeads')}
                            <span className="igr:block igr:text-xs igr:text-muted-foreground">{t('leads.settings.whatsappLeadsHelp')}</span>
                        </span>
                    </label>
                </CardContent>
            </Card>
            <Button type="submit" disabled={save.isPending}>
                {t('common.save')}
            </Button>
        </form>
    );
}

/** «Hoy» y el seguimiento: días para «quieto» y la plantilla de WhatsApp para escribir fuera de la ventana de 24 h. */
function AssistantCard({ s }: { s: LeadSettings }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const save = useSaveLeadSettings(workspace.id);
    const [days, setDays] = useState(s.assistant.stale_days);
    const [template, setTemplate] = useState(s.assistant.whatsapp_template_id ?? '');
    const [digest, setDigest] = useState(s.assistant.digest);
    const marketing = s.whatsapp_templates.filter((tpl) => tpl.category === 'marketing');
    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                save.mutate(
                    { assistant: { stale_days: days, whatsapp_template_id: template || null, digest } },
                    { onSuccess: () => toast.success(t('leads.settings.saved')), onError: (err) => toast.error(errorMessage(err, t)) },
                );
            }}
        >
            <Card>
                <CardHeader>
                    <CardTitle>{t('leads.settings.assistantTitle')}</CardTitle>
                    <CardDescription>{t('leads.settings.assistantHelp')}</CardDescription>
                </CardHeader>
                <CardContent className="igr:space-y-3">
                    <div className="igr:grid igr:gap-3 igr:sm:grid-cols-2">
                        <div className="igr:space-y-1">
                            <Label htmlFor="stale-days">{t('leads.settings.staleDays')}</Label>
                            <Input id="stale-days" type="number" min={1} max={90} value={days} onChange={(e) => setDays(Math.max(1, Math.min(90, Number(e.target.value) || 1)))} />
                        </div>
                        <div className="igr:space-y-1">
                            <Label htmlFor="followup-template">{t('leads.settings.followupTemplate')}</Label>
                            <Select id="followup-template" value={template} onChange={(e) => setTemplate(e.target.value)}>
                                <option value="">{t('leads.settings.followupTemplateDefault')}</option>
                                {marketing.map((tpl) => (
                                    <option key={tpl.id} value={tpl.id}>
                                        {tpl.name}
                                    </option>
                                ))}
                            </Select>
                            <p className="igr:text-xs igr:text-muted-foreground">{t('leads.settings.followupTemplateHelp')}</p>
                        </div>
                    </div>
                    <label className="igr:flex igr:items-start igr:gap-2 igr:text-[13px]">
                        <input type="checkbox" className="igr:mt-0.5 igr:size-4" checked={digest} onChange={(e) => setDigest(e.target.checked)} />
                        <span>
                            {t('leads.settings.digest')}
                            <span className="igr:block igr:text-xs igr:text-muted-foreground">{t('leads.settings.digestHelp')}</span>
                        </span>
                    </label>
                    <Button type="submit" size="sm" disabled={save.isPending}>
                        {t('common.save')}
                    </Button>
                </CardContent>
            </Card>
        </form>
    );
}

function SellersCard({ s }: { s: LeadSettings }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const team = useTeam(workspace.id);
    const { create, update, remove } = useSellers(workspace.id);
    const [user, setUser] = useState('');
    const [phone, setPhone] = useState('');
    const [services, setServices] = useState('');
    const fail = (e: unknown) => toast.error(errorMessage(e, t));
    const available = (team.data?.data ?? []).filter((m) => !s.sellers.some((x) => x.user.id === m.id));

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('leads.settings.sellersTitle')}</CardTitle>
                <CardDescription>{t('leads.settings.sellersHelp')}</CardDescription>
            </CardHeader>
            <CardContent className="igr:space-y-3">
                {s.sellers.length > 0 ? (
                    <Table>
                        <THead>
                            <TR>
                                <TH>{t('leads.settings.seller')}</TH>
                                <TH>{t('leads.settings.phone')}</TH>
                                <TH>{t('leads.settings.services')}</TH>
                                <TH className="igr:text-right">{t('leads.settings.openLeads')}</TH>
                                <TH>{t('leads.settings.active')}</TH>
                                <TH />
                            </TR>
                        </THead>
                        <TBody>
                            {s.sellers.map((x) => (
                                <TR key={x.id}>
                                    <TD>{x.user.name ?? '—'}</TD>
                                    <TD className="igr:tabular-nums">{x.phone ?? t('leads.settings.noPhone')}</TD>
                                    <TD className="igr:text-[13px]">{x.services.join(', ') || '—'}</TD>
                                    <TD className="igr:text-right igr:tabular-nums">{x.open_leads}</TD>
                                    <TD>
                                        <input
                                            type="checkbox"
                                            className="igr:size-4"
                                            aria-label={t('leads.settings.active')}
                                            checked={x.active}
                                            onChange={(e) => update.mutate({ id: x.id, active: e.target.checked }, { onError: fail })}
                                        />
                                    </TD>
                                    <TD className="igr:text-right">
                                        <Button variant="ghost" size="icon" aria-label={t('leads.settings.removeSeller')} onClick={() => remove.mutate(x.id, { onError: fail })}>
                                            <Trash2 />
                                        </Button>
                                    </TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                ) : (
                    <p className="igr:text-[13px] igr:text-muted-foreground">{t('leads.settings.noSellers')}</p>
                )}
                <form
                    className="igr:grid igr:items-end igr:gap-2 igr:sm:grid-cols-[1fr_1fr_1fr_auto]"
                    onSubmit={(e) => {
                        e.preventDefault();
                        if (user === '') return;
                        create.mutate(
                            { user_id: user, phone: phone.trim() || undefined, services: parseList(services, 30) },
                            {
                                onSuccess: () => {
                                    setUser('');
                                    setPhone('');
                                    setServices('');
                                },
                                onError: fail,
                            },
                        );
                    }}
                >
                    <div className="igr:space-y-1">
                        <Label htmlFor="seller-user">{t('leads.settings.seller')}</Label>
                        <Select id="seller-user" value={user} onChange={(e) => setUser(e.target.value)}>
                            <option value="">{t('leads.settings.chooseMember')}</option>
                            {available.map((m) => (
                                <option key={m.id} value={m.id}>
                                    {m.name}
                                </option>
                            ))}
                        </Select>
                    </div>
                    <div className="igr:space-y-1">
                        <Label htmlFor="seller-phone">{t('leads.settings.phone')}</Label>
                        <Input id="seller-phone" inputMode="tel" value={phone} onChange={(e) => setPhone(e.target.value)} placeholder={t('leads.settings.phonePlaceholder')} />
                    </div>
                    <div className="igr:space-y-1">
                        <Label htmlFor="seller-services">{t('leads.settings.services')}</Label>
                        <Input id="seller-services" value={services} onChange={(e) => setServices(e.target.value)} placeholder={t('leads.settings.servicesPlaceholder')} />
                    </div>
                    <Button type="submit" variant="outline" disabled={user === '' || create.isPending}>
                        <Plus />
                        {t('leads.settings.addSeller')}
                    </Button>
                </form>
            </CardContent>
        </Card>
    );
}

function PipelinesCard({ pipelines }: { pipelines: Pipeline[] }) {
    const { t } = useTranslation();
    const [editing, setEditing] = useState<Pipeline | 'new' | null>(null);
    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('leads.settings.pipelinesTitle')}</CardTitle>
                <CardDescription>{t('leads.settings.pipelinesHelp')}</CardDescription>
            </CardHeader>
            <CardContent className="igr:space-y-2">
                {pipelines.map((p) => (
                    <div key={p.id} className="igr:flex igr:items-center igr:justify-between igr:gap-2 igr:rounded-md igr:border igr:p-2.5 igr:text-[13px]">
                        <span>
                            <span className="igr:font-medium">{p.name}</span>
                            {p.is_default ? <span className="igr:ml-2 igr:text-xs igr:text-muted-foreground">{t('leads.settings.defaultPipeline')}</span> : null}
                            <span className="igr:block igr:text-xs igr:text-muted-foreground">{p.stages.map((s) => s.label).join(' → ')}</span>
                        </span>
                        <Button variant="outline" size="sm" onClick={() => setEditing(p)}>
                            {t('leads.settings.edit')}
                        </Button>
                    </div>
                ))}
                {editing !== null ? (
                    <PipelineEditor key={editing === 'new' ? 'new' : editing.id} pipeline={editing === 'new' ? null : editing} onDone={() => setEditing(null)} />
                ) : (
                    <Button variant="outline" size="sm" onClick={() => setEditing('new')}>
                        <Plus />
                        {t('leads.settings.newPipeline')}
                    </Button>
                )}
            </CardContent>
        </Card>
    );
}

function PipelineEditor({ pipeline, onDone }: { pipeline: Pipeline | null; onDone: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const { create, update, remove } = usePipelines(workspace.id);
    const [name, setName] = useState(pipeline?.name ?? '');
    const [stages, setStages] = useState<Stage[]>(
        pipeline?.stages ?? [
            { key: 'new', label: t('leads.stages.new'), kind: 'open' },
            { key: 'won', label: t('leads.stages.won'), kind: 'won' },
            { key: 'lost', label: t('leads.stages.lost'), kind: 'lost' },
        ],
    );
    const errors = pipelineErrors(name, stages);
    const fail = (e: unknown) => toast.error(errorMessage(e, t));
    const setAt = (i: number, patch: Partial<Stage>) => setStages(stages.map((s, j) => (j === i ? { ...s, ...patch } : s)));
    const swap = (i: number, j: number) => {
        const next = [...stages];
        const a = next[i];
        const b = next[j];
        if (!a || !b) return;
        next[i] = b;
        next[j] = a;
        setStages(next);
    };

    return (
        <form
            className="igr:space-y-3 igr:rounded-md igr:border igr:p-3"
            onSubmit={(e) => {
                e.preventDefault();
                if (errors.length > 0) return;
                const done = { onSuccess: onDone, onError: fail };
                if (pipeline) update.mutate({ id: pipeline.id, name, stages }, done);
                else create.mutate({ name, stages }, done);
            }}
        >
            <div className="igr:space-y-1">
                <Label htmlFor="pipeline-name">{t('leads.settings.pipelineName')}</Label>
                <Input id="pipeline-name" maxLength={120} value={name} onChange={(e) => setName(e.target.value)} />
            </div>
            <ol className="igr:space-y-2">
                {stages.map((s, i) => (
                    <li key={s.key} className="igr:flex igr:items-center igr:gap-2">
                        <Input aria-label={t('leads.settings.stageName')} maxLength={60} value={s.label} onChange={(e) => setAt(i, { label: e.target.value })} />
                        <Select aria-label={t('leads.settings.stageKind')} className="igr:w-36" value={s.kind} onChange={(e) => setAt(i, { kind: e.target.value as Stage['kind'] })}>
                            {(['open', 'won', 'lost'] as const).map((k) => (
                                <option key={k} value={k}>
                                    {t(`leads.settings.kinds.${k}`)}
                                </option>
                            ))}
                        </Select>
                        <Button type="button" variant="ghost" size="icon" aria-label={t('leads.settings.up')} disabled={i === 0} onClick={() => swap(i, i - 1)}>
                            <ArrowUp />
                        </Button>
                        <Button type="button" variant="ghost" size="icon" aria-label={t('leads.settings.down')} disabled={i === stages.length - 1} onClick={() => swap(i, i + 1)}>
                            <ArrowDown />
                        </Button>
                        <Button type="button" variant="ghost" size="icon" aria-label={t('leads.settings.removeStage')} onClick={() => setStages(stages.filter((_, j) => j !== i))}>
                            <Trash2 />
                        </Button>
                    </li>
                ))}
            </ol>
            <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={stages.length >= 12}
                onClick={() => {
                    const label = t('leads.settings.newStage');
                    const at = Math.max(0, stages.findIndex((s) => s.kind !== 'open'));
                    const next = [...stages];
                    next.splice(at === -1 ? stages.length : at, 0, { key: stageKey(label, stages.map((s) => s.key)), label, kind: 'open' });
                    setStages(next);
                }}
            >
                <Plus />
                {t('leads.settings.addStage')}
            </Button>
            {errors.length > 0 ? (
                <ul className="igr:text-xs igr:text-destructive">
                    {errors.map((e) => (
                        <li key={e}>{t(`leads.settings.errors.${e}`)}</li>
                    ))}
                </ul>
            ) : null}
            <div className="igr:flex igr:gap-2">
                <Button type="submit" size="sm" disabled={errors.length > 0 || create.isPending || update.isPending}>
                    {t('common.save')}
                </Button>
                <Button type="button" size="sm" variant="outline" onClick={onDone}>
                    {t('common.cancel')}
                </Button>
                {pipeline && !pipeline.is_default ? (
                    <Button type="button" size="sm" variant="ghost" className="igr:ml-auto igr:text-destructive" onClick={() => remove.mutate(pipeline.id, { onSuccess: onDone, onError: fail })}>
                        {t('leads.settings.deletePipeline')}
                    </Button>
                ) : null}
            </div>
        </form>
    );
}
