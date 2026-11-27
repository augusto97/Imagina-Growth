import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { MemoryRouter } from 'react-router';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { Workspace } from '@/api/types';
import { TooltipProvider } from '@/components/ui/tooltip';
import { NoticeSetupCard } from '@/features/agenda/NoticeSetupCard';
import { WorkspaceContext } from '@/hooks/useWorkspace';
import i18n from '@/lib/i18n';
import texts from '@/locales/es-CO/notices.json';
import type { BookingNoticeSetup, NoticeLogRow, NoticePreferencesData } from './api';
import { NoticeBell } from './NoticeBell';
import { NoticePreferencesForm } from './NoticeCenter';
import { NoticeLogList } from './NoticeLogList';
import { customerReached, noticeTone, reasonFix, splitByAudience, unreadBadge, useNoticeCenter } from './model';

const workspace = {
    id: 'ws_1',
    name: 'Clínica Sonrisa',
    slug: 'clinica',
    account_id: 'acc_1',
    country: 'CO',
    timezone: 'America/Bogota',
    currency: 'COP',
    locale: 'es-CO',
    settings: {},
    status: 'active',
    role: 'owner',
    abilities: ['viewWorkspace', 'useLeads'],
    requires_two_factor: false,
} as unknown as Workspace;

const me = {
    user: { id: 'usr_1', name: 'Dueña', email: 'duena@clinica.co', locale: 'es-CO', two_factor_enabled: true, two_factor_pending: false, is_platform_admin: false },
    workspaces: [workspace],
    require_two_factor: false,
};

function wrap(node: ReactNode, platformAdmin = false) {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    const who = { ...me, user: { ...me.user, is_platform_admin: platformAdmin } };
    return (
        <QueryClientProvider client={client}>
            <TooltipProvider>
                <MemoryRouter initialEntries={['/w/ws_1/agenda']}>
                    <WorkspaceContext.Provider value={{ workspace, me: who }}>{node}</WorkspaceContext.Provider>
                </MemoryRouter>
            </TooltipProvider>
        </QueryClientProvider>
    );
}

function json(body: unknown, status = 200) {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

function row(patch: Partial<NoticeLogRow>): NoticeLogRow {
    return {
        id: 'nlg_1',
        audience: 'customer',
        event: 'confirmation',
        channel: 'email',
        recipient: 'a**@c*****.co',
        status: 'sent',
        reason: null,
        detail: null,
        message_status: 'sent',
        created_at: '2026-11-27T15:00:00Z',
        ...patch,
    };
}

beforeEach(() => {
    void i18n.changeLanguage('es-CO');
    document.cookie = 'XSRF-TOKEN=prueba';
    useNoticeCenter.setState({ open: false });
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('Avisos: lógica', () => {
    it('el globo de la campana dice el número hasta 9 y «9+» después', () => {
        expect(unreadBadge(0)).toBeNull();
        expect(unreadBadge(undefined)).toBeNull();
        expect(unreadBadge(3)).toBe('3');
        expect(unreadBadge(12)).toBe('9+');
    });

    it('cada estado tiene su color y cada motivo su arreglo', () => {
        expect(noticeTone('sent')).toBe('success');
        expect(noticeTone('queued')).toBe('info');
        expect(noticeTone('skipped')).toBe('warning');
        expect(noticeTone('failed')).toBe('destructive');
        expect(reasonFix('no_whatsapp_channel', 'whatsapp', false)).toBe('conexiones?tarjeta=whatsapp');
        expect(reasonFix('channel_unavailable', 'email', false)).toBe('conexiones?tarjeta=email');
        expect(reasonFix('no_email', 'email', false)).toBe('agenda?pestana=pagina');
        // El correo de la plataforma solo lo arregla quien la administra.
        expect(reasonFix('platform_mail_off', 'email', false)).toBeNull();
        expect(reasonFix('platform_mail_off', 'email', true)).toBe('plataforma?pestana=ajustes&seccion=mail');
        expect(reasonFix('no_phone', 'whatsapp', true)).toBeNull();
    });

    it('separa lo del cliente de lo del equipo y sabe si al cliente le llegó algo', () => {
        const rows = [row({ status: 'skipped', reason: 'no_email' }), row({ id: 'nlg_2', audience: 'team', channel: 'in_app' })];
        expect(splitByAudience(rows).customer).toHaveLength(1);
        expect(customerReached(rows)).toBe(false);
        expect(customerReached([row({})])).toBe(true);
        expect(customerReached([row({ channel: 'whatsapp', reason: 'in_chat' })])).toBe(false);
    });

    it('tiene texto para cada motivo que manda el hub', () => {
        const reasons = (texts.notices.log.reason as Record<string, string>);
        for (const key of ['in_chat', 'no_phone', 'no_whatsapp_channel', 'no_whatsapp_template', 'whatsapp_not_sent', 'no_email', 'not_sent', 'channel_unavailable',
            'plan_no_platform_email', 'platform_mail_off', 'platform_mail', 'no_channel', 'default']) {
            expect(reasons[key]).toBeTypeOf('string');
        }
    });
});

describe('Avisos: pantallas', () => {
    it('la sección «Avisos» dice qué no salió, por qué y dónde se arregla', () => {
        render(
            wrap(
                <NoticeLogList
                    rows={[
                        row({ channel: 'whatsapp', status: 'skipped', reason: 'no_whatsapp_channel', recipient: '+57*****567' }),
                        row({ id: 'nlg_2', status: 'skipped', reason: 'no_email', recipient: null }),
                        row({ id: 'nlg_3', audience: 'team', channel: 'in_app', recipient: 'Dueña', event: 'booked' }),
                    ]}
                />,
            ),
        );
        expect(screen.getByText('Tu cliente no recibió ningún aviso de esta cita.')).toBeTruthy();
        expect(screen.getByText('A tu cliente')).toBeTruthy();
        expect(screen.getByText('A tu equipo')).toBeTruthy();
        expect(screen.getByText('No hay un WhatsApp conectado.')).toBeTruthy();
        expect(screen.getByText('El cliente no dejó su correo.')).toBeTruthy();
        const fixes = screen.getAllByRole('link', { name: /Arreglarlo/ }).map((a) => a.getAttribute('href'));
        expect(fixes).toEqual(['/w/ws_1/conexiones?tarjeta=whatsapp', '/w/ws_1/agenda?pestana=pagina']);
        expect(screen.getByText(/Nueva cita/)).toBeTruthy();
    });

    it('la campana muestra los no leídos y abre el centro de avisos', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(json({ unread: 3 })));
        render(wrap(<NoticeBell variant="mobile" />));
        const bell = await screen.findByRole('button', { name: 'Avisos, 3 sin leer' });
        expect(bell.textContent).toContain('3');
        fireEvent.click(bell);
        expect(useNoticeCenter.getState().open).toBe(true);
    });

    it('«Mis avisos»: sin permiso de elegir no ofrece «de todo el negocio» y guarda los canales', async () => {
        const prefs: NoticePreferencesData = {
            settings: {
                appointments: { in_app: true, email: true, push: true, whatsapp: false, scope: 'mine' },
                leads: { in_app: true, push: false, scope: 'mine' },
                conversations: { in_app: true },
                connections: { in_app: false },
            },
            channels: { appointments: ['in_app', 'email', 'push', 'whatsapp'], leads: ['in_app', 'push'], conversations: ['in_app'], connections: ['in_app'] },
            can_choose_scope: false,
            phone: null,
            phone_hint: '+57*****233',
            manager: false,
        };
        const fetchMock = vi.fn().mockResolvedValueOnce(json(prefs)).mockResolvedValueOnce(json(prefs));
        vi.stubGlobal('fetch', fetchMock);
        render(wrap(<NoticePreferencesForm />));

        await screen.findByText('Citas');
        expect(screen.queryByText('De todo el negocio')).toBeNull();
        expect(screen.queryByText('Conexiones')).toBeNull();
        fireEvent.click(screen.getAllByRole('switch', { name: 'WhatsApp' })[0] as HTMLElement);
        expect(screen.getByText(/Si lo dejas vacío usamos el de tu ficha de vendedor/)).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Guardar mis avisos' }));
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
        const body = JSON.parse((fetchMock.mock.calls[1]?.[1] as RequestInit).body as string) as { settings: NoticePreferencesData['settings']; phone: string | null };
        expect(body.settings.appointments.whatsapp).toBe(true);
        expect(body.phone).toBeNull();
    });

    it('«Así avisamos» avisa si al cliente no le llega nada y ofrece arreglarlo', async () => {
        const setup: BookingNoticeSetup = {
            customer: { whatsapp: 'none', whatsapp_number: null, email: 'platform', email_from: 'Clínica <no-responder@imagina.test>', reply_to: 'duena@clinica.co', ask_email: true, require_email: false, reminders: [24, 2] },
            team: { platform_mail: true, push: false, people: [{ name: 'Dueña', scope: 'all', channels: ['in_app', 'email'] }] },
            fixes: ['connect_whatsapp', 'require_email'],
        };
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(json(setup)));
        const requireEmail = vi.fn();
        render(wrap(<NoticeSetupCard onRequireEmail={requireEmail} onChooseTemplate={vi.fn()} saving={false} />));

        await screen.findByText('Así avisamos');
        expect(screen.getByText(/las respuestas|si el cliente responde, llega a duena@clinica.co/)).toBeTruthy();
        expect(screen.getByText('WhatsApp: no hay un número conectado.')).toBeTruthy();
        expect(screen.getByText(/24 y 2 horas antes/)).toBeTruthy();
        expect(screen.getByText('Dueña')).toBeTruthy();
        expect(screen.getByRole('link', { name: /Conectar WhatsApp/ }).getAttribute('href')).toBe('/w/ws_1/conexiones?tarjeta=whatsapp');
        fireEvent.click(screen.getByRole('button', { name: 'Pedir el correo siempre' }));
        expect(requireEmail).toHaveBeenCalled();
        expect(screen.queryByText(/Hoy tus clientes no reciben/)).toBeNull();
    });

    it('«Así avisamos» dice en rojo cuando no hay por dónde avisar', async () => {
        const setup: BookingNoticeSetup = {
            customer: { whatsapp: 'none', whatsapp_number: null, email: 'platform_off', email_from: null, reply_to: null, ask_email: true, require_email: false, reminders: [] },
            team: { platform_mail: false, push: false, people: [] },
            fixes: ['nothing_reaches', 'connect_whatsapp', 'platform_mail'],
        };
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(json(setup)));
        render(wrap(<NoticeSetupCard onRequireEmail={vi.fn()} onChooseTemplate={vi.fn()} saving={false} />));

        await screen.findByText(/Hoy tus clientes no reciben ninguna confirmación/);
        expect(screen.getByText(/Nadie del equipo recibe avisos/)).toBeTruthy();
        // Configurar el correo de la plataforma solo se le ofrece a quien la administra.
        expect(screen.queryByRole('link', { name: /Configurar el correo de la plataforma/ })).toBeNull();
    });
});
