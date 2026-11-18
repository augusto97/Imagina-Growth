import { Plus, X } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Input, Select, Textarea } from '@/components/ui/input';
import { cn } from '@/lib/cn';
import { addVariant, removeVariant } from '../model';
import type { FieldKey, WidgetContent, WidgetDesign, WidgetDraft, WidgetField, WidgetKind, WidgetStep, WidgetTheme } from '../types';
import { Check, Field, Section } from './fields';

const FIELD_KEYS: FieldKey[] = ['email', 'phone', 'first_name', 'last_name'];
const POSITIONS: Partial<Record<WidgetKind, string[]>> = {
    popup: ['center', 'corner', 'fullscreen'],
    two_step: ['center', 'corner', 'fullscreen'],
    spin_wheel: ['center', 'corner', 'fullscreen'],
    referral_welcome: ['center', 'corner', 'fullscreen'],
    quiz: ['center', 'corner', 'fullscreen'],
    bar: ['top', 'bottom'],
    teaser: ['left', 'right'],
    wa_widget: ['right', 'left'],
};

interface Props {
    kind: WidgetKind;
    draft: WidgetDraft;
    variant: number;
    onVariant: (index: number) => void;
    onChange: (draft: WidgetDraft) => void;
}

/** Textos, campos y diseño de cada variante, con las variantes A/B. */
export function ContentEditor({ kind, draft, variant, onVariant, onChange }: Props) {
    const { t } = useTranslation();
    const current = draft.variants[variant] ?? draft.variants[0];
    if (!current) return null;
    const setContent = (patch: Partial<WidgetContent>) =>
        onChange({ ...draft, variants: draft.variants.map((v, i) => (i === variant ? { ...v, content: { ...v.content, ...patch } } : v)) });
    const setDesign = (patch: Partial<WidgetDesign>) => onChange({ ...draft, variants: draft.variants.map((v, i) => (i === variant ? { ...v, design: { ...v.design, ...patch } } : v)) });
    const setTheme = (patch: Partial<WidgetTheme>) => setDesign({ theme: { ...current.design.theme, ...patch } });
    const c = current.content;
    const total = draft.variants.reduce((s, v) => s + v.weight, 0);

    return (
        <div className="igr:space-y-5">
            {kind !== 'wa_widget' ? (
                <Section title={t('onsite.editor.variants.title')} description={t('onsite.editor.variants.help')}>
                    <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-2">
                        {draft.variants.map((v, i) => (
                            <div key={v.key} className={cn('igr:flex igr:items-center igr:gap-1 igr:rounded-md igr:border igr:py-1 igr:pr-1 igr:pl-2', i === variant && 'igr:border-primary igr:bg-primary/5')}>
                                <button type="button" className="igr:text-[13px] igr:font-medium" onClick={() => onVariant(i)} aria-pressed={i === variant}>
                                    {t('onsite.editor.variants.name', { key: v.key.toUpperCase() })}
                                </button>
                                <Input
                                    type="number"
                                    min={0}
                                    max={100}
                                    value={v.weight}
                                    aria-label={t('onsite.editor.variants.weight', { key: v.key.toUpperCase() })}
                                    className="igr:h-7 igr:w-16"
                                    onChange={(e) => onChange({ ...draft, variants: draft.variants.map((x, j) => (j === i ? { ...x, weight: Number(e.target.value) || 0 } : x)) })}
                                />
                                <span className="igr:text-xs igr:text-muted-foreground">%</span>
                                {draft.variants.length > 1 ? (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="igr:size-7"
                                        aria-label={t('onsite.editor.variants.remove', { key: v.key.toUpperCase() })}
                                        onClick={() => {
                                            onChange({ ...draft, variants: removeVariant(draft.variants, i) });
                                            onVariant(0);
                                        }}
                                    >
                                        <X />
                                    </Button>
                                ) : null}
                            </div>
                        ))}
                        {draft.variants.length < 4 ? (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => {
                                    onChange({ ...draft, variants: addVariant(draft.variants) });
                                    onVariant(draft.variants.length);
                                }}
                            >
                                <Plus />
                                {t('onsite.editor.variants.add')}
                            </Button>
                        ) : null}
                    </div>
                    {total !== 100 && draft.variants.length > 1 ? <p className="igr:text-xs igr:text-warning">{t('onsite.editor.variants.total', { total })}</p> : null}
                    {draft.variants.length > 1 ? (
                        <Check checked={draft.ab_auto_winner} onChange={(v) => onChange({ ...draft, ab_auto_winner: v })} help={t('onsite.editor.variants.autoHelp')}>
                            {t('onsite.editor.variants.auto')}
                        </Check>
                    ) : null}
                </Section>
            ) : null}

            <Section title={t('onsite.editor.content.title')}>
                <Field label={kind === 'wa_widget' ? t('onsite.editor.content.bubble') : t('onsite.editor.content.heading')}>
                    {(id) => <Input id={id} value={c.title} maxLength={200} onChange={(e) => setContent({ title: e.target.value })} />}
                </Field>
                {kind !== 'wa_widget' && kind !== 'bar' ? (
                    <Field label={t('onsite.editor.content.body')}>{(id) => <Textarea id={id} rows={2} value={c.body} maxLength={600} onChange={(e) => setContent({ body: e.target.value })} />}</Field>
                ) : null}
                {kind === 'wa_widget' ? (
                    <div className="igr:grid igr:gap-3 igr:sm:grid-cols-2">
                        <Field label={t('onsite.editor.content.waNumber')} help={t('onsite.editor.content.waNumberHelp')}>
                            {(id) => <Input id={id} inputMode="tel" value={c.wa?.number ?? ''} onChange={(e) => setContent({ wa: { number: e.target.value, text: c.wa?.text ?? '' } })} placeholder="573001234567" />}
                        </Field>
                        <Field label={t('onsite.editor.content.waText')} help={t('onsite.editor.content.waTextHelp')}>
                            {(id) => <Input id={id} value={c.wa?.text ?? ''} maxLength={300} onChange={(e) => setContent({ wa: { number: c.wa?.number ?? '', text: e.target.value } })} />}
                        </Field>
                    </div>
                ) : (
                    <>
                        <FieldsEditor fields={c.fields} onChange={(fields) => setContent({ fields })} />
                        <div className="igr:grid igr:gap-3 igr:sm:grid-cols-2">
                            <Field label={kind === 'quiz' ? t('onsite.editor.content.ctaQuiz') : t('onsite.editor.content.cta')}>
                                {(id) => <Input id={id} value={c.cta} maxLength={60} onChange={(e) => setContent({ cta: e.target.value })} />}
                            </Field>
                            {kind === 'quiz' ? (
                                <Field label={t('onsite.editor.content.start')}>{(id) => <Input id={id} value={c.start ?? ''} maxLength={60} onChange={(e) => setContent({ start: e.target.value })} />}</Field>
                            ) : null}
                        </div>
                        <Field label={t('onsite.editor.content.success')} help={kind === 'spin_wheel' ? t('onsite.editor.content.successWheelHelp', { prize: '{{premio}}', code: '{{codigo}}' }) : t('onsite.editor.content.successHelp', { code: '{{codigo}}' })}>
                            {(id) => <Input id={id} value={c.success} maxLength={300} onChange={(e) => setContent({ success: e.target.value })} />}
                        </Field>
                        {kind === 'spin_wheel' ? (
                            <Field label={t('onsite.editor.content.lose')}>{(id) => <Input id={id} value={c.lose ?? ''} maxLength={300} onChange={(e) => setContent({ lose: e.target.value })} />}</Field>
                        ) : null}
                        {kind === 'quiz' ? (
                            <Check checked={Boolean(c.contact_optional)} onChange={(v) => setContent({ contact_optional: v })} help={t('onsite.editor.content.contactOptionalHelp')}>
                                {t('onsite.editor.content.contactOptional')}
                            </Check>
                        ) : null}
                        <Field label={t('onsite.editor.content.footer')} help={t('onsite.editor.content.footerHelp')}>
                            {(id) => <Input id={id} value={c.footer} maxLength={300} onChange={(e) => setContent({ footer: e.target.value })} />}
                        </Field>
                    </>
                )}
            </Section>

            {kind === 'two_step' && c.step2 ? <StepEditor step={c.step2} onChange={(step2) => setContent({ step2 })} /> : null}

            <Section title={t('onsite.editor.design.title')}>
                <div className="igr:grid igr:gap-3 igr:sm:grid-cols-2">
                    {POSITIONS[kind] ? (
                        <Field label={t('onsite.editor.design.position')}>
                            {(id) => (
                                <Select id={id} value={current.design.position ?? ''} onChange={(e) => setDesign({ position: e.target.value })}>
                                    {(POSITIONS[kind] ?? []).map((p) => (
                                        <option key={p} value={p}>
                                            {t(`onsite.editor.design.positions.${p}`)}
                                        </option>
                                    ))}
                                </Select>
                            )}
                        </Field>
                    ) : null}
                    {kind === 'quiz' ? (
                        <Field label={t('onsite.editor.design.display')} help={t('onsite.editor.design.displayHelp')}>
                            {(id) => (
                                <Select id={id} value={current.design.display ?? 'popup'} onChange={(e) => setDesign({ display: e.target.value === 'embedded' ? 'embedded' : 'popup' })}>
                                    <option value="popup">{t('onsite.editor.design.displays.popup')}</option>
                                    <option value="embedded">{t('onsite.editor.design.displays.embedded')}</option>
                                </Select>
                            )}
                        </Field>
                    ) : null}
                    {['popup', 'two_step', 'referral_welcome', 'embedded_form'].includes(kind) ? (
                        <>
                            <Field label={t('onsite.editor.design.layout')}>
                                {(id) => (
                                    <Select id={id} value={current.design.layout ?? 'text_only'} onChange={(e) => setDesign({ layout: e.target.value as WidgetDesign['layout'] })}>
                                        {(['text_only', 'image_top', 'image_left'] as const).map((l) => (
                                            <option key={l} value={l}>
                                                {t(`onsite.editor.design.layouts.${l}`)}
                                            </option>
                                        ))}
                                    </Select>
                                )}
                            </Field>
                            <Field label={t('onsite.editor.design.image')} help={t('onsite.editor.design.imageHelp')} className="igr:sm:col-span-2">
                                {(id) => <Input id={id} type="url" value={current.design.image_url ?? ''} placeholder="https://" onChange={(e) => setDesign({ image_url: e.target.value || null })} />}
                            </Field>
                        </>
                    ) : null}
                </div>
                <div className="igr:grid igr:grid-cols-2 igr:gap-3 igr:sm:grid-cols-4">
                    {(['background', 'text', 'accent', 'accent_text'] as const).map((k) => (
                        <Field key={k} label={t(`onsite.editor.design.colors.${k}`)}>
                            {(id) => (
                                <div className="igr:flex igr:items-center igr:gap-2">
                                    <input id={id} type="color" value={current.design.theme[k]} onChange={(e) => setTheme({ [k]: e.target.value.toUpperCase() })} className="igr:h-8 igr:w-10 igr:cursor-pointer igr:rounded igr:border" />
                                    <span className="igr:font-mono igr:text-xs igr:text-muted-foreground">{current.design.theme[k]}</span>
                                </div>
                            )}
                        </Field>
                    ))}
                </div>
            </Section>
        </div>
    );
}

function StepEditor({ step, onChange }: { step: WidgetStep; onChange: (s: WidgetStep) => void }) {
    const { t } = useTranslation();
    const set = (patch: Partial<WidgetStep>) => onChange({ ...step, ...patch });
    return (
        <Section title={t('onsite.editor.step2.title')} description={t('onsite.editor.step2.help')}>
            <Field label={t('onsite.editor.content.heading')}>{(id) => <Input id={id} value={step.title} maxLength={200} onChange={(e) => set({ title: e.target.value })} />}</Field>
            <Field label={t('onsite.editor.content.body')}>{(id) => <Textarea id={id} rows={2} value={step.body} maxLength={600} onChange={(e) => set({ body: e.target.value })} />}</Field>
            <FieldsEditor fields={step.fields} onChange={(fields) => set({ fields })} />
            <div className="igr:grid igr:gap-3 igr:sm:grid-cols-3">
                <Field label={t('onsite.editor.content.cta')}>{(id) => <Input id={id} value={step.cta} maxLength={60} onChange={(e) => set({ cta: e.target.value })} />}</Field>
                <Field label={t('onsite.editor.step2.skip')}>{(id) => <Input id={id} value={step.skip ?? ''} maxLength={60} onChange={(e) => set({ skip: e.target.value })} />}</Field>
                <Field label={t('onsite.editor.content.success')}>{(id) => <Input id={id} value={step.success} maxLength={300} onChange={(e) => set({ success: e.target.value })} />}</Field>
            </div>
        </Section>
    );
}

/** Qué datos pide el formulario (correo, WhatsApp, nombre, apellido) y cuáles son obligatorios. */
function FieldsEditor({ fields, onChange }: { fields: WidgetField[]; onChange: (f: WidgetField[]) => void }) {
    const { t } = useTranslation();
    return (
        <fieldset className="igr:space-y-2">
            <legend className="igr:mb-1 igr:text-[13px] igr:font-medium">{t('onsite.editor.fields.title')}</legend>
            {FIELD_KEYS.map((key) => {
                const field = fields.find((f) => f.key === key);
                return (
                    <div key={key} className="igr:grid igr:grid-cols-[140px_1fr_auto] igr:items-center igr:gap-2">
                        <label className="igr:flex igr:items-center igr:gap-2 igr:text-[13px]">
                            <input
                                type="checkbox"
                                checked={field !== undefined}
                                onChange={(e) => onChange(e.target.checked ? [...fields, { key, required: key === 'email' || key === 'phone', label: t(`onsite.fields.${key}`) }] : fields.filter((f) => f.key !== key))}
                            />
                            {t(`onsite.fields.${key}`)}
                        </label>
                        <Input
                            value={field?.label ?? ''}
                            disabled={field === undefined}
                            aria-label={t('onsite.editor.fields.label', { field: t(`onsite.fields.${key}`) })}
                            placeholder={t('onsite.editor.fields.labelPlaceholder')}
                            maxLength={80}
                            className="igr:h-8"
                            onChange={(e) => onChange(fields.map((f) => (f.key === key ? { ...f, label: e.target.value } : f)))}
                        />
                        <label className="igr:flex igr:items-center igr:gap-1.5 igr:text-xs igr:text-muted-foreground">
                            <input type="checkbox" disabled={field === undefined} checked={field?.required ?? false} onChange={(e) => onChange(fields.map((f) => (f.key === key ? { ...f, required: e.target.checked } : f)))} />
                            {t('onsite.editor.fields.required')}
                        </label>
                    </div>
                );
            })}
        </fieldset>
    );
}
