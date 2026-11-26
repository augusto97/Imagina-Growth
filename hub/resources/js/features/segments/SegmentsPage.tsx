import { Library, Plus, Search, Upload } from 'lucide-react';
import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useSearchParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { Callout } from '@/components/ui/callout';
import { Input } from '@/components/ui/input';
import { Kbd } from '@/components/ui/kbd';
import { EmptyState, ErrorState, PageHeader } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { useBusiness } from '@/features/contacts/business';
import { useSegments } from './api';
import { LibraryDialog, StaticSegmentDialog } from './dialogs';
import { appliesTo, familyOf, type GroupFamily } from './look';
import { SegmentCard } from './SegmentCard';
import type { SegmentSummary } from './types';
import { UseGroupDialog } from './UseGroupDialog';

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

/** El orden de las familias en la página: los propios, los de servicios, los RFM y los listos de la tienda. */
const FAMILIES: GroupFamily[] = ['custom', 'services', 'rfm', 'library'];

/** Los grupos que se ven, por familia, filtrados por el negocio y la búsqueda (RFM en su orden de valor). */
export function groupsByFamily(segments: SegmentSummary[], store: boolean, services: boolean, query: string): { family: GroupFamily; items: SegmentSummary[] }[] {
    const q = query.trim().toLocaleLowerCase('es');
    const visible = segments.filter(
        (s) => appliesTo(s, store, services) && (q === '' || s.name.toLocaleLowerCase('es').includes(q) || (s.description ?? '').toLocaleLowerCase('es').includes(q)),
    );
    const rfmOrder = ['champions', 'loyal', 'cant_lose', 'at_risk', 'potential_loyal', 'new', 'promising', 'need_attention', 'about_to_sleep', 'hibernating', 'lost'];
    return FAMILIES.map((family) => {
        const items = visible.filter((s) => familyOf(s) === family);
        items.sort((a, b) =>
            family === 'rfm' ? rfmOrder.indexOf((a.system_key ?? '').slice(4)) - rfmOrder.indexOf((b.system_key ?? '').slice(4)) : a.name.localeCompare(b.name, 'es'),
        );
        return { family, items };
    });
}

/**
 * Clientes → Grupos de clientes (modulos.md M04): cada grupo explicado en palabras, con su tamaño, su tendencia, a
 * cuántos se les puede escribir y las acciones directas (enviar una campaña, crear una automatización). Primero los
 * propios; después los listos para el negocio (de servicios, de compras RFM y de la biblioteca de la tienda).
 */
export function SegmentsPage() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canManage = useCan('manageMarketing');
    const business = useBusiness();
    const segments = useSegments(workspace.id);
    const [query, setQuery] = useState('');
    const [dialog, setDialog] = useState<'library' | 'static' | null>(null);
    const [using, setUsing] = useState<{ segment: SegmentSummary; mode: 'campaign' | 'flow' } | null>(null);

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
        else
            setParams(
                (p) => {
                    p.delete('system');
                    return p;
                },
                { replace: true },
            );
    }, [systemKey, list, navigate, setParams, workspace.id]);

    const families = useMemo(() => groupsByFamily(list ?? [], business.store, business.services, query), [list, business.store, business.services, query]);
    const folders = useMemo(() => [...new Set((list ?? []).map((s) => s.folder).filter((f): f is string => f !== null))], [list]);
    const custom = (list ?? []).filter((s) => !s.is_system).length;
    const shown = families.reduce((n, f) => n + f.items.length, 0);
    const onUse = canManage ? (segment: SegmentSummary) => (mode: 'campaign' | 'flow') => setUsing({ segment, mode }) : null;

    return (
        <div>
            <PageHeader
                title={t('segments.title')}
                description={t(business.servicesOnly ? 'segments.list.descriptionServices' : 'segments.list.descriptionStore')}
                actions={
                    <>
                        {business.store ? (
                            <Button variant="outline" onClick={() => setDialog('library')}>
                                <Library />
                                {t('segments.library.action')}
                            </Button>
                        ) : null}
                        {canManage ? (
                            <>
                                <Button variant="outline" onClick={() => setDialog('static')}>
                                    <Upload />
                                    {t('segments.static.action')}
                                </Button>
                                <Button asChild>
                                    <Link to="nuevo">
                                        <Plus />
                                        {t('segments.list.create')}
                                    </Link>
                                </Button>
                            </>
                        ) : null}
                    </>
                }
            />

            <div className="igr:relative igr:mb-6 igr:max-w-md">
                <Search className="igr:pointer-events-none igr:absolute igr:top-2.5 igr:left-3 igr:size-4 igr:text-muted-foreground" aria-hidden />
                <Input
                    id="segments-search"
                    type="search"
                    className="igr:pr-9 igr:pl-9"
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    placeholder={t('segments.list.search')}
                    aria-label={t('segments.list.search')}
                />
                <Kbd className="igr:absolute igr:top-2 igr:right-2 igr:max-sm:hidden">/</Kbd>
            </div>

            {segments.isPending ? (
                <div className="igr:grid igr:gap-3 igr:md:grid-cols-2 igr:xl:grid-cols-3" aria-busy>
                    {[0, 1, 2, 3, 4, 5].map((i) => (
                        <Skeleton key={i} className="igr:h-52 igr:rounded-xl" />
                    ))}
                </div>
            ) : segments.isError ? (
                <ErrorState error={segments.error} onRetry={() => void segments.refetch()} />
            ) : (
                <div className="igr:space-y-8">
                    {query !== '' && shown === 0 ? (
                        <EmptyState illustration="search" size="compact" title={t('segments.noMatches')} description={t('segments.list.noMatchesHelp')} />
                    ) : null}
                    {custom === 0 && query === '' && canManage ? (
                        // Sin grupos propios la página no está vacía (están los listos): una invitación corta arriba.
                        <Callout
                            tone="brand"
                            title={t('segments.list.emptyTitle')}
                            action={
                                <Button asChild size="sm">
                                    <Link to="nuevo">
                                        <Plus />
                                        {t('segments.list.create')}
                                    </Link>
                                </Button>
                            }
                        >
                            {t(business.servicesOnly ? 'segments.list.emptyServices' : 'segments.list.emptyStore')}
                        </Callout>
                    ) : null}
                    {families.map(({ family, items }) =>
                        items.length > 0 ? (
                            <Family key={family} family={family} count={items.length}>
                                <Grid>
                                    {items.map((s) => (
                                        <SegmentCard key={s.id} segment={s} showValue={business.store} onUse={onUse ? onUse(s) : null} />
                                    ))}
                                </Grid>
                            </Family>
                        ) : null,
                    )}
                </div>
            )}

            <LibraryDialog open={dialog === 'library'} onClose={() => setDialog(null)} />
            {dialog === 'static' ? <StaticSegmentDialog open onClose={() => setDialog(null)} folders={folders} /> : null}
            {using ? <UseGroupDialog segment={using.segment} mode={using.mode} onClose={() => setUsing(null)} /> : null}
        </div>
    );
}

function Family({ family, count, children }: { family: GroupFamily; count: number; children: ReactNode }) {
    const { t } = useTranslation();
    return (
        <section aria-labelledby={`grupos-${family}`}>
            <div className="igr:mb-3">
                <h2 id={`grupos-${family}`} className="igr:flex igr:items-center igr:gap-2 igr:text-section igr:font-semibold igr:tracking-tight">
                    {t(`segments.families.${family}.title`)}
                    {count > 0 ? <span className="igr:text-support igr:font-normal igr:text-muted-foreground igr:tabular-nums">{count}</span> : null}
                </h2>
                <p className="igr:mt-0.5 igr:max-w-3xl igr:text-support igr:text-muted-foreground">{t(`segments.families.${family}.description`)}</p>
            </div>
            {children}
        </section>
    );
}

function Grid({ children }: { children: ReactNode }) {
    return <div className="igr:grid igr:gap-3 igr:md:grid-cols-2 igr:xl:grid-cols-3">{children}</div>;
}
