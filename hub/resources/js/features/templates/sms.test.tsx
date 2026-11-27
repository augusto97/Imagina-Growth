import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { MemoryRouter } from 'react-router';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { Ability, Me, Workspace } from '@/api/types';
import { WorkspaceContext } from '@/hooks/useWorkspace';
import { liveChecks } from './content';
import { SmsEditor } from './editor/SmsEditor';
import { estimateSms, measureSms, smsOverflow, transliterateSms, type SmsLimits } from './sms';

/* Los mismos casos que lee el hub (tests/Unit/Messaging/SmsLengthTest.php): si una de las dos cuentas cambia, falla. */

interface Cases {
    measure: { name: string; text: [string, number][]; encoding: string; length: number; segments: number; offenders: string[] }[];
    transliterate: { in: string; out: string }[];
    estimate: { limits: SmsLimits; cases: { name: string; body: string; encoding: string; length: number; segments: number }[] };
}

const cases = JSON.parse(readFileSync(join(process.cwd(), 'tests/fixtures/sms/cases.json'), 'utf8')) as Cases;
const build = (chunks: [string, number][]) => chunks.map(([c, n]) => c.repeat(n)).join('');

describe('largo de un SMS: los casos compartidos con el hub', () => {
    it('mide GSM-7 con la extensión y UCS-2 con los pares, igual que el hub', () => {
        for (const c of cases.measure) {
            const m = measureSms(build(c.text));
            expect({ name: c.name, encoding: m.encoding, length: m.length, segments: m.segments, offenders: m.offenders }).toEqual({
                name: c.name,
                encoding: c.encoding,
                length: c.length,
                segments: c.segments,
                offenders: c.offenders,
            });
        }
    });

    it('cambia por letras sin tilde lo que tiene equivalente y deja la ñ, la é y la ü', () => {
        for (const c of cases.transliterate) expect(transliterateSms(c.in)).toBe(c.out);
    });

    it('cuenta el peor caso con las variables a su largo máximo, igual que el hub', () => {
        for (const c of cases.estimate.cases) {
            const e = estimateSms(c.body, cases.estimate.limits);
            expect({ name: c.name, encoding: e.encoding, length: e.length, segments: e.segments }).toEqual({ name: c.name, encoding: c.encoding, length: c.length, segments: c.segments });
        }
    });

    it('159, 160 y 161: uno, uno y dos; con el permiso, hasta dos', () => {
        expect(smsOverflow(measureSms('a'.repeat(159)), false)).toBeNull();
        expect(smsOverflow(measureSms('a'.repeat(160)), false)).toBeNull();
        expect(smsOverflow(measureSms('a'.repeat(161)), false)).toEqual({ limit: 160, over: 1 });
        expect(smsOverflow(measureSms('a'.repeat(161)), true)).toBeNull();
        expect(smsOverflow(measureSms('a'.repeat(307)), true)).toEqual({ limit: 306, over: 1 });
        expect(smsOverflow(measureSms('á'.repeat(71)), false)).toEqual({ limit: 70, over: 1 });
    });

    it('la revisión en vivo marca el SMS que no cabe y avisa de las letras que bajan el límite', () => {
        const limits = cases.estimate.limits;
        const codes = (body: string, allow = false) => liveChecks({ channel: 'sms', category: 'transactional', body, smsLimits: limits, smsAllowMultipart: allow }).map((c) => c.code);
        expect(codes(`${'a'.repeat(150)} {{ producto.nombre }}`)).toEqual(['sms_too_long']);
        expect(codes(`${'a'.repeat(150)} {{ producto.nombre }}`, true)).toEqual([]);
        expect(codes('Tu cotización está lista')).toEqual(['sms_unicode']);
        expect(codes('Tu pedido va en camino')).toEqual([]);
    });
});

const workspace = (abilities: Ability[]): Workspace => ({
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
    role: abilities.includes('manageBilling') ? 'owner' : 'marketing',
    abilities,
    requires_two_factor: false,
});

function mount(props: Partial<Parameters<typeof SmsEditor>[0]> & { abilities?: Ability[] } = {}) {
    const { abilities = ['viewWorkspace', 'manageMarketing'], ...rest } = props;
    const ws = workspace(abilities);
    const me: Me = {
        user: { id: 'usr_1', name: 'Laura Gómez', email: 'laura@pinturas.co', locale: 'es-CO', two_factor_enabled: true, two_factor_pending: false, is_platform_admin: false },
        workspaces: [ws],
        require_two_factor: false,
    };
    const onChange = vi.fn();
    render(
        <QueryClientProvider client={new QueryClient()}>
            <MemoryRouter>
                <WorkspaceContext.Provider value={{ workspace: ws, me }}>
                    <SmsEditor text="" allowMultipart={false} limits={cases.estimate.limits} canEdit onChange={onChange} {...rest} />
                </WorkspaceContext.Provider>
            </MemoryRouter>
        </QueryClientProvider>,
    );
    return onChange;
}

describe('editor de SMS', () => {
    afterEach(cleanup);

    it('cuenta en vivo «N de 160 · 1 mensaje» y con variables el peor caso', () => {
        mount({ text: 'Tu pedido va en camino' });
        expect(screen.getByTestId('sms-counter').textContent).toBe('22 de 160 caracteres · 1 mensaje');
        cleanup();
        mount({ text: 'Hola {{ contacto.nombre }}, tu pedido va en camino' });
        expect(screen.getByTestId('sms-counter').textContent).toBe('Hasta 49 de 160 caracteres con las variables a su largo máximo · 1 mensaje');
    });

    it('dice qué letra baja el límite y la cambia por letras sin tilde', () => {
        const onChange = mount({ text: 'Tu cotización está lista 😀' });
        expect(screen.getByText('«ó», «á», «😀» baja el límite a 70 caracteres')).toBeTruthy();
        expect(screen.getByText(/«😀» no tiene reemplazo/)).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: /Cambiar por letras sin tilde/ }));
        expect(onChange).toHaveBeenCalledWith({ body_text: 'Tu cotizacion esta lista 😀' });
    });

    it('avisa cuánto sobra si no cabe', () => {
        mount({ text: 'a'.repeat(165) });
        expect(screen.getByText('No cabe en un SMS')).toBeTruthy();
        expect(screen.getByText(/Sobran 5 caracteres para el límite de 160/)).toBeTruthy();
        expect(screen.getByTestId('sms-counter').textContent).toBe('165 de 160 caracteres · 2 mensajes');
    });

    it('solo el dueño puede permitir 2 partes', () => {
        mount({ text: 'Hola' });
        const box = screen.getByLabelText('Permitir mensajes de 2 partes (cuestan el doble)');
        expect((box as HTMLInputElement).disabled).toBe(true);
        expect(screen.getByText(/Solo el dueño del negocio puede activarlo/)).toBeTruthy();
        cleanup();
        const onChange = mount({ text: 'Hola', abilities: ['viewWorkspace', 'manageMarketing', 'manageBilling'] });
        fireEvent.click(screen.getByLabelText('Permitir mensajes de 2 partes (cuestan el doble)'));
        expect(onChange).toHaveBeenCalledWith({ sms_allow_multipart: true });
    });
});
