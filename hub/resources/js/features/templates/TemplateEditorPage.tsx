import { Eye, Mail, MessageCircle, MessageSquareText, Save } from 'lucide-react';
import { lazy, Suspense, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useBlocker, useParams } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input, Select, Textarea } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { EmptyState, ErrorState, FieldError } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { toast } from '@/stores/toast';
import { errorMessage } from '@/features/contacts/errors';
import { SuggestSubjectButton, SuggestVariantsButton, SuggestWhatsappButton } from '@/features/copilot/Suggest';
import {
    CATEGORIES,
    type PreviewResult,
    type TemplateCategory,
    type TemplateDetail,
    type TemplateInput,
    type WaVariables,
    usePreviewDraft,
    useTemplate,
    useUpdateTemplate,
    useWaSources,
} from './api';
import { insertAt, liveChecks, missingWaSlots, smsSegments, waBodyOf } from './content';
import { WaStatusCallout } from './WaStatus';
import { ChecksList } from './editor/ChecksList';
import type { EmailCanvasHandle, EmailOutput } from './editor/EmailCanvas';
import { PreviewContactPicker, PreviewView } from './editor/PreviewPanel';
import { VariablePicker } from './editor/VariablePicker';
import { AutoBreadcrumbs } from '@/components/ui/page-header';

const EmailCanvas = lazy(() => import('./editor/EmailCanvas'));

const CHANNEL_ICON = { email: Mail, sms: MessageSquareText, whatsapp: MessageCircle } as const;

interface Draft {
    name: string;
    category: TemplateCategory;
    subject: string;
    preheader: string;
    body_mjml: string | null;
    body_html: string | null;
    body_text: string;
    editor_state: string | null;
    wa_template_id: string | null;
    variables: WaVariables;
}

function toDraft(t: TemplateDetail): Draft {
    return {
        name: t.name,
        category: t.category,
        subject: t.subject ?? '',
        preheader: t.preheader ?? '',
        body_mjml: t.body_mjml,
        body_html: t.body_html,
        body_text: t.body_text ?? '',
        editor_state: t.editor_state,
        wa_template_id: t.wa_template_id,
        variables: Array.isArray(t.variables) ? {} : t.variables,
    };
}

function toInput(d: Draft, channel: TemplateDetail['channel']): TemplateInput {
    const common = { name: d.name, category: d.category };
    if (channel === 'email') return { ...common, subject: d.subject, preheader: d.preheader, body_mjml: d.body_mjml, body_html: d.body_html, editor_state: d.editor_state };
    if (channel === 'sms') return { ...common, body_text: d.body_text };
    return { ...common, wa_template_id: d.wa_template_id, variables: d.variables };
}

/** Editor de una plantilla: correo (arrastrar y soltar), SMS o WhatsApp (mapeo de variables). */
export function TemplateEditorPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const { templateId = '' } = useParams();
    const template = useTemplate(workspace.id, templateId);
    const back = <AutoBreadcrumbs />;

    if (template.isError) {
        return (
            <>
                {back}
                {isApiError(template.error, 'not_found') ? (
                    <EmptyState icon={Mail} title={t('templates.editor.notFound')} />
                ) : (
                    <ErrorState error={template.error} onRetry={() => void template.refetch()} />
                )}
            </>
        );
    }
    if (template.isPending) {
        return (
            <>
                {back}
                <Skeleton className="igr:mb-4 igr:h-12" />
                <Skeleton className="igr:h-[560px]" />
            </>
        );
    }
    return (
        <>
            {back}
            <Editor key={template.data.id} template={template.data} />
        </>
    );
}

function Editor({ template }: { template: TemplateDetail }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canEdit = useCan('manageMarketing') && template.status !== 'archived';
    const update = useUpdateTemplate(workspace.id, template.id);
    const [draft, setDraft] = useState<Draft>(() => toDraft(template));
    const [saved, setSaved] = useState<Draft>(() => toDraft(template));
    const [serverChecks, setServerChecks] = useState(template.checks);
    const [previewOpen, setPreviewOpen] = useState(false);
    const canvas = useRef<EmailCanvasHandle>(null);
    const dirty = JSON.stringify(draft) !== JSON.stringify(saved);
    const set = useCallback((patch: Partial<Draft>) => setDraft((d) => ({ ...d, ...patch })), []);
    const Icon = CHANNEL_ICON[template.channel];

    const blocker = useBlocker(({ currentLocation, nextLocation }) => dirty && currentLocation.pathname !== nextLocation.pathname);
    useEffect(() => {
        const onBeforeUnload = (e: BeforeUnloadEvent) => {
            if (dirty) e.preventDefault();
        };
        window.addEventListener('beforeunload', onBeforeUnload);
        return () => window.removeEventListener('beforeunload', onBeforeUnload);
    }, [dirty]);

    const waSources = useWaSources(workspace.id, template.channel === 'whatsapp');
    const live = useMemo(() => {
        if (template.channel === 'whatsapp') {
            const source = waSources.data?.find((s) => s.id === draft.wa_template_id);
            if (!source) return [{ level: 'error' as const, code: 'wa_template_missing', message: '' }];
            return missingWaSlots(source.slots, draft.variables).length > 0 ? [{ level: 'error' as const, code: 'wa_variables_missing', message: '' }] : [];
        }
        return liveChecks({
            channel: template.channel,
            category: draft.category,
            subject: draft.subject,
            preheader: draft.preheader,
            body: template.channel === 'sms' ? draft.body_text : (draft.body_html ?? draft.body_mjml),
        });
    }, [
        template.channel,
        waSources.data,
        draft.wa_template_id,
        draft.variables,
        draft.category,
        draft.subject,
        draft.preheader,
        draft.body_text,
        draft.body_html,
        draft.body_mjml,
    ]);

    const save = (status?: 'draft' | 'ready') => {
        let current = draft;
        if (template.channel === 'email') {
            const out = canvas.current?.output();
            if (out) current = { ...draft, body_mjml: out.mjml, body_html: out.html, editor_state: out.state };
        }
        update.mutate(
            { ...toInput(current, template.channel), ...(status ? { status } : {}) },
            {
                onSuccess: (result) => {
                    const next = toDraft(result);
                    setDraft(next);
                    setSaved(next);
                    setServerChecks(result.checks);
                    toast.success(status === 'ready' ? t('templates.editor.markedReady') : t('templates.editor.saved'));
                },
                onError: (error) => toast.error(errorMessage(error, t)),
            },
        );
    };

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 's') {
                e.preventDefault();
                if (canEdit && !update.isPending) save();
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    });

    const fieldErrors = isApiError(update.error) ? update.error.fieldErrors : {};
    const status = update.data?.status ?? template.status;

    return (
        <div className="igr:space-y-4">
            <div className="igr:flex igr:flex-wrap igr:items-start igr:justify-between igr:gap-3">
                <div className="igr:flex igr:min-w-0 igr:flex-1 igr:items-start igr:gap-3">
                    <span className="igr:mt-1 igr:flex igr:size-9 igr:shrink-0 igr:items-center igr:justify-center igr:rounded-md igr:bg-muted">
                        <Icon className="igr:size-4" aria-hidden="true" />
                    </span>
                    <div className="igr:min-w-0 igr:flex-1 igr:space-y-1.5">
                        <Input
                            value={draft.name}
                            onChange={(e) => set({ name: e.target.value })}
                            disabled={!canEdit}
                            aria-label={t('templates.fields.name')}
                            className="igr:h-9 igr:max-w-xl igr:text-base igr:font-semibold"
                            maxLength={160}
                        />
                        <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-2 igr:text-[13px] igr:text-muted-foreground">
                            <span>{t(`templates.channels.${template.channel}`)}</span>
                            <span aria-hidden="true">·</span>
                            <Label htmlFor="tpl-category" className="igr:sr-only">
                                {t('templates.fields.category')}
                            </Label>
                            <Select
                                id="tpl-category"
                                value={draft.category}
                                onChange={(e) => set({ category: e.target.value as TemplateCategory })}
                                disabled={!canEdit}
                                className="igr:h-7 igr:w-auto igr:text-[13px]"
                            >
                                {CATEGORIES.map((c) => (
                                    <option key={c} value={c}>
                                        {t(`templates.categories.${c}`)}
                                    </option>
                                ))}
                            </Select>
                            <Badge variant={status === 'ready' ? 'success' : status === 'archived' ? 'outline' : 'secondary'}>{t(`templates.statuses.${status}`)}</Badge>
                            {template.library_key && <Badge variant="outline">{t('templates.fromLibrary')}</Badge>}
                            {dirty && <span className="igr:text-warning">{t('templates.editor.unsaved')}</span>}
                        </div>
                    </div>
                </div>
                <div className="igr:flex igr:flex-wrap igr:gap-2">
                    <Button type="button" variant="outline" onClick={() => setPreviewOpen(true)}>
                        <Eye className="igr:size-4" />
                        {t('templates.preview.open')}
                    </Button>
                    {canEdit && (
                        <>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => save()}
                                loading={update.isPending}
                                disabled={!dirty && status !== 'archived'}
                                title={t('templates.editor.saveShortcut')}
                            >
                                <Save className="igr:size-4" />
                                {t('common.save')}
                            </Button>
                            {status !== 'ready' && (
                                <Button type="button" onClick={() => save('ready')} loading={update.isPending}>
                                    {t('templates.editor.markReady')}
                                </Button>
                            )}
                        </>
                    )}
                </div>
            </div>
            <FieldError message={fieldErrors.status ?? fieldErrors.name} />

            {template.channel === 'email' && <EmailEditor draft={draft} set={set} canEdit={canEdit} canvas={canvas} errors={fieldErrors} />}
            {template.channel === 'sms' && <SmsEditor draft={draft} set={set} canEdit={canEdit} error={fieldErrors.body_text} />}
            {template.channel === 'whatsapp' ? <WaStatusCallout template={template} /> : null}
            {template.channel === 'whatsapp' && <WhatsAppEditor draft={draft} set={set} canEdit={canEdit} />}

            <Card>
                <CardHeader>
                    <CardTitle>{t('templates.checks.title')}</CardTitle>
                    <CardDescription>{dirty ? t('templates.checks.liveHelp') : t('templates.checks.help')}</CardDescription>
                </CardHeader>
                <CardContent>{dirty ? <ChecksList checks={live} live /> : <ChecksList checks={serverChecks} />}</CardContent>
            </Card>

            <PreviewDialog open={previewOpen} onOpenChange={setPreviewOpen} channel={template.channel} draft={draft} canvas={canvas} />

            <Dialog open={blocker.state === 'blocked'} onOpenChange={(open) => !open && blocker.reset?.()}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('templates.editor.leaveTitle')}</DialogTitle>
                        <DialogDescription>{t('templates.editor.leaveDescription')}</DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => blocker.reset?.()}>
                            {t('templates.editor.stay')}
                        </Button>
                        <Button variant="destructive" onClick={() => blocker.proceed?.()}>
                            {t('templates.editor.leave')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

interface EditorProps {
    draft: Draft;
    set: (patch: Partial<Draft>) => void;
    canEdit: boolean;
}

/** El campo por id: los componentes de UI compartidos no reenvían `ref`. */
function fieldById(id: string): HTMLInputElement | HTMLTextAreaElement | null {
    return document.getElementById(id) as HTMLInputElement | HTMLTextAreaElement | null;
}

/** Campo de texto de una línea con selector de variables que inserta en el cursor. */
function LiquidField({
    id,
    label,
    value,
    onChange,
    disabled,
    max,
    error,
    help,
}: {
    id: string;
    label: string;
    value: string;
    onChange: (v: string) => void;
    disabled: boolean;
    max: number;
    error?: string | undefined;
    help?: string;
}) {
    const { t } = useTranslation();
    const length = Array.from(value).length;
    return (
        <div className="igr:space-y-1.5">
            <div className="igr:flex igr:items-center igr:justify-between igr:gap-2">
                <Label htmlFor={id}>{label}</Label>
                <span className={length > max ? 'igr:text-xs igr:text-warning' : 'igr:text-xs igr:text-muted-foreground'} aria-live="polite">
                    {t('templates.fields.chars', { n: length, max })}
                </span>
            </div>
            <div className="igr:flex igr:gap-2">
                <Input id={id} value={value} onChange={(e) => onChange(e.target.value)} disabled={disabled} aria-invalid={error ? true : undefined} />
                <VariablePicker
                    size="icon"
                    disabled={disabled}
                    label={t('templates.variables.insertIn', { field: label })}
                    onPick={(expr) => {
                        const el = fieldById(id);
                        const r = insertAt(value, el?.selectionStart ?? value.length, el?.selectionEnd ?? value.length, expr);
                        onChange(r.value);
                        requestAnimationFrame(() => {
                            el?.focus();
                            el?.setSelectionRange(r.caret, r.caret);
                        });
                    }}
                />
            </div>
            {help && <p className="igr:text-xs igr:text-muted-foreground">{help}</p>}
            <FieldError message={error} />
        </div>
    );
}

function EmailEditor({ draft, set, canEdit, canvas, errors }: EditorProps & { canvas: React.RefObject<EmailCanvasHandle | null>; errors: Record<string, string> }) {
    const { t } = useTranslation();
    const [device, setDevice] = useState<'desktop' | 'mobile'>('desktop');
    const onChange = useCallback((out: EmailOutput) => set({ body_mjml: out.mjml, body_html: out.html, editor_state: out.state }), [set]);

    return (
        <div className="igr:space-y-4">
            <Card>
                <CardContent className="igr:grid igr:gap-4 igr:pt-5 igr:md:grid-cols-2">
                    <LiquidField
                        id="tpl-subject"
                        label={t('templates.fields.subject')}
                        value={draft.subject}
                        onChange={(v) => set({ subject: v })}
                        disabled={!canEdit}
                        max={80}
                        error={errors.subject}
                    />
                    <LiquidField
                        id="tpl-preheader"
                        label={t('templates.fields.preheader')}
                        value={draft.preheader}
                        onChange={(v) => set({ preheader: v })}
                        disabled={!canEdit}
                        max={120}
                        error={errors.preheader}
                        help={t('templates.fields.preheaderHelp')}
                    />
                    {canEdit ? (
                        <div className="igr:flex igr:flex-wrap igr:justify-end igr:gap-1 igr:md:col-span-2">
                            <SuggestSubjectButton onPick={(subject, preheader) => set({ subject, preheader })} />
                            {draft.subject.trim() !== '' ? <SuggestVariantsButton text={draft.subject} kind="subject" onPick={(subject) => set({ subject })} /> : null}
                        </div>
                    ) : null}
                </CardContent>
            </Card>
            <div className="igr:flex igr:flex-wrap igr:items-center igr:justify-between igr:gap-2">
                <p className="igr:text-[13px] igr:text-muted-foreground">{t('templates.editor.canvasHelp')}</p>
                <div className="igr:flex igr:gap-2">
                    <div className="igr:flex igr:gap-1" role="group" aria-label={t('templates.preview.device')}>
                        {(['desktop', 'mobile'] as const).map((d) => (
                            <Button
                                key={d}
                                type="button"
                                size="sm"
                                variant={device === d ? 'secondary' : 'ghost'}
                                aria-pressed={device === d}
                                onClick={() => {
                                    setDevice(d);
                                    canvas.current?.setDevice(d);
                                }}
                            >
                                {t(`templates.preview.${d}`)}
                            </Button>
                        ))}
                    </div>
                    <VariablePicker
                        disabled={!canEdit}
                        onPick={(expr) => {
                            if (!canvas.current?.insertVariable(expr)) {
                                void navigator.clipboard.writeText(expr).catch(() => undefined);
                                toast.success(t('templates.variables.copied'));
                            }
                        }}
                    />
                </div>
            </div>
            <div className="igr:h-[calc(100vh-300px)] igr:min-h-[560px]">
                <Suspense fallback={<Skeleton className="igr:h-full" />}>
                    <EmailCanvas ref={canvas} mjml={draft.body_mjml} state={draft.editor_state} readOnly={!canEdit} onChange={onChange} />
                </Suspense>
            </div>
            <FieldError message={errors.body_html} />
        </div>
    );
}

function SmsEditor({ draft, set, canEdit, error }: EditorProps & { error?: string | undefined }) {
    const { t } = useTranslation();
    const info = smsSegments(draft.body_text);
    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('templates.sms.title')}</CardTitle>
                <CardDescription>{t('templates.sms.help')}</CardDescription>
            </CardHeader>
            <CardContent className="igr:space-y-2">
                <div className="igr:flex igr:items-center igr:justify-between igr:gap-2">
                    <Label htmlFor="tpl-sms">{t('templates.sms.text')}</Label>
                    <VariablePicker
                        disabled={!canEdit}
                        onPick={(expr) => {
                            const el = fieldById('tpl-sms');
                            const r = insertAt(draft.body_text, el?.selectionStart ?? draft.body_text.length, el?.selectionEnd ?? draft.body_text.length, expr);
                            set({ body_text: r.value });
                            requestAnimationFrame(() => {
                                el?.focus();
                                el?.setSelectionRange(r.caret, r.caret);
                            });
                        }}
                    />
                </div>
                <Textarea
                    id="tpl-sms"
                    rows={6}
                    value={draft.body_text}
                    onChange={(e) => set({ body_text: e.target.value })}
                    disabled={!canEdit}
                    aria-invalid={error ? true : undefined}
                />
                {canEdit && draft.body_text.trim() !== '' ? (
                    <div className="igr:flex igr:justify-end">
                        <SuggestVariantsButton text={draft.body_text} kind="body" onPick={(body_text) => set({ body_text })} />
                    </div>
                ) : null}
                <p className={info.segments > 3 ? 'igr:text-xs igr:text-warning' : 'igr:text-xs igr:text-muted-foreground'} aria-live="polite">
                    {t('templates.sms.counter', { length: info.length, segments: info.segments, encoding: info.encoding })}
                    {info.encoding === 'UCS-2' ? ` · ${t('templates.sms.unicode')}` : ''}
                </p>
                <p className="igr:text-xs igr:text-muted-foreground">{t('templates.sms.liquidNote')}</p>
                <FieldError message={error} />
            </CardContent>
        </Card>
    );
}

function WhatsAppEditor({ draft, set, canEdit }: EditorProps) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const sources = useWaSources(workspace.id);
    const source = sources.data?.find((s) => s.id === draft.wa_template_id);
    const missing = source ? missingWaSlots(source.slots, draft.variables) : [];
    const setVar = (part: 'header' | 'body' | 'buttons', key: string, value: string) =>
        set({ variables: { ...draft.variables, [part]: { ...(draft.variables[part] ?? {}), [key]: value } } });

    return (
        <div className="igr:grid igr:items-start igr:gap-4 igr:lg:grid-cols-[minmax(0,1fr)_360px]">
            <Card>
                <CardHeader>
                    <CardTitle>{t('templates.wa.title')}</CardTitle>
                    <CardDescription>{t('templates.wa.help')}</CardDescription>
                </CardHeader>
                <CardContent className="igr:space-y-4">
                    {canEdit ? (
                        <div className="igr:flex igr:justify-end">
                            <SuggestWhatsappButton />
                        </div>
                    ) : null}
                    {sources.isPending ? (
                        <Skeleton className="igr:h-9" />
                    ) : (sources.data ?? []).length === 0 ? (
                        <EmptyState icon={MessageCircle} title={t('templates.wa.noneTitle')} description={t('templates.wa.noneHelp')} />
                    ) : (
                        <div className="igr:space-y-1.5">
                            <Label htmlFor="tpl-wa-source">{t('templates.wa.source')}</Label>
                            <Select
                                id="tpl-wa-source"
                                value={draft.wa_template_id ?? ''}
                                disabled={!canEdit}
                                onChange={(e) => set({ wa_template_id: e.target.value || null, variables: {} })}
                            >
                                <option value="">{t('templates.wa.choose')}</option>
                                {(sources.data ?? []).map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name} · {s.language} · {t(`templates.wa.status.${s.status}`, { defaultValue: s.status })}
                                    </option>
                                ))}
                            </Select>
                        </div>
                    )}
                    {source && (
                        <div className="igr:space-y-3">
                            {source.slots.header_media && (
                                <MappingRow
                                    label={t('templates.wa.headerMedia', { kind: t(`templates.wa.media.${source.slots.header_media}`) })}
                                    value={draft.variables.header_media ?? ''}
                                    onChange={(v) => set({ variables: { ...draft.variables, header_media: v } })}
                                    disabled={!canEdit}
                                    missing={missing.includes('header_media')}
                                />
                            )}
                            {source.slots.header.map((k) => (
                                <MappingRow
                                    key={`h${k}`}
                                    label={t('templates.wa.headerParam', { key: k })}
                                    value={draft.variables.header?.[k] ?? ''}
                                    onChange={(v) => setVar('header', k, v)}
                                    disabled={!canEdit}
                                    missing={missing.includes(`header.${k}`)}
                                />
                            ))}
                            {source.slots.body.map((k) => (
                                <MappingRow
                                    key={`b${k}`}
                                    label={t('templates.wa.bodyParam', { key: k })}
                                    value={draft.variables.body?.[k] ?? ''}
                                    onChange={(v) => setVar('body', k, v)}
                                    disabled={!canEdit}
                                    missing={missing.includes(`body.${k}`)}
                                />
                            ))}
                            {source.slots.buttons.map((b) =>
                                b.needs_value || b.type === 'quick_reply' ? (
                                    <MappingRow
                                        key={`btn${b.index}`}
                                        label={t(
                                            b.type === 'quick_reply' ? 'templates.wa.buttonPayload' : b.type === 'copy_code' ? 'templates.wa.buttonCode' : 'templates.wa.buttonUrl',
                                            { text: b.text },
                                        )}
                                        value={draft.variables.buttons?.[String(b.index)] ?? ''}
                                        onChange={(v) => setVar('buttons', String(b.index), v)}
                                        disabled={!canEdit}
                                        missing={missing.includes(`buttons.${b.index}`)}
                                    />
                                ) : null,
                            )}
                            {source.slots.header.length + source.slots.body.length + source.slots.buttons.length === 0 && !source.slots.header_media && (
                                <p className="igr:text-[13px] igr:text-muted-foreground">{t('templates.wa.noParams')}</p>
                            )}
                        </div>
                    )}
                </CardContent>
            </Card>
            {source && (
                <Card>
                    <CardHeader>
                        <CardTitle>{t('templates.wa.approvedText')}</CardTitle>
                        <CardDescription>{t('templates.wa.approvedHelp')}</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <p className="igr:whitespace-pre-wrap igr:rounded-md igr:bg-muted/50 igr:p-3 igr:text-[13px]">{waBodyOf(source)?.body}</p>
                    </CardContent>
                </Card>
            )}
        </div>
    );
}

function MappingRow({ label, value, onChange, disabled, missing }: { label: string; value: string; onChange: (v: string) => void; disabled: boolean; missing: boolean }) {
    const { t } = useTranslation();
    const id = `wa-${label.replace(/\W+/g, '-')}`;
    return (
        <div className="igr:space-y-1.5">
            <Label htmlFor={id}>{label}</Label>
            <div className="igr:flex igr:gap-2">
                <Input
                    id={id}
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                    disabled={disabled}
                    placeholder={t('templates.wa.placeholder')}
                    aria-invalid={missing ? true : undefined}
                />
                <VariablePicker
                    size="icon"
                    disabled={disabled}
                    onPick={(expr) => {
                        const el = fieldById(id);
                        const r = insertAt(value, el?.selectionStart ?? value.length, el?.selectionEnd ?? value.length, expr);
                        onChange(r.value);
                    }}
                />
            </div>
            {missing && <p className="igr:text-xs igr:text-destructive">{t('templates.wa.missing')}</p>}
        </div>
    );
}

function PreviewDialog({
    open,
    onOpenChange,
    channel,
    draft,
    canvas,
}: {
    open: boolean;
    onOpenChange: (o: boolean) => void;
    channel: TemplateDetail['channel'];
    draft: Draft;
    canvas: React.RefObject<EmailCanvasHandle | null>;
}) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const preview = usePreviewDraft(workspace.id);
    const sources = useWaSources(workspace.id, channel === 'whatsapp' && open);
    const [contact, setContact] = useState<{ id: string; name: string } | null>(null);
    const [result, setResult] = useState<PreviewResult | null>(null);
    const mutate = preview.mutate;

    useEffect(() => {
        if (!open) return;
        const out = channel === 'email' ? canvas.current?.output() : null;
        mutate(
            {
                channel,
                category: draft.category,
                subject: draft.subject,
                preheader: draft.preheader,
                body_html: out?.html ?? draft.body_html,
                body_text: draft.body_text,
                wa_template_id: draft.wa_template_id,
                variables: draft.variables,
                contact_id: contact?.id ?? null,
            },
            { onSuccess: setResult, onError: () => setResult(null) },
        );
    }, [open, contact, channel, draft, canvas, mutate]);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="igr:max-w-4xl">
                <DialogHeader>
                    <DialogTitle>{t('templates.preview.title')}</DialogTitle>
                    <DialogDescription>{contact ? t('templates.preview.realHelp') : t('templates.preview.sampleHelp')}</DialogDescription>
                </DialogHeader>
                <div className="igr:space-y-3">
                    <PreviewContactPicker contact={contact} onChange={setContact} />
                    {preview.isPending && !result ? (
                        <Skeleton className="igr:h-[420px]" />
                    ) : preview.isError ? (
                        <div role="alert" className="igr:rounded-md igr:border igr:border-destructive/40 igr:bg-destructive/5 igr:p-3 igr:text-[13px] igr:text-destructive">
                            {errorMessage(preview.error, t)}
                        </div>
                    ) : result ? (
                        <PreviewView result={result} channel={channel} waBody={waBodyOf(sources.data?.find((s) => s.id === draft.wa_template_id))} />
                    ) : null}
                </div>
            </DialogContent>
        </Dialog>
    );
}
