import { useCallback, useEffect, useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { BillingBanner } from '@/features/billing/BillingBanner';
import { Link, Navigate, Outlet, useLocation, useNavigate, useParams } from 'react-router';
import { Compass } from 'lucide-react';
import { useMe } from '@/api/queries';
import type { Me } from '@/api/types';
import { Button } from '@/components/ui/button';
import { EmptyState, ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { WorkspaceContext } from '@/hooks/useWorkspace';
import { useShortcuts } from '@/hooks/useShortcuts';
import { setLocale } from '@/lib/i18n';
import { useUi } from '@/stores/ui';
import { CommandPalette } from './CommandPalette';
import { MAIN_NAV, SETTINGS_NAV } from './nav';
import { MobileNav } from './MobileNav';
import { Sidebar } from './Sidebar';

export function FullPageSkeleton() {
    return (
        <div className="igr:flex igr:h-full" aria-busy>
            <div className="igr:hidden igr:w-60 igr:border-r igr:p-4 igr:md:block">
                <Skeleton className="igr:h-6 igr:w-32" />
                <Skeleton className="igr:mt-6 igr:h-8 igr:w-full" />
                {Array.from({ length: 8 }, (_, i) => (
                    <Skeleton key={i} className="igr:mt-3 igr:h-5 igr:w-40" />
                ))}
            </div>
            <div className="igr:flex-1 igr:p-8">
                <Skeleton className="igr:h-6 igr:w-48" />
                <div className="igr:mt-6 igr:grid igr:grid-cols-4 igr:gap-4">
                    {Array.from({ length: 4 }, (_, i) => (
                        <Skeleton key={i} className="igr:h-24" />
                    ))}
                </div>
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
    const workspace = me?.workspaces.find((w) => w.id === workspaceId) ?? null;

    useEffect(() => {
        if (workspace) {
            setLastWorkspace(workspace.id);
            setLocale(workspace.locale);
        }
    }, [workspace, setLastWorkspace]);

    const onPalette = useCallback(() => setCommandOpen(true), [setCommandOpen]);
    const onGo = useCallback(
        (key: string) => {
            const item = [...MAIN_NAV, ...SETTINGS_NAV].find((i) => i.shortcut === key && i.to !== undefined);
            if (item && workspace) {
                void navigate(item.to === '' ? `/w/${workspace.id}` : `/w/${workspace.id}/${item.to ?? ''}`);
            }
        },
        [navigate, workspace],
    );
    useShortcuts({ onPalette, onGo });

    const ctx = useMemo(() => (me && workspace ? { me, workspace } : null), [me, workspace]);

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
            <div className="igr:flex igr:h-full">
                <div className="igr:hidden igr:h-full igr:md:block">
                    <Sidebar />
                </div>
                <main className="igr:min-w-0 igr:flex-1 igr:overflow-y-auto">
                    <MobileNav />
                    <div className="igr:mx-auto igr:max-w-6xl igr:px-4 igr:py-4 igr:md:px-8 igr:md:py-6">
                        <BillingBanner />
                        <Outlet />
                    </div>
                </main>
            </div>
            <CommandPalette />
        </WorkspaceContext.Provider>
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
