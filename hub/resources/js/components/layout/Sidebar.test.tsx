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

function renderSidebar(mode: 'fixed' | 'drawer' = 'fixed') {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(
        <QueryClientProvider client={client}>
            <TooltipProvider>
                <MemoryRouter initialEntries={['/w/ws_1/contactos']}>
                    <WorkspaceContext.Provider value={{ workspace, me }}>
                        <Sidebar mode={mode} />
                    </WorkspaceContext.Provider>
                </MemoryRouter>
            </TooltipProvider>
        </QueryClientProvider>,
    );
}

beforeEach(() => {
    useUi.setState({ sidebarCollapsed: false, navCollapsed: [] });
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

describe('barra lateral contraíble (U9)', () => {
    it('se contrae a íconos con su nombre accesible, lo recuerda y vuelve a expandirse', async () => {
        const user = userEvent.setup();
        renderSidebar();
        const toggle = screen.getByRole('button', { name: 'Contraer la barra lateral' });
        expect(toggle).toHaveAttribute('aria-expanded', 'true');
        expect(screen.getByRole('button', { name: /clientes/i })).toBeInTheDocument();

        await user.click(toggle);
        expect(useUi.getState().sidebarCollapsed).toBe(true);
        const expand = screen.getByRole('button', { name: 'Expandir la barra lateral' });
        expect(expand).toHaveAttribute('aria-expanded', 'false');
        expect(document.getElementById('igr-sidebar')).toHaveClass('igr:w-14');
        // Sin los encabezados plegables: cada grupo es un grupo con nombre y sus entradas siguen siendo enlaces con nombre.
        expect(screen.queryByRole('button', { name: /clientes/i })).not.toBeInTheDocument();
        const customers = screen.getByRole('group', { name: 'Clientes' });
        expect(within(customers).getByRole('link', { name: 'Contactos' })).toHaveAttribute('aria-current', 'page');
        expect(screen.getByRole('link', { name: 'Inicio' })).toHaveAttribute('href', '/w/ws_1');
        expect(screen.getByRole('button', { name: 'Buscar o ir a…' })).toBeInTheDocument();

        await user.click(expand);
        expect(useUi.getState().sidebarCollapsed).toBe(false);
        expect(screen.getByRole('button', { name: /clientes/i })).toHaveAttribute('aria-expanded', 'true');
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

    it('los grupos plegados se respetan al expandir; contraída se ven todas las entradas', async () => {
        useUi.setState({ navCollapsed: ['sell'] });
        const user = userEvent.setup();
        renderSidebar();
        expect(screen.queryByRole('link', { name: 'Campañas' })).not.toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Contraer la barra lateral' }));
        expect(screen.getByRole('link', { name: 'Campañas' })).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Expandir la barra lateral' }));
        expect(screen.queryByRole('link', { name: 'Campañas' })).not.toBeInTheDocument();
        expect(useUi.getState().navCollapsed).toEqual(['sell']);
    });

    it('en el panel del celular siempre va expandida y sin el botón de contraer', () => {
        useUi.setState({ sidebarCollapsed: true });
        renderSidebar('drawer');
        expect(screen.queryByRole('button', { name: /barra lateral/i })).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: /clientes/i })).toBeInTheDocument();
        expect(document.querySelector('aside')).toHaveClass('igr:w-60');
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
