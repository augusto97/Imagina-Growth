import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { Me, Workspace } from '@/api/types';
import { WorkspaceContext } from '@/hooks/useWorkspace';
import { describeAnswers, exampleScenarios, missingPrices, multiplierToPct, pctToMultiplier, preset } from './model';
import { PricesEditor } from './PricesEditor';
import type { QuoteDefinition, QuoteTemplate } from './types';
import { WhatStep } from './QuoteWizardPage';

afterEach(cleanup);

const t = (key: string, options?: Record<string, unknown>) => (options ? `${key}:${JSON.stringify(options)}` : key);

const paint: QuoteDefinition = {
    currency: null,
    base_price: 60000,
    minimum: 320000,
    range_pct: 10,
    round_to: 1000,
    intro: null,
    note: null,
    internal_notes: null,
    packages: [],
    fields: [
        { key: 'area', label: 'Área', type: 'number', required: true, help: null, unit: 'm²', min: 5, max: 60, step: 1, default: 40, unit_price: 14000, icon: '📐' },
        {
            key: 'tipo',
            label: 'Pintura',
            type: 'select',
            required: true,
            help: null,
            options: [
                { value: 'vinilo', label: 'Vinilo', price: 0, multiplier: 1, icon: '🏠' },
                { value: 'exterior', label: 'Exterior', price: 0, multiplier: 1.4 },
                { value: 'lavable', label: 'Lavable premium', price: 0, multiplier: 1.25 },
            ],
        },
        {
            key: 'extras',
            label: 'Extras',
            type: 'checkbox',
            required: false,
            help: null,
            options: [
                { value: 'resanes', label: 'Resanes', price: 120000, multiplier: 1 },
                { value: 'puertas', label: 'Puertas', price: 100000, multiplier: 1 },
            ],
        },
    ],
};

describe('asistente del cotizador: el modelo', () => {
    it('lleva el multiplicador a porcentaje y de vuelta', () => {
        expect(multiplierToPct(1.25)).toBe(25);
        expect(multiplierToPct(0.9)).toBe(-10);
        expect(multiplierToPct(1)).toBe(0);
        expect(pctToMultiplier(25)).toBe(1.25);
        expect(pctToMultiplier(-10)).toBe(0.9);
        expect(pctToMultiplier(-150)).toBe(0.01);
    });

    it('arma un ejemplo básico y uno completo (el doble hasta el máximo, la opción más cara y todos los extras)', () => {
        const [basic, full] = exampleScenarios(paint);
        expect(basic).toEqual({ area: 40, tipo: 'vinilo', extras: [] });
        expect(full).toEqual({ area: 60, tipo: 'exterior', extras: ['resanes', 'puertas'] });
        const pkg = preset('package', t).definition;
        expect(exampleScenarios(pkg)[1]).toMatchObject({ package: 'completo', urgente: ['urgente'] });
    });

    it('dice en palabras lo que pidió la persona', () => {
        expect(describeAnswers(paint, { area: 40, tipo: 'lavable', extras: [] }, t)).toBe('40 m² · Lavable premium');
        expect(describeAnswers(paint, { area: 15000, tipo: 'vinilo', extras: ['resanes'] }, t)).toBe('15.000 m² · Vinilo · Resanes');
        expect(describeAnswers(paint, { area: 10, extras: ['resanes', 'puertas'] }, t)).toBe('10 m² · quotes.wizard.extras:{"count":2}');
    });

    it('marca los precios que faltan: los que la IA dejó en cero y el precio por unidad del cotizador por área', () => {
        const needs = ['fields.1.options.1.multiplier', 'fields.2.options.0.price'];
        expect(missingPrices('area', paint, needs)).toEqual([]);
        const zero: QuoteDefinition = {
            ...paint,
            fields: paint.fields.map((f, i) =>
                i === 0 ? { ...f, unit_price: 0 } : i === 2 ? { ...f, options: (f.options ?? []).map((o, j) => (j === 0 ? { ...o, price: 0 } : o)) } : f,
            ),
        };
        expect(missingPrices('area', zero, needs)).toEqual(['fields.2.options.0.price', 'fields.0.unit_price']);
        expect(missingPrices('service', zero, [])).toEqual([]);
    });
});

const workspace: Workspace = {
    id: 'ws_1',
    name: 'Pinturas del Eje',
    slug: 'pinturas-del-eje',
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
    abilities: ['viewWorkspace', 'manageServices', 'useLeads'],
    requires_two_factor: false,
};

const me: Me = {
    user: { id: 'usr_1', name: 'Laura Gómez', email: 'laura@pinturas.co', locale: 'es-CO', two_factor_enabled: true, two_factor_pending: false, is_platform_admin: false },
    workspaces: [workspace],
    require_two_factor: false,
};

function wrap(node: React.ReactNode) {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(
        <QueryClientProvider client={client}>
            <MemoryRouter>
                <WorkspaceContext.Provider value={{ workspace, me }}>{node}</WorkspaceContext.Provider>
            </MemoryRouter>
        </QueryClientProvider>,
    );
}

const templates: QuoteTemplate[] = [
    { key: 'pintura', icon: '🎨', kind: 'area', name: 'Pintura', description: 'Por metro cuadrado.', definition: paint },
    { key: 'clases', icon: '📚', kind: 'package', name: 'Clases y tutorías', description: 'Paquetes de clases.', definition: preset('package', t).definition },
];

describe('asistente del cotizador: las pantallas', () => {
    it('elige una plantilla por oficio o empieza de cero; sin IA no ofrece describirlo', () => {
        const onTemplate = vi.fn();
        const onBlank = vi.fn();
        wrap(<WhatStep templates={templates} loading={false} ai={false} selected={null} onTemplate={onTemplate} onBlank={onBlank} onProposal={vi.fn()} />);
        expect(screen.queryByText('Descríbelo y la IA lo arma')).toBeNull();
        fireEvent.click(screen.getByRole('radio', { name: /Pintura/ }));
        expect(onTemplate).toHaveBeenCalledWith(templates[0]);
        fireEvent.click(screen.getByRole('radio', { name: /Empezar de cero/ }));
        expect(onBlank).toHaveBeenCalled();
    });

    it('con IA pide una descripción de al menos 15 caracteres antes de armarlo', () => {
        wrap(<WhatStep templates={templates} loading={false} ai selected={null} onTemplate={vi.fn()} onBlank={vi.fn()} onProposal={vi.fn()} />);
        const button = screen.getByRole('button', { name: /Armarlo con IA/ });
        expect(button).toBeDisabled();
        fireEvent.change(screen.getByLabelText('Describe tu negocio y cómo cobras'), { target: { value: 'Pinto casas, 15 mil el metro' } });
        expect(button).toBeEnabled();
    });

    it('edita los precios con el ajuste en porcentaje y marca en rojo los que faltan', () => {
        const onChange = vi.fn();
        wrap(<PricesEditor kind="area" definition={paint} currency="COP" missing={['fields.2.options.0.price']} onChange={onChange} />);
        const adjust = screen.getAllByLabelText('Ajusta el total (%)');
        expect(adjust[1]).toHaveValue(40);
        fireEvent.change(adjust[1] as HTMLElement, { target: { value: '50' } });
        const next = onChange.mock.calls[0]?.[0] as QuoteDefinition;
        expect(next.fields[1]?.options?.[1]?.multiplier).toBe(1.5);
        const adds = screen.getAllByLabelText('Suma (COP)');
        expect(adds[3]).toHaveAttribute('aria-invalid', 'true');
        expect(adds[0]).not.toHaveAttribute('aria-invalid');
    });
});
