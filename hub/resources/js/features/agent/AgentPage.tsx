import { ArrowLeft, Bot, Check, CircleAlert, Send, ThumbsDown, Wrench } from 'lucide-react';
import type { TFunction } from 'i18next';
import { type ReactNode, type SyntheticEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useSearchParams } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input, Select, Textarea } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { EmptyState, ErrorState, FieldError, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { formatDateTime, formatMoney, formatPercent } from '@/lib/format';
import { toast } from '@/stores/toast';
import { errorMessage } from '@/features/contacts/errors';
import { useAgentReview, useAgentSettings, useAgentTurn, useReviewTurn, useSandbox, useSaveAgentSettings } from './api';
import { appendSandboxReply, parseKeywords, reasonKey, settingsPayload, statusBadge } from './logic';
import { type AgentSettings, type AgentSettingsResponse, type ChatMessage, POLICY_KEYS, type ReviewFilter, type SandboxResult, type TurnRow } from './types';

const TABS = ['revision', 'probar', 'ajustes'] as const;
type Tab = (typeof TABS)[number];

/** Agente de ventas con IA de la bandeja (M11, inteligencia.md §6.7): revisión, prueba y ajustes. */
export function AgentPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const settings = useAgentSettings(workspace.id);
    const [params, setParams] = useSearchParams();
    const canManage = settings.data?.can_manage ?? false;
    const tabs = TABS.filter((k) => canManage || k === 'revision');
    const raw = params.get('tab');
    const tab: Tab = (tabs as readonly string[]).includes(raw ?? '') ? (raw as Tab) : 'revision';
    const setTab = (next: string) => {
        const p = new URLSearchParams(params);
        if (next === 'revision') p.delete('tab');
        else p.set('tab', next);
        setParams(p, { replace: true });
    };

    return (
        <>
            <PageHeader
                title={t('agent.title')}
                description={t('agent.description')}
                actions={
                    <Button asChild variant="outline" size="sm">
                        <Link to={`/w/${workspace.id}/bandeja`}>
                            <ArrowLeft aria-hidden />
                            {t('agent.backToInbox')}
                        </Link>
                    </Button>
                }
            />
            {settings.isError ? <ErrorState error={settings.error} onRetry={() => void settings.refetch()} /> : null}
            {settings.data ? <Notices data={settings.data} /> : null}
            <Tabs value={tab} onValueChange={setTab} className="igr:mt-4">
                <TabsList>
                    {tabs.map((k) => (
                        <TabsTrigger key={k} value={k}>
                            {t(`agent.tabs.${k}`)}
                        </TabsTrigger>
                    ))}
                </TabsList>
                <TabsContent value="revision" className="igr:mt-4">
                    <ReviewTab />
                </TabsContent>
                {canManage && settings.data ? (
                    <>
                        <TabsContent value="probar" className="igr:mt-4">
                            <SandboxTab data={settings.data} />
                        </TabsContent>
                        <TabsContent value="ajustes" className="igr:mt-4">
                            <SettingsEditor key={settings.data.settings.updated_at ?? 'nuevo'} data={settings.data} />
                        </TabsContent>
                    </>
                ) : null}
            </Tabs>
        </>
    );
}

function Notices({ data }: { data: AgentSettingsResponse }) {
    const { t } = useTranslation();
    const items: string[] = [];
    if (!data.ai_available) items.push(t('agent.notices.noAi'));
    if (!data.store_connected) items.push(t('agent.notices.noStore'));
    if (!data.settings.enabled) items.push(t('agent.notices.disabled'));
    if (items.length === 0) return null;
    return (
        <div className="igr:space-y-1.5 igr:rounded-md igr:border igr:border-warning/40 igr:bg-warning/10 igr:px-3 igr:py-2 igr:text-[13px]" role="status">
            {items.map((text) => (
                <p key={text} className="igr:flex igr:items-start igr:gap-2">
                    <CircleAlert className="igr:mt-0.5 igr:size-4 igr:shrink-0 igr:text-warning" aria-hidden />
                    {text}
                </p>
            ))}
        </div>
    );
}

function Kpi({ label, value, hint }: { label: string; value: ReactNode; hint?: ReactNode }) {
    return (
        <Card className="igr:flex igr:min-w-0 igr:flex-col igr:p-4">
            <span className="igr:truncate igr:text-[13px] igr:text-muted-foreground">{label}</span>
            <span className="igr:mt-1.5 igr:truncate igr:text-2xl igr:font-semibold igr:tracking-tight igr:tabular-nums">{value}</span>
            <span className="igr:mt-1 igr:min-h-4 igr:truncate igr:text-xs igr:text-muted-foreground">{hint}</span>
        </Card>
    );
}

function ReviewTab() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
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
        <div className="igr:space-y-4">
            {s ? (
                <div className="igr:grid igr:grid-cols-2 igr:gap-3 igr:lg:grid-cols-5">
                    <Kpi label={t('agent.review.kpis.conversations')} value={s.conversations.toLocaleString(locale)} hint={t('agent.review.kpis.turns', { count: s.turns })} />
                    <Kpi label={t('agent.review.kpis.replied')} value={s.replied.toLocaleString(locale)} hint={t('agent.review.kpis.suggested', { count: s.suggested })} />
                    <Kpi label={t('agent.review.kpis.handoffRate')} value={s.handoff_rate === null ? '—' : formatPercent(s.handoff_rate, locale, 1)} hint={t('agent.review.kpis.handoffs', { count: s.handoff })} />
                    <Kpi label={t('agent.review.kpis.flagged')} value={s.flagged.toLocaleString(locale)} hint={t('agent.review.kpis.flaggedHint')} />
                    <Kpi label={t('agent.review.kpis.sales')} value={formatMoney(s.revenue, s.currency, locale)} hint={t('agent.review.kpis.orders', { count: s.orders })} />
                </div>
            ) : (
                <Skeleton className="igr:h-24" />
            )}
            <div className="igr:flex igr:flex-wrap igr:items-end igr:gap-3">
                <div>
                    <Label htmlFor="agent-filter">{t('agent.review.filter')}</Label>
                    <Select id="agent-filter" className="igr:mt-1.5 igr:w-48" value={filter} onChange={(e) => { setFilter(e.target.value as ReviewFilter); setPage(1); }}>
                        {(['all', 'flagged', 'handoff', 'replied', 'suggested', 'test'] as const).map((f) => (
                            <option key={f} value={f}>
                                {t(`agent.review.filters.${f}`)}
                            </option>
                        ))}
                    </Select>
                </div>
                <div>
                    <Label htmlFor="agent-days">{t('agent.review.period')}</Label>
                    <Select id="agent-days" className="igr:mt-1.5 igr:w-40" value={days} onChange={(e) => { setDays(Number(e.target.value)); setPage(1); }}>
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
                <EmptyState icon={Bot} title={t('agent.review.emptyTitle')} description={t('agent.review.emptyDescription')} />
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
                                    <TD className="igr:whitespace-nowrap igr:text-xs igr:text-muted-foreground">{formatDateTime(row.created_at, locale, workspace.timezone)}</TD>
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
                                            <span className="igr:inline-flex igr:items-center igr:gap-1 igr:text-xs igr:text-success"><Check className="igr:size-3.5" aria-hidden />{t('agent.review.verified')}</span>
                                        ) : (
                                            <span className="igr:inline-flex igr:items-center igr:gap-1 igr:text-xs igr:text-destructive"><CircleAlert className="igr:size-3.5" aria-hidden />{t('agent.review.unverified')}</span>
                                        )}
                                    </TD>
                                    <TD className="igr:text-right">
                                        <div className="igr:inline-flex igr:gap-1">
                                            <Button size="sm" variant="ghost" onClick={() => setOpen(row.id)}>{t('agent.review.details')}</Button>
                                            <Button size="icon" variant={row.review_status === 'ok' ? 'secondary' : 'ghost'} aria-label={t('agent.review.markOk')} onClick={() => onMark(row, 'ok')}><Check aria-hidden /></Button>
                                            <Button size="icon" variant={row.review_status === 'wrong' ? 'secondary' : 'ghost'} aria-label={t('agent.review.markWrong')} onClick={() => onMark(row, 'wrong')}><ThumbsDown aria-hidden /></Button>
                                        </div>
                                    </TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                    {review.data.meta.last_page > 1 ? (
                        <div className="igr:flex igr:items-center igr:justify-end igr:gap-2 igr:border-t igr:p-3 igr:text-xs igr:text-muted-foreground">
                            <Button size="sm" variant="outline" disabled={page <= 1} onClick={() => setPage(page - 1)}>{t('agent.review.prev')}</Button>
                            <span>{t('agent.review.page', { page: review.data.meta.page, last: review.data.meta.last_page })}</span>
                            <Button size="sm" variant="outline" disabled={page >= review.data.meta.last_page} onClick={() => setPage(page + 1)}>{t('agent.review.next')}</Button>
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
                                <p className="igr:mt-1 igr:whitespace-pre-wrap igr:rounded-md igr:bg-muted igr:p-2">{turn.data.suggestion}</p>
                            </div>
                        ) : null}
                        {(turn.data.validation?.attempts ?? []).some((a) => !a.ok) ? (
                            <p className="igr:text-destructive">
                                {t('agent.review.unknownNumbers', { list: (turn.data.validation?.attempts ?? []).flatMap((a) => a.unknown).join(', ') || '—' })}
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
                                            <p className="igr:flex igr:items-center igr:gap-1.5 igr:font-medium"><Wrench className="igr:size-3.5" aria-hidden />{t(`agent.tools.${c.name}`, { defaultValue: c.name })}</p>
                                            <pre className="igr:mt-1 igr:overflow-x-auto igr:text-[11px] igr:text-muted-foreground">{JSON.stringify(c.input)}</pre>
                                            {c.error ? <p className="igr:mt-1 igr:text-xs igr:text-destructive">{c.error}</p> : <pre className="igr:mt-1 igr:overflow-x-auto igr:whitespace-pre-wrap igr:text-[11px]">{c.output}</pre>}
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

function SandboxTab({ data }: { data: AgentSettingsResponse }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const sandbox = useSandbox(workspace.id);
    const [history, setHistory] = useState<ChatMessage[]>([]);
    const [draft, setDraft] = useState('');
    const [last, setLast] = useState<SandboxResult | null>(null);

    const submit = (e: SyntheticEvent) => {
        e.preventDefault();
        const text = draft.trim();
        if (text === '' || sandbox.isPending) return;
        const next: ChatMessage[] = [...history, { role: 'user', content: text }];
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
        <div className="igr:grid igr:gap-4 igr:lg:grid-cols-[minmax(0,1fr)_20rem]">
            <Card>
                <CardHeader>
                    <CardTitle>{t('agent.sandbox.title')}</CardTitle>
                    <CardDescription>{t('agent.sandbox.description')}</CardDescription>
                </CardHeader>
                <CardContent>
                    <ol className="igr:flex igr:min-h-64 igr:flex-col igr:gap-2 igr:rounded-md igr:bg-muted/40 igr:p-3" aria-live="polite" aria-label={t('agent.sandbox.conversation')}>
                        {history.length === 0 ? <li className="igr:m-auto igr:text-[13px] igr:text-muted-foreground">{t('agent.sandbox.empty')}</li> : null}
                        {history.map((m, i) => (
                            <li key={i} className={m.role === 'user' ? 'igr:max-w-[80%] igr:self-end igr:rounded-lg igr:bg-primary igr:px-3 igr:py-2 igr:text-[13px] igr:text-primary-foreground' : 'igr:max-w-[80%] igr:self-start igr:whitespace-pre-wrap igr:rounded-lg igr:border igr:bg-background igr:px-3 igr:py-2 igr:text-[13px]'}>
                                {m.content}
                            </li>
                        ))}
                        {sandbox.isPending ? <li className="igr:self-start igr:text-xs igr:text-muted-foreground">{t('agent.sandbox.thinking')}</li> : null}
                    </ol>
                    <form onSubmit={submit} className="igr:mt-3 igr:flex igr:gap-2">
                        <Input aria-label={t('agent.sandbox.message')} placeholder={t('agent.sandbox.placeholder')} value={draft} maxLength={1000} onChange={(e) => setDraft(e.target.value)} disabled={!data.ai_available} />
                        <Button type="submit" loading={sandbox.isPending} disabled={!data.ai_available || draft.trim() === ''}>
                            <Send aria-hidden />
                            {t('agent.sandbox.send')}
                        </Button>
                        <Button type="button" variant="outline" onClick={() => { setHistory([]); setLast(null); }} disabled={history.length === 0}>
                            {t('agent.sandbox.reset')}
                        </Button>
                    </form>
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardTitle>{t('agent.sandbox.lastTurn')}</CardTitle>
                </CardHeader>
                <CardContent className="igr:space-y-2 igr:text-[13px]">
                    {last === null ? (
                        <p className="igr:text-muted-foreground">{t('agent.sandbox.lastEmpty')}</p>
                    ) : (
                        <>
                            <Badge variant={statusBadge(last.status)}>{t(`agent.status.${last.status}`)}</Badge>
                            <p>{last.validation?.ok === false || last.flagged ? t('agent.review.unverified') : t('agent.review.verified')}</p>
                            <p className="igr:text-xs igr:text-muted-foreground">
                                {last.tool_calls.length > 0 ? last.tool_calls.map((c) => t(`agent.tools.${c.name}`, { defaultValue: c.name })).join(' → ') : t('agent.review.noTools')}
                            </p>
                        </>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}

function SettingsEditor({ data }: { data: AgentSettingsResponse }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const save = useSaveAgentSettings(workspace.id);
    const [s, setS] = useState<AgentSettings>(data.settings);
    const [keywords, setKeywords] = useState(data.settings.handoff_rules.keywords.join(', '));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const set = (patch: Partial<AgentSettings>) => setS((prev) => ({ ...prev, ...patch }));
    const hours = s.handoff_rules.hours;
    const setHours = (patch: Partial<AgentSettings['handoff_rules']['hours']>) => set({ handoff_rules: { ...s.handoff_rules, hours: { ...hours, ...patch } } });
    const discount = s.discount_rules;
    const setDiscount = (patch: Partial<AgentSettings['discount_rules']>) => set({ discount_rules: { ...discount, ...patch } });

    const submit = (e: SyntheticEvent) => {
        e.preventDefault();
        setErrors({});
        const payload = settingsPayload({ ...s, handoff_rules: { ...s.handoff_rules, keywords: parseKeywords(keywords) } });
        save.mutate(payload, {
            onSuccess: () => toast.success(t('agent.settings.saved')),
            onError: (err) => {
                if (isApiError(err, 'validation')) setErrors(err.fieldErrors);
                toast.error(errorMessage(err, t));
            },
        });
    };

    return (
        <form onSubmit={submit} className="igr:grid igr:gap-4 igr:lg:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle>{t('agent.settings.generalTitle')}</CardTitle>
                    <CardDescription>{t('agent.settings.generalDescription')}</CardDescription>
                </CardHeader>
                <CardContent className="igr:space-y-4">
                    <label className="igr:flex igr:items-center igr:gap-2 igr:text-[13px] igr:font-medium">
                        <input type="checkbox" className="igr:size-4" checked={s.enabled} onChange={(e) => set({ enabled: e.target.checked })} />
                        {t('agent.settings.enabled')}
                    </label>
                    <div>
                        <Label htmlFor="agent-mode">{t('agent.settings.defaultMode')}</Label>
                        <Select id="agent-mode" className="igr:mt-1.5" value={s.default_mode} onChange={(e) => set({ default_mode: e.target.value as AgentSettings['default_mode'] })}>
                            {(['off', 'assist', 'auto'] as const).map((m) => (
                                <option key={m} value={m}>{t(`agent.modes.${m}`)} — {t(`agent.modeHelp.${m}`)}</option>
                            ))}
                        </Select>
                    </div>
                    <div>
                        <Label htmlFor="agent-scope">{t('agent.settings.scope')}</Label>
                        <Textarea id="agent-scope" className="igr:mt-1.5" rows={2} maxLength={500} placeholder={t('agent.settings.scopePlaceholder')} value={s.scope ?? ''} onChange={(e) => set({ scope: e.target.value })} />
                        <FieldError message={errors.scope} />
                    </div>
                    <div>
                        <Label htmlFor="agent-tone">{t('agent.settings.tone')}</Label>
                        <Input id="agent-tone" className="igr:mt-1.5" maxLength={255} placeholder={t('agent.settings.tonePlaceholder')} value={s.tone ?? ''} onChange={(e) => set({ tone: e.target.value })} />
                    </div>
                    <div>
                        <Label htmlFor="agent-steps">{t('agent.settings.maxSteps')}</Label>
                        <Input id="agent-steps" className="igr:mt-1.5 igr:w-24" type="number" min={2} max={10} value={s.max_steps} onChange={(e) => set({ max_steps: Number(e.target.value) })} />
                        <p className="igr:mt-1 igr:text-xs igr:text-muted-foreground">{t('agent.settings.maxStepsHelp')}</p>
                    </div>
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardTitle>{t('agent.settings.policiesTitle')}</CardTitle>
                    <CardDescription>{t('agent.settings.policiesDescription')}</CardDescription>
                </CardHeader>
                <CardContent className="igr:space-y-3">
                    {POLICY_KEYS.map((key) => (
                        <div key={key}>
                            <Label htmlFor={`agent-policy-${key}`}>{t(`agent.policies.${key}`)}</Label>
                            <Textarea id={`agent-policy-${key}`} className="igr:mt-1.5" rows={2} maxLength={1500} placeholder={t(`agent.policyPlaceholders.${key}`)} value={s.policies[key]} onChange={(e) => set({ policies: { ...s.policies, [key]: e.target.value } })} />
                        </div>
                    ))}
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardTitle>{t('agent.settings.handoffTitle')}</CardTitle>
                    <CardDescription>{t('agent.settings.handoffDescription')}</CardDescription>
                </CardHeader>
                <CardContent className="igr:space-y-4">
                    <div>
                        <Label htmlFor="agent-keywords">{t('agent.settings.keywords')}</Label>
                        <Input id="agent-keywords" className="igr:mt-1.5" value={keywords} onChange={(e) => setKeywords(e.target.value)} />
                        <p className="igr:mt-1 igr:text-xs igr:text-muted-foreground">{t('agent.settings.keywordsHelp')}</p>
                    </div>
                    <label className="igr:flex igr:items-center igr:gap-2 igr:text-[13px]">
                        <input type="checkbox" className="igr:size-4" checked={s.handoff_rules.intents.includes('complaint')}
                            onChange={(e) => set({ handoff_rules: { ...s.handoff_rules, intents: e.target.checked ? ['complaint'] : [] } })} />
                        {t('agent.settings.complaints')}
                    </label>
                    <div>
                        <Label htmlFor="agent-hours">{t('agent.settings.hoursMode')}</Label>
                        <Select id="agent-hours" className="igr:mt-1.5" value={hours.mode} onChange={(e) => setHours({ mode: e.target.value as 'always' | 'out_of_hours' })}>
                            <option value="always">{t('agent.settings.hours.always')}</option>
                            <option value="out_of_hours">{t('agent.settings.hours.out_of_hours')}</option>
                        </Select>
                    </div>
                    {hours.mode === 'out_of_hours' ? (
                        <div className="igr:space-y-3">
                            <fieldset>
                                <legend className="igr:text-[13px] igr:font-medium">{t('agent.settings.hours.days')}</legend>
                                <div className="igr:mt-1.5 igr:flex igr:flex-wrap igr:gap-3">
                                    {[1, 2, 3, 4, 5, 6, 7].map((d) => (
                                        <label key={d} className="igr:flex igr:items-center igr:gap-1.5 igr:text-[13px]">
                                            <input type="checkbox" className="igr:size-4" checked={hours.days.includes(d)}
                                                onChange={(e) => setHours({ days: e.target.checked ? [...hours.days, d].sort() : hours.days.filter((x) => x !== d) })} />
                                            {t(`agent.days.${d}`)}
                                        </label>
                                    ))}
                                </div>
                            </fieldset>
                            <div className="igr:flex igr:gap-3">
                                <div>
                                    <Label htmlFor="agent-start">{t('agent.settings.hours.start')}</Label>
                                    <Input id="agent-start" className="igr:mt-1.5" type="time" value={hours.start} onChange={(e) => setHours({ start: e.target.value })} />
                                </div>
                                <div>
                                    <Label htmlFor="agent-end">{t('agent.settings.hours.end')}</Label>
                                    <Input id="agent-end" className="igr:mt-1.5" type="time" value={hours.end} onChange={(e) => setHours({ end: e.target.value })} />
                                    <FieldError message={errors['handoff_rules.hours.end']} />
                                </div>
                            </div>
                        </div>
                    ) : null}
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardTitle>{t('agent.settings.discountTitle')}</CardTitle>
                    <CardDescription>{t('agent.settings.discountDescription')}</CardDescription>
                </CardHeader>
                <CardContent className="igr:space-y-3">
                    <label className="igr:flex igr:items-center igr:gap-2 igr:text-[13px] igr:font-medium">
                        <input type="checkbox" className="igr:size-4" checked={discount.enabled} onChange={(e) => setDiscount({ enabled: e.target.checked })} />
                        {t('agent.settings.discountEnabled')}
                    </label>
                    {discount.enabled ? (
                        <>
                            <div>
                                <Label htmlFor="agent-coupon">{t('agent.settings.couponCode')}</Label>
                                <Input id="agent-coupon" className="igr:mt-1.5" maxLength={40} value={discount.coupon_code ?? ''} onChange={(e) => setDiscount({ coupon_code: e.target.value })} />
                                <FieldError message={errors['discount_rules.coupon_code']} />
                                <p className="igr:mt-1 igr:text-xs igr:text-muted-foreground">{t('agent.settings.couponHelp')}</p>
                            </div>
                            <div>
                                <Label htmlFor="agent-discount-desc">{t('agent.settings.discountText')}</Label>
                                <Input id="agent-discount-desc" className="igr:mt-1.5" maxLength={160} placeholder={t('agent.settings.discountPlaceholder')} value={discount.description ?? ''} onChange={(e) => setDiscount({ description: e.target.value })} />
                                <FieldError message={errors['discount_rules.description']} />
                            </div>
                            <div>
                                <Label htmlFor="agent-min">{t('agent.settings.minSubtotal')}</Label>
                                <Input id="agent-min" className="igr:mt-1.5 igr:w-40" type="number" min={0} value={discount.min_subtotal ?? ''} onChange={(e) => setDiscount({ min_subtotal: e.target.value })} />
                            </div>
                        </>
                    ) : null}
                </CardContent>
            </Card>
            <div className="igr:flex igr:justify-end igr:lg:col-span-2">
                <Button type="submit" loading={save.isPending}>{t('agent.settings.save')}</Button>
            </div>
        </form>
    );
}
