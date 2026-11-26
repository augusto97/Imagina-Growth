import { ArrowDown, ArrowRight, ArrowUp, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { EmptyState, ErrorState, FieldError, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { formatNumber, formatPercent } from '@/lib/format';
import { cn } from '@/lib/cn';
import { toast } from '@/stores/toast';
import { errorMessage } from '@/features/contacts/errors';
import { resultsAudience } from '@/features/analytics/results';
import { type HdyhauSurvey, answerLabel, useHdyhauSurvey, useUpdateHdyhauSurvey } from './api';
import { LABEL_MAX, MAX_OPTIONS, MIN_OPTIONS, type SurveyDraft, addOption, breakdown, draftProblems, moveOption, removeOption, toDraft, toPayload } from './options';

/** Encuesta «¿Cómo nos conociste?»: se muestra en la página de gracias y alimenta la atribución declarada. */
export function SurveyPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    // Vive en la página de gracias de WooCommerce: un negocio sin tienda no tiene dónde mostrarla.
    const store = resultsAudience(workspace).store;
    const survey = useHdyhauSurvey(workspace.id, store);

    return (
        <>
            <PageHeader title={t('survey.title')} description={t('survey.description')} />
            {!store ? (
                <EmptyState illustration="reviews" title={t('survey.services.title')} description={t('survey.services.description')} />
            ) : survey.isError ? (
                <ErrorState error={survey.error} onRetry={() => void survey.refetch()} />
            ) : survey.isPending ? (
                <div className="igr:grid igr:gap-4 igr:lg:grid-cols-[minmax(0,1fr)_360px]">
                    <Skeleton className="igr:h-[28rem]" />
                    <Skeleton className="igr:h-72" />
                </div>
            ) : (
                <div className="igr:space-y-4">
                    <Breakdown survey={survey.data} />
                    <Editor key={survey.data.id} survey={survey.data} />
                </div>
            )}
        </>
    );
}

function Editor({ survey }: { survey: HdyhauSurvey }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canEdit = useCan('manageMarketing');
    const update = useUpdateHdyhauSurvey(workspace.id);
    const [draft, setDraft] = useState<SurveyDraft>(() => toDraft(survey.status, survey.questions));
    const [showErrors, setShowErrors] = useState(false);
    const problems = draftProblems(draft);
    const serverErrors = isApiError(update.error) ? update.error.fieldErrors : {};
    const set = (patch: Partial<SurveyDraft>) => setDraft({ ...draft, ...patch });

    const save = () => {
        if (problems.length > 0) {
            setShowErrors(true);
            return;
        }
        update.mutate(toPayload(draft), {
            onSuccess: (saved) => {
                setDraft(toDraft(saved.status, saved.questions));
                setShowErrors(false);
                toast.success(t('survey.saved'));
            },
            onError: (error) => toast.error(errorMessage(error, t)),
        });
    };

    return (
        <div className="igr:grid igr:items-start igr:gap-4 igr:lg:grid-cols-[minmax(0,1fr)_360px]">
            <div className="igr:space-y-4">
                <Card>
                    <CardHeader className="igr:flex-row igr:items-start igr:justify-between igr:gap-3">
                        <div>
                            <CardTitle>{t('survey.editor.title')}</CardTitle>
                            <CardDescription>{t('survey.editor.description')}</CardDescription>
                        </div>
                        <StatusToggle status={draft.status} disabled={!canEdit} onChange={(status) => set({ status })} />
                    </CardHeader>
                    <CardContent className="igr:space-y-4">
                        <TextField
                            id="survey-title"
                            label={t('survey.editor.surveyTitle')}
                            value={draft.title}
                            max={120}
                            readOnly={!canEdit}
                            onChange={(title) => set({ title })}
                            error={showErrors && problems.includes('title') ? t('survey.errors.title') : serverErrors.title}
                        />
                        <TextField
                            id="survey-question"
                            label={t('survey.editor.question')}
                            value={draft.question}
                            max={200}
                            readOnly={!canEdit}
                            onChange={(question) => set({ question })}
                            error={showErrors && problems.includes('question') ? t('survey.errors.question') : serverErrors.question}
                        />

                        <fieldset className="igr:space-y-2">
                            <legend className="igr:text-[13px] igr:font-medium">{t('survey.editor.options')}</legend>
                            <p className="igr:text-xs igr:text-muted-foreground">{t('survey.editor.optionsHelp', { max: MAX_OPTIONS })}</p>
                            <ol className="igr:space-y-1.5">
                                {draft.options.map((o, i) => (
                                    <li key={o.uid} className="igr:flex igr:items-center igr:gap-1.5">
                                        <span className="igr:w-5 igr:text-right igr:text-xs igr:tabular-nums igr:text-muted-foreground">{i + 1}</span>
                                        <Input
                                            aria-label={t('survey.editor.optionLabel', { n: i + 1 })}
                                            value={o.label}
                                            maxLength={LABEL_MAX}
                                            readOnly={!canEdit}
                                            aria-invalid={showErrors && o.label.trim() === '' ? true : undefined}
                                            onChange={(e) => set({ options: draft.options.map((x, j) => (j === i ? { ...x, label: e.target.value } : x)) })}
                                            onKeyDown={(e) => {
                                                if (e.altKey && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) {
                                                    e.preventDefault();
                                                    set({ options: moveOption(draft.options, i, e.key === 'ArrowUp' ? -1 : 1) });
                                                }
                                            }}
                                        />
                                        {o.key ? null : (
                                            <span className="igr:hidden igr:shrink-0 igr:sm:block">
                                                <Badge variant="brand">{t('survey.editor.new')}</Badge>
                                            </span>
                                        )}
                                        {canEdit && (
                                            <>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    disabled={i === 0}
                                                    aria-label={t('survey.editor.moveUp', { label: o.label })}
                                                    onClick={() => set({ options: moveOption(draft.options, i, -1) })}
                                                >
                                                    <ArrowUp />
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    disabled={i === draft.options.length - 1}
                                                    aria-label={t('survey.editor.moveDown', { label: o.label })}
                                                    onClick={() => set({ options: moveOption(draft.options, i, 1) })}
                                                >
                                                    <ArrowDown />
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    disabled={draft.options.length <= MIN_OPTIONS}
                                                    aria-label={t('survey.editor.remove', { label: o.label })}
                                                    onClick={() => set({ options: removeOption(draft.options, i) })}
                                                >
                                                    <Trash2 />
                                                </Button>
                                            </>
                                        )}
                                    </li>
                                ))}
                            </ol>
                            {showErrors && problems.includes('emptyOption') && <FieldError message={t('survey.errors.emptyOption')} />}
                            {showErrors && problems.includes('duplicateOption') && <FieldError message={t('survey.errors.duplicateOption')} />}
                            <FieldError message={serverErrors.options} />
                            {canEdit && (
                                <div className="igr:flex igr:items-center igr:justify-between igr:gap-2">
                                    <Button type="button" variant="ghost" size="sm" disabled={draft.options.length >= MAX_OPTIONS} onClick={() => set({ options: addOption(draft.options) })}>
                                        <Plus />
                                        {t('survey.editor.addOption')}
                                    </Button>
                                    <span className="igr:text-[11px] igr:text-muted-foreground">{t('survey.editor.reorderHint')}</span>
                                </div>
                            )}
                        </fieldset>

                        <div className="igr:space-y-2 igr:rounded-lg igr:border igr:p-3">
                            <label className="igr:flex igr:items-center igr:gap-2 igr:text-[13px] igr:font-medium">
                                <input
                                    type="checkbox"
                                    className="igr:size-4 igr:accent-[var(--primary)]"
                                    checked={draft.allow_other}
                                    disabled={!canEdit}
                                    onChange={(e) => set({ allow_other: e.target.checked })}
                                />
                                {t('survey.editor.allowOther')}
                            </label>
                            <p className="igr:text-xs igr:text-muted-foreground">{t('survey.editor.allowOtherHelp')}</p>
                            {draft.allow_other && (
                                <TextField id="survey-other" label={t('survey.editor.otherLabel')} value={draft.other_label} max={40} readOnly={!canEdit} onChange={(other_label) => set({ other_label })} />
                            )}
                        </div>

                        <TextField
                            id="survey-thanks"
                            label={t('survey.editor.thanks')}
                            value={draft.thanks}
                            max={160}
                            readOnly={!canEdit}
                            onChange={(thanks) => set({ thanks })}
                            error={showErrors && problems.includes('thanks') ? t('survey.errors.thanks') : serverErrors.thanks}
                        />
                    </CardContent>
                    {canEdit ? (
                        <CardFooter className="igr:justify-end">
                            <Button
                                variant="ghost"
                                onClick={() => {
                                    setDraft(toDraft(survey.status, survey.questions));
                                    setShowErrors(false);
                                }}
                            >
                                {t('survey.discard')}
                            </Button>
                            <Button loading={update.isPending} onClick={save}>
                                {t('survey.save')}
                            </Button>
                        </CardFooter>
                    ) : (
                        <CardFooter>
                            <p className="igr:text-xs igr:text-muted-foreground">{t('survey.readOnly')}</p>
                        </CardFooter>
                    )}
                </Card>
            </div>
            <aside className="igr:space-y-4 igr:lg:sticky igr:lg:top-4">
                <Preview draft={draft} />
            </aside>
        </div>
    );
}

function TextField({
    id,
    label,
    value,
    max,
    readOnly,
    onChange,
    error,
}: {
    id: string;
    label: string;
    value: string;
    max: number;
    readOnly: boolean;
    onChange: (value: string) => void;
    error?: string | undefined;
}) {
    return (
        <div className="igr:space-y-1.5">
            <Label htmlFor={id}>{label}</Label>
            <Input id={id} value={value} maxLength={max} readOnly={readOnly} aria-invalid={error ? true : undefined} onChange={(e) => onChange(e.target.value)} />
            <FieldError message={error} />
        </div>
    );
}

function StatusToggle({ status, disabled, onChange }: { status: 'active' | 'paused'; disabled: boolean; onChange: (status: 'active' | 'paused') => void }) {
    const { t } = useTranslation();
    return (
        <div role="radiogroup" aria-label={t('survey.status.label')} className="igr:flex igr:shrink-0 igr:rounded-md igr:border igr:p-0.5 igr:text-xs">
            {(['active', 'paused'] as const).map((s) => (
                <button
                    key={s}
                    type="button"
                    role="radio"
                    aria-checked={status === s}
                    disabled={disabled}
                    onClick={() => onChange(s)}
                    className={cn(
                        'igr:flex igr:items-center igr:gap-1.5 igr:rounded igr:px-2 igr:py-1 igr:outline-none igr:focus-visible:ring-2 igr:focus-visible:ring-ring',
                        status === s ? 'igr:bg-muted igr:font-medium igr:text-foreground' : 'igr:text-muted-foreground igr:enabled:hover:text-foreground',
                    )}
                >
                    <span aria-hidden="true" className={cn('igr:size-1.5 igr:rounded-full', s === 'active' ? 'igr:bg-success' : 'igr:bg-muted-foreground')} />
                    {t(`survey.status.${s}`)}
                </button>
            ))}
        </div>
    );
}

/** Vista previa del widget tal como se verá en la página de gracias. */
function Preview({ draft }: { draft: SurveyDraft }) {
    const { t } = useTranslation();
    const [selected, setSelected] = useState<string | null>(null);
    const [thanks, setThanks] = useState(false);

    return (
        <Card>
            <CardHeader className="igr:flex-row igr:items-center igr:justify-between">
                <CardTitle>{t('survey.preview.title')}</CardTitle>
                <Button variant="ghost" size="sm" onClick={() => setThanks(!thanks)}>
                    {thanks ? t('survey.preview.showQuestion') : t('survey.preview.showThanks')}
                </Button>
            </CardHeader>
            <CardContent>
                <div className={cn('igr:rounded-xl igr:border igr:bg-background igr:p-4 igr:shadow-sm', draft.status === 'paused' && 'igr:opacity-60')} aria-live="polite">
                    {thanks ? (
                        <p className="igr:py-6 igr:text-center igr:text-sm igr:font-medium">{draft.thanks || '—'}</p>
                    ) : (
                        <>
                            <p className="igr:text-[11px] igr:font-medium igr:uppercase igr:tracking-wide igr:text-muted-foreground">{draft.title || '—'}</p>
                            <p className="igr:mt-1 igr:text-sm igr:font-semibold">{draft.question || '—'}</p>
                            <div className="igr:mt-3 igr:flex igr:flex-wrap igr:gap-1.5">
                                {draft.options.map((o) => (
                                    <button
                                        key={o.uid}
                                        type="button"
                                        aria-pressed={selected === o.uid}
                                        onClick={() => setSelected(o.uid)}
                                        className={cn(
                                            'igr:rounded-full igr:border igr:px-3 igr:py-1 igr:text-xs igr:outline-none igr:transition-colors igr:focus-visible:ring-2 igr:focus-visible:ring-ring',
                                            selected === o.uid ? 'igr:border-primary igr:bg-primary igr:text-primary-foreground' : 'igr:hover:bg-accent',
                                        )}
                                    >
                                        {o.label || '…'}
                                    </button>
                                ))}
                                {draft.allow_other && (
                                    <button
                                        type="button"
                                        aria-pressed={selected === '__other'}
                                        onClick={() => setSelected('__other')}
                                        className={cn(
                                            'igr:rounded-full igr:border igr:border-dashed igr:px-3 igr:py-1 igr:text-xs igr:outline-none igr:focus-visible:ring-2 igr:focus-visible:ring-ring',
                                            selected === '__other' ? 'igr:border-primary igr:text-primary' : 'igr:hover:bg-accent',
                                        )}
                                    >
                                        {draft.other_label || t('survey.preview.other')}
                                    </button>
                                )}
                            </div>
                            {selected === '__other' && <Input className="igr:mt-2" placeholder={t('survey.preview.otherPlaceholder')} aria-label={t('survey.preview.otherPlaceholder')} />}
                        </>
                    )}
                </div>
                {draft.status === 'paused' && <p className="igr:mt-2 igr:text-xs igr:text-muted-foreground">{t('survey.preview.paused')}</p>}
            </CardContent>
        </Card>
    );
}

/**
 * Lo que responden tus clientes, arriba de todo: la conclusión (la respuesta más común), el reparto en barras de un solo
 * tono y el enlace al tablero que lo cruza con lo que mide la web.
 */
function Breakdown({ survey }: { survey: HdyhauSurvey }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const rows = breakdown(survey.responses.by_answer, survey.responses.total);
    const max = Math.max(1, ...rows.map((r) => r.count));
    const top = rows[0];

    return (
        <Card>
            <CardHeader className="igr:flex igr:flex-row igr:flex-wrap igr:items-start igr:justify-between igr:gap-2 igr:space-y-0">
                <div className="igr:min-w-0 igr:flex-1 igr:basis-64">
                    <p className="igr:text-caption igr:font-medium igr:text-muted-foreground">
                        {t('survey.breakdown.title')} · {t('survey.breakdown.total', { count: survey.responses.total, formatted: formatNumber(survey.responses.total, workspace.locale) })}
                    </p>
                    <CardTitle className="igr:mt-0.5">
                        {top ? t('survey.breakdown.top', { answer: answerLabel(survey, top.answer), share: formatPercent(top.share, workspace.locale) }) : t('survey.breakdown.empty')}
                    </CardTitle>
                    <CardDescription className="igr:mt-0.5">{top ? t('survey.breakdown.help') : t('survey.breakdown.emptyHelp')}</CardDescription>
                </div>
                {top ? (
                    <Button asChild variant="outline" size="sm">
                        <Link to={`/w/${workspace.id}/analitica/como-nos-conociste`}>
                            {t('survey.breakdown.compare')}
                            <ArrowRight aria-hidden />
                        </Link>
                    </Button>
                ) : null}
            </CardHeader>
            {rows.length > 0 ? (
                <CardContent>
                    <ul className="igr:grid igr:gap-x-8 igr:gap-y-2.5 igr:md:grid-cols-2">
                        {rows.map((r) => (
                            <li key={r.answer}>
                                <div className="igr:flex igr:items-baseline igr:justify-between igr:gap-3 igr:text-[13px]">
                                    <span className="igr:truncate">{answerLabel(survey, r.answer)}</span>
                                    <span className="igr:shrink-0 igr:text-xs igr:tabular-nums igr:text-muted-foreground">
                                        {formatNumber(r.count, workspace.locale)} · {formatPercent(r.share, workspace.locale)}
                                    </span>
                                </div>
                                <div className="igr:mt-1 igr:h-1.5 igr:rounded-full igr:bg-muted" aria-hidden="true">
                                    <div className="igr:h-1.5 igr:rounded-full igr:bg-primary" style={{ width: `${Math.max(2, (r.count / max) * 100)}%` }} />
                                </div>
                            </li>
                        ))}
                    </ul>
                </CardContent>
            ) : null}
        </Card>
    );
}
