import { X } from 'lucide-react';
import { useEffect, useMemo, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, Navigate, useLocation, useParams } from 'react-router';
import { useMe } from '@/api/queries';
import { Logo } from '@/components/layout/Logo';
import { FullPageSkeleton, needsTwoFactor, NotFoundPage } from '@/components/layout/guards';
import { Button } from '@/components/ui/button';
import { WorkspaceContext } from '@/hooks/useWorkspace';
import { setLocale } from '@/lib/i18n';
import { useUi } from '@/stores/ui';

/*
 * El marco de la bienvenida y del asistente (docs/diseno/rediseno-v2.md §4): pantalla completa, sin la barra lateral,
 * con el logo, el nombre del negocio y la salida al Inicio siempre a la vista. Da el mismo contexto del espacio de
 * trabajo que `WorkspaceLayout` (idioma, 2FA obligatoria), porque estas rutas van fuera de él.
 */
export function OnboardingShell({ children, exitLabel, aside }: { children: (base: string) => ReactNode; exitLabel?: string; aside?: ReactNode }) {
    const { t } = useTranslation();
    const { workspaceId = '' } = useParams();
    const location = useLocation();
    const { data: me } = useMe();
    const setLastWorkspace = useUi((s) => s.setLastWorkspace);
    const workspace = me?.workspaces.find((w) => w.id === workspaceId) ?? null;
    const ctx = useMemo(() => (me && workspace ? { me, workspace } : null), [me, workspace]);

    useEffect(() => {
        if (workspace) {
            setLastWorkspace(workspace.id);
            setLocale(workspace.locale);
        }
    }, [workspace, setLastWorkspace]);

    if (!me) {
        return <FullPageSkeleton />;
    }
    if (!workspace || !ctx) {
        return <NotFoundPage />;
    }
    if (needsTwoFactor(me, workspace.requires_two_factor)) {
        return <Navigate to={`/seguridad?next=${encodeURIComponent(location.pathname + location.search)}&requerido=1`} replace />;
    }
    const base = `/w/${workspace.id}`;

    return (
        <WorkspaceContext.Provider value={ctx}>
            <div className="igr:flex igr:h-dvh igr:flex-col igr:overflow-hidden igr:bg-background">
                <header className="igr:flex igr:h-14 igr:shrink-0 igr:items-center igr:gap-3 igr:border-b igr:bg-card/80 igr:px-4 igr:backdrop-blur igr:md:px-6">
                    <Logo brand={workspace.branding ?? null} />
                    <span aria-hidden className="igr:h-5 igr:w-px igr:bg-border igr:max-sm:hidden" />
                    <span className="igr:min-w-0 igr:flex-1 igr:truncate igr:text-support igr:text-muted-foreground igr:max-sm:hidden">{workspace.name}</span>
                    <span className="igr:flex-1 igr:sm:hidden" />
                    {aside}
                    <Button asChild variant="ghost" size="sm">
                        <Link to={base}>
                            <X aria-hidden />
                            {exitLabel ?? t('onboarding.shell.exit')}
                        </Link>
                    </Button>
                </header>
                <main id="igr-main" className="igr:relative igr:min-h-0 igr:flex-1 igr:overflow-y-auto">
                    {children(base)}
                </main>
            </div>
        </WorkspaceContext.Provider>
    );
}
