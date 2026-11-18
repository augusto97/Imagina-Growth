import { createBrowserRouter, Navigate } from 'react-router';
import { NotFoundPage, RequireAuth, RootRedirect, WorkspaceLayout } from '@/components/layout/guards';
import { LoginPage } from '@/features/auth/LoginPage';
import { moduleWorkspaceRoutes } from '@/app/modules';

/*
 * Rutas de la SPA. Las públicas van en español; /connect la fija el plugin
 * (api-connector.md §3.1). Cada pantalla se carga bajo demanda.
 */
export const router = createBrowserRouter([
    { path: '/login', element: <LoginPage /> },
    { path: '/registro', lazy: async () => ({ Component: (await import('@/features/auth/RegisterPage')).RegisterPage }) },
    { path: '/recuperar', lazy: async () => ({ Component: (await import('@/features/auth/PasswordResetPages')).ForgotPasswordPage }) },
    { path: '/restablecer/:token', lazy: async () => ({ Component: (await import('@/features/auth/PasswordResetPages')).ResetPasswordPage }) },
    { path: '/verificacion', lazy: async () => ({ Component: (await import('@/features/auth/TwoFactorChallengePage')).TwoFactorChallengePage }) },
    { path: '/invitacion/:token', lazy: async () => ({ Component: (await import('@/features/team/InvitationPage')).InvitationPage }) },
    {
        element: <RequireAuth />,
        children: [
            { path: '/', element: <RootRedirect /> },
            { path: '/connect', lazy: async () => ({ Component: (await import('@/features/connect/ConnectPage')).ConnectPage }) },
            { path: '/seguridad', lazy: async () => ({ Component: (await import('@/features/security/SecurityPage')).SecurityPage }) },
            { path: '/onboarding', lazy: async () => ({ Component: (await import('@/features/onboarding/CreateWorkspacePage')).CreateWorkspacePage }) },
            {
                path: '/w/:workspaceId',
                element: <WorkspaceLayout />,
                children: [
                    { index: true, lazy: async () => ({ Component: (await import('@/features/home/HomePage')).HomePage }) },
                    { path: 'sitios', lazy: async () => ({ Component: (await import('@/features/sites/SitesPage')).SitesPage }) },
                    { path: 'sitios/:siteId', lazy: async () => ({ Component: (await import('@/features/sites/SiteDetailPage')).SiteDetailPage }) },
                    { path: 'equipo', lazy: async () => ({ Component: (await import('@/features/team/TeamPage')).TeamPage }) },
                    { path: 'ajustes', lazy: async () => ({ Component: (await import('@/features/settings/WorkspaceSettingsPage')).WorkspaceSettingsPage }) },
                    { path: 'auditoria', lazy: async () => ({ Component: (await import('@/features/audit/AuditPage')).AuditPage }) },
                    ...moduleWorkspaceRoutes,
                    { path: '*', element: <NotFoundPage /> },
                ],
            },
        ],
    },
    { path: '/home', element: <Navigate to="/" replace /> },
    { path: '*', element: <NotFoundPage /> },
]);
