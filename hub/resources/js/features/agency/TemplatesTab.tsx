import { Copy, Library, Plus, Send, Trash2, TriangleAlert } from 'lucide-react';
import { type ReactNode, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input, Select, Textarea } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { EmptyState, ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { toast } from '@/stores/toast';
import {
    type AgencyTemplate,
    type ApplyResult,
    PORTABLE_KINDS,
    type PortableKind,
    useAgencyOverview,
    useAgencyTemplates,
    useApplyTemplate,
    useClientItems,
    useCopyBetweenClients,
    useDeleteTemplate,
    useSaveTemplate,
} from './api';

/* «Plantillas de la agencia» (M22): biblioteca portable entre clientes. */
export function TemplatesTab() {
    const { t, i18n } = useTranslation();
    const { workspace } = useWorkspace();
    const templates = useAgencyTemplates(workspace.id);
    const remove = useDeleteTemplate(workspace.id);
    const [kind, setKind] = useState<PortableKind | 'all'>('all');
    const [dialog, setDialog] = useState<'save' | 'copy' | null>(null);
    const [applying, setApplying] = useState<AgencyTemplate | null>(null);

    if (templates.isError) {
        return <ErrorState error={templates.error} onRetry={() => void templates.refetch()} />;
    }
    const list = (templates.data?.templates ?? []).filter((x) => kind === 'all' || x.kind === kind);

    return (
        <div className="igr:space-y-4">
            <div className="igr:flex igr:flex-wrap igr:items-center igr:justify-between igr:gap-2">
                <p className="igr:max-w-2xl igr:text-[13px] igr:text-muted-foreground">{t('agency.templates.intro')}</p>
                <div className="igr:flex igr:gap-2">
                    <Button variant="outline" onClick={() => setDialog('copy')}>
                        <Copy aria-hidden />
                        {t('agency.templates.copy')}
                    </Button>
                    <Button onClick={() => setDialog('save')}>
                        <Plus aria-hidden />
                        {t('agency.templates.save')}
                    </Button>
                </div>
            </div>
            <Select value={kind} onChange={(e) => setKind(e.target.value as PortableKind | 'all')} className="igr:w-56" aria-label={t('agency.templates.kind_filter')}>
                <option value="all">{t('agency.templates.all_kinds')}</option>
                {PORTABLE_KINDS.map((k) => (
                    <option key={k} value={k}>
                        {t(`agency.templates.kinds.${k}`)}
                    </option>
                ))}
            </Select>
            {templates.isPending ? (
                <Skeleton className="igr:h-40 igr:w-full" />
            ) : list.length === 0 ? (
                <EmptyState icon={Library} title={t('agency.templates.empty')} />
            ) : (
                <div className="igr:grid igr:gap-3 igr:md:grid-cols-2 igr:xl:grid-cols-3">
                    {list.map((tpl) => (
                        <div key={tpl.id} className="igr:flex igr:flex-col igr:rounded-lg igr:border igr:bg-card igr:p-4">
                            <div className="igr:flex igr:items-start igr:justify-between igr:gap-2">
                                <div className="igr:min-w-0">
                                    <div className="igr:truncate igr:font-medium">{tpl.name}</div>
                                    <div className="igr:text-xs igr:text-muted-foreground">
                                        {tpl.source_workspace ? t('agency.templates.from', { client: tpl.source_workspace }) : null}
                                        {tpl.updated_at ? ` · ${formatDate(tpl.updated_at, i18n.language)}` : null}
                                    </div>
                                </div>
                                <Badge variant="secondary">{t(`agency.templates.kinds.${tpl.kind}`)}</Badge>
                            </div>
                            {tpl.description ? <p className="igr:mt-2 igr:text-[13px] igr:text-muted-foreground">{tpl.description}</p> : null}
                            <p className="igr:mt-2 igr:text-xs igr:text-muted-foreground">{t('agency.templates.includes', tpl.includes)}</p>
                            {tpl.warnings.length > 0 ? (
                                <details className="igr:mt-2 igr:text-xs">
                                    <summary className="igr:inline-flex igr:cursor-pointer igr:items-center igr:gap-1 igr:text-warning">
                                        <TriangleAlert className="igr:size-3" aria-hidden />
                                        {t('agency.templates.warnings', { count: tpl.warnings.length })}
                                    </summary>
                                    <ul className="igr:mt-1 igr:list-disc igr:space-y-0.5 igr:pl-4 igr:text-muted-foreground">
                                        {tpl.warnings.map((w) => (
                                            <li key={w}>{w}</li>
                                        ))}
                                    </ul>
                                </details>
                            ) : null}
                            <div className="igr:mt-auto igr:flex igr:justify-end igr:gap-2 igr:pt-3">
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    aria-label={t('agency.templates.delete')}
                                    onClick={() => {
                                        if (window.confirm(t('agency.templates.delete_confirm', { name: tpl.name }))) {
                                            remove.mutate(tpl.id, { onError: (e) => toast.error(t('errors.title'), isApiError(e) ? e.message : undefined) });
                                        }
                                    }}
                                >
                                    <Trash2 aria-hidden />
                                </Button>
                                <Button size="sm" onClick={() => setApplying(tpl)}>
                                    <Send aria-hidden />
                                    {t('agency.templates.apply')}
                                </Button>
                            </div>
                        </div>
                    ))}
                </div>
            )}
            <SaveDialog mode={dialog} onClose={() => setDialog(null)} />
            <ApplyDialog template={applying} onClose={() => setApplying(null)} />
        </div>
    );
}

function useClients() {
    const { workspace } = useWorkspace();
    const overview = useAgencyOverview(workspace.id);
    return overview.data?.clients ?? [];
}

/** Guardar en la biblioteca o copiar directo: origen → tipo → elemento (→ destinos). */
function SaveDialog({ mode, onClose }: { mode: 'save' | 'copy' | null; onClose: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const clients = useClients();
    const [source, setSource] = useState('');
    const [kind, setKind] = useState<PortableKind>('flow');
    const [item, setItem] = useState('');
    const [name, setName] = useState('');
    const [description, setDescription] = useState('');
    const [targets, setTargets] = useState<string[]>([]);
    const [results, setResults] = useState<ApplyResult[] | null>(null);
    const items = useClientItems(workspace.id, source, kind);
    const save = useSaveTemplate(workspace.id);
    const copy = useCopyBetweenClients(workspace.id);
    const sourceAccount = clients.find((c) => c.id === source)?.account?.id;
    const reset = () => {
        setSource('');
        setItem('');
        setName('');
        setDescription('');
        setTargets([]);
        setResults(null);
        onClose();
    };
    const onError = (e: unknown) => toast.error(t('errors.title'), isApiError(e) ? e.message : undefined);

    return (
        <Dialog open={mode !== null} onOpenChange={(open) => (open ? null : reset())}>
            <DialogContent className="igr:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{mode === 'copy' ? t('agency.templates.copy_title') : t('agency.templates.save')}</DialogTitle>
                    <DialogDescription>{t('agency.templates.intro')}</DialogDescription>
                </DialogHeader>
                {results ? (
                    <ResultsList results={results} />
                ) : (
                    <div className="igr:space-y-3">
                        <Field label={t('agency.templates.source')}>
                            <Select value={source} onChange={(e) => (setSource(e.target.value), setItem(''), setTargets([]))}>
                                <option value="">{t('agency.templates.pick_item')}</option>
                                {clients.map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.name}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <Field label={t('agency.templates.kind_filter')}>
                            <Select value={kind} onChange={(e) => (setKind(e.target.value as PortableKind), setItem(''))}>
                                {PORTABLE_KINDS.map((k) => (
                                    <option key={k} value={k}>
                                        {t(`agency.templates.kinds.${k}`)}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <Field label={t('agency.templates.item')}>
                            <Select value={item} onChange={(e) => setItem(e.target.value)} disabled={source === ''}>
                                <option value="">{t('agency.templates.pick_item')}</option>
                                {(items.data?.items ?? []).map((x) => (
                                    <option key={x.id} value={x.id}>
                                        {x.name}
                                        {x.detail ? ` · ${x.detail}` : ''}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        {mode === 'save' ? (
                            <>
                                <Field label={t('agency.templates.name')}>
                                    <Input value={name} onChange={(e) => setName(e.target.value)} maxLength={160} />
                                </Field>
                                <Field label={t('agency.templates.description')}>
                                    <Textarea value={description} onChange={(e) => setDescription(e.target.value)} rows={2} maxLength={1000} />
                                </Field>
                            </>
                        ) : (
                            <TargetPicker
                                clients={clients.filter((c) => c.id !== source && c.account?.id === sourceAccount)}
                                value={targets}
                                onChange={setTargets}
                            />
                        )}
                    </div>
                )}
                <DialogFooter>
                    {results ? (
                        <Button onClick={reset}>{t('common.close')}</Button>
                    ) : mode === 'save' ? (
                        <Button
                            disabled={source === '' || item === ''}
                            loading={save.isPending}
                            onClick={() =>
                                save.mutate(
                                    { source_workspace: source, kind, id: item, name: name || undefined, description: description || undefined },
                                    { onSuccess: () => (toast.success(t('agency.templates.saved')), reset()), onError },
                                )
                            }
                        >
                            {t('agency.templates.save')}
                        </Button>
                    ) : (
                        <Button
                            disabled={source === '' || item === '' || targets.length === 0}
                            loading={copy.isPending}
                            onClick={() => copy.mutate({ source_workspace: source, kind, id: item, workspaces: targets }, { onSuccess: (r) => setResults(r.results), onError })}
                        >
                            {t('agency.templates.copy')}
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function ApplyDialog({ template, onClose }: { template: AgencyTemplate | null; onClose: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const clients = useClients().filter((c) => c.account?.id === template?.account_id);
    const apply = useApplyTemplate(workspace.id);
    const [targets, setTargets] = useState<string[]>([]);
    const [results, setResults] = useState<ApplyResult[] | null>(null);
    const close = () => {
        setTargets([]);
        setResults(null);
        onClose();
    };
    return (
        <Dialog open={template !== null} onOpenChange={(open) => (open ? null : close())}>
            <DialogContent className="igr:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{t('agency.templates.apply_title', { name: template?.name ?? '' })}</DialogTitle>
                    <DialogDescription>{t('agency.templates.intro')}</DialogDescription>
                </DialogHeader>
                {results ? <ResultsList results={results} /> : <TargetPicker clients={clients} value={targets} onChange={setTargets} />}
                <DialogFooter>
                    {results ? (
                        <Button onClick={close}>{t('common.close')}</Button>
                    ) : (
                        <Button
                            disabled={targets.length === 0}
                            loading={apply.isPending}
                            onClick={() =>
                                template &&
                                apply.mutate(
                                    { template: template.id, workspaces: targets },
                                    {
                                        onSuccess: (r) => {
                                            setResults(r.results);
                                            toast.success(t('agency.templates.applied', { count: r.results.filter((x) => x.ok).length }));
                                        },
                                        onError: (e) => toast.error(t('errors.title'), isApiError(e) ? e.message : undefined),
                                    },
                                )
                            }
                        >
                            {t('agency.templates.apply')}
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function TargetPicker({ clients, value, onChange }: { clients: { id: string; name: string }[]; value: string[]; onChange: (v: string[]) => void }) {
    const { t } = useTranslation();
    return (
        <fieldset className="igr:space-y-1.5">
            <legend className="igr:mb-1 igr:text-[13px] igr:font-medium">{t('agency.templates.targets')}</legend>
            <div className="igr:max-h-56 igr:space-y-1 igr:overflow-y-auto igr:rounded-md igr:border igr:p-2">
                {clients.map((c) => (
                    <label key={c.id} className="igr:flex igr:items-center igr:gap-2 igr:text-[13px]">
                        <input
                            type="checkbox"
                            checked={value.includes(c.id)}
                            onChange={(e) => onChange(e.target.checked ? [...value, c.id] : value.filter((x) => x !== c.id))}
                        />
                        {c.name}
                    </label>
                ))}
            </div>
        </fieldset>
    );
}

function ResultsList({ results }: { results: ApplyResult[] }) {
    const { t } = useTranslation();
    return (
        <ul className="igr:space-y-2 igr:text-[13px]">
            {results.map((r) => (
                <li key={r.workspace_id} className="igr:rounded-md igr:border igr:p-2">
                    <div className="igr:font-medium">{r.workspace}</div>
                    <div className={r.ok ? 'igr:text-muted-foreground' : 'igr:text-destructive'}>
                        {r.ok ? t('agency.templates.result_ok', { name: r.name ?? '' }) : t('agency.templates.result_error', { error: r.error ?? '' })}
                    </div>
                    {(r.warnings ?? []).length > 0 ? (
                        <ul className="igr:mt-1 igr:list-disc igr:pl-4 igr:text-xs igr:text-muted-foreground">
                            {(r.warnings ?? []).map((w) => (
                                <li key={w}>{w}</li>
                            ))}
                        </ul>
                    ) : null}
                </li>
            ))}
        </ul>
    );
}

function Field({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="igr:space-y-1">
            <Label>{label}</Label>
            {children}
        </div>
    );
}
