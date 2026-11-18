import { Archive, ArchiveRestore, Building2, Copy, Eye, FileText, Library, Mail, MessageCircle, MessageSquareText, MoreHorizontal, Plus, Search } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useSearchParams } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input, Select } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { EmptyState, ErrorState, FieldError, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { formatRelative } from '@/lib/format';
import { toast } from '@/stores/toast';
import { errorMessage } from '@/features/contacts/errors';
import {
    type BusinessProfile,
    CHANNELS,
    type LibraryEntry,
    type TemplateChannel,
    type TemplateStatus,
    type TemplateSummary,
    useArchiveTemplate,
    useBusinessProfile,
    useCreateTemplate,
    useDuplicateTemplate,
    useInstallFromLibrary,
    useLibrary,
    useLibraryPreview,
    useRestoreTemplate,
    useTemplates,
    useUpdateBusinessProfile,
} from './api';
import { BLANK_EMAIL_MJML } from './content';
import { PreviewView } from './editor/PreviewPanel';

const CHANNEL_ICON = { email: Mail, sms: MessageSquareText, whatsapp: MessageCircle } as const;

/** Plantillas (modulos.md M08): las del espacio de trabajo y la biblioteca en español por industria. */
export function TemplatesPage() {
    const { t } = useTranslation();
    const [params, setParams] = useSearchParams();
    const tab = params.get('tab') === 'biblioteca' ? 'library' : 'mine';
    const canEdit = useCan('manageMarketing');
    const canManage = useCan('manageWorkspace');
    const [creating, setCreating] = useState<TemplateChannel | null>(null);
    const [profileOpen, setProfileOpen] = useState(false);

    return (
        <>
            <PageHeader
                title={t('templates.title')}
                description={t('templates.description')}
                actions={
                    <div className="igr:flex igr:flex-wrap igr:gap-2">
                        <Button variant="outline" onClick={() => setProfileOpen(true)}>
                            <Building2 className="igr:size-4" />
                            {t('templates.business.open')}
                        </Button>
                        {canEdit && (
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button>
                                        <Plus className="igr:size-4" />
                                        {t('templates.new')}
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    {CHANNELS.map((c) => {
                                        const Icon = CHANNEL_ICON[c];
                                        return (
                                            <DropdownMenuItem key={c} onSelect={() => setCreating(c)}>
                                                <Icon className="igr:size-4" />
                                                {t(`templates.channels.${c}`)}
                                            </DropdownMenuItem>
                                        );
                                    })}
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem onSelect={() => setParams({ tab: 'biblioteca' })}>
                                        <Library className="igr:size-4" />
                                        {t('templates.library.fromLibrary')}
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        )}
                    </div>
                }
            />
            <Tabs value={tab} onValueChange={(v) => setParams(v === 'library' ? { tab: 'biblioteca' } : {})}>
                <TabsList>
                    <TabsTrigger value="mine">{t('templates.tabs.mine')}</TabsTrigger>
                    <TabsTrigger value="library">{t('templates.tabs.library')}</TabsTrigger>
                </TabsList>
                <TabsContent value="mine" className="igr:mt-4">
                    <MyTemplates onGoLibrary={() => setParams({ tab: 'biblioteca' })} />
                </TabsContent>
                <TabsContent value="library" className="igr:mt-4">
                    <LibraryTab />
                </TabsContent>
            </Tabs>
            <NewTemplateDialog channel={creating} onClose={() => setCreating(null)} />
            <BusinessProfileDialog open={profileOpen} onOpenChange={setProfileOpen} canManage={canManage} />
        </>
    );
}

function MyTemplates({ onGoLibrary }: { onGoLibrary: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const navigate = useNavigate();
    const canEdit = useCan('manageMarketing');
    const [channel, setChannel] = useState<TemplateChannel | ''>('');
    const [status, setStatus] = useState<TemplateStatus | ''>('');
    const [q, setQ] = useState('');
    const [debounced, setDebounced] = useState('');
    useEffect(() => {
        const id = setTimeout(() => setDebounced(q.trim()), 300);
        return () => clearTimeout(id);
    }, [q]);
    const list = useTemplates(workspace.id, { channel, status, q: debounced });
    const duplicate = useDuplicateTemplate(workspace.id);
    const archive = useArchiveTemplate(workspace.id);
    const restore = useRestoreTemplate(workspace.id);
    const open = (id: string) => void navigate(`/w/${workspace.id}/plantillas/${id}`);
    const filtered = channel !== '' || status !== '' || debounced !== '';

    return (
        <Card>
            <CardHeader className="igr:flex-row igr:flex-wrap igr:items-center igr:gap-2">
                <div className="igr:relative igr:w-full igr:max-w-xs">
                    <Search className="igr:pointer-events-none igr:absolute igr:top-2.5 igr:left-2.5 igr:size-3.5 igr:text-muted-foreground" />
                    <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('templates.filters.search')} aria-label={t('templates.filters.search')} className="igr:pl-8" />
                </div>
                <Select value={channel} onChange={(e) => setChannel(e.target.value as TemplateChannel | '')} aria-label={t('templates.filters.channel')} className="igr:w-auto">
                    <option value="">{t('templates.filters.allChannels')}</option>
                    {CHANNELS.map((c) => (
                        <option key={c} value={c}>
                            {t(`templates.channels.${c}`)}
                        </option>
                    ))}
                </Select>
                <Select value={status} onChange={(e) => setStatus(e.target.value as TemplateStatus | '')} aria-label={t('templates.filters.status')} className="igr:w-auto">
                    <option value="">{t('templates.filters.active')}</option>
                    <option value="draft">{t('templates.statuses.draft')}</option>
                    <option value="ready">{t('templates.statuses.ready')}</option>
                    <option value="archived">{t('templates.statuses.archived')}</option>
                </Select>
            </CardHeader>
            <CardContent className="igr:p-0">
                {list.isError ? (
                    <div className="igr:p-4">
                        <ErrorState error={list.error} onRetry={() => void list.refetch()} />
                    </div>
                ) : list.isPending ? (
                    <div className="igr:space-y-2 igr:p-4">
                        {[0, 1, 2].map((i) => (
                            <Skeleton key={i} className="igr:h-10" />
                        ))}
                    </div>
                ) : list.data.length === 0 ? (
                    <EmptyState
                        className="igr:py-12"
                        icon={FileText}
                        title={filtered ? t('templates.empty.filteredTitle') : t('templates.empty.title')}
                        description={filtered ? t('templates.empty.filteredHelp') : t('templates.empty.help')}
                        action={
                            filtered ? undefined : (
                                <Button onClick={onGoLibrary}>
                                    <Library className="igr:size-4" />
                                    {t('templates.empty.cta')}
                                </Button>
                            )
                        }
                    />
                ) : (
                    <Table>
                        <THead>
                            <TR>
                                <TH>{t('templates.columns.name')}</TH>
                                <TH>{t('templates.columns.channel')}</TH>
                                <TH>{t('templates.columns.category')}</TH>
                                <TH>{t('templates.columns.status')}</TH>
                                <TH className="igr:hidden igr:md:table-cell">{t('templates.columns.updated')}</TH>
                                <TH className="igr:w-10">
                                    <span className="igr:sr-only">{t('templates.columns.actions')}</span>
                                </TH>
                            </TR>
                        </THead>
                        <TBody>
                            {list.data.map((tpl) => (
                                <TemplateRow
                                    key={tpl.id}
                                    tpl={tpl}
                                    canEdit={canEdit}
                                    onOpen={() => open(tpl.id)}
                                    onDuplicate={() => duplicate.mutate(tpl.id, { onSuccess: (c) => (toast.success(t('templates.duplicated')), open(c.id)), onError: (e) => toast.error(errorMessage(e, t)) })}
                                    onArchive={() => archive.mutate(tpl.id, { onSuccess: () => toast.success(t('templates.archived')), onError: (e) => toast.error(errorMessage(e, t)) })}
                                    onRestore={() => restore.mutate(tpl.id, { onSuccess: () => toast.success(t('templates.restored')), onError: (e) => toast.error(errorMessage(e, t)) })}
                                />
                            ))}
                        </TBody>
                    </Table>
                )}
            </CardContent>
        </Card>
    );
}

function TemplateRow({ tpl, canEdit, onOpen, onDuplicate, onArchive, onRestore }: { tpl: TemplateSummary; canEdit: boolean; onOpen: () => void; onDuplicate: () => void; onArchive: () => void; onRestore: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const Icon = CHANNEL_ICON[tpl.channel];
    return (
        <TR className="igr:cursor-pointer" onClick={onOpen}>
            <TD className="igr:max-w-[340px]">
                <Link to={`/w/${workspace.id}/plantillas/${tpl.id}`} onClick={(e) => e.stopPropagation()} className="igr:font-medium igr:hover:underline">
                    {tpl.name}
                </Link>
                <p className="igr:truncate igr:text-xs igr:text-muted-foreground">{tpl.channel === 'whatsapp' ? (tpl.wa_template_name ?? t('templates.wa.notChosen')) : (tpl.excerpt ?? '')}</p>
            </TD>
            <TD>
                <span className="igr:inline-flex igr:items-center igr:gap-1.5 igr:text-[13px]">
                    <Icon className="igr:size-3.5 igr:text-muted-foreground" aria-hidden="true" />
                    {t(`templates.channels.${tpl.channel}`)}
                </span>
            </TD>
            <TD>
                <Badge variant="outline">{t(`templates.categories.${tpl.category}`)}</Badge>
            </TD>
            <TD>
                <Badge variant={tpl.status === 'ready' ? 'success' : tpl.status === 'archived' ? 'outline' : 'secondary'}>{t(`templates.statuses.${tpl.status}`)}</Badge>
            </TD>
            <TD className="igr:hidden igr:whitespace-nowrap igr:text-muted-foreground igr:md:table-cell">{formatRelative(tpl.updated_at, workspace.locale)}</TD>
            <TD onClick={(e) => e.stopPropagation()}>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button variant="ghost" size="icon" aria-label={t('templates.columns.actionsFor', { name: tpl.name })}>
                            <MoreHorizontal className="igr:size-4" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem onSelect={onOpen}>
                            <Eye className="igr:size-4" />
                            {t('templates.actions.open')}
                        </DropdownMenuItem>
                        {canEdit && (
                            <>
                                <DropdownMenuItem onSelect={onDuplicate}>
                                    <Copy className="igr:size-4" />
                                    {t('templates.actions.duplicate')}
                                </DropdownMenuItem>
                                {tpl.status === 'archived' ? (
                                    <DropdownMenuItem onSelect={onRestore}>
                                        <ArchiveRestore className="igr:size-4" />
                                        {t('templates.actions.restore')}
                                    </DropdownMenuItem>
                                ) : (
                                    <DropdownMenuItem onSelect={onArchive}>
                                        <Archive className="igr:size-4" />
                                        {t('templates.actions.archive')}
                                    </DropdownMenuItem>
                                )}
                            </>
                        )}
                    </DropdownMenuContent>
                </DropdownMenu>
            </TD>
        </TR>
    );
}

function LibraryTab() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const navigate = useNavigate();
    const canEdit = useCan('manageMarketing');
    const [industry, setIndustry] = useState<string | null>(null);
    const library = useLibrary(workspace.id, industry);
    const install = useInstallFromLibrary(workspace.id);
    const [previewKey, setPreviewKey] = useState<string | null>(null);
    const [channel, setChannel] = useState<'' | 'email' | 'sms'>('');
    const effectiveIndustry = industry ?? library.data?.industry ?? null;
    const entries = useMemo(() => (library.data?.data ?? []).filter((e) => channel === '' || e.channel === channel).sort((a, b) => a.flow - b.flow), [library.data, channel]);

    const use = (entry: LibraryEntry, copy = false) =>
        install.mutate(
            { key: entry.key, industry: effectiveIndustry, copy },
            {
                onSuccess: (tpl) => {
                    toast.success(t('templates.library.installed'));
                    void navigate(`/w/${workspace.id}/plantillas/${tpl.id}`);
                },
                onError: (e) => toast.error(errorMessage(e, t)),
            },
        );

    return (
        <div className="igr:space-y-4">
            <div className="igr:flex igr:flex-wrap igr:items-end igr:gap-3">
                <div className="igr:space-y-1.5">
                    <Label htmlFor="lib-industry">{t('templates.library.industry')}</Label>
                    <Select id="lib-industry" value={effectiveIndustry ?? ''} onChange={(e) => setIndustry(e.target.value || null)} className="igr:w-56">
                        <option value="">{t('templates.library.general')}</option>
                        {(library.data?.industries ?? []).map((i) => (
                            <option key={i} value={i}>
                                {t(`industries.${i}`)}
                            </option>
                        ))}
                    </Select>
                </div>
                <div className="igr:space-y-1.5">
                    <Label htmlFor="lib-channel">{t('templates.filters.channel')}</Label>
                    <Select id="lib-channel" value={channel} onChange={(e) => setChannel(e.target.value as '' | 'email' | 'sms')} className="igr:w-48">
                        <option value="">{t('templates.filters.allChannels')}</option>
                        <option value="email">{t('templates.channels.email')}</option>
                        <option value="sms">{t('templates.channels.sms')}</option>
                    </Select>
                </div>
                <p className="igr:max-w-xl igr:text-[13px] igr:text-muted-foreground">{t('templates.library.help')}</p>
            </div>
            {library.isError ? (
                <ErrorState error={library.error} onRetry={() => void library.refetch()} />
            ) : library.isPending ? (
                <div className="igr:grid igr:gap-3 igr:md:grid-cols-2 igr:xl:grid-cols-3">
                    {[0, 1, 2, 3, 4, 5].map((i) => (
                        <Skeleton key={i} className="igr:h-44" />
                    ))}
                </div>
            ) : (
                <div className="igr:grid igr:gap-3 igr:md:grid-cols-2 igr:xl:grid-cols-3">
                    {entries.map((entry) => {
                        const Icon = CHANNEL_ICON[entry.channel];
                        return (
                            <Card key={entry.key} className="igr:flex igr:flex-col">
                                <CardHeader className="igr:pb-2">
                                    <div className="igr:flex igr:items-center igr:gap-1.5 igr:text-xs igr:text-muted-foreground">
                                        <Icon className="igr:size-3.5" aria-hidden="true" />
                                        {t(`templates.channels.${entry.channel}`)} · {t('templates.library.flow', { n: entry.flow })}
                                    </div>
                                    <CardTitle className="igr:text-[14px]">{entry.name}</CardTitle>
                                    <CardDescription className="igr:line-clamp-2">{entry.subject ?? entry.text}</CardDescription>
                                </CardHeader>
                                <CardContent className="igr:flex-1 igr:space-y-1.5 igr:pb-3">
                                    <div className="igr:flex igr:flex-wrap igr:gap-1">
                                        <Badge variant="outline">{t(`templates.categories.${entry.category}`)}</Badge>
                                        {entry.has_industry_copy && <Badge variant="secondary">{t('templates.library.industryCopy')}</Badge>}
                                        {entry.installed_id && <Badge variant="success">{t('templates.library.inUse')}</Badge>}
                                    </div>
                                    <p className="igr:line-clamp-3 igr:text-xs igr:text-muted-foreground">{entry.description}</p>
                                </CardContent>
                                <CardFooter className="igr:flex igr:flex-wrap igr:gap-2">
                                    <Button variant="outline" size="sm" onClick={() => setPreviewKey(entry.key)}>
                                        <Eye className="igr:size-3.5" />
                                        {t('templates.library.preview')}
                                    </Button>
                                    {entry.installed_id ? (
                                        <>
                                            <Button size="sm" variant="secondary" onClick={() => void navigate(`/w/${workspace.id}/plantillas/${entry.installed_id}`)}>
                                                {t('templates.actions.open')}
                                            </Button>
                                            {canEdit && (
                                                <Button size="sm" variant="ghost" onClick={() => use(entry, true)} disabled={install.isPending}>
                                                    {t('templates.library.anotherCopy')}
                                                </Button>
                                            )}
                                        </>
                                    ) : (
                                        canEdit && (
                                            <Button size="sm" onClick={() => use(entry)} loading={install.isPending && install.variables.key === entry.key}>
                                                {t('templates.library.use')}
                                            </Button>
                                        )
                                    )}
                                </CardFooter>
                            </Card>
                        );
                    })}
                </div>
            )}
            <LibraryPreviewDialog entry={entries.find((e) => e.key === previewKey) ?? null} industry={effectiveIndustry} onClose={() => setPreviewKey(null)} />
        </div>
    );
}

function LibraryPreviewDialog({ entry, industry, onClose }: { entry: LibraryEntry | null; industry: string | null; onClose: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const preview = useLibraryPreview(workspace.id, entry?.key ?? null, industry);
    return (
        <Dialog open={entry !== null} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="igr:max-w-4xl">
                <DialogHeader>
                    <DialogTitle>{entry?.name}</DialogTitle>
                    <DialogDescription>{t('templates.preview.sampleHelp')}</DialogDescription>
                </DialogHeader>
                {preview.isPending ? <Skeleton className="igr:h-[420px]" /> : preview.isError ? <ErrorState error={preview.error} /> : entry ? <PreviewView result={preview.data} channel={entry.channel} /> : null}
            </DialogContent>
        </Dialog>
    );
}

function NewTemplateDialog({ channel, onClose }: { channel: TemplateChannel | null; onClose: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const navigate = useNavigate();
    const create = useCreateTemplate(workspace.id);
    const [name, setName] = useState('');
    const error = isApiError(create.error) ? (create.error.fieldErrors.name ?? errorMessage(create.error, t)) : undefined;

    const submit = () => {
        if (!channel || name.trim() === '') return;
        create.mutate(
            { channel, name: name.trim(), category: 'marketing', ...(channel === 'email' ? { subject: '', body_mjml: BLANK_EMAIL_MJML } : {}), ...(channel === 'sms' ? { body_text: '{{ tienda.nombre }}: ' } : {}) },
            {
                onSuccess: (tpl) => {
                    setName('');
                    onClose();
                    void navigate(`/w/${workspace.id}/plantillas/${tpl.id}`);
                },
            },
        );
    };

    return (
        <Dialog open={channel !== null} onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        submit();
                    }}
                    className="igr:space-y-4"
                >
                    <DialogHeader>
                        <DialogTitle>{channel ? t('templates.newTitle', { channel: t(`templates.channels.${channel}`) }) : ''}</DialogTitle>
                        <DialogDescription>{channel === 'whatsapp' ? t('templates.wa.newHelp') : t('templates.newHelp')}</DialogDescription>
                    </DialogHeader>
                    <div className="igr:space-y-1.5">
                        <Label htmlFor="new-tpl-name">{t('templates.fields.name')}</Label>
                        <Input id="new-tpl-name" value={name} onChange={(e) => setName(e.target.value)} maxLength={160} autoFocus placeholder={t('templates.fields.namePlaceholder')} />
                        <FieldError message={error} />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            {t('common.cancel')}
                        </Button>
                        <Button type="submit" loading={create.isPending} disabled={name.trim() === ''}>
                            {t('templates.create')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function BusinessProfileDialog({ open, onOpenChange, canManage }: { open: boolean; onOpenChange: (o: boolean) => void; canManage: boolean }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const profile = useBusinessProfile(workspace.id);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('templates.business.title')}</DialogTitle>
                    <DialogDescription>{t('templates.business.description')}</DialogDescription>
                </DialogHeader>
                {profile.isPending ? (
                    <Skeleton className="igr:h-60" />
                ) : profile.isError ? (
                    <ErrorState error={profile.error} onRetry={() => void profile.refetch()} />
                ) : (
                    // Se monta de nuevo al abrir: el formulario arranca con los datos guardados.
                    open && <BusinessProfileForm initial={profile.data} canManage={canManage} onDone={() => onOpenChange(false)} />
                )}
            </DialogContent>
        </Dialog>
    );
}

function BusinessProfileForm({ initial, canManage, onDone }: { initial: BusinessProfile; canManage: boolean; onDone: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const update = useUpdateBusinessProfile(workspace.id);
    const [form, setForm] = useState<BusinessProfile>(initial);
    const errors = isApiError(update.error) ? update.error.fieldErrors : {};
    const fields = ['name', 'address', 'contact', 'whatsapp', 'logo_url'] as const;

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                update.mutate(form, {
                    onSuccess: () => {
                        toast.success(t('templates.business.saved'));
                        onDone();
                    },
                });
            }}
            className="igr:space-y-4"
        >
            {fields.map((f) => (
                <div key={f} className="igr:space-y-1.5">
                    <Label htmlFor={`bp-${f}`}>{t(`templates.business.fields.${f}`)}</Label>
                    <Input id={`bp-${f}`} value={form[f]} onChange={(e) => setForm({ ...form, [f]: e.target.value })} disabled={!canManage} placeholder={t(`templates.business.placeholders.${f}`)} aria-invalid={errors[f] ? true : undefined} />
                    <FieldError message={errors[f]} />
                </div>
            ))}
            {!canManage && <p className="igr:text-xs igr:text-muted-foreground">{t('templates.business.readOnly')}</p>}
            <DialogFooter>
                <Button type="button" variant="outline" onClick={onDone}>
                    {canManage ? t('common.cancel') : t('common.close')}
                </Button>
                {canManage && (
                    <Button type="submit" loading={update.isPending}>
                        {t('common.save')}
                    </Button>
                )}
            </DialogFooter>
        </form>
    );
}
