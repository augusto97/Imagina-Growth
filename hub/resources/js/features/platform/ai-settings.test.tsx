import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { Me, Workspace } from '@/api/types';
import { WorkspaceContext } from '@/hooks/useWorkspace';
import i18n from '@/lib/i18n';
import texts from '@/locales/es-CO/platform-settings.json';
import { AiModelFields, AiTestPanel } from './AiSettings';
import { type AiFeatureInfo, type AiModelInfo, choice, effortsFor, factoryModel, isAiModelField, overridesCount, tierModel, usd } from './ai-settings-model';
import type { SettingField, SettingsSection } from './settings-api';
import type { Draft } from './settings-model';

const workspace = { id: 'ws_1', name: 'Café del Eje', locale: 'es-CO', currency: 'COP', abilities: [] } as unknown as Workspace;
const me = { user: { id: 'usr_1', email: 'admin@imagina.test', is_platform_admin: true }, workspaces: [workspace] } as unknown as Me;

function field(key: string, patch: Partial<SettingField> = {}): SettingField {
    return { key, type: 'enum', options: null, min: null, max: null, generate: null, value: null, fallback: null, source: null, configured: false, hint: null, ...patch };
}

const models: AiModelInfo[] = [
    { id: 'claude-opus-5-5', efforts: ['low', 'medium', 'high', 'xhigh', 'max'], default_effort: 'medium', input_usd: 4, output_usd: 20 },
    { id: 'claude-sonnet-5-5', efforts: ['low', 'medium', 'high', 'xhigh', 'max'], default_effort: 'high', input_usd: 2, output_usd: 10 },
    { id: 'claude-haiku-5-5', efforts: ['low', 'medium', 'high'], default_effort: 'medium', input_usd: 0.1, output_usd: 0.5 },
];

const features: AiFeatureInfo[] = [
    { key: 'weekly_analyst', tier: 'main', default_model: 'claude-sonnet-5-5', default_effort: 'medium', effective: { model: 'claude-sonnet-5-5', effort: 'medium', tier: 'main', source: 'default' } },
    { key: 'sales_agent', tier: 'main', default_model: 'claude-sonnet-5-5', default_effort: 'low', effective: { model: 'claude-opus-5-5', effort: 'low', tier: 'main', source: 'override' } },
    { key: 'inbound_classifier', tier: 'fast', default_model: 'claude-haiku-5-5', default_effort: 'low', effective: { model: 'claude-haiku-5-5', effort: 'low', tier: 'fast', source: 'default' } },
];

function section(fields: SettingField[]): SettingsSection {
    return { key: 'ai', fields, info: { configured: true, models, features } };
}

const fields = [
    field('enabled', { type: 'bool' }),
    field('main_model'),
    field('main_effort'),
    field('fast_model', { fallback: 'claude-haiku-5-5', source: 'server' }),
    field('fast_effort'),
    field('feature_weekly_analyst_model'),
    field('feature_weekly_analyst_effort'),
    field('feature_sales_agent_model', { value: 'claude-opus-5-5', source: 'panel' }),
    field('feature_sales_agent_effort'),
    field('feature_inbound_classifier_model'),
    field('feature_inbound_classifier_effort'),
];

function wrap(node: React.ReactNode) {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    return (
        <QueryClientProvider client={client}>
            <WorkspaceContext.Provider value={{ workspace, me }}>{node}</WorkspaceContext.Provider>
        </QueryClientProvider>
    );
}

function Harness() {
    const [draft, setDraft] = useState<Draft>({});
    return (
        <>
            <AiModelFields section={section(fields)} draft={draft} errors={{}} onChange={(k, v) => setDraft((d) => ({ ...d, [k]: v }))} />
            <output data-testid="draft">{JSON.stringify(draft)}</output>
        </>
    );
}

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('Plataforma → Ajustes → IA: lógica', () => {
    it('separa los campos de modelo y esfuerzo y calcula lo que elige cada grupo y cada función', () => {
        expect(isAiModelField('main_model')).toBe(true);
        expect(isAiModelField('fast_effort')).toBe(true);
        expect(isAiModelField('feature_lead_brief_effort')).toBe(true);
        expect(isAiModelField('api_key')).toBe(false);
        expect(isAiModelField('monthly_budget_usd')).toBe(false);

        expect(choice(fields[1], {})).toBe('');
        expect(choice(fields[1], { main_model: 'claude-opus-5-5' })).toBe('claude-opus-5-5');
        expect(choice(fields[7], { feature_sales_agent_model: null })).toBe('');
        expect(choice(fields[7], {})).toBe('claude-opus-5-5');

        // Grupo: borrador → servidor → de fábrica.
        expect(tierModel('main', fields, {}, features)).toBe('claude-sonnet-5-5');
        expect(tierModel('main', fields, { main_model: 'claude-opus-5-5' }, features)).toBe('claude-opus-5-5');
        expect(tierModel('fast', fields, {}, features)).toBe('claude-haiku-5-5');
        const first = features[0];
        if (first) {
            expect(factoryModel('main', [...features, { ...first, key: 'x', default_model: 'claude-opus-5-5' }])).toBeNull();
        }

        expect(effortsFor(models, 'claude-haiku-5-5')).toEqual(['low', 'medium', 'high']);
        expect(effortsFor(models, 'otro')).toHaveLength(5);
        expect(overridesCount(fields, {}, features)).toBe(1);
        expect(overridesCount(fields, { feature_sales_agent_model: null, feature_weekly_analyst_effort: 'high' }, features)).toBe(1);
        expect(usd(2, 'es-CO')).toContain('2');
        expect(usd(0.00002, 'es-CO')).toContain('0,00002');
    });

    it('tiene nombre y explicación para cada modelo, cada esfuerzo y cada función', () => {
        const ai = (texts as unknown as { platformSettings: { ai: Record<string, Record<string, { name: string; help: string }>> } }).platformSettings.ai;
        for (const id of ['claude-fable-5-2', 'claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5']) {
            expect(ai.models?.[id]?.name).toBeTypeOf('string');
            expect(ai.models?.[id]?.help).toBeTypeOf('string');
        }
        for (const effort of ['low', 'medium', 'high', 'xhigh', 'max']) {
            expect(ai.efforts?.[effort]?.help).toBeTypeOf('string');
        }
        expect(Object.keys(ai.features ?? {})).toContain('assistant');
    });
});

describe('Plataforma → Ajustes → IA: pantalla', () => {
    it('muestra los dos grupos con precios, limita el esfuerzo al modelo elegido y guarda en el borrador', () => {
        void i18n.changeLanguage('es-CO');
        render(wrap(<Harness />));
        expect(screen.getByText('Modelo principal')).toBeTruthy();
        expect(screen.getByText('Modelo rápido')).toBeTruthy();
        expect(screen.getAllByText(/por millón de tokens/).length).toBe(3);

        const mainModel = screen.getByLabelText<HTMLSelectElement>('Modelo', { selector: '#ps-ai-main-model' });
        expect(mainModel.options[0]?.textContent).toContain('Claude Sonnet 5.5');
        fireEvent.change(mainModel, { target: { value: 'claude-opus-5-5' } });
        expect(screen.getByTestId('draft').textContent).toContain('"main_model":"claude-opus-5-5"');

        // El rápido viene del servidor (Haiku) y Haiku no admite «Muy alto» ni «Máximo».
        const fastModel = screen.getByLabelText<HTMLSelectElement>('Modelo', { selector: '#ps-ai-fast-model' });
        expect(fastModel.options[0]?.textContent).toContain('Claude Haiku 5.5');
        const fastEffort = screen.getByLabelText<HTMLSelectElement>('Esfuerzo', { selector: '#ps-ai-fast-effort' });
        expect([...fastEffort.options].map((o) => o.value)).toEqual(['', 'low', 'medium', 'high']);

        // «Avanzado» se abre solo porque hay una función con su propio modelo.
        expect(screen.getByText('1 función con su propio modelo')).toBeTruthy();
        expect(screen.getByText('Agente de ventas')).toBeTruthy();
    });

    it('«Probar la IA» muestra lo que respondió o por qué no', async () => {
        void i18n.changeLanguage('es-CO');
        const fetchMock = vi
            .fn()
            .mockResolvedValueOnce(
                new Response(JSON.stringify({ ok: true, message: 'La IA respondió en 1,2 s.', model: 'claude-sonnet-5-5', effort: null, latency_ms: 1234, served_model: 'claude-sonnet-5-5', reply: 'listo', cost_usd: 0.0004 }), {
                    status: 200,
                    headers: { 'Content-Type': 'application/json' },
                }),
            )
            .mockResolvedValueOnce(
                new Response(JSON.stringify({ ok: false, reason: 'auth', message: 'Anthropic no reconoce la clave.', model: 'claude-sonnet-5-5', effort: null, detail: '401' }), {
                    status: 422,
                    headers: { 'Content-Type': 'application/json' },
                }),
            );
        vi.stubGlobal('fetch', fetchMock);
        // Con la cookie de CSRF ya puesta, la SPA no pide /sanctum/csrf-cookie antes del POST.
        document.cookie = 'XSRF-TOKEN=prueba';
        render(wrap(<AiTestPanel section={section(fields)} dirty />));
        expect(screen.getByText('Tienes cambios sin guardar: la prueba usa lo guardado.')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: /Probar la IA/ }));
        await waitFor(() => expect(screen.getByText('La IA funciona')).toBeTruthy());
        expect(screen.getByText('Dijo: «listo»')).toBeTruthy();
        expect(String(fetchMock.mock.calls[0]?.[0])).toContain('/api/platform/settings/ai/test');

        fireEvent.click(screen.getByRole('button', { name: /Probar la IA/ }));
        await waitFor(() => expect(screen.getByText('La IA no respondió')).toBeTruthy());
        expect(screen.getByText('Anthropic no reconoce la clave.')).toBeTruthy();
    });
});
