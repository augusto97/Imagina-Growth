import { Save } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useLocation, useNavigate, useParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input, Textarea } from '@/components/ui/input';
import { Kbd } from '@/components/ui/kbd';
import { Label } from '@/components/ui/label';
import { ErrorState, FieldError, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { useDebounced } from '@/hooks/useDebounced';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { formatNumber } from '@/lib/format';
import { toast } from '@/stores/toast';
import { useSaveSegment, useSegment, useSegmentOptions, useSegmentPreview, useSegments } from './api';
import { countConditions, defaultCondition, emptyGroup, prune } from './ast';
import { BuilderContext, type BuilderContextValue } from './builder/context';
import { AddConditionMenu, GroupEditor, conditionTypesFor } from './builder/GroupEditor';
import { PreviewPanel } from './PreviewPanel';
import type { Condition, Group, SegmentDetail } from './types';
import { useBusiness } from '@/features/contacts/business';
import { useAutoCrumbs } from '@/components/ui/page-header';

interface Draft {
    name: string;
    description: string;
    folder: string;
    definition: Group;
}

/** Estado que deja «Duplicar» en la navegación. */
export interface DuplicateState {
    duplicate?: { name: string; description: string | null; folder: string | null; definition: Group };
}

export function SegmentBuilderPage() {
    const { segmentId } = useParams();
    const { workspace } = useWorkspace();
    const segment = useSegment(workspace.id, segmentId);
    const options = useSegmentOptions(workspace.id);

    if ((segmentId !== undefined && segment.isPending) || options.isPending) {
        return (
            <div className="igr:grid igr:gap-4 igr:lg:grid-cols-[minmax(0,1fr)_380px]">
                <Skeleton className="igr:h-96" />
                <Skeleton className="igr:h-72" />
            </div>
        );
    }
    if (segment.isError || options.isError) {
        return <ErrorState error={segment.error ?? options.error} onRetry={() => void (segment.isError ? segment.refetch() : options.refetch())} />;
    }
    return <Builder key={segmentId ?? 'new'} segment={segmentId === undefined ? null : (segment.data ?? null)} options={options.data} />;
}

function Builder({ segment, options }: { segment: SegmentDetail | null; options: BuilderContextValue['options'] }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const navigate = useNavigate();
    const location = useLocation();
    const canManage = useCan('manageMarketing');
    const segments = useSegments(workspace.id);
    const save = useSaveSegment(workspace.id, segment?.id);
    const duplicate = (location.state as DuplicateState | null)?.duplicate;
    const editable = canManage && (segment === null || (segment.kind === 'dynamic' && !segment.is_system));

    const [draft, setDraft] = useState<Draft>(() => {
        const source = segment ?? duplicate;
        return {
            name: segment ? segment.name : duplicate ? t('segments.form.copyOf', { name: duplicate.name }) : '',
            description: source?.description ?? '',
            folder: source?.folder ?? '',
            definition: source?.definition ?? { ...emptyGroup(), conditions: [] },
        };
    });
    const [errors, setErrors] = useState<Record<string, string>>({});

    const pruned = useMemo(() => prune(draft.definition), [draft.definition]);
    const debounced = useDebounced(pruned, 600);
    const pending = JSON.stringify(debounced) !== JSON.stringify(pruned);
    const preview = useSegmentPreview(workspace.id, countConditions(debounced) > 0 ? debounced : null);
    const folders = useMemo(() => [...new Set((segments.data ?? []).map((s) => s.folder).filter((f): f is string => f !== null))], [segments.data]);

    const submit = () => {
        if (!editable || save.isPending) {
            return;
        }
        setErrors({});
        save.mutate(
            {
                name: draft.name.trim(),
                description: draft.description.trim() === '' ? null : draft.description.trim(),
                folder: draft.folder.trim() === '' ? null : draft.folder.trim(),
                definition: draft.definition,
            },
            {
                onSuccess: (saved) => {
                    toast.success(segment ? t('segments.form.saved') : t('segments.form.created'));
                    void navigate(`/w/${workspace.id}/segmentos/${saved.id}`);
                },
                onError: (error) => {
                    if (isApiError(error, 'validation')) {
                        setErrors(error.fieldErrors);
                        toast.error(t('segments.form.invalid'));
                    } else {
                        toast.error(t('segments.form.failed'), isApiError(error) ? error.message : undefined);
                    }
                },
            },
        );
    };

    // ⌘S / Ctrl+S guarda (con la versión más reciente del borrador).
    const submitRef = useRef(submit);
    useEffect(() => {
        submitRef.current = submit;
    });
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 's') {
                e.preventDefault();
                submitRef.current();
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, []);

    const business = useBusiness();
    const ctx: BuilderContextValue = {
        ws: workspace.id,
        options,
        readOnly: !editable,
        errors,
        selfId: segment?.id,
        currency: workspace.currency,
        locale: workspace.locale,
        types: conditionTypesFor(business.store, business.services),
    };
    const setDefinition = (definition: Group) => setDraft((d) => ({ ...d, definition }));
    const back = segment ? `/w/${workspace.id}/segmentos/${segment.id}` : `/w/${workspace.id}/segmentos`;
    const crumbs = useAutoCrumbs();

    return (
        <div>
            <PageHeader
                breadcrumb={segment ? [...crumbs, { label: segment.name, to: back }] : crumbs}
                title={segment ? (editable ? t('segments.form.editTitle') : segment.name) : t('segments.form.newTitle')}
                description={editable ? t('segments.form.help') : t('segments.form.readOnly')}
                actions={
                    editable ? (
                        <Button onClick={submit} loading={save.isPending}>
                            <Save />
                            {segment ? t('segments.form.saveShort') : t('segments.form.create')}
                            <Kbd className="igr:ml-1 igr:border-primary-foreground/30 igr:bg-primary-foreground/10 igr:text-primary-foreground igr:max-sm:hidden">⌘S</Kbd>
                        </Button>
                    ) : null
                }
            />

            <div className="igr:grid igr:items-start igr:gap-4 igr:lg:grid-cols-[minmax(0,1fr)_380px]">
                <div className="igr:space-y-4">
                    <Card>
                        <CardContent className="igr:grid igr:gap-3 igr:pt-4 igr:md:grid-cols-2">
                            <div className="igr:space-y-1">
                                <Label htmlFor="segment-name">{t('segments.form.name')}</Label>
                                <Input
                                    id="segment-name"
                                    value={draft.name}
                                    disabled={!editable}
                                    maxLength={160}
                                    placeholder={t(business.servicesOnly ? 'segments.form.namePlaceholderServices' : 'segments.form.namePlaceholder')}
                                    aria-invalid={errors.name ? true : undefined}
                                    onChange={(e) => setDraft({ ...draft, name: e.target.value })}
                                />
                                <FieldError message={errors.name} />
                            </div>
                            <div className="igr:space-y-1">
                                <Label htmlFor="segment-folder">{t('segments.form.folder')}</Label>
                                <Input
                                    id="segment-folder"
                                    list="segment-folders"
                                    value={draft.folder}
                                    disabled={!editable}
                                    maxLength={80}
                                    placeholder={t('segments.form.folderPlaceholder')}
                                    onChange={(e) => setDraft({ ...draft, folder: e.target.value })}
                                />
                                <datalist id="segment-folders">
                                    {folders.map((f) => (
                                        <option key={f} value={f} />
                                    ))}
                                </datalist>
                            </div>
                            <div className="igr:space-y-1 igr:md:col-span-2">
                                <Label htmlFor="segment-description">{t('segments.form.description')}</Label>
                                <Textarea
                                    id="segment-description"
                                    className="igr:min-h-14"
                                    value={draft.description}
                                    disabled={!editable}
                                    maxLength={1000}
                                    onChange={(e) => setDraft({ ...draft, description: e.target.value })}
                                />
                            </div>
                        </CardContent>
                    </Card>

                    <BuilderContext.Provider value={ctx}>
                        <Card>
                            <CardContent className="igr:pt-4">
                                {draft.definition.conditions.length === 0 ? (
                                    <StartHere onPick={(c) => setDefinition({ ...draft.definition, conditions: [c] })} editable={editable} />
                                ) : (
                                    <GroupEditor group={draft.definition} path={[]} root={draft.definition} setRoot={setDefinition} />
                                )}
                                {errors.definition ? <p className="igr:mt-2 igr:text-xs igr:text-destructive">{errors.definition}</p> : null}
                            </CardContent>
                        </Card>
                    </BuilderContext.Provider>
                </div>

                <div className="igr:lg:sticky igr:lg:top-4" id="segment-preview">
                    <PreviewPanel data={preview.data} error={preview.error} loading={preview.isFetching} stale={pending} empty={countConditions(pruned) === 0} commerce={business.store} />
                </div>
            </div>

            {countConditions(pruned) > 0 ? (
                <div className="igr:sticky igr:bottom-3 igr:z-10 igr:mt-4 igr:flex igr:items-center igr:gap-3 igr:rounded-xl igr:border igr:bg-popover igr:px-3 igr:py-2 igr:shadow-lg igr:lg:hidden">
                    <a href="#segment-preview" className="igr:min-w-0 igr:flex-1 igr:outline-none igr:focus-visible:underline">
                        <span className="igr:block igr:text-section igr:leading-6 igr:font-semibold igr:tabular-nums" aria-live="polite">
                            {preview.data ? formatNumber(preview.data.count, workspace.locale) : '…'}
                        </span>
                        <span className="igr:block igr:text-caption igr:text-muted-foreground">{t('segments.builder.liveCount')}</span>
                    </a>
                    {editable ? (
                        <Button onClick={submit} loading={save.isPending}>
                            <Save />
                            {segment ? t('segments.form.saveShort') : t('segments.form.create')}
                        </Button>
                    ) : null}
                </div>
            ) : null}
        </div>
    );
}

/** Las primeras condiciones que se ofrecen, según el negocio: compras y carritos en una tienda; leads y citas sin ella. */
export function quickStarts(store: boolean, services: boolean): { key: string; make: () => Condition }[] {
    const city = { key: 'city', make: () => defaultCondition('attribute') };
    const whatsapp = { key: 'whatsapp', make: (): Condition => ({ type: 'consent', channel: 'whatsapp', purpose: 'marketing', status: 'granted' }) };
    const servicesQuick = [
        { key: 'leadHot', make: (): Condition => ({ type: 'lead', field: 'temperature', operator: 'in', value: ['hot'] }) },
        { key: 'leadOpen', make: () => defaultCondition('lead') },
        { key: 'upcoming', make: () => defaultCondition('service') },
    ];
    if (services && !store) {
        return [...servicesQuick, city, whatsapp];
    }
    return [
        city,
        { key: 'bought', make: () => defaultCondition('purchase') },
        { key: 'viewed', make: () => defaultCondition('event') },
        { key: 'cart', make: () => defaultCondition('cart') },
        { key: 'rfm', make: () => defaultCondition('prediction') },
        ...(services ? servicesQuick.slice(0, 1) : []),
    ];
}

function StartHere({ onPick, editable }: { onPick: (c: ReturnType<typeof defaultCondition>) => void; editable: boolean }) {
    const { t } = useTranslation();
    const business = useBusiness();
    const quick = quickStarts(business.store, business.services);
    return (
        <div className="igr:py-6 igr:text-center">
            <p className="igr:text-sm igr:font-medium">{t('segments.form.startTitle')}</p>
            <p className="igr:mx-auto igr:mt-1 igr:max-w-md igr:text-[13px] igr:text-muted-foreground">{t('segments.form.startHelp')}</p>
            {editable ? (
                <div className="igr:mt-4 igr:flex igr:flex-wrap igr:justify-center igr:gap-1.5">
                    {quick.map((q) => (
                        <Button key={q.key} type="button" variant="outline" size="sm" onClick={() => onPick(q.make())}>
                            {t(`segments.form.quick.${q.key}`)}
                        </Button>
                    ))}
                    <AddConditionMenu variant="default" onAdd={(type) => onPick(defaultCondition(type))} />
                </div>
            ) : null}
        </div>
    );
}
