import { MessageSquareText } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input, Select, Textarea } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { EmptyState, ErrorState, FieldError } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { cn } from '@/lib/cn';
import { formatDate, formatDateTime } from '@/lib/format';
import { toast } from '@/stores/toast';
import { errorMessage } from '@/features/contacts/errors';
import { CategoryBars, Legend, LineTrend } from '@/features/analytics/components/charts';
import { TileGrid } from '@/features/analytics/components/StatTile';
import { useFormatters } from '@/features/analytics/format';
import { useChartTheme, type ChartTheme } from '@/features/analytics/theme';
import { useReviewSettings, useSaveSurvey, useSurvey, useSurveyReport } from './api';
import { Kpi } from './Kpi';
import { CATEGORY_ORDER, CHANNELS, parseEmails, surveyCategory, surveyDraft, surveyProblems, toggleChannel, type SurveyDraft } from './logic';
import type { SurveyCategory, SurveyKind, SurveyReport, SurveySettings } from './types';

const PERIODS = [30, 90, 365] as const;

/** Color por categoría (sigue a la categoría, nunca a su posición): promotor azul, detractor naranja, pasivo gris. */
function categoryColor(theme: ChartTheme, cat: SurveyCategory): string {
    if (cat === 'promoter' || cat === 'satisfied') return theme.series[0] ?? '#2a78d6';
    if (cat === 'detractor' || cat === 'unsatisfied') return theme.series[1] ?? '#eb6834';
    return theme.muted;
}

/** NPS y CSAT (M15, flujo 17): tablero y ajustes de cada encuesta. */
export function SurveysTab() {
    const { t } = useTranslation();
    const [kind, setKind] = useState<SurveyKind>('nps');
    const [days, setDays] = useState<number>(90);
    return (
        <div className="igr:space-y-4">
            <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-3">
                <div className="igr:inline-flex igr:rounded-md igr:border igr:p-0.5" role="radiogroup" aria-label={t('reviews.surveys.kind')}>
                    {(['nps', 'csat'] as const).map((k) => (
                        <button
                            key={k}
                            type="button"
                            role="radio"
                            aria-checked={kind === k}
                            onClick={() => setKind(k)}
                            className={cn('igr:rounded igr:px-3 igr:py-1 igr:text-[13px] igr:font-medium', kind === k ? 'igr:bg-primary igr:text-primary-foreground' : 'igr:text-muted-foreground igr:hover:text-foreground')}
                        >
                            {t(`reviews.surveys.kinds.${k}`)}
                        </button>
                    ))}
                </div>
                <Select className="igr:w-auto" aria-label={t('reviews.surveys.period')} value={days} onChange={(e) => setDays(Number(e.target.value))}>
                    {PERIODS.map((d) => (
                        <option key={d} value={d}>
                            {t('reviews.surveys.lastDays', { count: d })}
                        </option>
                    ))}
                </Select>
            </div>
            <div className="igr:grid igr:items-start igr:gap-4 igr:xl:grid-cols-[minmax(0,1fr)_380px]">
                <Report kind={kind} days={days} />
                <SurveyForm key={kind} kind={kind} />
            </div>
        </div>
    );
}

function Report({ kind, days }: { kind: SurveyKind; days: number }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const fmt = useFormatters();
    const report = useSurveyReport(workspace.id, kind, days);
    if (report.isError) return <ErrorState error={report.error} onRetry={() => void report.refetch()} />;
    if (report.isPending) return <Skeleton className="igr:h-96" />;
    const r = report.data;
    return (
        <div className="igr:space-y-4">
            <TileGrid className="igr:lg:grid-cols-3">
                <Kpi
                    label={t(`reviews.surveys.score.${kind}`)}
                    value={r.score === null ? '—' : kind === 'nps' ? `${r.score > 0 ? '+' : ''}${fmt.decimal(r.score, 0)}` : `${fmt.decimal(r.score, 0)} %`}
                    hint={t(`reviews.surveys.scoreHint.${kind}`)}
                    help={t(`reviews.surveys.scoreHelp.${kind}`)}
                />
                <Kpi label={t('reviews.surveys.responses')} value={fmt.number(r.responses)} hint={t('reviews.surveys.sent', { count: r.sent })} />
                <Kpi label={t('reviews.surveys.rate')} value={fmt.percent(r.response_rate, 0)} help={t('reviews.surveys.rateHelp')} />
            </TileGrid>
            {r.responses === 0 ? (
                <EmptyState icon={MessageSquareText} title={t('reviews.surveys.emptyTitle')} description={t('reviews.surveys.emptyDescription')} />
            ) : (
                <>
                    <Card>
                        <CardHeader>
                            <CardTitle>{t('reviews.surveys.distribution')}</CardTitle>
                            <CardDescription>{t(`reviews.surveys.distributionHelp.${kind}`)}</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Distribution report={r} />
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>{t(`reviews.surveys.trend.${kind}`)}</CardTitle>
                            <CardDescription>{t('reviews.surveys.trendHelp')}</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Trend report={r} />
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>{t('reviews.surveys.recent')}</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <ul className="igr:divide-y">
                                {r.recent.map((row) => (
                                    <li key={row.id} className="igr:flex igr:gap-3 igr:py-2.5">
                                        <span className="igr:flex igr:size-8 igr:shrink-0 igr:items-center igr:justify-center igr:rounded-md igr:border igr:font-semibold igr:tabular-nums">{row.score}</span>
                                        <div className="igr:min-w-0 igr:flex-1 igr:text-[13px]">
                                            <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-2">
                                                <Badge variant={row.category === 'detractor' || row.category === 'unsatisfied' ? 'warning' : row.category === 'promoter' || row.category === 'satisfied' ? 'success' : 'secondary'}>
                                                    {t(`reviews.surveys.category.${row.category}`)}
                                                </Badge>
                                                <span className="igr:text-xs igr:text-muted-foreground">{formatDateTime(row.created_at, workspace.locale, workspace.timezone)}</span>
                                                {row.order ? <span className="igr:text-xs igr:text-muted-foreground">{t('reviews.card.order', { number: row.order })}</span> : null}
                                                {row.contact_id ? (
                                                    <Link className="igr:text-xs igr:text-primary igr:hover:underline" to={`/w/${workspace.id}/contactos/${row.contact_id}`}>
                                                        {t('reviews.card.profile')}
                                                    </Link>
                                                ) : null}
                                            </div>
                                            {row.comment ? <p className="igr:mt-1 igr:whitespace-pre-line">{row.comment}</p> : null}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </CardContent>
                    </Card>
                </>
            )}
        </div>
    );
}

/** Respuestas por nota, coloreadas por categoría, con leyenda y vista de tabla. */
function Distribution({ report }: { report: SurveyReport }) {
    const { t } = useTranslation();
    const theme = useChartTheme();
    const fmt = useFormatters();
    const [table, setTable] = useState(false);
    // Una columna por categoría (apiladas): cada nota llena solo la de su categoría, así el color sigue a la categoría.
    const data = Object.entries(report.distribution)
        .map(([score, count]) => {
            const category = surveyCategory(report.kind, Number(score));
            return { score: Number(score), count, category, [category]: count };
        })
        .sort((a, b) => a.score - b.score);
    const total = Math.max(1, report.responses);
    return (
        <div>
            <div className="igr:flex igr:items-start igr:justify-between igr:gap-2">
                <Legend
                    items={CATEGORY_ORDER[report.kind].map((c) => ({
                        label: `${t(`reviews.surveys.categories.${c}`)} · ${fmt.percent((report.categories[c] ?? 0) / total, 0)}`,
                        color: categoryColor(theme, c),
                    }))}
                />
                <Button size="sm" variant="ghost" onClick={() => setTable(!table)} aria-pressed={table}>
                    {table ? t('reviews.surveys.showChart') : t('reviews.surveys.showTable')}
                </Button>
            </div>
            {table ? (
                <Table>
                    <THead>
                        <TR>
                            <TH>{t('reviews.surveys.scoreCol')}</TH>
                            <TH>{t('reviews.surveys.categoryCol')}</TH>
                            <TH className="igr:text-right">{t('reviews.surveys.responses')}</TH>
                        </TR>
                    </THead>
                    <TBody>
                        {data.map((d) => (
                            <TR key={d.score}>
                                <TD className="igr:tabular-nums">{d.score}</TD>
                                <TD>{t(`reviews.surveys.categories.${d.category}`)}</TD>
                                <TD className="igr:text-right igr:tabular-nums">{fmt.number(d.count)}</TD>
                            </TR>
                        ))}
                    </TBody>
                </Table>
            ) : (
                <CategoryBars
                    data={data}
                    x="score"
                    stacked
                    legend={false}
                    height={220}
                    series={CATEGORY_ORDER[report.kind].map((c) => ({ key: c, label: t(`reviews.surveys.categories.${c}`), color: categoryColor(theme, c) }))}
                    ariaLabel={t('reviews.surveys.distribution')}
                    tooltip={(row) => ({
                        title: t('reviews.surveys.scoreTitle', { score: row.score }),
                        rows: [{ label: t(`reviews.surveys.categories.${row.category}`), value: `${fmt.number(row.count)} · ${fmt.percent(row.count / total, 0)}`, color: categoryColor(theme, row.category) }],
                    })}
                />
            )}
        </div>
    );
}

/** Nota por semana: una sola serie (línea de 2px, puntos de 8px y cruz al pasar el mouse). */
function Trend({ report }: { report: SurveyReport }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const theme = useChartTheme();
    const fmt = useFormatters();
    const color = theme.series[0] ?? '#2a78d6';
    const domain: [number, number] = report.kind === 'nps' ? [-100, 100] : [0, 100];
    const label = (v: number) => (report.kind === 'nps' ? `${v > 0 ? '+' : ''}${fmt.decimal(v, 0)}` : `${fmt.decimal(v, 0)} %`);
    return (
        <LineTrend
            data={report.trend}
            x="week"
            y="score"
            color={color}
            height={220}
            domain={domain}
            referenceY={report.kind === 'nps' ? 0 : undefined}
            xTick={(v) => formatDate(v, workspace.locale, 'UTC')}
            yTick={label}
            ariaLabel={t(`reviews.surveys.trend.${report.kind}`)}
            tooltip={(row) => ({
                title: t('reviews.surveys.weekOf', { date: formatDate(row.week, workspace.locale, 'UTC') }),
                rows: [
                    { label: t(`reviews.surveys.score.${report.kind}`), value: label(row.score), color },
                    { label: t('reviews.surveys.responses'), value: fmt.number(row.responses) },
                ],
            })}
        />
    );
}

function SurveyForm({ kind }: { kind: SurveyKind }) {
    const { workspace } = useWorkspace();
    const survey = useSurvey(workspace.id, kind);
    if (survey.isError) return <ErrorState error={survey.error} onRetry={() => void survey.refetch()} />;
    if (survey.isPending) return <Skeleton className="igr:h-[32rem]" />;
    return <SurveyEditor key={survey.data.id} kind={kind} survey={survey.data} />;
}

function SurveyEditor({ kind, survey }: { kind: SurveyKind; survey: SurveySettings }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canEdit = useCan('manageMarketing');
    const settings = useReviewSettings(workspace.id);
    const save = useSaveSurvey(workspace.id, kind);
    const [draft, setDraft] = useState<SurveyDraft>(() => surveyDraft(survey));
    const [emails, setEmails] = useState(survey.alert_emails.join(', '));
    const [showErrors, setShowErrors] = useState(false);
    const problems = surveyProblems({ ...draft, alert_emails: parseEmails(emails) }, kind);
    const set = (patch: Partial<SurveyDraft>) => setDraft({ ...draft, ...patch });
    const templates = settings.data?.options.templates;

    const submit = () => {
        if (problems.length > 0) {
            setShowErrors(true);
            return;
        }
        save.mutate(
            { ...draft, alert_emails: parseEmails(emails) },
            {
                onSuccess: () => {
                    setShowErrors(false);
                    toast.success(t('reviews.surveys.saved'));
                },
                onError: (e) => toast.error(errorMessage(e, t)),
            },
        );
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t(`reviews.surveys.settingsTitle.${kind}`)}</CardTitle>
                <CardDescription>{t(`reviews.surveys.settingsDescription.${kind}`)}</CardDescription>
            </CardHeader>
            <CardContent className="igr:space-y-4">
                <fieldset disabled={!canEdit} className="igr:space-y-4">
                    <label className="igr:flex igr:items-center igr:gap-2 igr:text-[13px] igr:font-medium">
                        <input type="checkbox" className="igr:size-4" checked={draft.status === 'active'} onChange={(e) => set({ status: e.target.checked ? 'active' : 'paused' })} />
                        {t('reviews.surveys.active')}
                    </label>
                    <div>
                        <Label htmlFor={`sv-q-${kind}`}>{t('reviews.surveys.question')}</Label>
                        <Textarea id={`sv-q-${kind}`} className="igr:mt-1.5" rows={2} maxLength={240} value={draft.question} onChange={(e) => set({ question: e.target.value })} />
                        {showErrors && problems.includes('question') ? <FieldError message={t('reviews.surveys.problems.question')} /> : null}
                    </div>
                    <div>
                        <Label htmlFor={`sv-c-${kind}`}>{t('reviews.surveys.commentLabel')}</Label>
                        <Input id={`sv-c-${kind}`} className="igr:mt-1.5" maxLength={160} value={draft.comment_label} onChange={(e) => set({ comment_label: e.target.value })} />
                    </div>
                    <div>
                        <Label htmlFor={`sv-t-${kind}`}>{t('reviews.surveys.thanks')}</Label>
                        <Input id={`sv-t-${kind}`} className="igr:mt-1.5" maxLength={200} value={draft.thanks} onChange={(e) => set({ thanks: e.target.value })} />
                        {showErrors && problems.includes('thanks') ? <FieldError message={t('reviews.surveys.problems.thanks')} /> : null}
                    </div>
                    <div className="igr:grid igr:grid-cols-2 igr:gap-3">
                        <div>
                            <Label htmlFor={`sv-a-${kind}`}>{t('reviews.surveys.after')}</Label>
                            <Select id={`sv-a-${kind}`} className="igr:mt-1.5" value={draft.after} onChange={(e) => set({ after: e.target.value as 'completed' | 'paid' })}>
                                <option value="completed">{t('reviews.settings.triggers.completed')}</option>
                                <option value="paid">{t('reviews.settings.triggers.paid')}</option>
                            </Select>
                        </div>
                        <div>
                            <Label htmlFor={`sv-d-${kind}`}>{t('reviews.surveys.days')}</Label>
                            <Input id={`sv-d-${kind}`} className="igr:mt-1.5" type="number" min={0} max={120} value={draft.days} onChange={(e) => set({ days: Number(e.target.value) })} />
                        </div>
                    </div>
                    <fieldset>
                        <legend className="igr:text-[13px] igr:font-medium">{t('reviews.surveys.channels')}</legend>
                        <div className="igr:mt-1.5 igr:space-y-2">
                            {CHANNELS.map((c) => (
                                <div key={c} className="igr:space-y-1.5">
                                    <label className="igr:flex igr:items-center igr:gap-2 igr:text-[13px]">
                                        <input type="checkbox" className="igr:size-4" checked={draft.channels.includes(c)} onChange={() => set({ channels: toggleChannel(draft.channels, c) })} />
                                        {t(`reviews.channels.${c}`)}
                                        {draft.channels.includes(c) ? <span className="igr:text-xs igr:text-muted-foreground">{t('reviews.settings.order', { n: draft.channels.indexOf(c) + 1 })}</span> : null}
                                    </label>
                                    {draft.channels.includes(c) ? (
                                        <Select
                                            aria-label={t('reviews.settings.templateFor', { channel: t(`reviews.channels.${c}`) })}
                                            value={draft[`${c}_template_id`] ?? ''}
                                            onChange={(e) => set({ [`${c}_template_id`]: e.target.value === '' ? null : e.target.value })}
                                        >
                                            <option value="">{c === 'email' && kind === 'nps' ? t('reviews.surveys.libraryTemplate') : t('reviews.settings.chooseTemplate')}</option>
                                            {(templates?.[c] ?? []).map((tpl) => (
                                                <option key={tpl.id} value={tpl.id}>
                                                    {tpl.name}
                                                </option>
                                            ))}
                                        </Select>
                                    ) : null}
                                </div>
                            ))}
                        </div>
                        {showErrors && problems.includes('noChannel') ? <FieldError message={t('reviews.settings.problems.noChannel')} /> : null}
                        {showErrors && problems.includes('whatsappTemplate') ? <FieldError message={t('reviews.settings.problems.whatsappTemplate')} /> : null}
                        {showErrors && problems.includes('csatTemplate') ? <FieldError message={t('reviews.surveys.problems.csatTemplate')} /> : null}
                    </fieldset>
                    <div>
                        <Label htmlFor={`sv-th-${kind}`}>{t(`reviews.surveys.threshold.${kind}`)}</Label>
                        <Input id={`sv-th-${kind}`} className="igr:mt-1.5" type="number" min={-1} max={survey.scale.max} value={draft.alert_threshold} onChange={(e) => set({ alert_threshold: Number(e.target.value) })} />
                        <p className="igr:mt-1 igr:text-xs igr:text-muted-foreground">{t('reviews.surveys.thresholdHelp')}</p>
                    </div>
                    <div>
                        <Label htmlFor={`sv-e-${kind}`}>{t('reviews.surveys.alertEmails')}</Label>
                        <Input id={`sv-e-${kind}`} className="igr:mt-1.5" value={emails} onChange={(e) => setEmails(e.target.value)} placeholder={t('reviews.surveys.alertEmailsPlaceholder')} />
                        <p className="igr:mt-1 igr:text-xs igr:text-muted-foreground">{t('reviews.surveys.alertEmailsHelp')}</p>
                        {showErrors && problems.includes('alertEmails') ? <FieldError message={t('reviews.surveys.problems.alertEmails')} /> : null}
                    </div>
                </fieldset>
                <p className="igr:text-xs igr:text-muted-foreground">{t('reviews.surveys.noGating')}</p>
                {canEdit ? (
                    <Button onClick={submit} loading={save.isPending}>
                        {t('common.save')}
                    </Button>
                ) : (
                    <p className="igr:text-xs igr:text-muted-foreground">{t('reviews.settings.readOnly')}</p>
                )}
            </CardContent>
        </Card>
    );
}
