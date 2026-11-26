import { Archive, EyeOff, ExternalLink, ImagePlus, Monitor, MoreHorizontal, Pencil, QrCode, Rocket, Save, Sparkles, Trash2, Undo2 } from 'lucide-react';
import { useEffect, useId, useMemo, useRef, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate, useParams, useSearchParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { Callout } from '@/components/ui/callout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input, Select, Textarea } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ErrorState, FieldError } from '@/components/ui/misc';
import { PageHeader } from '@/components/ui/page-header';
import { SegmentedControl } from '@/components/ui/segmented';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { errorMessage } from '@/features/contacts/errors';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { cn } from '@/lib/cn';
import { toast } from '@/stores/toast';
import { useCaptureOptions, useLandingMutations, useLandingPage } from './api';
import { LandingFieldsEditor } from './LandingFieldsEditor';
import { BenefitsEditor, FaqEditor, LandingLivePreview, ReviewsToggle, TemplatePicker, useCanWriteWithAi, WriteWithAiDialog } from './LandingDesignParts';
import { LandingResults } from './LandingResults';
import { DEFAULT_ACCENT, draftFrom, fieldsProblem, isHexColor, isHttpsUrl, patchFrom, rebase, slugValid, type LandingDraft } from './model';
import { QrShare } from './QrShare';
import { PageStatusBadge, Warnings } from './shared';
import type { CaptureOptions, LandingPageDetail } from './types';

const VIEWS = { diseno: 'design', compartir: 'share', resultados: 'results' } as const;
type View = (typeof VIEWS)[keyof typeof VIEWS];
const VIEW_PARAM: Record<View, string> = { design: 'diseno', share: 'compartir', results: 'resultados' };

/** Editor de una página de captura (modulos.md M12): diseño con vista previa en vivo, compartir (enlace y QR) y resultados. */
export function LandingEditorPage() {
    const { pageId } = useParams();
    const { workspace } = useWorkspace();
    const page = useLandingPage(workspace.id, pageId ?? null);
    if (page.isError) {
        return <ErrorState error={page.error} onRetry={() => void page.refetch()} />;
    }
    if (page.isPending) {
        return (
            <div className="igr:space-y-4" aria-busy>
                <Skeleton className="igr:h-4 igr:w-40" />
                <Skeleton className="igr:h-8 igr:w-72" />
                <Skeleton className="igr:h-9 igr:w-80" />
                <div className="igr:grid igr:gap-6 igr:xl:grid-cols-2">
                    <Skeleton className="igr:h-[560px] igr:rounded-xl" />
                    <Skeleton className="igr:h-[560px] igr:rounded-xl igr:max-xl:hidden" />
                </div>
            </div>
        );
    }
    return <Editor key={page.data.id} page={page.data} />;
}

function Editor({ page }: { page: LandingPageDetail }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const navigate = useNavigate();
    const [params, setParams] = useSearchParams();
    const canManage = useCan('manageMarketing') && page.status !== 'archived';
    const options = useCaptureOptions(workspace.id);
    const m = useLandingMutations(workspace.id);

    // Lo guardado es el punto de partida; si cambia en el servidor, el borrador toma lo nuevo sin perder lo que se edita.
    const saved = useMemo(() => draftFrom(page), [page]);
    const [draft, setDraft] = useState<LandingDraft>(saved);
    const [base, setBase] = useState(saved);
    if (base !== saved) {
        setBase(saved);
        setDraft(rebase(draft, base, saved));
    }
    const patch = patchFrom(draft, saved);
    const dirty = Object.keys(patch).length > 0;
    const problem = fieldsProblem(draft.fields, options.data?.max_fields ?? 12);
    const invalid =
        problem !== null ||
        draft.content.title.trim() === '' ||
        !slugValid(draft.slug) ||
        (draft.content.redirect_url !== null && draft.content.redirect_url !== '' && !isHttpsUrl(draft.content.redirect_url));

    const raw = params.get('vista') ?? '';
    const view: View = raw in VIEWS ? VIEWS[raw as keyof typeof VIEWS] : page.status === 'published' ? 'results' : 'design';
    const setView = (next: string) => {
        const p = new URLSearchParams(params);
        p.set('vista', VIEW_PARAM[next as View]);
        setParams(p, { replace: true });
    };
    const [confirm, setConfirm] = useState<'unpublish' | 'archive' | null>(null);

    useEffect(() => {
        if (!dirty) return;
        const warn = (e: BeforeUnloadEvent) => e.preventDefault();
        window.addEventListener('beforeunload', warn);
        return () => window.removeEventListener('beforeunload', warn);
    }, [dirty]);

    const save = (then?: () => void) =>
        m.update.mutate(
            { id: page.id, data: patch },
            {
                onSuccess: () => {
                    if (then) then();
                    else toast.success(t('capture.editor.saved'));
                },
                onError: (e) => toast.error(t('capture.editor.saveFailed'), errorMessage(e, t)),
            },
        );
    const publish = () => {
        const run = () =>
            m.publish.mutate(page.id, {
                onSuccess: () => {
                    toast.success(t('capture.editor.published'), t('capture.editor.publishedBody'));
                    setView('share');
                },
                onError: (e) => toast.error(t('capture.editor.publishFailed'), errorMessage(e, t)),
            });
        if (dirty) save(run);
        else run();
    };

    const busy = m.update.isPending || m.publish.isPending;
    const actions = canManage ? (
        <>
            <Button asChild variant="ghost">
                <a href={page.status === 'published' ? page.url : page.preview_url} target="_blank" rel="noopener noreferrer">
                    <ExternalLink aria-hidden />
                    <span className="igr:max-sm:sr-only">{page.status === 'published' ? t('capture.editor.openLive') : t('capture.editor.openPreview')}</span>
                </a>
            </Button>
            {page.status === 'published' ? (
                <Button variant="outline" onClick={() => setView('share')}>
                    <QrCode aria-hidden />
                    {t('capture.editor.share')}
                </Button>
            ) : (
                <Button disabled={invalid} loading={busy} onClick={publish}>
                    <Rocket aria-hidden />
                    {dirty ? t('capture.editor.saveAndPublish') : t('capture.editor.publish')}
                </Button>
            )}
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button variant="ghost" size="icon" aria-label={t('capture.editor.more')}>
                        <MoreHorizontal aria-hidden />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    {page.status === 'published' ? (
                        <DropdownMenuItem onSelect={() => setConfirm('unpublish')}>
                            <EyeOff aria-hidden />
                            {t('capture.editor.unpublish')}
                        </DropdownMenuItem>
                    ) : null}
                    <DropdownMenuItem className="igr:text-destructive" onSelect={() => setConfirm('archive')}>
                        <Archive aria-hidden />
                        {t('capture.editor.archive')}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </>
    ) : null;

    return (
        <>
            <PageHeader
                title={draft.name || page.name}
                meta={<PageStatusBadge status={page.status} />}
                description={page.status === 'published' ? page.url.replace(/^https?:\/\//, '') : t('capture.editor.draftLine')}
                actions={actions}
            />
            <Warnings warnings={page.warnings} className="igr:mb-5" />
            <Tabs value={view} onValueChange={setView}>
                <TabsList>
                    <TabsTrigger value="design">{t('capture.editor.tabs.design')}</TabsTrigger>
                    <TabsTrigger value="share">{t('capture.editor.tabs.share')}</TabsTrigger>
                    <TabsTrigger value="results">{t('capture.editor.tabs.results')}</TabsTrigger>
                </TabsList>
                <TabsContent value="design">
                    <Design page={page} draft={draft} saved={saved} onChange={setDraft} options={options.data} canManage={canManage} problem={problem} />
                </TabsContent>
                <TabsContent value="share">
                    {page.status === 'published' ? (
                        <Card>
                            <CardHeader>
                                <CardTitle>{t('capture.share.title')}</CardTitle>
                                <CardDescription>{t('capture.share.description')}</CardDescription>
                            </CardHeader>
                            <CardContent>
                                <QrShare url={page.url} qr={page.qr} name={page.name} linkLabel={t('capture.share.link')} shareText={draft.content.title} />
                            </CardContent>
                        </Card>
                    ) : (
                        <Callout
                            tone="brand"
                            title={t('capture.share.draftTitle')}
                            action={
                                canManage ? (
                                    <Button disabled={invalid} loading={busy} onClick={publish}>
                                        <Rocket aria-hidden />
                                        {t('capture.editor.publish')}
                                    </Button>
                                ) : undefined
                            }
                        >
                            {t('capture.share.draftBody')}
                        </Callout>
                    )}
                </TabsContent>
                <TabsContent value="results">
                    <LandingResults page={page} />
                </TabsContent>
            </Tabs>

            {dirty && canManage ? (
                <div className="igr:sticky igr:bottom-0 igr:z-10 igr:-mx-4 igr:-mb-5 igr:mt-6 igr:border-t igr:bg-card/95 igr:px-4 igr:py-3 igr:backdrop-blur igr:md:-mx-6 igr:md:-mb-7 igr:md:px-6 igr:lg:-mx-8 igr:lg:px-8">
                    <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-2">
                        <p className="igr:min-w-0 igr:flex-1 igr:text-support igr:text-muted-foreground" role="status">
                            {invalid ? t('capture.editor.fixFirst') : page.status === 'published' ? t('capture.editor.unsavedLive') : t('capture.editor.unsaved')}
                        </p>
                        <Button variant="ghost" onClick={() => setDraft(saved)}>
                            <Undo2 aria-hidden />
                            {t('capture.editor.discard')}
                        </Button>
                        <Button disabled={invalid} loading={m.update.isPending} onClick={() => save()}>
                            <Save aria-hidden />
                            {page.status === 'published' ? t('capture.editor.saveLive') : t('capture.editor.save')}
                        </Button>
                    </div>
                </div>
            ) : null}

            <ConfirmDialog
                open={confirm === 'unpublish'}
                onOpenChange={(open) => !open && setConfirm(null)}
                title={t('capture.editor.unpublishTitle')}
                description={t('capture.editor.unpublishBody')}
                confirmLabel={t('capture.editor.unpublish')}
                loading={m.unpublish.isPending}
                onConfirm={() =>
                    m.unpublish.mutate(page.id, {
                        onSuccess: () => {
                            setConfirm(null);
                            toast.info(t('capture.editor.unpublished'));
                        },
                        onError: (e) => toast.error(errorMessage(e, t)),
                    })
                }
            />
            <ConfirmDialog
                open={confirm === 'archive'}
                onOpenChange={(open) => !open && setConfirm(null)}
                title={t('capture.editor.archiveTitle')}
                description={t('capture.editor.archiveBody')}
                confirmLabel={t('capture.editor.archive')}
                tone="danger"
                loading={m.remove.isPending}
                onConfirm={() =>
                    m.remove.mutate(page.id, {
                        onSuccess: () => {
                            toast.info(t('capture.editor.archived'));
                            void navigate(`/w/${workspace.id}/paginas-de-captura`);
                        },
                        onError: (e) => toast.error(errorMessage(e, t)),
                    })
                }
            />
        </>
    );
}

function Design({
    page,
    draft,
    saved,
    onChange,
    options,
    canManage,
    problem,
}: {
    page: LandingPageDetail;
    draft: LandingDraft;
    saved: LandingDraft;
    onChange: (draft: LandingDraft) => void;
    options: CaptureOptions | undefined;
    canManage: boolean;
    problem: ReturnType<typeof fieldsProblem>;
}) {
    const { t } = useTranslation();
    const [pane, setPane] = useState<'edit' | 'preview'>('edit');
    const [params, setParams] = useSearchParams();
    const canWrite = useCanWriteWithAi() && canManage;
    // Desde el asistente de «Nueva página» se llega con ?ia=1: se abre «Escribir con IA».
    const [writing, setWriting] = useState(params.get('ia') === '1');
    const closeWriting = (open: boolean) => {
        setWriting(open);
        if (!open && params.has('ia')) {
            const p = new URLSearchParams(params);
            p.delete('ia');
            setParams(p, { replace: true });
        }
    };
    const content = (patch: Partial<LandingDraft['content']>) => onChange({ ...draft, content: { ...draft.content, ...patch } });
    const accent = isHexColor(draft.content.color) ? draft.content.color : DEFAULT_ACCENT;
    return (
        <>
            <SegmentedControl
                className="igr:mb-4 igr:xl:hidden"
                label={t('capture.editor.pane')}
                value={pane}
                onChange={setPane}
                options={[
                    { value: 'edit', label: t('capture.editor.paneEdit'), icon: Pencil },
                    { value: 'preview', label: t('capture.editor.panePreview'), icon: Monitor },
                ]}
            />
            <div className="igr:grid igr:items-start igr:gap-6 igr:xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                <fieldset disabled={!canManage} className={cn('igr:min-w-0 igr:space-y-5', pane === 'preview' && 'igr:max-xl:hidden')}>
                    <legend className="igr:sr-only">{t('capture.editor.tabs.design')}</legend>
                    <Section title={t('capture.design.title')} description={t('capture.design.help')}>
                        <TemplatePicker value={draft.content.template} accent={accent} onChange={(template) => content({ template })} disabled={!canManage} />
                    </Section>

                    <Section
                        title={t('capture.editor.text.title')}
                        description={t('capture.editor.text.help')}
                        action={
                            canWrite ? (
                                <Button type="button" size="sm" variant="outline" onClick={() => setWriting(true)}>
                                    <Sparkles aria-hidden />
                                    {t('capture.ai.open')}
                                </Button>
                            ) : undefined
                        }
                    >
                        <Field label={t('capture.design.eyebrow')} hint={t('capture.design.eyebrowHelp')} count={[(draft.content.eyebrow ?? '').length, 60]}>
                            {(id) => (
                                <Input
                                    id={id}
                                    value={draft.content.eyebrow ?? ''}
                                    maxLength={60}
                                    placeholder={t('capture.design.eyebrowPlaceholder')}
                                    onChange={(e) => content({ eyebrow: e.target.value })}
                                />
                            )}
                        </Field>
                        <Field label={t('capture.editor.text.headline')} count={[draft.content.title.length, 120]}>
                            {(id) => (
                                <Input
                                    id={id}
                                    value={draft.content.title}
                                    maxLength={120}
                                    onChange={(e) => content({ title: e.target.value })}
                                    aria-invalid={draft.content.title.trim() === '' || undefined}
                                />
                            )}
                        </Field>
                        <Field label={t('capture.editor.text.body')} hint={t('capture.editor.text.bodyHelp')}>
                            {(id) => <Textarea id={id} rows={5} maxLength={2000} value={draft.content.body ?? ''} onChange={(e) => content({ body: e.target.value })} />}
                        </Field>
                        <Field label={t('capture.editor.text.button')} hint={t('capture.editor.text.buttonHelp')}>
                            {(id) => (
                                <Input
                                    id={id}
                                    maxLength={40}
                                    value={draft.content.button ?? ''}
                                    placeholder={t('capture.preview.send')}
                                    onChange={(e) => content({ button: e.target.value })}
                                />
                            )}
                        </Field>
                    </Section>

                    <Section title={t('capture.design.more')} description={t('capture.design.moreHelp')}>
                        <div className="igr:space-y-2">
                            <p className="igr:text-[13px] igr:font-medium">{t('capture.design.benefits')}</p>
                            <BenefitsEditor value={draft.content.benefits} onChange={(benefits) => content({ benefits })} />
                        </div>
                        <div className="igr:space-y-2">
                            <p className="igr:text-[13px] igr:font-medium">{t('capture.design.faq')}</p>
                            <FaqEditor value={draft.content.faq} onChange={(faq) => content({ faq })} />
                        </div>
                        <ReviewsToggle value={draft.content.reviews} onChange={(reviews) => content({ reviews })} options={options} />
                    </Section>

                    <Section title={t('capture.editor.brand.title')} description={t('capture.editor.brand.help')}>
                        <div className="igr:grid igr:gap-4 igr:sm:grid-cols-2">
                            <ImageField page={page} kind="logo" draft={draft} options={options} onUrl={(url) => content({ logo_url: url })} />
                            <ImageField page={page} kind="image" draft={draft} options={options} onUrl={(url) => content({ image_url: url })} />
                        </div>
                        <ColorField value={draft.content.color} onChange={(color) => content({ color })} />
                    </Section>

                    <Section title={t('capture.editor.form.title')} description={t('capture.editor.form.help')}>
                        <LandingFieldsEditor
                            fields={draft.fields}
                            channels={draft.consent_channels}
                            options={options}
                            onChange={(fields, consent_channels) => onChange({ ...draft, fields, consent_channels })}
                        />
                        {problem ? <FieldError message={t(`capture.fields.problems.${problem}`, { max: options?.max_fields ?? 12 })} /> : null}
                    </Section>

                    <Section title={t('capture.editor.after.title')} description={t('capture.editor.after.help')}>
                        <AfterSubmit draft={draft} onContent={content} />
                    </Section>

                    <Section title={t('capture.editor.whatsapp.title')} description={t('capture.editor.whatsapp.help')}>
                        <label className="igr:flex igr:items-center igr:gap-2 igr:text-[13px] igr:font-medium">
                            <input
                                type="checkbox"
                                className="igr:size-4 igr:accent-primary"
                                checked={draft.content.whatsapp.enabled}
                                onChange={(e) => content({ whatsapp: { ...draft.content.whatsapp, enabled: e.target.checked } })}
                            />
                            {t('capture.editor.whatsapp.enable')}
                        </label>
                        {draft.content.whatsapp.enabled ? (
                            <div className="igr:grid igr:gap-4 igr:sm:grid-cols-2">
                                <Field label={t('capture.editor.whatsapp.label')}>
                                    {(id) => (
                                        <Input
                                            id={id}
                                            maxLength={40}
                                            value={draft.content.whatsapp.label ?? ''}
                                            placeholder={t('capture.preview.whatsapp')}
                                            onChange={(e) => content({ whatsapp: { ...draft.content.whatsapp, label: e.target.value } })}
                                        />
                                    )}
                                </Field>
                                <Field
                                    label={t('capture.editor.whatsapp.message')}
                                    hint={page.whatsapp_link ? t('capture.editor.whatsapp.code', { code: page.whatsapp_link.code }) : t('capture.editor.whatsapp.codeLater')}
                                >
                                    {(id) => (
                                        <Input
                                            id={id}
                                            maxLength={300}
                                            value={draft.content.whatsapp.message ?? ''}
                                            onChange={(e) => content({ whatsapp: { ...draft.content.whatsapp, message: e.target.value } })}
                                        />
                                    )}
                                </Field>
                            </div>
                        ) : null}
                    </Section>

                    <Section title={t('capture.editor.address.title')} description={t('capture.editor.address.help')}>
                        <div className="igr:grid igr:gap-4 igr:sm:grid-cols-2">
                            <Field label={t('capture.editor.address.name')} hint={t('capture.editor.address.nameHelp')}>
                                {(id) => <Input id={id} maxLength={160} value={draft.name} onChange={(e) => onChange({ ...draft, name: e.target.value })} />}
                            </Field>
                            <Field
                                label={t('capture.editor.address.slug')}
                                hint={slugValid(draft.slug) ? `${options?.base_url ?? ''}${draft.slug}` : t('capture.editor.address.slugHelp')}
                            >
                                {(id) => (
                                    <Input
                                        id={id}
                                        maxLength={80}
                                        value={draft.slug}
                                        autoCapitalize="none"
                                        spellCheck={false}
                                        aria-invalid={!slugValid(draft.slug) || undefined}
                                        onChange={(e) => onChange({ ...draft, slug: e.target.value.toLowerCase().replace(/\s+/g, '-') })}
                                    />
                                )}
                            </Field>
                        </div>
                        {page.status === 'published' && draft.slug !== page.slug ? <Callout tone="warning">{t('capture.editor.address.slugWarning')}</Callout> : null}
                        {(options?.sites.length ?? 0) > 0 ? (
                            <Field label={t('capture.editor.address.site')} hint={t('capture.editor.address.siteHelp')}>
                                {(id) => (
                                    <Select id={id} value={draft.site_id ?? ''} onChange={(e) => onChange({ ...draft, site_id: e.target.value || null })}>
                                        <option value="">{t('capture.editor.address.noSite')}</option>
                                        {(options?.sites ?? []).map((s) => (
                                            <option key={s.id} value={s.id}>
                                                {s.name}
                                            </option>
                                        ))}
                                    </Select>
                                )}
                            </Field>
                        ) : null}
                        <label className="igr:flex igr:items-start igr:gap-2 igr:text-[13px]">
                            <input
                                type="checkbox"
                                className="igr:mt-0.5 igr:size-4 igr:accent-primary"
                                checked={draft.indexable}
                                onChange={(e) => onChange({ ...draft, indexable: e.target.checked })}
                            />
                            <span>
                                <span className="igr:font-medium">{t('capture.editor.address.indexable')}</span>
                                <span className="igr:block igr:text-caption igr:text-muted-foreground">{t('capture.editor.address.indexableHelp')}</span>
                            </span>
                        </label>
                    </Section>
                </fieldset>
                <LandingLivePreview page={page} draft={draft} saved={saved} options={options} className={cn(pane === 'edit' && 'igr:max-xl:hidden')} />
            </div>
            {canWrite ? <WriteWithAiDialog open={writing} onOpenChange={closeWriting} draft={draft} onApply={onChange} /> : null}
        </>
    );
}

function AfterSubmit({ draft, onContent }: { draft: LandingDraft; onContent: (patch: Partial<LandingDraft['content']>) => void }) {
    const { t } = useTranslation();
    const name = useId();
    const redirect = draft.content.redirect_url !== null;
    return (
        <>
            <div className="igr:grid igr:gap-2 igr:sm:grid-cols-2" role="radiogroup" aria-label={t('capture.editor.after.title')}>
                {(['thanks', 'redirect'] as const).map((mode) => (
                    <label
                        key={mode}
                        className={cn(
                            'igr:flex igr:cursor-pointer igr:items-start igr:gap-2.5 igr:rounded-lg igr:border igr:p-3 igr:transition-colors igr:has-[:focus-visible]:ring-2 igr:has-[:focus-visible]:ring-ring',
                            (mode === 'redirect') === redirect ? 'igr:border-primary igr:bg-brand-soft/60' : 'igr:hover:bg-accent',
                        )}
                    >
                        <input
                            type="radio"
                            name={name}
                            className="igr:mt-0.5 igr:size-4 igr:accent-primary"
                            checked={(mode === 'redirect') === redirect}
                            onChange={() => onContent({ redirect_url: mode === 'redirect' ? '' : null })}
                        />
                        <span>
                            <span className="igr:block igr:text-[13px] igr:font-medium">{t(`capture.editor.after.${mode}`)}</span>
                            <span className="igr:block igr:text-caption igr:text-muted-foreground">{t(`capture.editor.after.${mode}Help`)}</span>
                        </span>
                    </label>
                ))}
            </div>
            {redirect ? (
                <Field label={t('capture.editor.after.url')} hint={t('capture.editor.after.urlHelp')}>
                    {(id) => (
                        <Input
                            id={id}
                            type="url"
                            inputMode="url"
                            placeholder={t('capture.editor.after.urlPlaceholder')}
                            value={draft.content.redirect_url ?? ''}
                            aria-invalid={(draft.content.redirect_url !== '' && !isHttpsUrl(draft.content.redirect_url)) || undefined}
                            onChange={(e) => onContent({ redirect_url: e.target.value })}
                        />
                    )}
                </Field>
            ) : (
                <div className="igr:grid igr:gap-4">
                    <Field label={t('capture.editor.after.thanksTitle')}>
                        {(id) => (
                            <Input
                                id={id}
                                maxLength={120}
                                value={draft.content.success_title ?? ''}
                                placeholder={t('capture.preview.thanksTitle')}
                                onChange={(e) => onContent({ success_title: e.target.value })}
                            />
                        )}
                    </Field>
                    <Field label={t('capture.editor.after.thanksBody')}>
                        {(id) => (
                            <Textarea
                                id={id}
                                rows={3}
                                maxLength={600}
                                value={draft.content.success_message ?? ''}
                                placeholder={t('capture.preview.thanksBody')}
                                onChange={(e) => onContent({ success_message: e.target.value })}
                            />
                        )}
                    </Field>
                </div>
            )}
        </>
    );
}

function ImageField({
    page,
    kind,
    draft,
    options,
    onUrl,
}: {
    page: LandingPageDetail;
    kind: 'logo' | 'image';
    draft: LandingDraft;
    options: CaptureOptions | undefined;
    onUrl: (url: string | null) => void;
}) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const m = useLandingMutations(workspace.id);
    const input = useRef<HTMLInputElement>(null);
    const id = useId();
    const uploaded = kind === 'logo' ? page.content.logo_key : page.content.image_key;
    const src = kind === 'logo' ? page.content.logo_src : page.content.image_src;
    const url = kind === 'logo' ? draft.content.logo_url : draft.content.image_url;
    const supported = options?.images.supported ?? true;
    const busy = (m.uploadImage.isPending && m.uploadImage.variables.kind === kind) || (m.deleteImage.isPending && m.deleteImage.variables.kind === kind);
    return (
        <div className="igr:min-w-0 igr:space-y-2">
            <p id={`${id}-label`} className="igr:text-[13px] igr:font-medium">
                {t(`capture.editor.brand.${kind}`)}
            </p>
            <div
                className={cn(
                    'igr:flex igr:items-center igr:justify-center igr:overflow-hidden igr:rounded-lg igr:border igr:border-dashed igr:bg-muted/40',
                    kind === 'logo' ? 'igr:h-20' : 'igr:h-28',
                )}
            >
                {src && (uploaded || url) ? (
                    <img src={src} alt="" className={kind === 'logo' ? 'igr:max-h-14 igr:max-w-[80%] igr:object-contain' : 'igr:size-full igr:object-cover'} />
                ) : (
                    <span className="igr:px-3 igr:text-center igr:text-caption igr:text-muted-foreground">{t(`capture.editor.brand.${kind}Empty`)}</span>
                )}
            </div>
            {supported ? (
                <div className="igr:flex igr:flex-wrap igr:gap-2">
                    <input
                        ref={input}
                        type="file"
                        accept="image/png,image/jpeg,image/webp"
                        className="igr:sr-only"
                        aria-labelledby={`${id}-label`}
                        tabIndex={-1}
                        onChange={(e) => {
                            const file = e.target.files?.[0];
                            e.target.value = '';
                            if (!file) return;
                            if (options && file.size > options.images.max_kb * 1024) {
                                toast.error(t('capture.editor.brand.tooBig', { max: Math.round(options.images.max_kb / 1024) }));
                                return;
                            }
                            m.uploadImage.mutate({ id: page.id, kind, file }, { onError: (err) => toast.error(errorMessage(err, t)) });
                        }}
                    />
                    <Button type="button" size="sm" variant="outline" loading={busy} onClick={() => input.current?.click()}>
                        <ImagePlus aria-hidden />
                        {uploaded ? t('capture.editor.brand.replace') : t(`capture.editor.brand.upload.${kind}`)}
                    </Button>
                    {uploaded ? (
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            loading={busy}
                            onClick={() => m.deleteImage.mutate({ id: page.id, kind }, { onError: (err) => toast.error(errorMessage(err, t)) })}
                        >
                            <Trash2 aria-hidden />
                            {t('capture.editor.brand.remove')}
                        </Button>
                    ) : null}
                </div>
            ) : (
                <Input aria-labelledby={`${id}-label`} type="url" inputMode="url" placeholder="https://" value={url ?? ''} onChange={(e) => onUrl(e.target.value || null)} />
            )}
            {kind === 'logo' && !uploaded ? <p className="igr:text-caption igr:text-muted-foreground">{t('capture.editor.brand.logoHelp')}</p> : null}
        </div>
    );
}

function ColorField({ value, onChange }: { value: string | null; onChange: (value: string | null) => void }) {
    const { t } = useTranslation();
    const id = useId();
    const color = isHexColor(value) ? value : DEFAULT_ACCENT;
    return (
        <div className="igr:space-y-1.5">
            <Label htmlFor={id}>{t('capture.editor.brand.color')}</Label>
            <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-2">
                <input
                    id={id}
                    type="color"
                    value={color}
                    onChange={(e) => onChange(e.target.value)}
                    className="igr:h-9 igr:w-12 igr:cursor-pointer igr:rounded-md igr:border igr:border-input igr:bg-card igr:p-1"
                />
                <Input
                    aria-label={t('capture.editor.brand.colorHex')}
                    className="igr:w-28 igr:font-mono"
                    maxLength={7}
                    value={value ?? ''}
                    placeholder={DEFAULT_ACCENT}
                    onChange={(e) => onChange(e.target.value || null)}
                    aria-invalid={(value !== null && value !== '' && !isHexColor(value)) || undefined}
                />
                {value ? (
                    <Button type="button" size="sm" variant="ghost" onClick={() => onChange(null)}>
                        {t('capture.editor.brand.colorReset')}
                    </Button>
                ) : null}
            </div>
            <p className="igr:text-caption igr:text-muted-foreground">{t('capture.editor.brand.colorHelp')}</p>
        </div>
    );
}

function Section({ title, description, action, children }: { title: string; description?: string; action?: ReactNode; children: ReactNode }) {
    return (
        <Card>
            <CardHeader className={action ? 'igr:flex igr:flex-row igr:flex-wrap igr:items-start igr:justify-between igr:gap-3' : undefined}>
                <div className="igr:min-w-0">
                    <CardTitle>{title}</CardTitle>
                    {description ? <CardDescription>{description}</CardDescription> : null}
                </div>
                {action}
            </CardHeader>
            <CardContent className="igr:space-y-4">{children}</CardContent>
        </Card>
    );
}

function Field({ label, hint, count, children }: { label: string; hint?: string; count?: [number, number]; children: (id: string) => ReactNode }) {
    const id = useId();
    return (
        <div className="igr:min-w-0 igr:space-y-1.5">
            <div className="igr:flex igr:items-baseline igr:justify-between igr:gap-2">
                <Label htmlFor={id}>{label}</Label>
                {count ? <span className="igr:text-caption igr:text-muted-foreground igr:tabular-nums">{`${count[0]}/${count[1]}`}</span> : null}
            </div>
            {children(id)}
            {hint ? <p className="igr:break-words igr:text-caption igr:text-muted-foreground">{hint}</p> : null}
        </div>
    );
}
