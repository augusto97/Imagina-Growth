import { useCallback, useEffect, useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { BillingBanner } from '@/features/billing/BillingBanner';
import { Link, Navigate, Outlet, useLocation, useMatches, useNavigate, useParams } from 'react-router';
import { Compass } from 'lucide-react';
import { useMe } from '@/api/queries';
import type { Me, Workspace } from '@/api/types';
import { Button } from '@/components/ui/button';
import { EmptyState, ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { WorkspaceContext } from '@/hooks/useWorkspace';
import { DESKTOP_QUERY, useMedia } from '@/hooks/useMedia';
import { useShortcuts } from '@/hooks/useShortcuts';
import { setLocale } from '@/lib/i18n';
import { useUi } from '@/stores/ui';
import { CommandPalette } from './CommandPalette';
import { LockedPage } from './LockedPage';
import { LAYOUT_CLASS, pageLayout } from './pageLayout';
import { PageContext, type PageContextValue } from './pageContext';
import { flattenNav, itemLock, itemPath, locate, navContext, visibleSections, type NavLock } from './nav';
import { MobileNav } from './MobileNav';
import { SectionNav } from './SectionNav';
import { Sidebar } from './Sidebar';

export function FullPageSkeleton() {
    return (
        <div className="igr:flex igr:h-full" aria-busy>
            <div className="igr:hidden igr:w-60 igr:border-r igr:bg-sidebar igr:p-4 igr:lg:block">
                <Skeleton className="igr:h-6 igr:w-32" />
                <Skeleton className="igr:mt-6 igr:h-11 igr:w-full igr:rounded-lg" />
                <Skeleton className="igr:mt-3 igr:h-9 igr:w-full igr:rounded-lg" />
                {Array.from({ length: 8 }, (_, i) => (
                    <Skeleton key={i} className="igr:mt-3 igr:h-6 igr:w-40" />
                ))}
            </div>
            <div className="igr:mx-auto igr:w-full igr:max-w-7xl igr:flex-1 igr:p-5 igr:md:p-8">
                <Skeleton className="igr:h-4 igr:w-24" />
                <Skeleton className="igr:mt-3 igr:h-8 igr:w-64" />
                <Skeleton className="igr:mt-2 igr:h-4 igr:w-96 igr:max-w-full" />
                <div className="igr:mt-8 igr:grid igr:gap-4 igr:sm:grid-cols-2 igr:lg:grid-cols-4">
                    {Array.from({ length: 4 }, (_, i) => (
                        <Skeleton key={i} className="igr:h-28 igr:rounded-xl" />
                    ))}
                </div>
                <Skeleton className="igr:mt-4 igr:h-64 igr:rounded-xl" />
            </div>
        </div>
    );
}

/** Exige sesión. Sin sesión, va a /login conservando el destino (incluida la query de /connect). */
export function RequireAuth({ children }: { children?: React.ReactNode }) {
    const { data: me, isPending, error, refetch } = useMe();
    const location = useLocation();
    if (isPending) {
        return <FullPageSkeleton />;
    }
    if (error) {
        return (
            <div className="igr:mx-auto igr:max-w-md igr:p-8">
                <ErrorState error={error} onRetry={() => void refetch()} />
            </div>
        );
    }
    if (me === null) {
        const next = `${location.pathname}${location.search}`;
        return <Navigate to={`/login?next=${encodeURIComponent(next)}`} replace />;
    }
    return children ?? <Outlet />;
}

/** Lleva / al último espacio de trabajo usado o al onboarding. */
export function RootRedirect() {
    const { data: me } = useMe();
    const last = useUi((s) => s.lastWorkspaceId);
    if (!me) {
        return <FullPageSkeleton />;
    }
    if (me.workspaces.length === 0) {
        return <Navigate to="/onboarding" replace />;
    }
    const target = me.workspaces.find((w) => w.id === last) ?? me.workspaces[0];
    return <Navigate to={`/w/${target?.id ?? ''}`} replace />;
}

export function needsTwoFactor(me: Me, workspaceRequires: boolean): boolean {
    return me.require_two_factor && workspaceRequires && !me.user.two_factor_enabled;
}

/** Shell de la app dentro de /w/:workspaceId. */
export function WorkspaceLayout() {
    const { t } = useTranslation();
    const { workspaceId = '' } = useParams();
    const { data: me } = useMe();
    const location = useLocation();
    const navigate = useNavigate();
    const setLastWorkspace = useUi((s) => s.setLastWorkspace);
    const setCommandOpen = useUi((s) => s.setCommandOpen);
    const setSidebarCollapsed = useUi((s) => s.setSidebarCollapsed);
    const desktop = useMedia(DESKTOP_QUERY);
    const sidebarCollapsed = useUi((s) => s.sidebarCollapsed);
    const workspace = me?.workspaces.find((w) => w.id === workspaceId) ?? null;
    const layout = pageLayout(useMatches().map((m) => m.handle));

    useEffect(() => {
        if (workspace) {
            setLastWorkspace(workspace.id);
            setLocale(workspace.locale);
        }
    }, [workspace, setLastWorkspace]);

    const onPalette = useCallback(() => setCommandOpen(true), [setCommandOpen]);
    const onGo = useCallback(
        (key: string) => {
            if (!workspace) {
                return;
            }
            const item = flattenNav(visibleSections(navContext(workspace), me?.user.is_platform_admin ?? false)).find((i) => i.shortcut === key);
            if (item) {
                void navigate(itemPath(`/w/${workspace.id}`, item));
            }
        },
        [navigate, workspace, me],
    );
    // ⌘B / Ctrl+B: solo hay barra lateral que contraer en el escritorio.
    const onToggleSidebar = useCallback(() => setSidebarCollapsed(!useUi.getState().sidebarCollapsed), [setSidebarCollapsed]);
    useShortcuts({ onPalette, onGo, onToggleSidebar: desktop ? onToggleSidebar : undefined });

    const ctx = useMemo(() => (me && workspace ? { me, workspace } : null), [me, workspace]);
    const nav = useMemo(() => (me && workspace ? navContext(workspace) : null), [me, workspace]);
    const sections = useMemo(() => (nav ? visibleSections(nav, me?.user.is_platform_admin ?? false) : []), [nav, me]);
    const base = `/w/${workspaceId}`;
    // Dónde está la persona: la sección (para la miga y la subnavegación) y si a la entrada le falta algo para servir.
    const located = useMemo(() => locate(sections, location.pathname, base), [sections, location.pathname, base]);
    const page = useMemo<PageContextValue | null>(() => (located ? { ...located, base } : null), [located, base]);
    const lock = page?.item && nav ? itemLock(page.item, nav) : null;
    // Una pantalla bloqueada se ve como una página normal aunque la ruta sea un lienzo o llene el alto.
    const frame = lock ? 'default' : layout;

    if (!me) {
        return <FullPageSkeleton />;
    }
    if (!workspace || !ctx) {
        return (
            <div className="igr:mx-auto igr:max-w-md igr:p-10">
                <EmptyState
                    icon={Compass}
                    title={t('errors.workspaceNotFound')}
                    description={t('errors.workspaceNotFoundDescription')}
                    action={
                        <Button asChild variant="outline">
                            <Link to="/">{t('common.goHome')}</Link>
                        </Button>
                    }
                />
            </div>
        );
    }
    if (needsTwoFactor(me, workspace.requires_two_factor)) {
        return <Navigate to={`/seguridad?next=${encodeURIComponent(location.pathname + location.search)}&requerido=1`} replace />;
    }

    return (
        <WorkspaceContext.Provider value={ctx}>
            {/*
             * Diseño adaptable (hub/CLAUDE.md §14): alto de 100dvh (la barra del navegador del celular no tapa nada) y solo
             * `main` se desplaza. Desde `lg` la barra lateral fija; por debajo, la barra superior del menú, que queda fuera
             * de `main` y siempre se ve. `main` es `relative` para que lo posicionado (los `sr-only` de las tablas) no
             * agrande la página.
             */}
            <div className="igr:flex igr:h-dvh igr:overflow-hidden">
                <div className="igr:hidden igr:h-full igr:lg:block">
                    <Sidebar />
                </div>
                <div className="igr:flex igr:min-w-0 igr:flex-1 igr:flex-col">
                    <MobileNav />
                    <main id="igr-main" className="igr:relative igr:min-h-0 igr:flex-1 igr:overflow-y-auto">
                        <div className={LAYOUT_CLASS[frame]}>
                            <BillingBanner />
                            <PageContext.Provider value={page}>
                                <SectionFrame page={page} layout={frame} nav={nav} lock={lock} abilities={workspace.abilities} sidebarCollapsed={sidebarCollapsed} />
                            </PageContext.Provider>
                        </div>
                    </main>
                </div>
            </div>
            <CommandPalette />
        </WorkspaceContext.Provider>
    );
}

/**
 * El contenido de la página con la subnavegación de su sección (rediseño v2 §3 y §8). En el escritorio la subnavegación
 * va dentro de la barra lateral, debajo de la sección; arriba del contenido, como control segmentado, solo por debajo de
 * `lg` o con la barra contraída. Si a la entrada le falta algo (plan, web, canal), `LockedPage` en lugar de la pantalla.
 * Los lienzos (`fullBleed`) y las pantallas que llenan el alto (`fill`, la bandeja) van sin el control segmentado: la
 * miga del encabezado lleva de vuelta a la sección.
 */
function SectionFrame({
    page,
    layout,
    nav,
    lock,
    abilities,
    sidebarCollapsed,
}: {
    page: PageContextValue | null;
    layout: ReturnType<typeof pageLayout>;
    nav: ReturnType<typeof navContext> | null;
    lock: NavLock | null;
    abilities: Workspace['abilities'];
    sidebarCollapsed: boolean;
}) {
    const content = page?.item && lock ? <LockedPage section={page.section} item={page.item} lock={lock} base={page.base} abilities={abilities} /> : <Outlet />;
    if (layout !== 'default' || !page || !nav || page.section.items.length === 0) {
        return content;
    }
    return (
        <>
            <SectionNav section={page.section} location={page} base={page.base} ctx={nav} className={sidebarCollapsed ? undefined : 'igr:lg:hidden'} />
            {content}
        </>
    );
}

export function NotFoundPage() {
    const { t } = useTranslation();
    return (
        <div className="igr:mx-auto igr:max-w-md igr:p-10">
            <EmptyState
                icon={Compass}
                title={t('errors.notFound')}
                description={t('errors.notFoundDescription')}
                action={
                    <Button asChild variant="outline">
                        <Link to="/">{t('common.goHome')}</Link>
                    </Button>
                }
            />
        </div>
    );
}
