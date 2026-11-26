import { createBrowserRouter, Navigate, type RouteObject } from 'react-router';
import { FullPageSkeleton, NotFoundPage, RequireAuth, RootRedirect, WorkspaceLayout } from '@/components/layout/guards';
import { moduleWorkspaceRoutes, withFeatureTexts } from '@/app/modules';

/*
 * Rutas de la SPA. Las públicas van en español; /connect la fija el plugin
 * (api-connector.md §3.1). Cada pantalla se carga bajo demanda, también el ingreso
 * (zod y react-hook-form no entran en el arranque de quien ya tiene sesión), y espera
 * sus textos junto con su chunk (app/routeTexts.ts). Mientras carga la primera, el
 * esqueleto de la página (`HydrateFallback`).
 */
const publicRoutes: RouteObject[] = [
    { path: '/login', lazy: async () => ({ Component: (await import('@/features/auth/LoginPage')).LoginPage }) },
    { path: '/registro', lazy: async () => ({ Component: (await import('@/features/auth/RegisterPage')).RegisterPage }) },
    { path: '/recuperar', lazy: async () => ({ Component: (await import('@/features/auth/PasswordResetPages')).ForgotPasswordPage }) },
    { path: '/restablecer/:token', lazy: async () => ({ Component: (await import('@/features/auth/PasswordResetPages')).ResetPasswordPage }) },
    { path: '/verificacion', lazy: async () => ({ Component: (await import('@/features/auth/TwoFactorChallengePage')).TwoFactorChallengePage }) },
];

export const router = createBrowserRouter([
    {
        HydrateFallback: FullPageSkeleton,
        children: [
            ...withFeatureTexts('auth', publicRoutes),
            ...withFeatureTexts('team', [{ path: '/invitacion/:token', lazy: async () => ({ Component: (await import('@/features/team/InvitationPage')).InvitationPage }) }]),
            {
                element: <RequireAuth />,
                children: [
                    { path: '/', element: <RootRedirect /> },
                    ...withFeatureTexts('connect', [{ path: '/connect', lazy: async () => ({ Component: (await import('@/features/connect/ConnectPage')).ConnectPage }) }]),
                    ...withFeatureTexts('security', [{ path: '/seguridad', lazy: async () => ({ Component: (await import('@/features/security/SecurityPage')).SecurityPage }) }]),
                    ...withFeatureTexts('onboarding', [
                        { path: '/onboarding', lazy: async () => ({ Component: (await import('@/features/onboarding/CreateWorkspacePage')).CreateWorkspacePage }) },
                        // Primera vez (rediseño v2 §4): la bienvenida y el asistente, a pantalla completa (fuera del menú).
                        { path: '/w/:workspaceId/bienvenida', lazy: async () => ({ Component: (await import('@/features/onboarding/WelcomePage')).WelcomePage }) },
                        { path: '/w/:workspaceId/configurar', lazy: async () => ({ Component: (await import('@/features/onboarding/SetupWizardPage')).SetupWizardPage }) },
                    ]),
                    {
                        path: '/w/:workspaceId',
                        element: <WorkspaceLayout />,
                        children: [
                            ...withFeatureTexts('home', [{ index: true, lazy: async () => ({ Component: (await import('@/features/home/HomePage')).HomePage }) }]),
                            ...withFeatureTexts('sites', [
                                { path: 'sitios', lazy: async () => ({ Component: (await import('@/features/sites/SitesPage')).SitesPage }) },
                                { path: 'sitios/:siteId', lazy: async () => ({ Component: (await import('@/features/sites/SiteDetailPage')).SiteDetailPage }) },
                            ]),
                            ...withFeatureTexts('team', [{ path: 'equipo', lazy: async () => ({ Component: (await import('@/features/team/TeamPage')).TeamPage }) }]),
                            ...withFeatureTexts('settings', [
                                { path: 'ajustes', lazy: async () => ({ Component: (await import('@/features/settings/WorkspaceSettingsPage')).WorkspaceSettingsPage }) },
                            ]),
                            ...withFeatureTexts('audit', [{ path: 'auditoria', lazy: async () => ({ Component: (await import('@/features/audit/AuditPage')).AuditPage }) }]),
                            ...moduleWorkspaceRoutes,
                            { path: '*', element: <NotFoundPage /> },
                        ],
                    },
                ],
            },
            { path: '/home', element: <Navigate to="/" replace /> },
            { path: '*', element: <NotFoundPage /> },
        ],
    },
]);
