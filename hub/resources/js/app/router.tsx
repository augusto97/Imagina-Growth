import { createBrowserRouter, Navigate } from 'react-router';
import { NotFoundPage, RequireAuth, RootRedirect, WorkspaceLayout } from '@/components/layout/guards';
import { LoginPage } from '@/features/auth/LoginPage';
import { ForgotPasswordPage, ResetPasswordPage } from '@/features/auth/PasswordResetPages';
import { RegisterPage } from '@/features/auth/RegisterPage';
import { TwoFactorChallengePage } from '@/features/auth/TwoFactorChallengePage';
import { AuditPage } from '@/features/audit/AuditPage';
import { ConnectPage } from '@/features/connect/ConnectPage';
import { ContactsPage } from '@/features/contacts/ContactsPage';
import { HomePage } from '@/features/home/HomePage';
import { CreateWorkspacePage } from '@/features/onboarding/CreateWorkspacePage';
import { SecurityPage } from '@/features/security/SecurityPage';
import { WorkspaceSettingsPage } from '@/features/settings/WorkspaceSettingsPage';
import { SiteDetailPage } from '@/features/sites/SiteDetailPage';
import { SitesPage } from '@/features/sites/SitesPage';
import { InvitationPage } from '@/features/team/InvitationPage';
import { TeamPage } from '@/features/team/TeamPage';

/** Rutas de la SPA. Las rutas públicas en español; /connect la fija el plugin (api-connector.md §3.1). */
export const router = createBrowserRouter([
    { path: '/login', element: <LoginPage /> },
    { path: '/registro', element: <RegisterPage /> },
    { path: '/recuperar', element: <ForgotPasswordPage /> },
    { path: '/restablecer/:token', element: <ResetPasswordPage /> },
    { path: '/verificacion', element: <TwoFactorChallengePage /> },
    { path: '/invitacion/:token', element: <InvitationPage /> },
    {
        element: <RequireAuth />,
        children: [
            { path: '/', element: <RootRedirect /> },
            { path: '/connect', element: <ConnectPage /> },
            { path: '/seguridad', element: <SecurityPage /> },
            { path: '/onboarding', element: <CreateWorkspacePage /> },
            {
                path: '/w/:workspaceId',
                element: <WorkspaceLayout />,
                children: [
                    { index: true, element: <HomePage /> },
                    { path: 'contactos', element: <ContactsPage /> },
                    { path: 'sitios', element: <SitesPage /> },
                    { path: 'sitios/:siteId', element: <SiteDetailPage /> },
                    { path: 'equipo', element: <TeamPage /> },
                    { path: 'ajustes', element: <WorkspaceSettingsPage /> },
                    { path: 'auditoria', element: <AuditPage /> },
                    { path: '*', element: <NotFoundPage /> },
                ],
            },
        ],
    },
    { path: '/home', element: <Navigate to="/" replace /> },
    { path: '*', element: <NotFoundPage /> },
]);
