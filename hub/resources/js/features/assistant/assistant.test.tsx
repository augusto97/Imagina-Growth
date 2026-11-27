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
import { readdirSync, readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { SUGGESTION_KEYS, historyFor, pageFromPath, useAssistant } from './model';
import { ASSISTANT_PAGES, pagePath } from './pages';
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
        // Los pasos propuestos viajan con su respuesta: el seguimiento («¿y el segundo?») se entiende.
        expect(historyFor([{ question: 'como inicio?', answer: 'Conecta tu web.', status: 'answered', steps: [{ text: 'Conecta tu web.' }, { text: 'Conecta WhatsApp.' }] }])[0]?.answer).toBe(
            'Conecta tu web.\n- Conecta tu web.\n- Conecta WhatsApp.',
        );
    });

    it('cada pantalla a la que lleva un paso existe en el router (la lista la comparte el hub)', () => {
        const features = resolve(__dirname, '..');
        const declared = new Set<string>();
        for (const dir of readdirSync(features)) {
            let code: string;
            try {
                code = readFileSync(join(features, dir, 'routes.tsx'), 'utf8');
            } catch {
                continue;
            }
            for (const m of code.matchAll(/path:\s*'([^']+)'/g)) declared.add(m[1] ?? '');
        }
        // Las de pantalla completa (bienvenida, asistente de configuración) y algunas sueltas (equipo) están en el router de la app.
        for (const m of readFileSync(resolve(features, '..', 'app', 'router.tsx'), 'utf8').matchAll(/path:\s*'(?:\/w\/:workspaceId\/)?([^'/][^']*)'/g)) declared.add(m[1] ?? '');
        const patterns = [...declared].filter((p) => /^[a-z]/.test(p)).map((p) => new RegExp(`^${p.replace(/:[A-Za-z]+/g, '[^/]+')}$`));
        for (const [key, path] of Object.entries(ASSISTANT_PAGES)) {
            const pathname = (path.split('?')[0] ?? '').replace('{ref}', 'abandoned_cart');
            const exists = pathname === '' || patterns.some((re) => re.test(pathname));
            expect(exists, `la pantalla «${key}» (${path}) no existe en el router`).toBe(true);
        }
        expect(pagePath('connections_web')).toBe('conexiones?tarjeta=web');
        expect(pagePath('strategy')).toBeNull();
        expect(pagePath('no_existe')).toBeNull();
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

    it('muestra los pasos con su enlace y los manda en la conversación', async () => {
        const steps = [
            { text: 'Conecta tu web con el plugin.', page: 'connections_web', ref: null, path: 'conexiones?tarjeta=web' },
            { text: 'Prende la recuperación de carritos.', page: 'strategy', ref: 'abandoned_cart', path: 'estrategias/abandoned_cart' },
            { text: 'Revisa tu plan.', page: null, ref: null, path: null },
        ];
        const fetchMock = vi
            .fn()
            .mockResolvedValueOnce(json(answer({ answer: 'Empieza por conectar tu web.', actions: [], steps })))
            .mockResolvedValueOnce(json(answer({ answer: 'Listo.', actions: [] })));
        vi.stubGlobal('fetch', fetchMock);
        useAssistant.setState({ open: true, pending: 'como inicio?' });
        render(wrap(<AssistantPanel />, '/w/ws_1/resultados'));

        await waitFor(() => expect(screen.getByText('Empieza por conectar tu web.')).toBeTruthy());
        expect(screen.getByRole('link', { name: /Conecta tu web con el plugin/ }).getAttribute('href')).toBe('/w/ws_1/conexiones?tarjeta=web');
        expect(screen.getByRole('link', { name: /Prende la recuperación de carritos/ }).getAttribute('href')).toBe('/w/ws_1/estrategias/abandoned_cart');
        // Un paso sin pantalla se lee, pero no es un enlace.
        expect(screen.getByText('Revisa tu plan.').closest('a')).toBeNull();

        fireEvent.change(screen.getByLabelText('Tu pregunta'), { target: { value: 'ok pero quiero conectar un nuevo sitio' } });
        fireEvent.keyDown(screen.getByLabelText('Tu pregunta'), { key: 'Enter' });
        await waitFor(() => expect(screen.getByText('Listo.')).toBeTruthy());
        const second = JSON.parse((fetchMock.mock.calls[1]?.[1] as RequestInit).body as string) as { history: { answer: string }[] };
        expect(second.history[0]?.answer).toContain('- Conecta tu web con el plugin.');
    });

    it('si la IA no respondió lo dice y deja volver a preguntar; un error de red también', async () => {
        const fetchMock = vi
            .fn()
            .mockResolvedValueOnce(json(answer({ status: 'failed', answer: 'La IA no respondió esta vez.', fallback_reason: 'provider', retryable: true, actions: [] })))
            .mockResolvedValueOnce(json(answer({ answer: 'Ahora sí: conecta tu web.', actions: [] })))
            .mockResolvedValueOnce(json({ message: 'Error del servidor' }, 500))
            .mockResolvedValueOnce(json(answer({ answer: 'Respuesta después del error.', actions: [] })));
        vi.stubGlobal('fetch', fetchMock);
        useAssistant.setState({ open: true, pending: 'como inicio?' });
        render(wrap(<AssistantPanel />, '/w/ws_1'));

        await waitFor(() => expect(screen.getByText('La IA no respondió esta vez.')).toBeTruthy());
        expect(screen.getByText('No pude responder esta vez')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: /Volver a preguntar/ }));
        await waitFor(() => expect(screen.getByText('Ahora sí: conecta tu web.')).toBeTruthy());
        expect(screen.queryByText('La IA no respondió esta vez.')).toBeNull();
        const again = JSON.parse((fetchMock.mock.calls[1]?.[1] as RequestInit).body as string) as { question: string };
        expect(again.question).toBe('como inicio?');

        fireEvent.change(screen.getByLabelText('Tu pregunta'), { target: { value: '¿y luego?' } });
        fireEvent.keyDown(screen.getByLabelText('Tu pregunta'), { key: 'Enter' });
        await waitFor(() => expect(screen.getByRole('button', { name: /Volver a preguntar/ })).toBeTruthy());
        fireEvent.click(screen.getByRole('button', { name: /Volver a preguntar/ }));
        await waitFor(() => expect(screen.getByText('Respuesta después del error.')).toBeTruthy());

        // Un rechazo no ofrece reintentar: hay que preguntarlo de otra forma.
        cleanup();
        vi.stubGlobal('fetch', vi.fn().mockResolvedValueOnce(json(answer({ status: 'failed', answer: 'Pregúntalo de otra forma.', fallback_reason: 'refusal', retryable: false, actions: [] }))));
        useAssistant.setState({ open: true, pending: 'algo raro' });
        render(wrap(<AssistantPanel />, '/w/ws_1'));
        await waitFor(() => expect(screen.getByText('Pregúntalo de otra forma.')).toBeTruthy());
        expect(screen.queryByRole('button', { name: /Volver a preguntar/ })).toBeNull();
    });

    it('mientras piensa dice que sigue trabajando si tarda', async () => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        try {
            vi.stubGlobal('fetch', vi.fn().mockReturnValue(new Promise(() => {})));
            useAssistant.setState({ open: true, pending: '¿Cuánto vendí?' });
            render(wrap(<AssistantPanel />, '/w/ws_1'));
            await waitFor(() => expect(screen.getByText('Pensando…')).toBeTruthy());
            act(() => {
                vi.advanceTimersByTime(9000);
            });
            expect(screen.getByText(/Sigo pensando/)).toBeTruthy();
            act(() => {
                vi.advanceTimersByTime(35000);
            });
            expect(screen.getByText(/tardando más de lo normal/)).toBeTruthy();
        } finally {
            vi.useRealTimers();
        }
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
