import { FileUp, Library, Sparkles } from 'lucide-react';
import { type ChangeEvent, type SyntheticEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input, Textarea } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ErrorState, FieldError } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { formatNumber } from '@/lib/format';
import { toast } from '@/stores/toast';
import { useCreateStatic, useFromTemplate, useLibrary, useSegmentAction } from './api';
import { CatalogPicker } from './builder/inputs';
import type { LibraryResponse, SegmentSummary } from './types';

/** Saca correos y teléfonos de un texto pegado o de un CSV (cualquier columna). */
export function extractContacts(text: string): string[] {
    const found = new Set<string>();
    for (const cell of text.split(/[\n\r,;\t]+/)) {
        const value = cell.trim().replace(/^"|"$/g, '').trim();
        if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
            found.add(value.toLowerCase());
        } else if (/^\+?[\d\s().-]{7,20}$/.test(value) && value.replace(/\D/g, '').length >= 7) {
            found.add(value);
        }
    }
    return [...found];
}

export function LibraryDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const library = useLibrary(workspace.id);
    const canManage = useCan('manageMarketing');
    const navigate = useNavigate();
    const create = useFromTemplate(workspace.id);
    const [template, setTemplate] = useState<LibraryResponse['templates'][number] | null>(null);

    const install = (key: string) =>
        create.mutate(
            { key, params: {} },
            {
                onSuccess: (segment) => {
                    onClose();
                    void navigate(`/w/${workspace.id}/segmentos/${segment.id}`);
                },
                onError: (error) => toast.error(t('segments.library.failed'), isApiError(error) ? error.message : undefined),
            },
        );

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent className="igr:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{t('segments.library.title')}</DialogTitle>
                    <DialogDescription>{t('segments.library.description')}</DialogDescription>
                </DialogHeader>
                {template ? (
                    <TemplateForm template={template} onBack={() => setTemplate(null)} onDone={onClose} />
                ) : library.isPending ? (
                    <div className="igr:space-y-2">
                        <Skeleton className="igr:h-12" />
                        <Skeleton className="igr:h-12" />
                        <Skeleton className="igr:h-12" />
                    </div>
                ) : library.isError ? (
                    <ErrorState error={library.error} onRetry={() => void library.refetch()} />
                ) : (
                    <div className="igr:max-h-[60vh] igr:space-y-4 igr:overflow-y-auto igr:pr-1">
                        <section>
                            <h3 className="igr:mb-2 igr:flex igr:items-center igr:gap-1.5 igr:text-xs igr:font-medium igr:uppercase igr:tracking-wide igr:text-muted-foreground">
                                <Sparkles className="igr:size-3.5" />
                                {t('segments.library.templates')}
                            </h3>
                            <ul className="igr:space-y-1.5">
                                {library.data.templates.map((tpl) => (
                                    <li key={tpl.key} className="igr:flex igr:items-center igr:gap-3 igr:rounded-md igr:border igr:p-2.5">
                                        <div className="igr:min-w-0 igr:flex-1">
                                            <p className="igr:text-[13px] igr:font-medium">{tpl.name}</p>
                                            <p className="igr:text-xs igr:text-muted-foreground">{tpl.description}</p>
                                        </div>
                                        {canManage ? (
                                            <Button size="sm" variant="outline" onClick={() => setTemplate(tpl)}>
                                                {t('segments.library.useTemplate')}
                                            </Button>
                                        ) : null}
                                    </li>
                                ))}
                            </ul>
                        </section>
                        <section>
                            <h3 className="igr:mb-2 igr:flex igr:items-center igr:gap-1.5 igr:text-xs igr:font-medium igr:uppercase igr:tracking-wide igr:text-muted-foreground">
                                <Library className="igr:size-3.5" />
                                {t('segments.library.ready')}
                            </h3>
                            <ul className="igr:space-y-1.5">
                                {library.data.system.map((s) => (
                                    <li key={s.key} className="igr:flex igr:items-center igr:gap-3 igr:rounded-md igr:border igr:p-2.5">
                                        <div className="igr:min-w-0 igr:flex-1">
                                            <p className="igr:flex igr:items-center igr:gap-2 igr:text-[13px] igr:font-medium">
                                                {s.name}
                                                <Badge variant="outline">{s.folder}</Badge>
                                            </p>
                                            <p className="igr:text-xs igr:text-muted-foreground">{s.description}</p>
                                        </div>
                                        {s.segment_id ? (
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() => {
                                                    onClose();
                                                    void navigate(`/w/${workspace.id}/segmentos/${s.segment_id ?? ''}`);
                                                }}
                                            >
                                                {t('segments.library.open')}
                                            </Button>
                                        ) : canManage ? (
                                            <Button size="sm" variant="outline" loading={create.isPending && create.variables.key === s.key} onClick={() => install(s.key)}>
                                                {t('segments.library.create')}
                                            </Button>
                                        ) : null}
                                    </li>
                                ))}
                            </ul>
                        </section>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}

function TemplateForm({ template, onBack, onDone }: { template: LibraryResponse['templates'][number]; onBack: () => void; onDone: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const navigate = useNavigate();
    const create = useFromTemplate(workspace.id);
    const [name, setName] = useState('');
    const [params, setParams] = useState<Record<string, string[]>>({});
    const ready = template.params.every((p) => (params[p.name] ?? []).length > 0);

    const submit = (e: SyntheticEvent) => {
        e.preventDefault();
        create.mutate(
            { key: template.key, name: name.trim() === '' ? undefined : name.trim(), params },
            {
                onSuccess: (segment) => {
                    toast.success(t('segments.library.created'));
                    onDone();
                    void navigate(`/w/${workspace.id}/segmentos/${segment.id}`);
                },
                onError: (error) => toast.error(t('segments.library.failed'), isApiError(error) ? error.message : undefined),
            },
        );
    };

    return (
        <form onSubmit={submit} className="igr:space-y-3">
            <p className="igr:text-[13px] igr:font-medium">{template.name}</p>
            {template.params.map((p) => (
                <div key={p.name} className="igr:space-y-1">
                    <Label>{p.label}</Label>
                    <CatalogPicker kind={p.type === 'product' ? 'products' : 'categories'} ids={params[p.name] ?? []} onChange={(ids) => setParams({ ...params, [p.name]: ids })} />
                </div>
            ))}
            <div className="igr:space-y-1">
                <Label htmlFor="tpl-name">{t('segments.library.nameOptional')}</Label>
                <Input id="tpl-name" value={name} onChange={(e) => setName(e.target.value)} placeholder={template.name} maxLength={160} />
            </div>
            <DialogFooter>
                <Button type="button" variant="ghost" onClick={onBack}>
                    {t('common.back')}
                </Button>
                <Button type="submit" disabled={!ready} loading={create.isPending}>
                    {t('segments.library.createFromTemplate')}
                </Button>
            </DialogFooter>
        </form>
    );
}

export function StaticSegmentDialog({ open, onClose, folders }: { open: boolean; onClose: () => void; folders: string[] }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const navigate = useNavigate();
    const create = useCreateStatic(workspace.id);
    const [name, setName] = useState('');
    const [folder, setFolder] = useState('');
    const [text, setText] = useState('');
    const values = extractContacts(text);
    const errors = isApiError(create.error) ? create.error.fieldErrors : {};

    const onFile = async (e: ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (file) {
            setText(await file.text());
        }
    };
    const submit = (e: SyntheticEvent) => {
        e.preventDefault();
        create.mutate(
            { name: name.trim(), folder: folder.trim() === '' ? null : folder.trim(), values },
            {
                onSuccess: (result) => {
                    toast.success(t('segments.static.created'), t('segments.static.matched', { matched: formatNumber(result.matched, workspace.locale), unmatched: formatNumber(result.unmatched, workspace.locale) }));
                    onClose();
                    void navigate(`/w/${workspace.id}/segmentos/${result.segment.id}`);
                },
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('segments.static.title')}</DialogTitle>
                    <DialogDescription>{t('segments.static.description')}</DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="igr:space-y-3">
                    <div className="igr:space-y-1">
                        <Label htmlFor="static-name">{t('segments.form.name')}</Label>
                        <Input id="static-name" value={name} onChange={(e) => setName(e.target.value)} maxLength={160} required minLength={2} aria-invalid={errors.name ? true : undefined} />
                        <FieldError message={errors.name} />
                    </div>
                    <div className="igr:space-y-1">
                        <Label htmlFor="static-folder">{t('segments.form.folder')}</Label>
                        <Input id="static-folder" list="static-folders" value={folder} onChange={(e) => setFolder(e.target.value)} maxLength={80} />
                        <datalist id="static-folders">
                            {folders.map((f) => (
                                <option key={f} value={f} />
                            ))}
                        </datalist>
                    </div>
                    <div className="igr:space-y-1">
                        <Label htmlFor="static-values">{t('segments.static.values')}</Label>
                        <Textarea id="static-values" className="igr:min-h-32 igr:font-mono igr:text-xs" value={text} onChange={(e) => setText(e.target.value)} placeholder={t('segments.static.placeholder')} />
                        <div className="igr:flex igr:items-center igr:justify-between igr:text-xs igr:text-muted-foreground">
                            <span>{t('segments.static.detected', { count: values.length, formatted: formatNumber(values.length, workspace.locale) })}</span>
                            <label className="igr:inline-flex igr:cursor-pointer igr:items-center igr:gap-1 igr:hover:text-foreground">
                                <FileUp className="igr:size-3.5" />
                                {t('segments.static.file')}
                                <input type="file" accept=".csv,.txt,text/csv,text/plain" className="igr:sr-only" onChange={(e) => void onFile(e)} />
                            </label>
                        </div>
                        <FieldError message={errors.values} />
                        <p className="igr:text-xs igr:text-muted-foreground">{t('segments.static.privacy')}</p>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>
                            {t('common.cancel')}
                        </Button>
                        <Button type="submit" disabled={values.length === 0 || name.trim().length < 2} loading={create.isPending}>
                            {t('segments.static.submit')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export function SnapshotDialog({ segment, open, onClose }: { segment: SegmentSummary; open: boolean; onClose: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const navigate = useNavigate();
    const { snapshot } = useSegmentAction(workspace.id, segment.id);
    const [name, setName] = useState(() => `${segment.name} · ${new Date().toLocaleDateString(workspace.locale)}`);

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('segments.snapshot.title')}</DialogTitle>
                    <DialogDescription>{t('segments.snapshot.description')}</DialogDescription>
                </DialogHeader>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        snapshot.mutate(name.trim(), {
                            onSuccess: (created) => {
                                toast.success(t('segments.snapshot.created'));
                                onClose();
                                void navigate(`/w/${workspace.id}/segmentos/${created.id}`);
                            },
                            onError: (error) => toast.error(t('segments.snapshot.failed'), isApiError(error) ? error.message : undefined),
                        });
                    }}
                    className="igr:space-y-3"
                >
                    <div className="igr:space-y-1">
                        <Label htmlFor="snapshot-name">{t('segments.form.name')}</Label>
                        <Input id="snapshot-name" value={name} onChange={(e) => setName(e.target.value)} maxLength={160} required minLength={2} />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>
                            {t('common.cancel')}
                        </Button>
                        <Button type="submit" loading={snapshot.isPending}>
                            {t('segments.snapshot.submit')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export function DeleteSegmentDialog({ segment, open, onClose }: { segment: SegmentSummary; open: boolean; onClose: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const navigate = useNavigate();
    const { remove } = useSegmentAction(workspace.id, segment.id);

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('segments.delete.title', { name: segment.name })}</DialogTitle>
                    <DialogDescription>{t('segments.delete.description')}</DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="ghost" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    <Button
                        variant="destructive"
                        loading={remove.isPending}
                        onClick={() =>
                            remove.mutate(undefined, {
                                onSuccess: () => {
                                    toast.success(t('segments.delete.done'));
                                    void navigate(`/w/${workspace.id}/segmentos`);
                                },
                                onError: (error) => toast.error(t('segments.delete.failed'), isApiError(error) ? error.message : undefined),
                            })
                        }
                    >
                        {t('segments.delete.confirm')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
