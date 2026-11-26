import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { Workspace } from '@/api/types';
import { CommandPalette } from '@/components/layout/CommandPalette';
import { Sidebar } from '@/components/layout/Sidebar';
import { TooltipProvider } from '@/components/ui/tooltip';
import { WorkspaceContext } from '@/hooks/useWorkspace';
import i18n from '@/lib/i18n';
import texts from '@/locales/es-CO/assistant.json';
import { useUi } from '@/stores/ui';
import { AssistantPanel } from './AssistantPanel';
import { ASSISTANT_PAGES, SUGGESTION_KEYS, historyFor, pageFromPath, useAssistant } from './model';
import { suggestionKey } from './suggestions';

const workspace = {
    id: 'ws_1',
    name: 'Café del Eje',
    slug: 'cafe',
    account_id: 'acc_1',
    country: 'CO',
    timezone: 'America/Bogota',
    currency: 'COP',
    locale: 'es-CO',
    settings: {},
    status: 'active',
    role: 'owner',
    abilities: ['viewWorkspace', 'viewDashboards', 'viewAssignedContacts', 'viewAllContacts', 'useAi'],
    requires_two_factor: false,
} as unknown as Workspace;

const me = {
    user: { id: 'usr_1', name: 'Laura', email: 'laura@cafe.test', locale: 'es-CO', two_factor_enabled: true, two_factor_pending: false, is_platform_admin: true },
    workspaces: [workspace],
    require_two_factor: false,
};

function wrap(node: React.ReactNode, path = '/w/ws_1/contactos/ct_01J000000000000000000000AB', ws: Workspace = workspace) {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    return (
        <QueryClientProvider client={client}>
            <TooltipProvider>
                <MemoryRouter initialEntries={[path]}>
                    <WorkspaceContext.Provider value={{ workspace: ws, me: { ...me, workspaces: [ws] } }}>{node}</WorkspaceContext.Provider>
                </MemoryRouter>
            </TooltipProvider>
        </QueryClientProvider>
    );
}

function answer(patch: Record<string, unknown> = {}) {
    return {
        id: 'adq_01J000000000000000000000AA',
        question: '¿Qué le ofrezco?',
        answer: 'Lleva 4 pedidos: ofrécele tu programa de puntos.',
        status: 'answered',
        query: null,
        result: null,
        source: 'ai',
        saved_as: null,
        context: 'contact',
        actions: [{ kind: 'page', prompt: null, page: 'campaigns' }],
        fallback_reason: null,
        created_at: null,
        ...patch,
    };
}

function json(body: unknown, status = 201) {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

beforeEach(() => {
    void i18n.changeLanguage('es-CO');
    document.cookie = 'XSRF-TOKEN=prueba';
    useAssistant.setState({ open: false, pending: null });
    useUi.setState({ sidebarCollapsed: false, commandOpen: false });
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
    Element.prototype.scrollIntoView = vi.fn();
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('Pregúntale a la IA: lógica', () => {
    it('sabe de qué pantalla se habla por la URL', () => {
        const base = '/w/ws_1';
        expect(pageFromPath('/w/ws_1', base)).toEqual({ kind: 'home', id: null });
        expect(pageFromPath('/w/ws_1/contactos/ct_01J000000000000000000000AB', base)).toEqual({ kind: 'contact', id: 'ct_01J000000000000000000000AB' });
        expect(pageFromPath('/w/ws_1/segmentos/sg_01J000000000000000000000AB/editar', base)).toEqual({ kind: 'segment', id: 'sg_01J000000000000000000000AB' });
        expect(pageFromPath('/w/ws_1/bandeja/cnv_01J000000000000000000000AB', base)).toEqual({ kind: 'conversation', id: 'cnv_01J000000000000000000000AB' });
        expect(pageFromPath('/w/ws_1/campanas/nueva', base)).toEqual({ kind: 'other', id: null });
        expect(pageFromPath('/w/ws_1/leads', base).kind).toBe('leads');
        expect(pageFromPath('/w/ws_1/analitica/ventas', base).kind).toBe('results');
    });

    it('manda solo lo último de la conversación con respuesta', () => {
        const turns = [
            { question: 'a', answer: 'uno', status: 'answered' },
            { question: 'b', answer: null, status: 'failed' },
            { question: 'c', answer: 'dos', status: 'answered' },
            { question: 'd', answer: 'tres', status: 'answered' },
            { question: 'e', answer: 'cuatro', status: 'answered' },
        ];
        expect(historyFor(turns).map((h) => h.question)).toEqual(['c', 'd', 'e']);
    });

    it('tiene texto para cada pantalla, cada pregunta sugerida y cada destino', () => {
        const a = texts.assistant as unknown as {
            context: Record<string, string>;
            suggestions: Record<string, Record<string, string>>;
            suggestionsServices: Record<string, Record<string, string>>;
            pages: Record<string, string>;
        };
        for (const [kind, keys] of Object.entries(SUGGESTION_KEYS)) {
            expect(a.context[kind]).toBeTypeOf('string');
            for (const key of keys) {
                expect(a.suggestions[kind]?.[key]).toBeTypeOf('string');
                // Sin tienda: las mismas preguntas, sin ventas ni productos.
                expect(a.suggestionsServices[kind]?.[key]).toBeTypeOf('string');
                expect(a.suggestionsServices[kind]?.[key]).not.toMatch(/product|vend|compr/i);
            }
        }
        expect(suggestionKey('other', 'best', true)).toBe('assistant.suggestions.other.best');
        expect(suggestionKey('other', 'best', false)).toBe('assistant.suggestionsServices.other.best');
        for (const page of Object.keys(ASSISTANT_PAGES)) expect(a.pages[page]).toBeTypeOf('string');
    });
});

describe('Pregúntale a la IA: pantalla', () => {
    it('sugiere preguntas de la pantalla, manda la pantalla y la conversación, y ofrece el paso como enlace', async () => {
        const fetchMock = vi.fn().mockResolvedValueOnce(json(answer())).mockResolvedValueOnce(json(answer({ answer: 'Sí, está activo.', actions: [] })));
        vi.stubGlobal('fetch', fetchMock);
        useAssistant.setState({ open: true });
        render(wrap(<AssistantPanel />));

        expect(screen.getByText(/Estás viendo un cliente/)).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: /Qué le ofrezco a este cliente/ }));
        await waitFor(() => expect(screen.getByText('Lleva 4 pedidos: ofrécele tu programa de puntos.')).toBeTruthy());
        const body = JSON.parse((fetchMock.mock.calls[0]?.[1] as RequestInit).body as string) as { page: unknown; history: unknown[] };
        expect(String(fetchMock.mock.calls[0]?.[0])).toContain('/api/w/ws_1/ai/assistant');
        expect(body.page).toEqual({ kind: 'contact', id: 'ct_01J000000000000000000000AB' });
        expect(body.history).toEqual([]);
        expect(screen.getByRole('link', { name: /Ir a Campañas/ }).getAttribute('href')).toBe('/w/ws_1/campanas');

        // La segunda pregunta lleva la primera como conversación.
        fireEvent.change(screen.getByLabelText('Tu pregunta'), { target: { value: '¿Y sigue activo?' } });
        fireEvent.keyDown(screen.getByLabelText('Tu pregunta'), { key: 'Enter' });
        await waitFor(() => expect(screen.getByText('Sí, está activo.')).toBeTruthy());
        const second = JSON.parse((fetchMock.mock.calls[1]?.[1] as RequestInit).body as string) as { history: { question: string }[] };
        expect(second.history.map((h) => h.question)).toEqual(['¿Qué le ofrezco a este cliente?']);
    });

    it('con la IA apagada lleva al administrador de la plataforma a encenderla', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValueOnce(json(answer({ status: 'failed', answer: 'La IA está apagada.', fallback_reason: 'disabled', actions: [] }))));
        useAssistant.setState({ open: true, pending: '¿Cómo voy?' });
        render(wrap(<AssistantPanel />, '/w/ws_1'));
        await waitFor(() => expect(screen.getByText('La IA está apagada', { selector: '*' })).toBeTruthy());
        expect(screen.getByRole('link', { name: /Encender la IA/ }).getAttribute('href')).toBe('/w/ws_1/plataforma?pestana=ajustes&seccion=ai');
    });

    it('la barra lateral y ⌘K abren el asistente, solo para quien usa la IA', () => {
        render(wrap(<Sidebar />, '/w/ws_1'));
        fireEvent.click(screen.getByRole('button', { name: /Pregúntale a la IA/ }));
        expect(useAssistant.getState().open).toBe(true);
        cleanup();

        useAssistant.setState({ open: false, pending: null });
        useUi.setState({ commandOpen: true });
        render(wrap(<CommandPalette />, '/w/ws_1'));
        fireEvent.change(screen.getByRole('combobox'), { target: { value: 'ventas de ayer' } });
        act(() => {
            fireEvent.click(screen.getByText('Preguntar a la IA: «ventas de ayer»'));
        });
        expect(useAssistant.getState()).toMatchObject({ open: true, pending: 'ventas de ayer' });
        cleanup();

        const noAi = { ...workspace, abilities: ['viewWorkspace', 'viewDashboards'] } as unknown as Workspace;
        render(wrap(<Sidebar />, '/w/ws_1', noAi));
        expect(screen.queryByRole('button', { name: /Pregúntale a la IA/ })).toBeNull();
    });
});
