import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useCallback } from 'react';
import { MemoryRouter } from 'react-router';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { Me, Workspace } from '@/api/types';
import { TooltipProvider } from '@/components/ui/tooltip';
import { useShortcuts } from '@/hooks/useShortcuts';
import { WorkspaceContext } from '@/hooks/useWorkspace';
import { useUi } from '@/stores/ui';
import { LAYOUT_CLASS, pageLayout } from './pageLayout';
import { CommandPalette } from './CommandPalette';
import { Sidebar } from './Sidebar';

const workspace: Workspace = {
    id: 'ws_1',
    name: 'Café del Eje',
    slug: 'cafe-del-eje',
    account_id: 'acc_1',
    country: 'CO',
    timezone: 'America/Bogota',
    currency: 'COP',
    locale: 'es-CO',
    industry: null,
    legal_name: null,
    privacy_policy_url: null,
    settings: {},
    status: 'active',
    role: 'owner',
    abilities: ['viewWorkspace', 'viewDashboards', 'viewAssignedContacts', 'viewAllContacts', 'manageMarketing', 'useInbox'],
    requires_two_factor: false,
};

const me: Me = {
    user: { id: 'usr_1', name: 'Laura Gómez', email: 'laura@cafedeleje.co', locale: 'es-CO', two_factor_enabled: true, two_factor_pending: false, is_platform_admin: false },
    workspaces: [workspace],
    require_two_factor: false,
};

function renderSidebar(mode: 'fixed' | 'drawer' = 'fixed', ws: Workspace = workspace, path = '/w/ws_1/contactos') {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(
        <QueryClientProvider client={client}>
            <TooltipProvider>
                <MemoryRouter initialEntries={[path]}>
                    <WorkspaceContext.Provider value={{ workspace: ws, me: { ...me, workspaces: [ws] } }}>
                        <Sidebar mode={mode} />
                    </WorkspaceContext.Provider>
                </MemoryRouter>
            </TooltipProvider>
        </QueryClientProvider>,
    );
}

beforeEach(() => {
    useUi.setState({ sidebarCollapsed: false });
    // jsdom no trae ResizeObserver y el tooltip (floating-ui) lo usa para ubicarse.
    if (!('ResizeObserver' in globalThis)) {
        vi.stubGlobal(
            'ResizeObserver',
            class {
                observe() {}
                unobserve() {}
                disconnect() {}
            },
        );
    }
});
afterEach(cleanup);

describe('menú por secciones (rediseño v2 §3)', () => {
    it('muestra las secciones con nombre de resultado y despliega la subnavegación de la actual', () => {
        renderSidebar();
        const nav = screen.getByRole('navigation', { name: 'Navegación principal' });
        const names = [...nav.querySelectorAll(':scope > ul > li > a')].map((a) => a.textContent);
        expect(names).toEqual(['Inicio', 'Estrategias', 'Clientes', 'Conversaciones', 'Mensajes', 'En tu web', 'Resultados', 'Configuración']);
        // /contactos es parte de Clientes: la sección lleva a su página de entrada y se despliega con sus pantallas.
        expect(within(nav).getByRole('link', { name: 'Clientes' })).toHaveAttribute('href', '/w/ws_1/clientes');
        const sub = within(nav).getByRole('list', { name: 'Secciones de Clientes' });
        expect(within(sub).getByRole('link', { name: 'Personas' })).toHaveAttribute('aria-current', 'page');
        expect(within(sub).getByRole('link', { name: 'Grupos de clientes' })).toHaveAttribute('href', '/w/ws_1/segmentos');
        expect(within(nav).queryByRole('link', { name: 'Automatizaciones' })).not.toBeInTheDocument();
        expect(within(nav).getByRole('link', { name: 'Inicio' })).toHaveAttribute('href', '/w/ws_1');
    });

    it('en la página de entrada, la sección es la página actual', () => {
        renderSidebar('fixed', workspace, '/w/ws_1/clientes');
        expect(screen.getByRole('link', { name: 'Clientes' })).toHaveAttribute('aria-current', 'page');
    });

    it('una sección que todavía no puede servir lleva un candado con su motivo para el lector de pantalla', () => {
        renderSidebar('fixed', { ...workspace, abilities: [...workspace.abilities, 'useInbox'], connections: { site: 'none', channels: [] } });
        const nav = screen.getByRole('navigation', { name: 'Navegación principal' });
        expect(within(nav).getByRole('link', { name: /^Conversaciones ?\(Necesita un canal conectado\)$/ })).toBeInTheDocument();
        expect(within(nav).getByRole('link', { name: /^Resultados ?\(Necesita tu web conectada\)$/ })).toBeInTheDocument();
        expect(within(nav).getByRole('link', { name: 'Clientes' })).toBeInTheDocument();
    });

    it('Agencia y Plataforma van aparte; la guía de estilo solo para quien administra la plataforma', () => {
        const admin: Workspace = { ...workspace, abilities: [...workspace.abilities, 'viewAgency', 'platformAdmin'] };
        renderSidebar('fixed', admin);
        const group = screen.getByRole('group', { name: 'Administración' });
        expect(
            within(group)
                .getAllByRole('link')
                .map((a) => a.textContent),
        ).toEqual(['Agencia', 'Plataforma']);
    });
});

describe('barra lateral contraíble (U9)', () => {
    it('se contrae a íconos con su nombre accesible, lo recuerda y vuelve a expandirse', async () => {
        const user = userEvent.setup();
        renderSidebar();
        const toggle = screen.getByRole('button', { name: 'Contraer la barra lateral' });
        expect(toggle).toHaveAttribute('aria-expanded', 'true');

        await user.click(toggle);
        expect(useUi.getState().sidebarCollapsed).toBe(true);
        const expand = screen.getByRole('button', { name: 'Expandir la barra lateral' });
        expect(expand).toHaveAttribute('aria-expanded', 'false');
        expect(document.getElementById('igr-sidebar')).toHaveClass('igr:w-14');
        // Contraída, cada sección sigue siendo un enlace con nombre y sin la subnavegación (va arriba del contenido).
        expect(screen.getByRole('link', { name: 'Clientes' })).toHaveAttribute('aria-current', 'page');
        expect(screen.queryByRole('link', { name: 'Personas' })).not.toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Inicio' })).toHaveAttribute('href', '/w/ws_1');
        expect(screen.getByRole('button', { name: 'Buscar o ir a…' })).toBeInTheDocument();

        await user.click(expand);
        expect(useUi.getState().sidebarCollapsed).toBe(false);
        expect(document.getElementById('igr-sidebar')).toHaveClass('igr:w-60');
    });

    it('con la barra contraída, el nombre aparece en un tooltip al enfocar con el teclado', async () => {
        useUi.setState({ sidebarCollapsed: true });
        const user = userEvent.setup();
        renderSidebar();
        await user.tab();
        await user.tab();
        expect(document.activeElement).toHaveAccessibleName(/Café del Eje/);
        expect(await screen.findByRole('tooltip')).toHaveTextContent('Café del Eje');
    });

    it('en el panel del celular siempre va expandida y sin el botón de contraer', () => {
        useUi.setState({ sidebarCollapsed: true });
        renderSidebar('drawer');
        expect(screen.queryByRole('button', { name: /barra lateral/i })).not.toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Clientes' })).toHaveTextContent('Clientes');
        expect(document.querySelector('aside')).toHaveClass('igr:w-60');
    });
});

describe('⌘K con las secciones', () => {
    it('agrupa por sección y encuentra una pantalla por su nombre de antes', async () => {
        Element.prototype.scrollIntoView = vi.fn();
        useUi.setState({ commandOpen: true });
        const user = userEvent.setup();
        const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
        render(
            <QueryClientProvider client={client}>
                <MemoryRouter initialEntries={['/w/ws_1']}>
                    <WorkspaceContext.Provider value={{ workspace, me }}>
                        <CommandPalette />
                    </WorkspaceContext.Provider>
                </MemoryRouter>
            </QueryClientProvider>,
        );
        expect(screen.getByRole('option', { name: /Mensajes · resumen/ })).toBeInTheDocument();
        await user.type(screen.getByRole('combobox'), 'flujos');
        expect(screen.getByRole('option', { name: /Automatizaciones/ })).toBeInTheDocument();
        expect(screen.queryByRole('option', { name: /Personas/ })).not.toBeInTheDocument();
        useUi.setState({ commandOpen: false });
    });
});

function ShortcutsProbe({ onToggle }: { onToggle: () => void }) {
    const noop = useCallback(() => undefined, []);
    useShortcuts({ onPalette: noop, onGo: noop, onToggleSidebar: onToggle });
    return <input aria-label="campo" />;
}

describe('⌘B / Ctrl+B', () => {
    afterEach(() => {
        cleanup();
    });

    it('contrae la barra fuera de los campos de texto, no dentro (ahí es negrita)', () => {
        let calls = 0;
        render(<ShortcutsProbe onToggle={() => calls++} />);
        act(() => {
            fireEvent.keyDown(window, { key: 'b', ctrlKey: true });
            fireEvent.keyDown(window, { key: 'B', metaKey: true });
        });
        expect(calls).toBe(2);
        fireEvent.keyDown(screen.getByLabelText('campo'), { key: 'b', ctrlKey: true });
        fireEvent.keyDown(window, { key: 'b' });
        fireEvent.keyDown(window, { key: 'b', ctrlKey: true, shiftKey: true });
        expect(calls).toBe(2);
    });
});

describe('pageLayout', () => {
    it('la ruta más profunda decide: lienzo, llenar el alto o la columna normal', () => {
        expect(pageLayout([undefined, null])).toBe('default');
        expect(pageLayout([undefined, { fullBleed: true }])).toBe('fullBleed');
        expect(pageLayout([{ fullBleed: true }, { fill: true }])).toBe('fill');
        expect(pageLayout([{ fill: true }, undefined])).toBe('fill');
        // «fill» llena el alto y en el celular va de borde a borde (sin márgenes por debajo de md).
        expect(LAYOUT_CLASS.fill).toContain('igr:h-full');
        expect(LAYOUT_CLASS.fill).not.toMatch(/(^| )igr:p[xy]?-/);
    });
});
