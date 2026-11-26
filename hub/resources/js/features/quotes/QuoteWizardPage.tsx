import { ArrowLeft, ArrowRight, CheckCircle2, ListChecks, Package, Plus, Ruler, Sparkles } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Callout } from '@/components/ui/callout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import type { StepStatus } from '@/components/ui/checklist';
import { Stepper } from '@/components/ui/checklist';
import { ChoiceCard } from '@/components/ui/choice-card';
import { CopyField } from '@/components/ui/copy-field';
import { Input, Textarea } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ErrorState, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { cn } from '@/lib/cn';
import { toast } from '@/stores/toast';
import { errorMessage } from '@/features/contacts/errors';
import { QrShare } from '@/features/capture/QrShare';
import { quoteQrPaths, useCalculators, useQuoteAssistant, useQuoteMutations, useQuoteTemplates } from './api';
import { DisplaySettings } from './DisplaySettings';
import { missingPrices, preset } from './model';
import { PriceExamples } from './PriceExamples';
import { PricesEditor } from './PricesEditor';
import { QuotePagePreview } from './QuotePagePreview';
import type { Calculator, CalculatorKind, CalculatorStatus, QuoteCaptureSettings, QuoteDefinition, QuoteTemplate } from './types';

export const WIZARD_STEPS = ['what', 'how', 'prices', 'show', 'done'] as const;
export type WizardStep = (typeof WIZARD_STEPS)[number];

const KIND_ICON = { service: ListChecks, area: Ruler, package: Package } as const;

/** Lo que pide un cotizador nuevo antes de mostrar el precio (lo mismo que `QuoteCapture::defaults()` del hub). */
export const DEFAULT_CAPTURE: QuoteCaptureSettings = {
    required: true,
    fields: ['first_name', 'phone', 'email'],
    require: 'phone_or_email',
    consent_channels: ['whatsapp', 'email'],
    title: null,
    cta: null,
    success: null,
    whatsapp_number: null,
};

interface Draft {
    /** De dónde salió: la clave de una plantilla, `blank` o `ai`. */
    origin: string;
    name: string;
    kind: CalculatorKind;
    definition: QuoteDefinition;
    /** Precios que la IA dejó en cero porque no estaban en la descripción. */
    needs: string[];
}

/**
 * Asistente para crear un cotizador (M18) en cinco pasos: qué vendes (una plantilla por oficio, de cero o «Descríbelo
 * y la IA lo arma») → cómo cobras → preguntas y precios (con ejemplos calculados en el hub) → cómo se muestra (con la
 * vista previa de la página pública) → listo (enlace, QR y código para WordPress). Nada se guarda hasta «Publicar» o
 * «Guardar como borrador».
 */
export function QuoteWizardPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const navigate = useNavigate();
    const list = useCalculators(workspace.id);
    const templates = useQuoteTemplates(workspace.id);
    const { save } = useQuoteMutations(workspace.id);
    const tr = (k: string, o?: Record<string, unknown>) => t(k, o ?? {});
    const back = `/w/${workspace.id}/en-el-sitio/cotizador`;

    const [step, setStep] = useState<WizardStep>('what');
    const [draft, setDraft] = useState<Draft | null>(null);
    const [capture, setCapture] = useState<QuoteCaptureSettings>(DEFAULT_CAPTURE);
    const [siteId, setSiteId] = useState('');
    const [created, setCreated] = useState<Calculator | null>(null);
    const currency = templates.data?.currency ?? list.data?.currency ?? workspace.currency;
    const missing = useMemo(() => (draft ? missingPrices(draft.kind, draft.definition, draft.needs) : []), [draft]);

    if (list.isError) {
        return (
            <>
                <PageHeader title={t('quotes.wizard.title')} />
                <ErrorState error={list.error} onRetry={() => void list.refetch()} />
            </>
        );
    }
    if (list.isPending) {
        return (
            <>
                <PageHeader title={t('quotes.wizard.title')} />
                <Skeleton className="igr:h-96 igr:rounded-xl" />
            </>
        );
    }
    if (!list.data.can_manage) {
        return (
            <>
                <PageHeader title={t('quotes.wizard.title')} />
                <Callout tone="neutral" title={t('quotes.wizard.noPermission')} action={<Link to={back}>{t('quotes.wizard.backToList')}</Link>} />
            </>
        );
    }

    const index = WIZARD_STEPS.indexOf(step);
    const statusOf = (i: number): StepStatus => (i < index ? 'done' : i === index ? 'current' : 'todo');
    const go = (s: WizardStep) => {
        setStep(s);
        document.querySelector('main')?.scrollTo({ top: 0, behavior: 'smooth' });
    };
    const pick = (d: Draft) => {
        setDraft(d);
        go(d.origin === 'ai' ? 'prices' : 'how');
    };
    const persist = (status: CalculatorStatus) => {
        if (!draft) return;
        save.mutate(
            {
                id: null,
                data: { name: draft.name.trim(), kind: draft.kind, definition: draft.definition, capture, site_id: siteId === '' ? null : siteId, status },
            },
            {
                onSuccess: (c) => {
                    setCreated(c);
                    go('done');
                },
                onError: (e) => {
                    toast.error(errorMessage(e, t));
                    if (isApiError(e) && Object.keys(e.fieldErrors).some((k) => k.startsWith('definition'))) go('prices');
                },
            },
        );
    };

    return (
        <>
            <PageHeader
                title={t('quotes.wizard.title')}
                description={t('quotes.wizard.description')}
                actions={
                    step !== 'done' ? (
                        <Button variant="ghost" asChild>
                            <Link to={back}>{t('common.cancel')}</Link>
                        </Button>
                    ) : null
                }
            />
            <Stepper
                className="igr:mb-6"
                steps={WIZARD_STEPS.map((s, i) => ({ key: s, title: t(`quotes.wizard.steps.${s}`), status: step === 'done' ? 'done' : statusOf(i) }))}
            />

            {step === 'what' ? (
                <WhatStep
                    templates={templates.data?.data}
                    loading={templates.isPending}
                    ai={list.data.ai}
                    selected={draft?.origin ?? null}
                    onTemplate={(tpl) => pick({ origin: tpl.key, name: tpl.name, kind: tpl.kind, definition: tpl.definition, needs: [] })}
                    onBlank={() => {
                        const p = preset('service', tr);
                        pick({ origin: 'blank', name: '', kind: 'service', definition: p.definition, needs: [] });
                    }}
                    onProposal={(p) => pick({ origin: 'ai', name: p.name, kind: p.kind, definition: p.definition, needs: p.needs_price })}
                />
            ) : null}

            {step === 'how' && draft ? (
                <Card>
                    <CardHeader>
                        <CardTitle>{t('quotes.wizard.how.title')}</CardTitle>
                        <CardDescription>{t('quotes.wizard.how.description')}</CardDescription>
                    </CardHeader>
                    <CardContent className="igr:space-y-5">
                        <div className="igr:max-w-xl">
                            <Label htmlFor="qw-name">{t('quotes.editor.name')}</Label>
                            <Input
                                id="qw-name"
                                className="igr:mt-1"
                                maxLength={160}
                                value={draft.name}
                                placeholder={t('quotes.wizard.namePlaceholder')}
                                onChange={(e) => setDraft({ ...draft, name: e.target.value })}
                            />
                            <p className="igr:mt-1 igr:text-caption igr:text-muted-foreground">{t('quotes.wizard.nameHelp')}</p>
                        </div>
                        <fieldset>
                            <legend className="igr:mb-2 igr:text-sm igr:font-semibold">{t('quotes.wizard.how.kind')}</legend>
                            <div className="igr:grid igr:gap-3 igr:md:grid-cols-3">
                                {(['area', 'service', 'package'] as const).map((k) => (
                                    <ChoiceCard
                                        key={k}
                                        name="qw-kind"
                                        value={k}
                                        checked={draft.kind === k}
                                        icon={KIND_ICON[k]}
                                        tone={k === 'area' ? 'sky' : k === 'service' ? 'indigo' : 'violet'}
                                        title={t(`quotes.kind.${k}`)}
                                        description={
                                            <>
                                                {t(`quotes.kindHelp.${k}`)}
                                                <span className="igr:mt-1 igr:block igr:text-caption">{t(`quotes.wizard.how.example.${k}`)}</span>
                                            </>
                                        }
                                        onChange={() => {
                                            if (k === draft.kind) return;
                                            const p = preset(k, tr);
                                            setDraft({ ...draft, kind: k, definition: { ...p.definition, round_to: draft.definition.round_to }, needs: [] });
                                        }}
                                    />
                                ))}
                            </div>
                            {draft.origin !== 'blank' ? (
                                <p className="igr:mt-2 igr:text-caption igr:text-muted-foreground">{t('quotes.wizard.how.changeNote')}</p>
                            ) : null}
                        </fieldset>
                    </CardContent>
                </Card>
            ) : null}

            {step === 'prices' && draft ? (
                <div className="igr:grid igr:gap-4 igr:xl:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
                    <Card>
                        <CardHeader>
                            <CardTitle>{t('quotes.wizard.prices.title')}</CardTitle>
                            <CardDescription>{t(`quotes.editor.questionsHelp.${draft.kind}`)}</CardDescription>
                        </CardHeader>
                        <CardContent className="igr:space-y-4">
                            {draft.origin === 'ai' ? (
                                <Callout tone="brand" icon={Sparkles} title={t('quotes.wizard.prices.aiTitle')}>
                                    {t('quotes.wizard.prices.aiBody')}
                                </Callout>
                            ) : null}
                            {missing.length > 0 ? (
                                <Callout tone="warning" title={t('quotes.wizard.prices.missing', { count: missing.length })}>
                                    {t('quotes.wizard.prices.missingHelp')}
                                </Callout>
                            ) : null}
                            <PricesEditor
                                kind={draft.kind}
                                definition={draft.definition}
                                currency={currency}
                                missing={missing}
                                onChange={(definition) => setDraft({ ...draft, definition })}
                            />
                        </CardContent>
                    </Card>
                    <div className="igr:xl:sticky igr:xl:top-4 igr:self-start">
                        <Card>
                            <CardContent className="igr:pt-5">
                                <PriceExamples kind={draft.kind} definition={draft.definition} currency={currency} />
                            </CardContent>
                        </Card>
                    </div>
                </div>
            ) : null}

            {step === 'show' && draft ? (
                <div className="igr:grid igr:gap-4 igr:xl:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]">
                    <Card>
                        <CardHeader>
                            <CardTitle>{t('quotes.wizard.show.title')}</CardTitle>
                            <CardDescription>{t('quotes.wizard.show.description')}</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <DisplaySettings capture={capture} onChange={setCapture} sites={list.data.sites} siteId={siteId} onSite={setSiteId} />
                        </CardContent>
                    </Card>
                    <div className="igr:xl:sticky igr:xl:top-4 igr:self-start">
                        <Card>
                            <CardHeader>
                                <CardTitle>{t('quotes.preview.title')}</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <QuotePagePreview name={draft.name} kind={draft.kind} definition={draft.definition} capture={capture} />
                            </CardContent>
                        </Card>
                    </div>
                </div>
            ) : null}

            {step === 'done' && created ? <DoneStep calc={created} onEdit={() => void navigate(`${back}/${created.id}`)} back={back} /> : null}

            {step !== 'what' && step !== 'done' ? (
                <div className="igr:sticky igr:bottom-0 igr:z-10 igr:mt-6 igr:flex igr:flex-wrap igr:items-center igr:justify-between igr:gap-2 igr:border-t igr:bg-background/95 igr:py-3 igr:backdrop-blur">
                    <Button variant="ghost" onClick={() => go(WIZARD_STEPS[index - 1] ?? 'what')}>
                        <ArrowLeft aria-hidden />
                        {t('common.back')}
                    </Button>
                    {step === 'show' ? (
                        <div className="igr:flex igr:flex-wrap igr:gap-2">
                            <Button
                                variant="outline"
                                loading={save.isPending && save.variables.data.status === 'draft'}
                                disabled={save.isPending}
                                onClick={() => persist('draft')}
                            >
                                {t('quotes.wizard.saveDraft')}
                            </Button>
                            <Button
                                loading={save.isPending && save.variables.data.status === 'active'}
                                disabled={save.isPending}
                                onClick={() => persist('active')}
                            >
                                {t('quotes.wizard.publish')}
                            </Button>
                        </div>
                    ) : (
                        <div className="igr:flex igr:items-center igr:gap-3">
                            {step === 'how' && draft && draft.name.trim() === '' ? (
                                <span className="igr:text-caption igr:text-muted-foreground">{t('quotes.wizard.nameRequired')}</span>
                            ) : null}
                            {step === 'prices' && missing.length > 0 ? (
                                <span className="igr:text-caption igr:text-muted-foreground">{t('quotes.wizard.prices.fillFirst')}</span>
                            ) : null}
                            <Button
                                disabled={!draft || (step === 'how' && draft.name.trim() === '') || (step === 'prices' && missing.length > 0)}
                                onClick={() => go(WIZARD_STEPS[index + 1] ?? 'done')}
                            >
                                {t('common.next')}
                                <ArrowRight aria-hidden />
                            </Button>
                        </div>
                    )}
                </div>
            ) : null}
        </>
    );
}

/** Paso 1: una plantilla por oficio, empezar de cero o «Descríbelo y la IA lo arma». */
export function WhatStep({
    templates,
    loading,
    ai,
    selected,
    onTemplate,
    onBlank,
    onProposal,
}: {
    templates: QuoteTemplate[] | undefined;
    loading: boolean;
    ai: boolean;
    selected: string | null;
    onTemplate: (template: QuoteTemplate) => void;
    onBlank: () => void;
    onProposal: (proposal: { name: string; kind: CalculatorKind; definition: QuoteDefinition; needs_price: string[] }) => void;
}) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const { propose } = useQuoteAssistant(workspace.id);
    const [description, setDescription] = useState('');
    const [aiError, setAiError] = useState<string | null>(null);

    return (
        <div className="igr:space-y-6">
            {ai ? (
                <Card className="igr:hero-surface igr:overflow-hidden">
                    <CardContent className="igr:space-y-3 igr:pt-5">
                        <div className="igr:flex igr:items-start igr:gap-3">
                            <span className="igr:flex igr:size-10 igr:shrink-0 igr:items-center igr:justify-center igr:rounded-xl igr:bg-primary igr:text-primary-foreground">
                                <Sparkles className="igr:size-5" aria-hidden />
                            </span>
                            <div>
                                <h2 className="igr:text-section igr:font-semibold">{t('quotes.wizard.ai.title')}</h2>
                                <p className="igr:text-support igr:text-muted-foreground">{t('quotes.wizard.ai.description')}</p>
                            </div>
                        </div>
                        <Label htmlFor="qw-ai" className="igr:sr-only">
                            {t('quotes.wizard.ai.label')}
                        </Label>
                        <Textarea
                            id="qw-ai"
                            rows={3}
                            maxLength={2000}
                            value={description}
                            placeholder={t('quotes.wizard.ai.placeholder')}
                            onChange={(e) => {
                                setDescription(e.target.value);
                                setAiError(null);
                            }}
                        />
                        {aiError ? <p className="igr:text-support igr:text-destructive">{aiError}</p> : null}
                        <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-3">
                            <Button
                                variant="brand"
                                loading={propose.isPending}
                                disabled={description.trim().length < 15}
                                onClick={() =>
                                    propose.mutate(description.trim(), {
                                        onSuccess: onProposal,
                                        onError: (e) => setAiError(isApiError(e) ? e.message : t('errors.generic')),
                                    })
                                }
                            >
                                <Sparkles aria-hidden />
                                {propose.isPending ? t('quotes.wizard.ai.working') : t('quotes.wizard.ai.submit')}
                            </Button>
                            <span className="igr:text-caption igr:text-muted-foreground">{t('quotes.wizard.ai.note')}</span>
                        </div>
                    </CardContent>
                </Card>
            ) : null}

            <section aria-labelledby="qw-templates">
                <h2 id="qw-templates" className="igr:text-section igr:font-semibold">
                    {t('quotes.wizard.what.title')}
                </h2>
                <p className="igr:mb-3 igr:text-support igr:text-muted-foreground">{t('quotes.wizard.what.description')}</p>
                <div role="radiogroup" aria-labelledby="qw-templates" className="igr:grid igr:gap-3 igr:sm:grid-cols-2 igr:lg:grid-cols-3 igr:2xl:grid-cols-4">
                    {loading
                        ? Array.from({ length: 6 }, (_, i) => <Skeleton key={i} className="igr:h-28 igr:rounded-xl" />)
                        : (templates ?? []).map((tpl) => (
                              <TemplateCard key={tpl.key} template={tpl} checked={selected === tpl.key} onPick={() => onTemplate(tpl)} />
                          ))}
                    <button
                        type="button"
                        role="radio"
                        aria-checked={selected === 'blank'}
                        onClick={onBlank}
                        className={cn(
                            'igr:flex igr:min-h-28 igr:items-center igr:gap-3 igr:rounded-xl igr:border igr:border-dashed igr:bg-card igr:p-4 igr:text-left igr:transition-colors igr:hover:border-primary/50 igr:focus-visible:ring-2 igr:focus-visible:ring-ring igr:focus-visible:outline-none',
                            selected === 'blank' && 'igr:border-primary igr:bg-brand-soft/40',
                        )}
                    >
                        <span className="igr:flex igr:size-11 igr:shrink-0 igr:items-center igr:justify-center igr:rounded-xl igr:bg-muted">
                            <Plus className="igr:size-5" aria-hidden />
                        </span>
                        <span>
                            <span className="igr:block igr:text-sm igr:font-semibold">{t('quotes.wizard.what.blank')}</span>
                            <span className="igr:block igr:text-support igr:text-muted-foreground">{t('quotes.wizard.what.blankHelp')}</span>
                        </span>
                    </button>
                </div>
            </section>
        </div>
    );
}

function TemplateCard({ template, checked, onPick }: { template: QuoteTemplate; checked: boolean; onPick: () => void }) {
    const { t } = useTranslation();
    return (
        <button
            type="button"
            role="radio"
            aria-checked={checked}
            onClick={onPick}
            className={cn(
                'igr:group igr:flex igr:min-h-28 igr:items-start igr:gap-3 igr:rounded-xl igr:border igr:bg-card igr:p-4 igr:text-left igr:shadow-xs igr:transition-[border-color,box-shadow,transform] igr:duration-150 igr:hover:-translate-y-0.5 igr:hover:border-primary/40 igr:hover:shadow-sm igr:focus-visible:ring-2 igr:focus-visible:ring-ring igr:focus-visible:outline-none',
                checked && 'igr:border-primary igr:bg-brand-soft/40',
            )}
        >
            <span className="igr:flex igr:size-11 igr:shrink-0 igr:items-center igr:justify-center igr:rounded-xl igr:bg-brand-soft igr:text-2xl" aria-hidden>
                {template.icon}
            </span>
            <span className="igr:min-w-0">
                <span className="igr:flex igr:flex-wrap igr:items-center igr:gap-2">
                    <span className="igr:text-sm igr:font-semibold">{template.name}</span>
                    <Badge variant="outline">{t(`quotes.kind.${template.kind}`)}</Badge>
                </span>
                <span className="igr:mt-1 igr:block igr:text-support igr:text-muted-foreground">{template.description}</span>
            </span>
        </button>
    );
}

/** Paso 5: el enlace y el QR de la página, el código para WordPress y a dónde seguir. */
function DoneStep({ calc, onEdit, back }: { calc: Calculator; onEdit: () => void; back: string }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const active = calc.status === 'active';
    return (
        <div className="igr:space-y-4">
            <Callout tone="success" icon={CheckCircle2} title={t(active ? 'quotes.wizard.done.published' : 'quotes.wizard.done.draft', { name: calc.name })}>
                {t(active ? 'quotes.wizard.done.publishedHelp' : 'quotes.wizard.done.draftHelp')}
            </Callout>
            {active ? (
                <Card>
                    <CardHeader>
                        <CardTitle>{t('quotes.share.title')}</CardTitle>
                        <CardDescription>{t('quotes.share.description')}</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <QrShare
                            url={calc.page_url}
                            qr={quoteQrPaths(workspace.id, calc.id)}
                            name={calc.name}
                            linkLabel={t('quotes.share.link')}
                            shareText={t('quotes.share.whatsappText', { name: calc.name })}
                        />
                    </CardContent>
                </Card>
            ) : null}
            <Card>
                <CardHeader>
                    <CardTitle>{t('quotes.share.wordpress')}</CardTitle>
                    <CardDescription>{t('quotes.share.wordpressHelp')}</CardDescription>
                </CardHeader>
                <CardContent>
                    <CopyField label={t('quotes.editor.embed')} value={calc.shortcode} mono />
                </CardContent>
            </Card>
            <div className="igr:flex igr:flex-wrap igr:gap-2">
                <Button onClick={onEdit}>{t('quotes.wizard.done.edit')}</Button>
                <Button variant="outline" asChild>
                    <Link to={back}>{t('quotes.wizard.backToList')}</Link>
                </Button>
            </div>
        </div>
    );
}
