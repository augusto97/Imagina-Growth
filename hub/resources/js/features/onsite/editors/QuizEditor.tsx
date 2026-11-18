import { Plus, X } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Input, Select } from '@/components/ui/input';
import { linesToList, newQuestionId, slugKey } from '../model';
import type { OnsiteOptions, ProductRule, Quiz, QuizQuestion, QuizRule } from '../types';
import { Check, Field, Section } from './fields';

const EMPTY_RULE: ProductRule = { product_ids: [], category_ids: [], brands: [], max_price: null, kind: null };

/** Preguntas con lógica (a qué pregunta lleva cada respuesta) y reglas de resultado → productos. */
export function QuizEditor({ quiz, options, onChange }: { quiz: Quiz; options: OnsiteOptions | undefined; onChange: (q: Quiz) => void }) {
    const { t } = useTranslation();
    const setQuestion = (i: number, patch: Partial<QuizQuestion>) => onChange({ ...quiz, questions: quiz.questions.map((q, j) => (j === i ? { ...q, ...patch } : q)) });
    const setRule = (i: number, patch: Partial<QuizRule>) => onChange({ ...quiz, outcomes: { ...quiz.outcomes, rules: quiz.outcomes.rules.map((r, j) => (j === i ? { ...r, ...patch } : r)) } });

    return (
        <div className="igr:space-y-5">
            <Section title={t('onsite.quiz.questions')} description={t('onsite.quiz.questionsHelp')}>
                {quiz.questions.map((q, i) => (
                    <div key={q.id} className="igr:space-y-3 igr:rounded-md igr:border igr:p-3">
                        <div className="igr:flex igr:items-start igr:gap-2">
                            <span className="igr:mt-2 igr:rounded igr:bg-muted igr:px-1.5 igr:py-0.5 igr:font-mono igr:text-xs">{q.id}</span>
                            <Field label={t('onsite.quiz.text')} className="igr:flex-1">
                                {(id) => <Input id={id} value={q.text} maxLength={200} onChange={(e) => setQuestion(i, { text: e.target.value })} />}
                            </Field>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="igr:mt-6"
                                aria-label={t('onsite.quiz.removeQuestion')}
                                disabled={quiz.questions.length <= 1}
                                onClick={() => onChange({ ...quiz, questions: quiz.questions.filter((_, j) => j !== i) })}
                            >
                                <X />
                            </Button>
                        </div>
                        <Field label={t('onsite.quiz.attribute')} help={t('onsite.quiz.attributeHelp')}>
                            {(id) => <Input id={id} value={q.attribute ?? ''} placeholder="quiz_tipo_piel" onChange={(e) => setQuestion(i, { attribute: e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, '') || undefined })} />}
                        </Field>
                        <div className="igr:space-y-2">
                            <p className="igr:text-[13px] igr:font-medium">{t('onsite.quiz.options')}</p>
                            {q.options.map((o, k) => (
                                <div key={k} className="igr:grid igr:grid-cols-[1fr_210px_auto] igr:items-center igr:gap-2">
                                    <Input
                                        value={o.label}
                                        aria-label={t('onsite.quiz.optionLabel', { n: k + 1 })}
                                        maxLength={120}
                                        onChange={(e) => setQuestion(i, { options: q.options.map((x, m) => (m === k ? { ...x, label: e.target.value, key: x.key || slugKey(e.target.value, `o${k + 1}`) } : x)) })}
                                    />
                                    <Select
                                        aria-label={t('onsite.quiz.next', { n: k + 1 })}
                                        value={o.next ?? ''}
                                        onChange={(e) => setQuestion(i, { options: q.options.map((x, m) => (m === k ? { ...x, next: e.target.value || undefined } : x)) })}
                                    >
                                        <option value="">{t('onsite.quiz.nextDefault')}</option>
                                        {quiz.questions
                                            .filter((other) => other.id !== q.id)
                                            .map((other) => (
                                                <option key={other.id} value={other.id}>
                                                    {t('onsite.quiz.goTo', { id: other.id })}
                                                </option>
                                            ))}
                                        <option value="end">{t('onsite.quiz.end')}</option>
                                    </Select>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        aria-label={t('onsite.quiz.removeOption')}
                                        disabled={q.options.length <= 2}
                                        onClick={() => setQuestion(i, { options: q.options.filter((_, m) => m !== k) })}
                                    >
                                        <X />
                                    </Button>
                                </div>
                            ))}
                            {q.options.length < 12 ? (
                                <Button type="button" variant="ghost" size="sm" onClick={() => setQuestion(i, { options: [...q.options, { key: `o${q.options.length + 1}`, label: '' }] })}>
                                    <Plus />
                                    {t('onsite.quiz.addOption')}
                                </Button>
                            ) : null}
                        </div>
                    </div>
                ))}
                {quiz.questions.length < 20 ? (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => onChange({ ...quiz, questions: [...quiz.questions, { id: newQuestionId(quiz), text: '', options: [{ key: 'o1', label: '' }, { key: 'o2', label: '' }] }] })}
                    >
                        <Plus />
                        {t('onsite.quiz.addQuestion')}
                    </Button>
                ) : null}
            </Section>

            <Section title={t('onsite.quiz.outcomes')} description={t('onsite.quiz.outcomesHelp')}>
                {quiz.outcomes.rules.map((rule, i) => (
                    <div key={i} className="igr:space-y-3 igr:rounded-md igr:border igr:p-3">
                        <div className="igr:flex igr:items-end igr:gap-2">
                            <Field label={t('onsite.quiz.ruleName')} className="igr:flex-1">
                                {(id) => <Input id={id} value={rule.label} maxLength={120} onChange={(e) => setRule(i, { label: e.target.value })} />}
                            </Field>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                aria-label={t('onsite.quiz.removeRule')}
                                onClick={() => onChange({ ...quiz, outcomes: { ...quiz.outcomes, rules: quiz.outcomes.rules.filter((_, j) => j !== i) } })}
                            >
                                <X />
                            </Button>
                        </div>
                        <div className="igr:space-y-1">
                            <p className="igr:text-[13px] igr:font-medium">{t('onsite.quiz.when')}</p>
                            {quiz.questions.map((q) => (
                                <div key={q.id} className="igr:flex igr:flex-wrap igr:items-center igr:gap-x-3 igr:gap-y-1 igr:text-[13px]">
                                    <span className="igr:w-full igr:text-xs igr:text-muted-foreground igr:sm:w-40 igr:sm:truncate">{q.text || q.id}</span>
                                    {q.options.map((o) => (
                                        <Check
                                            key={o.key}
                                            checked={(rule.match[q.id] ?? []).includes(o.key)}
                                            onChange={(on) => {
                                                const current = rule.match[q.id] ?? [];
                                                setRule(i, { match: { ...rule.match, [q.id]: on ? [...current, o.key] : current.filter((k) => k !== o.key) } });
                                            }}
                                        >
                                            {o.label || o.key}
                                        </Check>
                                    ))}
                                </div>
                            ))}
                            <p className="igr:text-xs igr:text-muted-foreground">{t('onsite.quiz.whenHelp')}</p>
                        </div>
                        <ProductRuleEditor rule={rule.products} options={options} onChange={(products) => setRule(i, { products })} />
                        <Field label={t('onsite.quiz.message')}>{(id) => <Input id={id} value={rule.message} maxLength={300} onChange={(e) => setRule(i, { message: e.target.value })} />}</Field>
                    </div>
                ))}
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => onChange({ ...quiz, outcomes: { ...quiz.outcomes, rules: [...quiz.outcomes.rules, { label: '', match: {}, products: { ...EMPTY_RULE }, message: '' }] } })}
                >
                    <Plus />
                    {t('onsite.quiz.addRule')}
                </Button>
                <div className="igr:space-y-3 igr:rounded-md igr:border igr:border-dashed igr:p-3">
                    <p className="igr:text-[13px] igr:font-medium">{t('onsite.quiz.fallback')}</p>
                    <ProductRuleEditor rule={quiz.outcomes.fallback.products} options={options} allowKind onChange={(products) => onChange({ ...quiz, outcomes: { ...quiz.outcomes, fallback: { ...quiz.outcomes.fallback, products } } })} />
                    <Field label={t('onsite.quiz.message')}>
                        {(id) => <Input id={id} value={quiz.outcomes.fallback.message} maxLength={300} onChange={(e) => onChange({ ...quiz, outcomes: { ...quiz.outcomes, fallback: { ...quiz.outcomes.fallback, message: e.target.value } } })} />}
                    </Field>
                </div>
                <Field label={t('onsite.quiz.limit')}>
                    {(id) => <Input id={id} type="number" min={1} max={12} className="igr:w-24" value={quiz.outcomes.limit} onChange={(e) => onChange({ ...quiz, outcomes: { ...quiz.outcomes, limit: Number(e.target.value) } })} />}
                </Field>
            </Section>
        </div>
    );
}

function ProductRuleEditor({ rule, options, onChange, allowKind = false }: { rule: ProductRule; options: OnsiteOptions | undefined; onChange: (r: ProductRule) => void; allowKind?: boolean }) {
    const { t } = useTranslation();
    return (
        <div className="igr:grid igr:gap-3 igr:sm:grid-cols-2">
            {allowKind ? (
                <Field label={t('onsite.quiz.products.kind')} help={t('onsite.quiz.products.kindHelp')} className="igr:sm:col-span-2">
                    {(id) => (
                        <Select id={id} value={rule.kind ?? ''} onChange={(e) => onChange({ ...rule, kind: (e.target.value || null) as ProductRule['kind'] })}>
                            <option value="">{t('onsite.quiz.products.byRules')}</option>
                            <option value="trending">{t('onsite.quiz.products.kinds.trending')}</option>
                            <option value="best_sellers_category">{t('onsite.quiz.products.kinds.best_sellers_category')}</option>
                            <option value="para_ti">{t('onsite.quiz.products.kinds.para_ti')}</option>
                        </Select>
                    )}
                </Field>
            ) : null}
            <Field label={t('onsite.quiz.products.categories')}>
                {(id) => (
                    <select
                        id={id}
                        multiple
                        className="igr:h-24 igr:w-full igr:rounded-md igr:border igr:bg-transparent igr:px-2 igr:py-1 igr:text-[13px]"
                        value={rule.category_ids ?? []}
                        onChange={(e) => onChange({ ...rule, category_ids: Array.from(e.target.selectedOptions).map((o) => o.value) })}
                    >
                        {(options?.categories ?? []).map((c) => (
                            <option key={c.id} value={c.id}>
                                {c.name}
                            </option>
                        ))}
                    </select>
                )}
            </Field>
            <Field label={t('onsite.quiz.products.brands')}>
                {(id) => (
                    <select
                        id={id}
                        multiple
                        className="igr:h-24 igr:w-full igr:rounded-md igr:border igr:bg-transparent igr:px-2 igr:py-1 igr:text-[13px]"
                        value={rule.brands ?? []}
                        onChange={(e) => onChange({ ...rule, brands: Array.from(e.target.selectedOptions).map((o) => o.value) })}
                    >
                        {(options?.brands ?? []).map((b) => (
                            <option key={b} value={b}>
                                {b}
                            </option>
                        ))}
                    </select>
                )}
            </Field>
            <Field label={t('onsite.quiz.products.ids')} help={t('onsite.quiz.products.idsHelp')}>
                {(id) => <Input id={id} defaultValue={(rule.product_ids ?? []).join(', ')} placeholder="88, 91" onBlur={(e) => onChange({ ...rule, product_ids: linesToList(e.target.value) })} />}
            </Field>
            <Field label={t('onsite.quiz.products.maxPrice')}>
                {(id) => <Input id={id} type="number" min={0} value={rule.max_price ?? ''} onChange={(e) => onChange({ ...rule, max_price: e.target.value === '' ? null : e.target.value })} />}
            </Field>
        </div>
    );
}
