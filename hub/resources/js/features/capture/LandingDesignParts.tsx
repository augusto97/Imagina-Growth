import { ArrowDown, ArrowUp, Monitor, Plus, Smartphone, Sparkles, Trash2 } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Callout } from '@/components/ui/callout';
import { DeviceFrame, type PreviewDevice } from '@/components/ui/device-frame';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input, Textarea } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { FieldError } from '@/components/ui/misc';
import { PagePreviewFrame } from '@/components/ui/page-preview';
import { SegmentedControl } from '@/components/ui/segmented';
import { errorMessage } from '@/features/contacts/errors';
import { useCopilotStatus } from '@/features/copilot/api';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { cn } from '@/lib/cn';
import { useLandingPreview, useWriteLanding } from './api';
import { applyTexts, DEFAULT_ACCENT, isHexColor, MAX_BENEFITS, MAX_FAQ, patchFrom, type LandingDraft } from './model';
import type { CaptureOptions, LandingFaqItem, LandingPageDetail, LandingTemplate } from './types';

export const TEMPLATES: readonly LandingTemplate[] = ['split', 'hero', 'centered'];

/** Miniatura de cada diseño, pintada con el color de la página (no es una imagen: se adapta al color elegido). */
export function TemplateThumb({ template, accent }: { template: LandingTemplate; accent: string }) {
    const line = (w: string, extra = '') => <span className={cn('igr:block igr:h-1.5 igr:rounded-full igr:bg-current igr:opacity-40', extra)} style={{ width: w }} />;
    const card = <span className="igr:block igr:h-full igr:w-full igr:rounded-md igr:border igr:border-slate-200 igr:bg-white igr:shadow-sm" />;
    if (template === 'hero') {
        return (
            <span className="igr:flex igr:h-20 igr:items-center igr:gap-2 igr:rounded-lg igr:p-2.5 igr:text-white" style={{ background: accent }} aria-hidden>
                <span className="igr:flex igr:flex-1 igr:flex-col igr:gap-1.5">
                    {line('80%', 'igr:opacity-90 igr:h-2')}
                    {line('60%')}
                    {line('70%')}
                </span>
                <span className="igr:h-14 igr:w-[38%]">{card}</span>
            </span>
        );
    }
    if (template === 'centered') {
        return (
            <span className="igr:flex igr:h-20 igr:flex-col igr:items-center igr:gap-1.5 igr:rounded-lg igr:bg-slate-50 igr:p-2.5 igr:text-slate-700" aria-hidden>
                {line('55%', 'igr:h-2')}
                {line('40%')}
                <span className="igr:mt-0.5 igr:h-8 igr:w-[50%]">{card}</span>
            </span>
        );
    }
    return (
        <span className="igr:flex igr:h-20 igr:items-start igr:gap-2 igr:rounded-lg igr:bg-slate-50 igr:p-2.5 igr:text-slate-700" aria-hidden>
            <span className="igr:flex igr:flex-1 igr:flex-col igr:gap-1.5 igr:pt-1">
                {line('85%', 'igr:h-2')}
                {line('65%')}
                <span className="igr:mt-1 igr:block igr:h-5 igr:w-[80%] igr:rounded" style={{ background: `${accent}33` }} />
            </span>
            <span className="igr:h-14 igr:w-[38%]">{card}</span>
        </span>
    );
}

/** Elegir el diseño de la página entre los tres (grupo de radio con miniaturas). */
export function TemplatePicker({ value, accent, onChange, disabled }: { value: LandingTemplate; accent: string; onChange: (t: LandingTemplate) => void; disabled?: boolean }) {
    const { t } = useTranslation();
    const name = useId();
    return (
        <div className="igr:grid igr:gap-3 igr:sm:grid-cols-3" role="radiogroup" aria-label={t('capture.design.template')}>
            {TEMPLATES.map((template) => (
                <label
                    key={template}
                    className={cn(
                        'igr:flex igr:cursor-pointer igr:flex-col igr:gap-2 igr:rounded-xl igr:border igr:p-2.5 igr:transition-colors igr:has-[:focus-visible]:ring-2 igr:has-[:focus-visible]:ring-ring',
                        value === template ? 'igr:border-primary igr:bg-brand-soft/50' : 'igr:hover:bg-accent',
                        disabled && 'igr:cursor-not-allowed igr:opacity-60',
                    )}
                >
                    <input type="radio" name={name} className="igr:sr-only" checked={value === template} disabled={disabled} onChange={() => onChange(template)} />
                    <TemplateThumb template={template} accent={accent} />
                    <span className="igr:px-0.5">
                        <span className="igr:block igr:text-[13px] igr:font-medium">{t(`capture.design.templates.${template}.name`)}</span>
                        <span className="igr:block igr:text-caption igr:text-muted-foreground">{t(`capture.design.templates.${template}.help`)}</span>
                    </span>
                </label>
            ))}
        </div>
    );
}

/** Lista corta de frases editable (beneficios): agregar, quitar y mover. */
export function BenefitsEditor({ value, onChange }: { value: string[]; onChange: (v: string[]) => void }) {
    const { t } = useTranslation();
    const set = (i: number, text: string) => onChange(value.map((b, j) => (j === i ? text : b)));
    const move = (i: number, step: -1 | 1) => {
        const next = [...value];
        const [item] = next.splice(i, 1);
        if (item !== undefined) next.splice(i + step, 0, item);
        onChange(next);
    };
    return (
        <div className="igr:space-y-2">
            {value.map((benefit, i) => (
                <div key={i} className="igr:flex igr:items-center igr:gap-1.5">
                    <Input
                        aria-label={t('capture.design.benefitN', { n: i + 1 })}
                        value={benefit}
                        maxLength={120}
                        placeholder={t('capture.design.benefitPlaceholder')}
                        onChange={(e) => set(i, e.target.value)}
                    />
                    <Button type="button" size="icon" variant="ghost" disabled={i === 0} aria-label={t('capture.design.up')} onClick={() => move(i, -1)}>
                        <ArrowUp aria-hidden />
                    </Button>
                    <Button type="button" size="icon" variant="ghost" disabled={i === value.length - 1} aria-label={t('capture.design.down')} onClick={() => move(i, 1)}>
                        <ArrowDown aria-hidden />
                    </Button>
                    <Button type="button" size="icon" variant="ghost" aria-label={t('capture.design.remove')} onClick={() => onChange(value.filter((_, j) => j !== i))}>
                        <Trash2 aria-hidden />
                    </Button>
                </div>
            ))}
            {value.length < MAX_BENEFITS ? (
                <Button type="button" size="sm" variant="outline" onClick={() => onChange([...value, ''])}>
                    <Plus aria-hidden />
                    {t('capture.design.addBenefit')}
                </Button>
            ) : (
                <p className="igr:text-caption igr:text-muted-foreground">{t('capture.design.maxBenefits', { max: MAX_BENEFITS })}</p>
            )}
        </div>
    );
}

/** Preguntas frecuentes: pregunta y respuesta, hasta `MAX_FAQ`. */
export function FaqEditor({ value, onChange }: { value: LandingFaqItem[]; onChange: (v: LandingFaqItem[]) => void }) {
    const { t } = useTranslation();
    const set = (i: number, patch: Partial<LandingFaqItem>) => onChange(value.map((f, j) => (j === i ? { ...f, ...patch } : f)));
    return (
        <div className="igr:space-y-3">
            {value.map((item, i) => (
                <div key={i} className="igr:space-y-2 igr:rounded-lg igr:border igr:p-3">
                    <div className="igr:flex igr:items-center igr:gap-1.5">
                        <Input
                            aria-label={t('capture.design.questionN', { n: i + 1 })}
                            value={item.q}
                            maxLength={140}
                            placeholder={t('capture.design.questionPlaceholder')}
                            onChange={(e) => set(i, { q: e.target.value })}
                        />
                        <Button type="button" size="icon" variant="ghost" aria-label={t('capture.design.removeQuestion')} onClick={() => onChange(value.filter((_, j) => j !== i))}>
                            <Trash2 aria-hidden />
                        </Button>
                    </div>
                    <Textarea
                        aria-label={t('capture.design.answerN', { n: i + 1 })}
                        rows={2}
                        value={item.a}
                        maxLength={600}
                        placeholder={t('capture.design.answerPlaceholder')}
                        onChange={(e) => set(i, { a: e.target.value })}
                    />
                </div>
            ))}
            {value.length < MAX_FAQ ? (
                <Button type="button" size="sm" variant="outline" onClick={() => onChange([...value, { q: '', a: '' }])}>
                    <Plus aria-hidden />
                    {t('capture.design.addQuestion')}
                </Button>
            ) : null}
        </div>
    );
}

/** «Mostrar reseñas»: solo se puede si el negocio tiene reseñas publicadas. */
export function ReviewsToggle({ value, onChange, options }: { value: boolean; onChange: (v: boolean) => void; options: CaptureOptions | undefined }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const reviews = options?.reviews ?? null;
    return (
        <label className={cn('igr:flex igr:items-start igr:gap-2 igr:text-[13px]', reviews === null && 'igr:opacity-70')}>
            <input type="checkbox" className="igr:mt-0.5 igr:size-4 igr:accent-primary" checked={value} disabled={reviews === null && !value} onChange={(e) => onChange(e.target.checked)} />
            <span>
                <span className="igr:font-medium">{t('capture.design.reviews')}</span>
                <span className="igr:block igr:text-caption igr:text-muted-foreground">
                    {reviews === null
                        ? t('capture.design.reviewsNone')
                        : t('capture.design.reviewsHelp', { count: reviews.count, avg: new Intl.NumberFormat(workspace.locale, { maximumFractionDigits: 1, minimumFractionDigits: 1 }).format(reviews.avg) })}
                </span>
            </span>
        </label>
    );
}

/** ¿Se puede escribir con la IA aquí? (permiso y la IA configurada en la plataforma). */
export function useCanWriteWithAi(): boolean {
    const { workspace } = useWorkspace();
    const can = useCan('useAi');
    const status = useCopilotStatus(workspace.id);
    return can && status.data?.available === true;
}

/** «Escribir con IA»: pide de qué se trata la página y pone los textos en el borrador (no guarda nada). */
export function WriteWithAiDialog({ open, onOpenChange, draft, onApply }: { open: boolean; onOpenChange: (open: boolean) => void; draft: LandingDraft; onApply: (d: LandingDraft) => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const write = useWriteLanding(workspace.id);
    const [goal, setGoal] = useState(draft.content.title);
    const [offer, setOffer] = useState('');
    const [audience, setAudience] = useState('');
    const goalId = useId();
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('capture.ai.title')}</DialogTitle>
                    <DialogDescription>{t('capture.ai.description')}</DialogDescription>
                </DialogHeader>
                <form
                    className="igr:space-y-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        write.mutate(
                            { goal: goal.trim(), ...(offer.trim() ? { offer: offer.trim() } : {}), ...(audience.trim() ? { audience: audience.trim() } : {}) },
                            {
                                onSuccess: (r) => {
                                    onApply(applyTexts(draft, r.texts));
                                    onOpenChange(false);
                                },
                            },
                        );
                    }}
                >
                    <div className="igr:space-y-1.5">
                        <Label htmlFor={goalId}>{t('capture.ai.goal')}</Label>
                        <Textarea id={goalId} rows={3} required minLength={3} maxLength={500} value={goal} placeholder={t('capture.ai.goalPlaceholder')} onChange={(e) => setGoal(e.target.value)} />
                    </div>
                    <div className="igr:grid igr:gap-4 igr:sm:grid-cols-2">
                        <div className="igr:space-y-1.5">
                            <Label htmlFor={`${goalId}-offer`}>{t('capture.ai.offer')}</Label>
                            <Input id={`${goalId}-offer`} maxLength={300} value={offer} placeholder={t('capture.ai.offerPlaceholder')} onChange={(e) => setOffer(e.target.value)} />
                        </div>
                        <div className="igr:space-y-1.5">
                            <Label htmlFor={`${goalId}-audience`}>{t('capture.ai.audience')}</Label>
                            <Input id={`${goalId}-audience`} maxLength={300} value={audience} placeholder={t('capture.ai.audiencePlaceholder')} onChange={(e) => setAudience(e.target.value)} />
                        </div>
                    </div>
                    <p className="igr:text-caption igr:text-muted-foreground">{t('capture.ai.note')}</p>
                    {write.isError ? <FieldError message={errorMessage(write.error, t)} /> : null}
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={() => onOpenChange(false)}>
                            {t('common.cancel')}
                        </Button>
                        <Button type="submit" loading={write.isPending} disabled={goal.trim().length < 3}>
                            <Sparkles aria-hidden />
                            {write.isPending ? t('capture.ai.writing') : t('capture.ai.submit')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export type PreviewState = 'form' | 'thanks';

/**
 * Vista previa del editor: la página pública real que arma el hub con el borrador sin guardar (`useLandingPreview`),
 * en un iframe sin scripts ni formularios. Se pide de nuevo medio segundo después del último cambio; si el borrador
 * no es válido todavía, se queda la última que sí lo era y se avisa.
 */
export function LandingLivePreview({
    page,
    draft,
    saved,
    options,
    className,
}: {
    page: LandingPageDetail;
    draft: LandingDraft;
    saved: LandingDraft;
    options: CaptureOptions | undefined;
    className?: string;
}) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const [device, setDevice] = useState<PreviewDevice>('desktop');
    const [state, setState] = useState<PreviewState>('form');
    const [html, setHtml] = useState<string | null>(null);
    const [invalid, setInvalid] = useState(false);
    const preview = useLandingPreview(workspace.id, page.id);
    const { mutate } = preview;
    const latest = useRef(0);
    const first = useRef(true);
    const patch = patchFrom(draft, saved);
    const request = JSON.stringify({ content: patch.content, fields: patch.fields, consent_channels: patch.consent_channels });
    useEffect(() => {
        const body = JSON.parse(request) as { content?: object; fields?: object[]; consent_channels?: string[] };
        const run = () => {
            const n = ++latest.current;
            mutate(
                { patch: body as never, state },
                {
                    onSuccess: (r) => {
                        if (n !== latest.current) return;
                        setHtml(r.html);
                        setInvalid(false);
                    },
                    onError: () => {
                        if (n === latest.current) setInvalid(true);
                    },
                },
            );
        };
        const delay = first.current ? 0 : 450;
        first.current = false;
        const timer = window.setTimeout(run, delay);
        return () => window.clearTimeout(timer);
    }, [request, state, mutate]);
    const height = device === 'mobile' ? 620 : 600;
    const accent = isHexColor(draft.content.color) ? draft.content.color : DEFAULT_ACCENT;
    return (
        <aside className={cn('igr:min-w-0 igr:space-y-3 igr:xl:sticky igr:xl:top-4', className)} aria-label={t('capture.preview.title')}>
            <div className="igr:flex igr:flex-wrap igr:items-center igr:justify-between igr:gap-2">
                <SegmentedControl
                    size="sm"
                    label={t('capture.preview.device')}
                    value={device}
                    onChange={setDevice}
                    options={[
                        { value: 'desktop', label: t('capture.preview.desktop'), icon: Monitor },
                        { value: 'mobile', label: t('capture.preview.mobile'), icon: Smartphone },
                    ]}
                />
                <SegmentedControl
                    size="sm"
                    label={t('capture.preview.state')}
                    value={state}
                    onChange={setState}
                    options={[
                        { value: 'form', label: t('capture.preview.stateForm') },
                        { value: 'thanks', label: t('capture.preview.stateThanks') },
                    ]}
                />
            </div>
            <DeviceFrame device={device} label={t('capture.preview.title')} url={`${(options?.base_url ?? '').replace(/^https?:\/\//, '')}${draft.slug}`} height={height}>
                {html === null ? (
                    <div className="igr:flex igr:h-full igr:items-center igr:justify-center igr:p-6" style={{ height }}>
                        <span className="igr:size-8 igr:animate-pulse igr:rounded-full" style={{ background: `${accent}55` }} aria-label={t('capture.preview.loading')} />
                    </div>
                ) : (
                    <PagePreviewFrame device={device} title={t('capture.preview.title')} srcDoc={html} height={height} />
                )}
            </DeviceFrame>
            {invalid ? <Callout tone="warning">{t('capture.preview.invalid')}</Callout> : null}
            <p className="igr:text-caption igr:text-muted-foreground">
                {t('capture.preview.note')} {state === 'thanks' && draft.content.redirect_url ? t('capture.preview.redirectNote') : null}
            </p>
        </aside>
    );
}
