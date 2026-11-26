import { Archive, ArchiveRestore, Building2, Copy, Eye, Library, Mail, MessageCircle, MessageSquareText, MoreHorizontal, Plus, Search, Sparkles } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useSearchParams } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input, Select } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { EmptyState, ErrorState, FieldError, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useGallery, useInstallFromGallery, useWaGalleryPreview } from '@/features/gallery/api';
import type { GalleryEntry, GalleryGoal } from '@/features/gallery/types';
import { businessKind } from '@/features/flows/business';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { cn } from '@/lib/cn';
import { formatRelative } from '@/lib/format';
import { toast } from '@/stores/toast';
import { errorMessage } from '@/features/contacts/errors';
import {
    type BusinessProfile,
    CHANNELS,
    type TemplateChannel,
    type TemplateStatus,
    type TemplateSummary,
    useArchiveTemplate,
    useBusinessProfile,
    useCreateTemplate,
    useDuplicateTemplate,
    useLibraryPreview,
    useRestoreTemplate,
    useTemplates,
    useUpdateBusinessProfile,
} from './api';
import { BLANK_EMAIL_MJML, humanizeLiquid } from './content';
import { PhoneMock, WaBubble } from './editor/MessageMock';
import { PreviewView } from './editor/PreviewPanel';
import { notifySubmission, WaStatusAction, WaStatusText } from './WaStatus';

const CHANNEL_ICON = { email: Mail, sms: MessageSquareText, whatsapp: MessageCircle } as const;

/** Plantillas (modulos.md M08): las tuyas por canal con la aprobación de Meta en palabras, y la galería por objetivo. */
export function TemplatesPage() {
    const { t } = useTranslation();
    const [params, setParams] = useSearchParams();
    // `?tab=biblioteca` (de antes) abre la galería.
    const tab = params.get('tab') === 'galeria' || params.get('tab') === 'biblioteca' ? 'gallery' : 'mine';
    const canEdit = useCan('manageMarketing');
    const canManage = useCan('manageWorkspace');
    const [creating, setCreating] = useState<TemplateChannel | null>(null);
    const [profileOpen, setProfileOpen] = useState(false);
    const goGallery = () => setParams({ tab: 'galeria' }, { replace: true });

    return (
        <>
            <PageHeader
                title={t('templates.title')}
                description={t('templates.description')}
                actions={
                    <>
                        <Button variant="outline" onClick={() => setProfileOpen(true)}>
                            <Building2 />
                            {t('templates.business.open')}
                        </Button>
                        {canEdit && (
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button>
                                        <Plus />
                                        {t('templates.new')}
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem onSelect={goGallery}>
                                        <Library className="igr:size-4" />
                                        {t('templates.gallery.fromGallery')}
                                    </DropdownMenuItem>
                                    <DropdownMenuSeparator />
                                    {CHANNELS.map((c) => {
                                        const Icon = CHANNEL_ICON[c];
                                        return (
                                            <DropdownMenuItem key={c} onSelect={() => setCreating(c)}>
                                                <Icon className="igr:size-4" />
                                                {t('templates.newBlank', { channel: t(`templates.channels.${c}`) })}
                                            </DropdownMenuItem>
                                        );
                                    })}
                                </DropdownMenuContent>
                            </DropdownMenu>
                        )}
                    </>
                }
            />
            <Tabs value={tab} onValueChange={(v) => setParams(v === 'gallery' ? { tab: 'galeria' } : {}, { replace: true })}>
                <TabsList>
                    <TabsTrigger value="mine">{t('templates.tabs.mine')}</TabsTrigger>
                    <TabsTrigger value="gallery" className="igr:inline-flex igr:items-center igr:gap-1.5">
                        <Sparkles className="igr:size-3.5" aria-hidden />
                        {t('templates.tabs.gallery')}
                    </TabsTrigger>
                </TabsList>
                <TabsContent value="mine" className="igr:mt-5">
                    <MyTemplates onGoGallery={goGallery} />
                </TabsContent>
                <TabsContent value="gallery" className="igr:mt-5">
                    <GalleryTab />
                </TabsContent>
            </Tabs>
            <NewTemplateDialog channel={creating} onClose={() => setCreating(null)} />
            <BusinessProfileDialog open={profileOpen} onOpenChange={setProfileOpen} canManage={canManage} />
        </>
    );
}

function ChannelChips({ value, onChange, counts }: { value: TemplateChannel | ''; onChange: (v: TemplateChannel | '') => void; counts?: Partial<Record<TemplateChannel | '', number>> }) {
    const { t } = useTranslation();
    return (
        <div role="group" aria-label={t('templates.filters.channel')} className="igr:inline-flex igr:max-w-full igr:overflow-x-auto igr:rounded-lg igr:border igr:bg-muted/40 igr:p-0.5 igr:scrollbar-none">
            {(['', 'whatsapp', 'email', 'sms'] as const).map((c) => {
                const Icon = c === '' ? null : CHANNEL_ICON[c];
                return (
                    <button
                        key={c || 'all'}
                        type="button"
                        aria-pressed={value === c}
                        onClick={() => onChange(c)}
                        className={cn(
                            'igr:inline-flex igr:shrink-0 igr:items-center igr:gap-1.5 igr:rounded-md igr:px-3 igr:py-1 igr:text-support igr:font-medium igr:whitespace-nowrap igr:transition-colors igr:focus-visible:outline-none igr:focus-visible:ring-2 igr:focus-visible:ring-ring',
                            value === c ? 'igr:bg-card igr:text-foreground igr:shadow-xs' : 'igr:text-muted-foreground igr:hover:text-foreground',
                        )}
                    >
                        {Icon ? <Icon className="igr:size-3.5" aria-hidden /> : null}
                        {c === '' ? t('templates.filters.allChannels') : t(`templates.channels.${c}`)}
                        {counts?.[c] !== undefined ? <span className="igr:text-caption igr:tabular-nums igr:text-muted-foreground">{counts[c]}</span> : null}
                    </button>
                );
            })}
        </div>
    );
}

function MyTemplates({ onGoGallery }: { onGoGallery: () => void }) {
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
    // El conteo por canal sale de la lista sin filtro de canal (los demás filtros sí aplican).
    const list = useTemplates(workspace.id, { status, q: debounced });
    const duplicate = useDuplicateTemplate(workspace.id);
    const archive = useArchiveTemplate(workspace.id);
    const restore = useRestoreTemplate(workspace.id);
    const open = (id: string) => void navigate(`/w/${workspace.id}/plantillas/${id}`);
    const all = list.data?.data ?? [];
    const rows = channel === '' ? all : all.filter((tpl) => tpl.channel === channel);
    const counts = { '': all.length, whatsapp: all.filter((x) => x.channel === 'whatsapp').length, email: all.filter((x) => x.channel === 'email').length, sms: all.filter((x) => x.channel === 'sms').length };
    const connected = list.data?.meta?.whatsapp_connected ?? false;
    const filtered = channel !== '' || status !== '' || debounced !== '';

    return (
        <div className="igr:space-y-4">
            <div className="igr:flex igr:flex-col igr:gap-2 igr:lg:flex-row igr:lg:items-center igr:lg:justify-between">
                <ChannelChips value={channel} onChange={setChannel} counts={list.data ? counts : undefined} />
                <div className="igr:flex igr:flex-wrap igr:gap-2">
                    <div className="igr:relative igr:min-w-0 igr:flex-1 igr:sm:w-64 igr:sm:flex-none">
                        <Search className="igr:pointer-events-none igr:absolute igr:top-2.5 igr:left-2.5 igr:size-3.5 igr:text-muted-foreground" aria-hidden />
                        <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('templates.filters.search')} aria-label={t('templates.filters.search')} className="igr:pl-8" />
                    </div>
                    <Select value={status} onChange={(e) => setStatus(e.target.value as TemplateStatus | '')} aria-label={t('templates.filters.status')} className="igr:w-auto">
                        <option value="">{t('templates.filters.active')}</option>
                        <option value="draft">{t('templates.statuses.draft')}</option>
                        <option value="ready">{t('templates.statuses.ready')}</option>
                        <option value="archived">{t('templates.statuses.archived')}</option>
                    </Select>
                </div>
            </div>
            {list.isError ? (
                <ErrorState error={list.error} onRetry={() => void list.refetch()} />
            ) : list.isPending ? (
                <Skeleton className="igr:h-60 igr:rounded-xl" />
            ) : rows.length === 0 ? (
                <EmptyState
                    illustration={filtered ? 'search' : 'messages'}
                    title={filtered ? t('templates.empty.filteredTitle') : t('templates.empty.title')}
                    description={filtered ? t('templates.empty.filteredHelp') : t('templates.empty.help')}
                    action={
                        filtered ? undefined : (
                            <Button onClick={onGoGallery}>
                                <Sparkles />
                                {t('templates.empty.cta')}
                            </Button>
                        )
                    }
                />
            ) : (
                <Card className="igr:overflow-hidden">
                    <ul className="igr:divide-y">
                        {rows.map((tpl) => (
                            <TemplateRow
                                key={tpl.id}
                                tpl={tpl}
                                connected={connected}
                                canEdit={canEdit}
                                onOpen={() => open(tpl.id)}
                                onDuplicate={() => duplicate.mutate(tpl.id, { onSuccess: (c) => (toast.success(t('templates.duplicated')), open(c.id)), onError: (e) => toast.error(errorMessage(e, t)) })}
                                onArchive={() => archive.mutate(tpl.id, { onSuccess: () => toast.success(t('templates.archived')), onError: (e) => toast.error(errorMessage(e, t)) })}
                                onRestore={() => restore.mutate(tpl.id, { onSuccess: () => toast.success(t('templates.restored')), onError: (e) => toast.error(errorMessage(e, t)) })}
                            />
                        ))}
                    </ul>
                </Card>
            )}
        </div>
    );
}

/** El estado de una plantilla de correo o SMS en palabras. */
function PlainStatus({ tpl }: { tpl: TemplateSummary }) {
    const { t } = useTranslation();
    return (
        <span className={cn('igr:text-support', tpl.status === 'ready' ? 'igr:text-success' : 'igr:text-muted-foreground')}>
            {t(`templates.statusText.${tpl.status}`)}
        </span>
    );
}

function TemplateRow({
    tpl,
    connected,
    canEdit,
    onOpen,
    onDuplicate,
    onArchive,
    onRestore,
}: {
    tpl: TemplateSummary;
    connected: boolean;
    canEdit: boolean;
    onOpen: () => void;
    onDuplicate: () => void;
    onArchive: () => void;
    onRestore: () => void;
}) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const Icon = CHANNEL_ICON[tpl.channel];
    const excerpt = tpl.channel === 'whatsapp' ? tpl.wa_template_name : tpl.excerpt ? humanizeLiquid(tpl.excerpt, workspace.name) : null;
    return (
        <li className="igr:relative igr:grid igr:cursor-pointer igr:gap-2 igr:py-3 igr:pr-12 igr:pl-4 igr:md:pr-4 igr:transition-colors igr:hover:bg-accent/40 igr:md:grid-cols-[minmax(0,1fr)_minmax(0,300px)_110px_40px] igr:md:items-center igr:md:gap-4" onClick={onOpen}>
            <div className="igr:flex igr:min-w-0 igr:items-start igr:gap-3">
                <span className="igr:mt-0.5 igr:grid igr:size-8 igr:shrink-0 igr:place-items-center igr:rounded-lg igr:bg-muted igr:text-muted-foreground" aria-hidden>
                    <Icon className="igr:size-4" />
                </span>
                <div className="igr:min-w-0">
                    <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-x-2 igr:gap-y-0.5">
                        <Link to={`/w/${workspace.id}/plantillas/${tpl.id}`} onClick={(e) => e.stopPropagation()} className="igr:font-medium igr:text-foreground igr:hover:underline">
                            {tpl.name}
                        </Link>
                        {tpl.category !== 'marketing' ? <Badge variant="outline">{t(`templates.categories.${tpl.category}`)}</Badge> : null}
                    </div>
                    <p className="igr:truncate igr:text-caption igr:text-muted-foreground">
                        {t(`templates.channels.${tpl.channel}`)}
                        {excerpt ? ` · ${excerpt}` : ''}
                    </p>
                </div>
            </div>
            <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-2 igr:pl-11 igr:md:pl-0" onClick={(e) => e.stopPropagation()}>
                {tpl.channel === 'whatsapp' && tpl.status !== 'archived' ? (
                    <>
                        <WaStatusText template={tpl} connected={connected} />
                        <WaStatusAction template={tpl} connected={connected} />
                    </>
                ) : (
                    <PlainStatus tpl={tpl} />
                )}
            </div>
            <span className="igr:hidden igr:text-caption igr:whitespace-nowrap igr:text-muted-foreground igr:md:block">{formatRelative(tpl.updated_at, workspace.locale)}</span>
            <div className="igr:absolute igr:top-2.5 igr:right-2 igr:md:static" onClick={(e) => e.stopPropagation()}>
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
            </div>
        </li>
    );
}

const GOALS: GalleryGoal[] = ['welcome', 'promotion', 'news', 'reminder', 'event_invite', 'seasonal', 'survey', 'review', 'follow_up'];

/** La galería (rediseño v2 §11.5): por objetivo y para tu vertical, en WhatsApp y correo, instalar en un clic. */
function GalleryTab() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const navigate = useNavigate();
    const canEdit = useCan('manageMarketing');
    const [goal, setGoal] = useState<GalleryGoal | 'all'>('all');
    const [channel, setChannel] = useState<'' | 'email' | 'whatsapp'>('');
    const [scope, setScope] = useState<'mine' | 'all'>('mine');
    const gallery = useGallery(workspace.id, scope === 'all' ? { vertical: 'all' } : {});
    const install = useInstallFromGallery(workspace.id);
    const [preview, setPreview] = useState<GalleryEntry | null>(null);
    const kind = businessKind(workspace);
    const entries = useMemo(
        () =>
            (gallery.data?.data ?? [])
                .filter((e) => (goal === 'all' || e.goal === goal) && (channel === '' || e.channel === channel))
                // Primero las de la galería (gal_*), luego las de los flujos; WhatsApp antes que correo.
                .sort((a, b) => Number(a.flow !== null) - Number(b.flow !== null) || Number(a.channel !== 'whatsapp') - Number(b.channel !== 'whatsapp')),
        [gallery.data, goal, channel],
    );
    const goals = GOALS.filter((g) => (gallery.data?.data ?? []).some((e) => e.goal === g));

    const run = (entry: GalleryEntry) =>
        install.mutate(
            { key: entry.key },
            {
                onSuccess: (res) => {
                    if (res.whatsapp_submission) {
                        notifySubmission(t, res.whatsapp_submission);
                    } else {
                        toast.success(t('templates.gallery.installed', { name: res.name }));
                    }
                    setPreview(null);
                    void navigate(`/w/${workspace.id}/plantillas/${res.id}`);
                },
                onError: (e) => toast.error(errorMessage(e, t)),
            },
        );

    return (
        <div className="igr:space-y-4">
            <div className="igr:flex igr:flex-col igr:gap-3 igr:lg:flex-row igr:lg:items-end igr:lg:justify-between">
                <div className="igr:min-w-0">
                    <h2 className="igr:text-section igr:font-semibold">{t('templates.gallery.title')}</h2>
                    <p className="igr:mt-0.5 igr:text-support igr:text-muted-foreground">{t('templates.gallery.description')}</p>
                </div>
                <div className="igr:flex igr:flex-wrap igr:gap-2">
                    <div role="group" aria-label={t('templates.filters.channel')} className="igr:inline-flex igr:rounded-lg igr:border igr:bg-muted/40 igr:p-0.5">
                        {(['', 'whatsapp', 'email'] as const).map((c) => (
                            <button
                                key={c || 'all'}
                                type="button"
                                aria-pressed={channel === c}
                                onClick={() => setChannel(c)}
                                className={cn(
                                    'igr:rounded-md igr:px-3 igr:py-1 igr:text-support igr:font-medium igr:transition-colors igr:focus-visible:outline-none igr:focus-visible:ring-2 igr:focus-visible:ring-ring',
                                    channel === c ? 'igr:bg-card igr:text-foreground igr:shadow-xs' : 'igr:text-muted-foreground igr:hover:text-foreground',
                                )}
                            >
                                {c === '' ? t('templates.filters.allChannels') : t(`templates.channels.${c}`)}
                            </button>
                        ))}
                    </div>
                    <Select aria-label={t('templates.gallery.scope')} value={scope} onChange={(e) => setScope(e.target.value === 'all' ? 'all' : 'mine')} className="igr:w-auto">
                        <option value="mine">{t('templates.gallery.scopeMine', { context: kind === 'store' ? 'store' : undefined })}</option>
                        <option value="all">{t('templates.gallery.scopeAll')}</option>
                    </Select>
                </div>
            </div>
            <div role="group" aria-label={t('templates.gallery.goalsLabel')} className="igr:-mx-1 igr:flex igr:max-w-full igr:gap-1.5 igr:overflow-x-auto igr:px-1 igr:pb-1 igr:scrollbar-none">
                {(['all', ...goals] as const).map((g) => (
                    <button
                        key={g}
                        type="button"
                        aria-pressed={goal === g}
                        onClick={() => setGoal(g)}
                        className={cn(
                            'igr:shrink-0 igr:rounded-full igr:border igr:px-3 igr:py-1.5 igr:text-support igr:font-medium igr:whitespace-nowrap igr:transition-colors igr:focus-visible:outline-none igr:focus-visible:ring-2 igr:focus-visible:ring-ring',
                            goal === g ? 'igr:border-primary/40 igr:bg-brand-soft igr:text-brand-soft-foreground' : 'igr:bg-card igr:text-muted-foreground igr:hover:text-foreground',
                        )}
                    >
                        {t(`templates.gallery.goals.${g}`)}
                    </button>
                ))}
            </div>
            {gallery.isError ? (
                <ErrorState error={gallery.error} onRetry={() => void gallery.refetch()} />
            ) : gallery.isPending ? (
                <div className="igr:grid igr:gap-3 igr:md:grid-cols-2 igr:xl:grid-cols-3">
                    {[0, 1, 2, 3, 4, 5].map((i) => (
                        <Skeleton key={i} className="igr:h-64 igr:rounded-xl" />
                    ))}
                </div>
            ) : entries.length === 0 ? (
                <EmptyState illustration="search" title={t('templates.gallery.empty')} description={t('templates.gallery.emptyHelp')} />
            ) : (
                <div className="igr:grid igr:gap-3 igr:md:grid-cols-2 igr:xl:grid-cols-3">
                    {entries.map((entry) => (
                        <GalleryCard
                            key={entry.key}
                            entry={entry}
                            canEdit={canEdit}
                            busy={install.isPending && install.variables.key === entry.key}
                            onPreview={() => setPreview(entry)}
                            onInstall={() => run(entry)}
                            onOpen={() => entry.installed && void navigate(`/w/${workspace.id}/plantillas/${entry.installed.id}`)}
                        />
                    ))}
                </div>
            )}
            <GalleryPreviewDialog entry={preview} canEdit={canEdit} busy={install.isPending} onInstall={run} onClose={() => setPreview(null)} />
        </div>
    );
}

function GalleryCard({ entry, canEdit, busy, onPreview, onInstall, onOpen }: { entry: GalleryEntry; canEdit: boolean; busy: boolean; onPreview: () => void; onInstall: () => void; onOpen: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const Icon = CHANNEL_ICON[entry.channel];
    const body = entry.channel === 'whatsapp' ? humanizeLiquid(entry.text ?? '', workspace.name) : humanizeLiquid(entry.subject ?? entry.text ?? '', workspace.name);
    return (
        <Card className="igr:flex igr:flex-col igr:overflow-hidden">
            <div className={cn('igr:relative igr:h-40 igr:overflow-hidden igr:border-b igr:p-3', entry.channel === 'whatsapp' ? 'igr:bg-[#efeae2]' : 'igr:bg-muted/40')}>
                {entry.channel === 'whatsapp' ? (
                    <div className="igr:flex igr:flex-col">
                        <WaBubble body={body} buttons={(entry.whatsapp?.buttons ?? []).slice(0, 2).map((b) => ({ text: b.text, url: b.type === 'URL' }))} />
                    </div>
                ) : (
                    <div className="igr:rounded-lg igr:border igr:bg-card igr:p-3">
                        <p className="igr:text-caption igr:text-muted-foreground">{workspace.name}</p>
                        <p className="igr:line-clamp-2 igr:text-support igr:font-semibold igr:text-foreground">{body}</p>
                        {entry.preheader ? <p className="igr:line-clamp-1 igr:text-caption igr:text-muted-foreground">{humanizeLiquid(entry.preheader, workspace.name)}</p> : null}
                    </div>
                )}
                {/* El texto largo se desvanece abajo: el mensaje completo está en «Ver». */}
                <div aria-hidden className={cn('igr:pointer-events-none igr:absolute igr:inset-x-0 igr:bottom-0 igr:h-8 igr:bg-gradient-to-t', entry.channel === 'whatsapp' ? 'igr:from-[#efeae2]' : 'igr:from-muted/60')} />
            </div>
            <div className="igr:flex igr:flex-1 igr:flex-col igr:p-4">
                <div className="igr:flex igr:items-center igr:gap-1.5 igr:text-caption igr:text-muted-foreground">
                    <Icon className="igr:size-3.5" aria-hidden />
                    {t(`templates.channels.${entry.channel}`)} · {t(`templates.gallery.goals.${entry.goal}`)}
                    {entry.channel === 'whatsapp' ? ` · ${t(`templates.categories.${entry.category}`)}` : ''}
                </div>
                <h3 className="igr:mt-1 igr:font-semibold igr:leading-snug igr:text-foreground">{entry.name}</h3>
                <p className="igr:mt-1 igr:line-clamp-2 igr:text-support igr:text-muted-foreground">{entry.description}</p>
                {entry.installed ? (
                    <Badge variant={entry.installed.linked || entry.channel !== 'whatsapp' ? 'success' : 'secondary'} className="igr:mt-2 igr:self-start">
                        {entry.channel === 'whatsapp' && !entry.installed.linked ? t('templates.gallery.installedDraft') : t('templates.gallery.inUse')}
                    </Badge>
                ) : null}
                <div className="igr:mt-auto igr:flex igr:flex-wrap igr:gap-2 igr:pt-4">
                    {entry.installed ? (
                        <Button size="sm" variant="outline" onClick={onOpen}>
                            {t('templates.actions.open')}
                        </Button>
                    ) : canEdit ? (
                        <Button size="sm" loading={busy} onClick={onInstall}>
                            {entry.channel === 'whatsapp' ? t('templates.gallery.installWa') : t('templates.gallery.install')}
                        </Button>
                    ) : null}
                    <Button size="sm" variant="ghost" onClick={onPreview}>
                        <Eye />
                        {t('templates.gallery.preview')}
                    </Button>
                </div>
            </div>
        </Card>
    );
}

function GalleryPreviewDialog({ entry, canEdit, busy, onInstall, onClose }: { entry: GalleryEntry | null; canEdit: boolean; busy: boolean; onInstall: (e: GalleryEntry) => void; onClose: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const email = useLibraryPreview(workspace.id, entry?.channel === 'email' ? entry.key : null, null);
    const wa = useWaGalleryPreview(workspace.id, entry?.channel === 'whatsapp' ? entry.key : null);
    return (
        <Dialog open={entry !== null} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className={entry?.channel === 'email' ? 'igr:max-w-4xl' : 'igr:max-w-lg'}>
                <DialogHeader>
                    <DialogTitle>{entry?.name}</DialogTitle>
                    <DialogDescription>{entry?.channel === 'whatsapp' ? t('templates.gallery.waPreviewHelp', { category: t(`templates.categories.${entry.category}`) }) : t('templates.preview.sampleHelp')}</DialogDescription>
                </DialogHeader>
                {entry?.channel === 'email' ? (
                    email.isPending ? (
                        <Skeleton className="igr:h-[420px]" />
                    ) : email.isError ? (
                        <ErrorState error={email.error} />
                    ) : (
                        <PreviewView result={email.data} channel="email" />
                    )
                ) : entry ? (
                    wa.isPending ? (
                        <Skeleton className="igr:mx-auto igr:h-80 igr:max-w-[320px] igr:rounded-[30px]" />
                    ) : wa.isError || !wa.data.ok ? (
                        <ErrorState error={wa.error} />
                    ) : (
                        <PhoneMock business={workspace.name}>
                            <WaBubble body={wa.data.text ?? ''} footer={wa.data.whatsapp?.footer ?? null} buttons={(wa.data.whatsapp?.buttons ?? []).map((b) => ({ text: b.text, url: b.type === 'URL' }))} />
                        </PhoneMock>
                    )
                ) : null}
                {entry && !entry.installed && canEdit ? (
                    <DialogFooter>
                        <Button variant="outline" onClick={onClose}>
                            {t('common.close')}
                        </Button>
                        <Button loading={busy} onClick={() => onInstall(entry)}>
                            {entry.channel === 'whatsapp' ? t('templates.gallery.installWa') : t('templates.gallery.install')}
                        </Button>
                    </DialogFooter>
                ) : null}
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
