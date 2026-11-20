import { FolderClosed, Library, Lock, Plus, Search, Shapes, Upload } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useSearchParams } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Kbd } from '@/components/ui/kbd';
import { EmptyState, ErrorState, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { formatMoney, formatNumber, formatRelative } from '@/lib/format';
import { useSegments } from './api';
import { LibraryDialog, StaticSegmentDialog } from './dialogs';
import type { SegmentSummary } from './types';

/** Agrupa por carpeta; las del sistema primero y «Sin carpeta» al final. */
export function groupByFolder(segments: SegmentSummary[]): { folder: string | null; items: SegmentSummary[] }[] {
    const map = new Map<string | null, SegmentSummary[]>();
    for (const s of segments) {
        const key = s.folder ?? null;
        map.set(key, [...(map.get(key) ?? []), s]);
    }
    const systemFirst = (items: SegmentSummary[]) => (items.some((s) => s.is_system) ? 0 : 1);
    return [...map.entries()]
        .map(([folder, items]) => ({ folder, items: [...items].sort((a, b) => a.name.localeCompare(b.name, 'es')) }))
        .sort((a, b) => {
            if (a.folder === null || b.folder === null) {
                return a.folder === null ? 1 : -1;
            }
            return systemFirst(a.items) - systemFirst(b.items) || a.folder.localeCompare(b.folder, 'es');
        });
}

export function SegmentsPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageMarketing');
    const segments = useSegments(workspace.id);
    const [query, setQuery] = useState('');
    const [dialog, setDialog] = useState<'library' | 'static' | null>(null);

    // «/» enfoca la búsqueda.
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            const target = e.target as HTMLElement | null;
            if (e.key === '/' && !e.metaKey && !e.ctrlKey && !(target && ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))) {
                e.preventDefault();
                document.getElementById('segments-search')?.focus();
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, []);

    const list = segments.data;

    // `?system=rfm_at_risk` (mapa RFM de Analítica, hallazgos): abre ese segmento del sistema.
    const navigate = useNavigate();
    const [params, setParams] = useSearchParams();
    const systemKey = params.get('system');
    useEffect(() => {
        if (systemKey === null || list === undefined) return;
        const target = list.find((s) => s.system_key === systemKey);
        if (target) void navigate(`/w/${workspace.id}/segmentos/${target.id}`, { replace: true });
        else setParams((p) => { p.delete('system'); return p; }, { replace: true });
    }, [systemKey, list, navigate, setParams, workspace.id]);
    const groups = useMemo(() => {
        const q = query.trim().toLocaleLowerCase('es');
        const filtered = (list ?? []).filter((s) => q === '' || s.name.toLocaleLowerCase('es').includes(q) || (s.folder ?? '').toLocaleLowerCase('es').includes(q));
        return groupByFolder(filtered);
    }, [list, query]);
    const folders = useMemo(() => [...new Set((list ?? []).map((s) => s.folder).filter((f): f is string => f !== null))], [list]);
    const custom = (list ?? []).filter((s) => !s.is_system).length;

    return (
        <div>
            <PageHeader
                title={t('segments.title')}
                description={t('segments.description')}
                actions={
                    <>
                        <Button variant="outline" onClick={() => setDialog('library')}>
                            <Library />
                            {t('segments.library.action')}
                        </Button>
                        {canManage ? (
                            <>
                                <Button variant="outline" onClick={() => setDialog('static')}>
                                    <Upload />
                                    {t('segments.static.action')}
                                </Button>
                                <Button asChild>
                                    <Link to="nuevo">
                                        <Plus />
                                        {t('segments.new')}
                                    </Link>
                                </Button>
                            </>
                        ) : null}
                    </>
                }
            />

            <div className="igr:mb-4 igr:flex igr:items-center igr:gap-2">
                <div className="igr:relative igr:max-w-sm igr:flex-1">
                    <Search className="igr:absolute igr:left-2.5 igr:top-2 igr:size-4 igr:text-muted-foreground" />
                    <Input id="segments-search" className="igr:pl-8" value={query} onChange={(e) => setQuery(e.target.value)} placeholder={t('segments.search')} aria-label={t('segments.search')} />
                    <Kbd className="igr:absolute igr:right-2 igr:top-1.5">/</Kbd>
                </div>
            </div>

            {segments.isPending ? (
                <div className="igr:space-y-3">
                    <Skeleton className="igr:h-40" />
                    <Skeleton className="igr:h-40" />
                </div>
            ) : segments.isError ? (
                <ErrorState error={segments.error} onRetry={() => void segments.refetch()} />
            ) : (
                <div className="igr:space-y-5">
                    {custom === 0 && query === '' ? (
                        <EmptyState
                            icon={Shapes}
                            title={t('segments.empty.title')}
                            description={t('segments.empty.description')}
                            action={
                                canManage ? (
                                    <Button asChild>
                                        <Link to="nuevo">
                                            <Plus />
                                            {t('segments.empty.action')}
                                        </Link>
                                    </Button>
                                ) : undefined
                            }
                        />
                    ) : null}
                    {groups.length === 0 ? <p className="igr:py-8 igr:text-center igr:text-[13px] igr:text-muted-foreground">{t('segments.noMatches')}</p> : null}
                    {groups.map((g) => (
                        <section key={g.folder ?? '—'}>
                            <h2 className="igr:mb-2 igr:flex igr:items-center igr:gap-1.5 igr:text-xs igr:font-medium igr:uppercase igr:tracking-wide igr:text-muted-foreground">
                                <FolderClosed className="igr:size-3.5" />
                                {g.folder ?? t('segments.noFolder')}
                                <span className="igr:font-normal">· {g.items.length}</span>
                            </h2>
                            <Card>
                                <SegmentTable items={g.items} />
                            </Card>
                        </section>
                    ))}
                </div>
            )}

            <LibraryDialog open={dialog === 'library'} onClose={() => setDialog(null)} />
            {dialog === 'static' ? <StaticSegmentDialog open onClose={() => setDialog(null)} folders={folders} /> : null}
        </div>
    );
}

/** Un segmento dinámico que todavía no se calcula: sus cifras aún no existen. */
const pending = (s: { last_computed_at: string | null; kind: string }) => s.last_computed_at === null && s.kind === 'dynamic';

function SegmentTable({ items }: { items: SegmentSummary[] }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    return (
        <Table className="igr:table-fixed">
            <THead>
                <TR className="igr:hover:bg-transparent">
                    <TH className="igr:w-[38%]">{t('segments.columns.name')}</TH>
                    <TH className="igr:text-right">{t('segments.columns.members')}</TH>
                    <TH className="igr:text-right">{t('segments.columns.whatsapp')}</TH>
                    <TH className="igr:text-right">{t('segments.columns.email')}</TH>
                    <TH className="igr:text-right">{t('segments.columns.value')}</TH>
                    <TH className="igr:text-right">{t('segments.columns.updated')}</TH>
                </TR>
            </THead>
            <TBody>
                {items.map((s) => (
                    <TR key={s.id}>
                        <TD>
                            <Link to={s.id} className="igr:flex igr:items-center igr:gap-2 igr:font-medium igr:hover:underline">
                                <span className="igr:truncate">{s.name}</span>
                                {s.is_system ? (
                                    <Badge variant="secondary" title={t('segments.badges.systemHelp')}>
                                        <Lock className="igr:size-3" />
                                        {t('segments.badges.system')}
                                    </Badge>
                                ) : null}
                                {s.kind === 'static' ? <Badge variant="outline">{t('segments.badges.static')}</Badge> : null}
                            </Link>
                            {s.description ? <p className="igr:truncate igr:text-xs igr:text-muted-foreground">{s.description}</p> : null}
                        </TD>
                        <TD className="igr:text-right igr:font-medium igr:tabular-nums">{pending(s) ? <span className="igr:text-xs igr:font-normal igr:text-muted-foreground">{t('segments.computing')}</span> : formatNumber(s.member_count, workspace.locale)}</TD>
                        {/* Mientras se calcula por primera vez, nada de ceros que parecen datos (U6). */}
                        <TD className="igr:text-right igr:tabular-nums igr:text-muted-foreground">{pending(s) ? '—' : formatNumber(s.channel_counts.whatsapp ?? 0, workspace.locale)}</TD>
                        <TD className="igr:text-right igr:tabular-nums igr:text-muted-foreground">{pending(s) ? '—' : formatNumber(s.channel_counts.email ?? 0, workspace.locale)}</TD>
                        <TD className="igr:text-right igr:tabular-nums">{pending(s) ? <span className="igr:text-muted-foreground">—</span> : formatMoney(s.revenue_ws, workspace.currency, workspace.locale)}</TD>
                        <TD className="igr:text-right igr:text-xs igr:text-muted-foreground">{s.last_computed_at ? formatRelative(s.last_computed_at, workspace.locale) : '—'}</TD>
                    </TR>
                ))}
            </TBody>
        </Table>
    );
}
