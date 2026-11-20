import type { TFunction } from 'i18next';
import { ArrowDown, ArrowUp, Filter, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input, Select } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { EmptyState, ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { useWorkspace } from '@/hooks/useWorkspace';
import { toast } from '@/stores/toast';
import { errorMessage } from '@/features/contacts/errors';
import {
    ACTIONS,
    changeType,
    choiceQuestions,
    draftErrors,
    emptyDraft,
    LINKS,
    MAX_OPTIONS,
    MAX_QUESTIONS,
    MAX_RULES,
    newQuestion,
    nextKey,
    parseWords,
    QUESTION_TYPES,
    useQualification,
    useQualificationSets,
    WHEN,
    type QualificationDraft,
    type QualificationIndex,
    type QualificationSet,
    type Question,
    type RouteRule,
    type RouteTarget,
} from './qualification';

/** Leads → Calificación (auditoría §5.1 función 3): lo que el agente pregunta en el chat y a dónde deriva. */
export function QualificationTab() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const index = useQualification(workspace.id);
    const [editing, setEditing] = useState<QualificationSet | 'new' | null>(null);
    if (index.isError) return <ErrorState error={index.error} onRetry={() => void index.refetch()} />;
    if (index.isPending) return <Skeleton className="igr:h-72" />;
    const { sets, can_manage: canManage } = index.data;

    return (
        <div className="igr:space-y-4">
            <Card>
                <CardHeader>
                    <CardTitle>{t('qualification.title')}</CardTitle>
                    <CardDescription>{t('qualification.description')}</CardDescription>
                </CardHeader>
                <CardContent className="igr:space-y-2">
                    {sets.length === 0 && editing === null ? (
                        <EmptyState
                            icon={Filter}
                            title={t('qualification.emptyTitle')}
                            description={t('qualification.emptyDescription')}
                            action={
                                canManage ? (
                                    <Button size="sm" onClick={() => setEditing('new')}>
                                        <Plus />
                                        {t('qualification.new')}
                                    </Button>
                                ) : undefined
                            }
                        />
                    ) : null}
                    {sets.map((s) =>
                        editing !== 'new' && editing?.id === s.id ? null : (
                            <div key={s.id} className="igr:flex igr:items-center igr:justify-between igr:gap-2 igr:rounded-md igr:border igr:p-2.5 igr:text-[13px]">
                                <span>
                                    <span className="igr:font-medium">{s.name}</span>
                                    {!s.active ? (
                                        <Badge variant="outline" className="igr:ml-2">
                                            {t('qualification.inactive')}
                                        </Badge>
                                    ) : null}
                                    <span className="igr:block igr:text-xs igr:text-muted-foreground">
                                        {t('qualification.questionsCount', { count: s.questions.length })} · {scopeText(t, s, index.data)}
                                    </span>
                                </span>
                                {canManage ? (
                                    <Button variant="outline" size="sm" onClick={() => setEditing(s)}>
                                        {t('qualification.edit')}
                                    </Button>
                                ) : null}
                            </div>
                        ),
                    )}
                    {editing !== null ? (
                        <SetEditor key={editing === 'new' ? 'new' : editing.id} set={editing === 'new' ? null : editing} index={index.data} onDone={() => setEditing(null)} />
                    ) : canManage && sets.length > 0 ? (
                        <Button variant="outline" size="sm" onClick={() => setEditing('new')}>
                            <Plus />
                            {t('qualification.new')}
                        </Button>
                    ) : null}
                </CardContent>
            </Card>
        </div>
    );
}

function scopeText(t: TFunction, s: QualificationSet, index: QualificationIndex): string {
    const pipeline = index.pipelines.find((p) => p.id === s.pipeline_id)?.name;
    const parts = [pipeline ? t('qualification.scopePipeline', { name: pipeline }) : t('qualification.scopeAll')];
    if (s.services.length > 0) parts.push(t('qualification.scopeServices', { words: s.services.join(', ') }));
    return parts.join(' · ');
}

function SetEditor({ set, index, onDone }: { set: QualificationSet | null; index: QualificationIndex; onDone: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const { create, update, remove } = useQualificationSets(workspace.id);
    const [draft, setDraft] = useState<QualificationDraft>(() => (set ? { ...set } : emptyDraft()));
    const [services, setServices] = useState((set?.services ?? []).join(', '));
    const labels = { yes: t('qualification.yes'), no: t('qualification.no'), option: (n: number) => t('qualification.optionN', { n }) };
    const errors = draftErrors(draft);
    const fail = (e: unknown) => toast.error(errorMessage(e, t));
    const patch = (p: Partial<QualificationDraft>) => setDraft((d) => ({ ...d, ...p }));
    const setQuestion = (i: number, q: Question) => patch({ questions: draft.questions.map((x, j) => (j === i ? q : x)) });
    const move = (i: number, j: number) => {
        const next = [...draft.questions];
        const a = next[i];
        const b = next[j];
        if (!a || !b) return;
        next[i] = b;
        next[j] = a;
        patch({ questions: next });
    };
    const setRule = (i: number, r: RouteRule) => patch({ routing: { ...draft.routing, rules: draft.routing.rules.map((x, j) => (j === i ? r : x)) } });

    return (
        <form
            className="igr:space-y-4 igr:rounded-md igr:border igr:p-3"
            onSubmit={(e) => {
                e.preventDefault();
                if (errors.length > 0) return;
                const body = { ...draft, services: parseWords(services) };
                const done = {
                    onSuccess: () => {
                        toast.success(t('qualification.saved'));
                        onDone();
                    },
                    onError: fail,
                };
                if (set) update.mutate({ ...body, id: set.id }, done);
                else create.mutate(body, done);
            }}
        >
            <div className="igr:grid igr:gap-3 sm:igr:grid-cols-2">
                <div className="igr:space-y-1">
                    <Label htmlFor="q-name">{t('qualification.name')}</Label>
                    <Input id="q-name" maxLength={80} placeholder={t('qualification.namePlaceholder')} value={draft.name} onChange={(e) => patch({ name: e.target.value })} />
                </div>
                <div className="igr:space-y-1">
                    <Label htmlFor="q-pipeline">{t('qualification.pipeline')}</Label>
                    <Select id="q-pipeline" value={draft.pipeline_id ?? ''} onChange={(e) => patch({ pipeline_id: e.target.value || null })}>
                        <option value="">{t('qualification.allPipelines')}</option>
                        {index.pipelines.map((p) => (
                            <option key={p.id} value={p.id}>
                                {p.name}
                            </option>
                        ))}
                    </Select>
                </div>
                <div className="igr:space-y-1 sm:igr:col-span-2">
                    <Label htmlFor="q-services">{t('qualification.services')}</Label>
                    <Input id="q-services" placeholder={t('qualification.servicesPlaceholder')} value={services} onChange={(e) => setServices(e.target.value)} />
                    <p className="igr:text-xs igr:text-muted-foreground">{t('qualification.servicesHelp')}</p>
                </div>
                <label className="igr:flex igr:items-center igr:gap-2 igr:text-[13px]">
                    <input type="checkbox" checked={draft.active} onChange={(e) => patch({ active: e.target.checked })} />
                    {t('qualification.active')}
                </label>
            </div>

            <section className="igr:space-y-2">
                <h3 className="igr:text-[13px] igr:font-semibold">{t('qualification.questions')}</h3>
                <p className="igr:text-xs igr:text-muted-foreground">{t('qualification.questionsHelp')}</p>
                <ol className="igr:space-y-3">
                    {draft.questions.map((q, i) => (
                        <li key={q.key} className="igr:space-y-2 igr:rounded-md igr:border igr:p-2.5">
                            <div className="igr:flex igr:items-center igr:gap-2">
                                <Input
                                    aria-label={t('qualification.questionText')}
                                    maxLength={200}
                                    placeholder={t('qualification.questionPlaceholder')}
                                    value={q.text}
                                    onChange={(e) => setQuestion(i, { ...q, text: e.target.value })}
                                />
                                <Button type="button" variant="ghost" size="icon" aria-label={t('qualification.up')} disabled={i === 0} onClick={() => move(i, i - 1)}>
                                    <ArrowUp />
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label={t('qualification.down')}
                                    disabled={i === draft.questions.length - 1}
                                    onClick={() => move(i, i + 1)}
                                >
                                    <ArrowDown />
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label={t('qualification.removeQuestion')}
                                    onClick={() => patch({ questions: draft.questions.filter((_, j) => j !== i) })}
                                >
                                    <Trash2 />
                                </Button>
                            </div>
                            <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-3">
                                <Select
                                    aria-label={t('qualification.type')}
                                    className="igr:w-52"
                                    value={q.type}
                                    onChange={(e) => setQuestion(i, changeType(q, e.target.value as Question['type'], labels))}
                                >
                                    {QUESTION_TYPES.map((k) => (
                                        <option key={k} value={k}>
                                            {t(`qualification.types.${k}`)}
                                        </option>
                                    ))}
                                </Select>
                                <label className="igr:flex igr:items-center igr:gap-2 igr:text-[13px]">
                                    <input type="checkbox" checked={q.required} onChange={(e) => setQuestion(i, { ...q, required: e.target.checked })} />
                                    {t('qualification.required')}
                                </label>
                            </div>
                            <QuestionScoring q={q} onChange={(next) => setQuestion(i, next)} />
                        </li>
                    ))}
                </ol>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={draft.questions.length >= MAX_QUESTIONS}
                    onClick={() =>
                        patch({
                            questions: [
                                ...draft.questions,
                                newQuestion(
                                    'choice',
                                    draft.questions.map((x) => x.key),
                                    labels,
                                ),
                            ],
                        })
                    }
                >
                    <Plus />
                    {t('qualification.addQuestion')}
                </Button>
            </section>

            <section className="igr:space-y-2">
                <h3 className="igr:text-[13px] igr:font-semibold">{t('qualification.routing')}</h3>
                <p className="igr:text-xs igr:text-muted-foreground">{t('qualification.routingHelp')}</p>
                <ol className="igr:space-y-2">
                    {draft.routing.rules.map((r, i) => (
                        <li key={i} className="igr:flex igr:flex-wrap igr:items-center igr:gap-2 igr:rounded-md igr:border igr:p-2">
                            <span className="igr:text-[13px]">{t('qualification.if')}</span>
                            <RuleCondition rule={r} draft={draft} onChange={(next) => setRule(i, next)} />
                            <span className="igr:text-[13px]">{t('qualification.then')}</span>
                            <TargetInput value={r} index={index} onChange={(target) => setRule(i, { ...r, ...target })} />
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="igr:ml-auto"
                                aria-label={t('qualification.removeRule')}
                                onClick={() => patch({ routing: { ...draft.routing, rules: draft.routing.rules.filter((_, j) => j !== i) } })}
                            >
                                <Trash2 />
                            </Button>
                        </li>
                    ))}
                </ol>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={draft.routing.rules.length >= MAX_RULES}
                    onClick={() => patch({ routing: { ...draft.routing, rules: [...draft.routing.rules, { when: 'score_gte', value: 50, action: 'seller' }] } })}
                >
                    <Plus />
                    {t('qualification.addRule')}
                </Button>
                <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-2 igr:rounded-md igr:bg-muted/40 igr:p-2">
                    <span className="igr:text-[13px]">{t('qualification.otherwise')}</span>
                    <TargetInput value={draft.routing.default} index={index} onChange={(target) => patch({ routing: { ...draft.routing, default: target } })} />
                </div>
            </section>

            {errors.length > 0 ? (
                <ul className="igr:text-xs igr:text-destructive">
                    {errors.map((e) => (
                        <li key={e}>{t(`qualification.errors.${e}`)}</li>
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
                {set ? (
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        className="igr:ml-auto igr:text-destructive"
                        onClick={() => remove.mutate(set.id, { onSuccess: onDone, onError: fail })}
                    >
                        {t('qualification.delete')}
                    </Button>
                ) : null}
            </div>
        </form>
    );
}

/** Puntos y descalificación de cada tipo de pregunta. */
function QuestionScoring({ q, onChange }: { q: Question; onChange: (q: Question) => void }) {
    const { t } = useTranslation();
    const num = (v: string, fallback: number) => (v === '' || Number.isNaN(Number(v)) ? fallback : Math.max(-50, Math.min(50, Math.round(Number(v)))));
    if (q.type === 'choice' || q.type === 'yes_no') {
        const options = q.options ?? [];
        const setOption = (j: number, patch: Partial<NonNullable<Question['options']>[number]>) =>
            onChange({ ...q, options: options.map((o, k) => (k === j ? { ...o, ...patch } : o)) });
        return (
            <div className="igr:space-y-1.5">
                {options.map((o, j) => (
                    <div key={o.key} className="igr:flex igr:flex-wrap igr:items-center igr:gap-2">
                        <Input
                            aria-label={t('qualification.optionLabel')}
                            className="igr:w-56"
                            maxLength={60}
                            value={o.label}
                            disabled={q.type === 'yes_no'}
                            onChange={(e) => setOption(j, { label: e.target.value })}
                        />
                        <Input
                            aria-label={t('qualification.points')}
                            type="number"
                            min={-50}
                            max={50}
                            className="igr:w-24"
                            value={o.points}
                            onChange={(e) => setOption(j, { points: num(e.target.value, 0) })}
                        />
                        <span className="igr:text-xs igr:text-muted-foreground">{t('qualification.pointsUnit')}</span>
                        <label className="igr:flex igr:items-center igr:gap-1.5 igr:text-[13px]">
                            <input type="checkbox" checked={o.disqualifies} onChange={(e) => setOption(j, { disqualifies: e.target.checked })} />
                            {t('qualification.disqualifies')}
                        </label>
                        {q.type === 'choice' && options.length > 2 ? (
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                aria-label={t('qualification.removeOption')}
                                onClick={() => onChange({ ...q, options: options.filter((_, k) => k !== j) })}
                            >
                                <Trash2 />
                            </Button>
                        ) : null}
                    </div>
                ))}
                {q.type === 'choice' && options.length < MAX_OPTIONS ? (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() =>
                            onChange({
                                ...q,
                                options: [
                                    ...options,
                                    {
                                        key: nextKey(
                                            'o',
                                            options.map((o) => o.key),
                                        ),
                                        label: '',
                                        points: 0,
                                        disqualifies: false,
                                    },
                                ],
                            })
                        }
                    >
                        <Plus />
                        {t('qualification.addOption')}
                    </Button>
                ) : null}
            </div>
        );
    }
    if (q.type === 'number') {
        const bound = (v: string) => (v === '' || Number.isNaN(Number(v)) ? null : Number(v));
        return (
            <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-2 igr:text-[13px]">
                <span>{t('qualification.accepted')}</span>
                <Input
                    aria-label={t('qualification.min')}
                    type="number"
                    className="igr:w-28"
                    placeholder={t('qualification.min')}
                    value={q.min ?? ''}
                    onChange={(e) => onChange({ ...q, min: bound(e.target.value) })}
                />
                <span>–</span>
                <Input
                    aria-label={t('qualification.max')}
                    type="number"
                    className="igr:w-28"
                    placeholder={t('qualification.max')}
                    value={q.max ?? ''}
                    onChange={(e) => onChange({ ...q, max: bound(e.target.value) })}
                />
                <Input
                    aria-label={t('qualification.points')}
                    type="number"
                    min={-50}
                    max={50}
                    className="igr:w-24"
                    value={q.points ?? 0}
                    onChange={(e) => onChange({ ...q, points: num(e.target.value, 0) })}
                />
                <span className="igr:text-xs igr:text-muted-foreground">{t('qualification.pointsIfInRange')}</span>
                <label className="igr:flex igr:items-center igr:gap-1.5">
                    <input type="checkbox" checked={q.out_of_range === 'disqualify'} onChange={(e) => onChange({ ...q, out_of_range: e.target.checked ? 'disqualify' : 'none' })} />
                    {t('qualification.outOfRange')}
                </label>
            </div>
        );
    }
    return (
        <div className="igr:flex igr:items-center igr:gap-2 igr:text-[13px]">
            <Input
                aria-label={t('qualification.points')}
                type="number"
                min={-50}
                max={50}
                className="igr:w-24"
                value={q.points ?? 0}
                onChange={(e) => onChange({ ...q, points: num(e.target.value, 0) })}
            />
            <span className="igr:text-xs igr:text-muted-foreground">{t('qualification.pointsIfAnswered')}</span>
        </div>
    );
}

function RuleCondition({ rule, draft, onChange }: { rule: RouteRule; draft: QualificationDraft; onChange: (r: RouteRule) => void }) {
    const { t } = useTranslation();
    const questions = choiceQuestions(draft);
    const question = questions.find((q) => q.key === rule.question);
    return (
        <>
            <Select
                aria-label={t('qualification.when')}
                className="igr:w-56"
                value={rule.when}
                onChange={(e) => {
                    const when = e.target.value as RouteRule['when'];
                    const first = questions[0];
                    onChange(
                        when === 'answer'
                            ? { ...rule, when, question: first?.key, option: first?.options?.[0]?.key }
                            : { ...rule, when, value: when === 'disqualified' ? undefined : (rule.value ?? 50) },
                    );
                }}
            >
                {WHEN.map((w) => (
                    <option key={w} value={w} disabled={w === 'answer' && questions.length === 0}>
                        {t(`qualification.whens.${w}`)}
                    </option>
                ))}
            </Select>
            {rule.when === 'score_gte' || rule.when === 'score_lt' ? (
                <Input
                    aria-label={t('qualification.score')}
                    type="number"
                    className="igr:w-24"
                    value={rule.value ?? 0}
                    onChange={(e) => onChange({ ...rule, value: Math.round(Number(e.target.value) || 0) })}
                />
            ) : null}
            {rule.when === 'answer' ? (
                <>
                    <Select
                        aria-label={t('qualification.question')}
                        className="igr:w-56"
                        value={rule.question ?? ''}
                        onChange={(e) => onChange({ ...rule, question: e.target.value, option: questions.find((q) => q.key === e.target.value)?.options?.[0]?.key })}
                    >
                        {questions.map((q) => (
                            <option key={q.key} value={q.key}>
                                {q.text || q.key}
                            </option>
                        ))}
                    </Select>
                    <Select aria-label={t('qualification.answer')} className="igr:w-40" value={rule.option ?? ''} onChange={(e) => onChange({ ...rule, option: e.target.value })}>
                        {(question?.options ?? []).map((o) => (
                            <option key={o.key} value={o.key}>
                                {o.label || o.key}
                            </option>
                        ))}
                    </Select>
                </>
            ) : null}
        </>
    );
}

function TargetInput({ value, index, onChange }: { value: RouteTarget; index: QualificationIndex; onChange: (t: RouteTarget) => void }) {
    const { t } = useTranslation();
    const link = value.link ?? 'catalog';
    const preset = link === 'booking' ? index.links.booking : link === 'catalog' ? index.links.catalog : null;
    return (
        <>
            <Select
                aria-label={t('qualification.action')}
                className="igr:w-52"
                value={value.action}
                onChange={(e) => onChange(e.target.value === 'web' ? { action: 'web', link: 'catalog', url: null } : { action: e.target.value as RouteTarget['action'] })}
            >
                {ACTIONS.map((a) => (
                    <option key={a} value={a}>
                        {t(`qualification.actions.${a}`)}
                    </option>
                ))}
            </Select>
            {value.action === 'web' ? (
                <>
                    <Select
                        aria-label={t('qualification.link')}
                        className="igr:w-40"
                        value={link}
                        onChange={(e) => onChange({ ...value, link: e.target.value as RouteTarget['link'] })}
                    >
                        {LINKS.map((l) => (
                            <option key={l} value={l}>
                                {t(`qualification.links.${l}`)}
                            </option>
                        ))}
                    </Select>
                    <Input
                        aria-label={t('qualification.url')}
                        type="url"
                        className="igr:w-64"
                        placeholder={preset ?? 'https://'}
                        value={value.url ?? ''}
                        onChange={(e) => onChange({ ...value, url: e.target.value.trim() === '' ? null : e.target.value.trim() })}
                    />
                </>
            ) : null}
        </>
    );
}
