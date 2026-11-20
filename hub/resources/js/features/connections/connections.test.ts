import { describe, expect, it, vi } from 'vitest';
import texts from '@/locales/es-CO/connections.json';
import type { WhatsAppComplete, WhatsAppStart } from './api';
import { type FbSdk, parseSignupMessage, runEmbeddedSignup, type SignupCalls } from './embeddedSignup';

type Tree = { [key: string]: string | Tree };

function has(path: string): boolean {
    let node: string | Tree = texts;
    for (const part of path.split('.')) {
        if (typeof node !== 'object' || !(part in node)) return false;
        node = node[part] as string | Tree;
    }
    return typeof node === 'string';
}

const START: WhatsAppStart = { state: 'st.firma', app_id: '1234', config_id: 'cfg-wa', graph_version: 'v27.0', version: 'v5' };

/** SDK de Facebook de prueba: al llamar login, la «ventana» manda sus mensajes y luego responde. */
function fakeSdk(messages: unknown[], code: string | null, order: 'messages-first' | 'login-first' = 'messages-first'): { sdk: FbSdk; login: ReturnType<typeof vi.fn> } {
    const login = vi.fn((cb: (r: { authResponse?: { code?: string } | null }) => void) => {
        const post = () => messages.forEach((data) => window.dispatchEvent(new MessageEvent('message', { origin: 'https://www.facebook.com', data: JSON.stringify(data) })));
        if (order === 'messages-first') post();
        cb({ authResponse: code !== null ? { code } : null });
        if (order === 'login-first') post();
    });
    return { sdk: { init: vi.fn(), login }, login };
}

function calls(
    sdk: FbSdk,
    complete?: (b: Record<string, unknown>) => Promise<WhatsAppComplete>,
): SignupCalls & { completeSpy: ReturnType<typeof vi.fn>; abandonSpy: ReturnType<typeof vi.fn> } {
    const completeSpy = vi.fn(
        complete ?? (() => Promise.resolve({ connection: { id: 'oac_1' }, data: { id: 'cha_1', identity: '+57 301' }, phones: [] } as unknown as WhatsAppComplete)),
    );
    const abandonSpy = vi.fn(() => Promise.resolve({ ok: true }));
    return { start: () => Promise.resolve(START), complete: completeSpy, abandon: abandonSpy, loadSdk: () => Promise.resolve(sdk), waitMs: 5, completeSpy, abandonSpy };
}

describe('Conexiones: textos de las claves dinámicas', () => {
    it('tiene nombre, descripción, estados, usos y motivos de error', () => {
        const missing: string[] = [];
        for (const p of ['meta', 'meta_whatsapp', 'google', 'tiktok'])
            for (const part of ['name', 'description']) if (!has(`connections.provider.${p}.${part}`)) missing.push(`${p}.${part}`);
        for (const s of ['active', 'expired', 'error', 'revoked', 'none', 'unavailable']) if (!has(`connections.status.${s}`)) missing.push(`status.${s}`);
        for (const u of ['conversions', 'ads', 'analytics', 'calendar', 'audiences', 'spend', 'messenger', 'instagram', 'whatsapp'])
            if (!has(`connections.uses.${u}`)) missing.push(`uses.${u}`);
        for (const r of ['state', 'scopes', 'exchange', 'expired', 'provider', 'not_configured', 'forbidden', 'asset'])
            if (!has(`connections.reason.${r}`)) missing.push(`reason.${r}`);
        for (const c of ['whatsapp', 'messenger', 'instagram', 'email', 'sms']) if (!has(`connections.usedBy.${c}`)) missing.push(`usedBy.${c}`);
        for (const k of ['purchase', 'lead', 'lead_won']) if (!has(`connections.pick.google.action.${k}`)) missing.push(`action.${k}`);
        for (const c of ['messenger', 'instagram']) if (!has(`connections.pick.meta.${c}Connected`)) missing.push(`${c}Connected`);
        expect(missing).toEqual([]);
    });
});

describe('Embedded Signup: mensajes de la ventana de Meta', () => {
    it('solo acepta mensajes de facebook.com con el tipo WA_EMBEDDED_SIGNUP', () => {
        const data = JSON.stringify({ type: 'WA_EMBEDDED_SIGNUP', event: 'FINISH', data: { waba_id: '333', phone_number_id: '444' } });
        expect(parseSignupMessage({ origin: 'https://www.facebook.com', data })).toEqual({ event: 'FINISH', data: { waba_id: '333', phone_number_id: '444' } });
        expect(parseSignupMessage({ origin: 'https://web.facebook.com', data: JSON.parse(data) as unknown })).not.toBeNull();
        expect(parseSignupMessage({ origin: 'https://facebook.com.evil.co', data })).toBeNull();
        expect(parseSignupMessage({ origin: 'https://evilfacebook.com', data })).toBeNull();
        expect(parseSignupMessage({ origin: 'https://www.facebook.com', data: '{"type":"otra cosa"}' })).toBeNull();
        expect(parseSignupMessage({ origin: 'https://www.facebook.com', data: 'no es json' })).toBeNull();
    });
});

describe('Embedded Signup: resultado de la ventana', () => {
    it('FINISH: abre la ventana de la v5 con el config_id y manda el código con la cuenta', async () => {
        const { sdk, login } = fakeSdk([{ type: 'WA_EMBEDDED_SIGNUP', event: 'FINISH', data: { waba_id: '333', phone_number_id: '444', business_id: '999' } }], 'AQB');
        const c = calls(sdk);
        const out = await runEmbeddedSignup(c);
        expect(out.kind).toBe('connected');
        expect(login.mock.calls[0]?.[1]).toEqual({ config_id: 'cfg-wa', response_type: 'code', override_default_response_type: true, extras: { version: 'v5' } });
        expect(c.completeSpy).toHaveBeenCalledWith({ state: 'st.firma', code: 'AQB', event: 'FINISH', waba_id: '333', phone_number_id: '444', business_id: '999' });
    });

    it('coexistencia: manda el evento de la app de WhatsApp Business aunque el mensaje llegue después', async () => {
        const { sdk } = fakeSdk([{ type: 'WA_EMBEDDED_SIGNUP', event: 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING', data: { waba_id: '333' } }], 'AQB', 'login-first');
        const c = calls(sdk, () =>
            Promise.resolve({
                connection: { id: 'oac_1' },
                data: null,
                phones: [{ id: '444', display_phone_number: '+57', verified_name: null, is_on_biz_app: true }],
            } as unknown as WhatsAppComplete),
        );
        const out = await runEmbeddedSignup(c);
        expect(out).toEqual({ kind: 'choose', connectionId: 'oac_1', phones: [{ id: '444', display_phone_number: '+57', verified_name: null, is_on_biz_app: true }] });
        expect(c.completeSpy.mock.calls[0]?.[0]).toMatchObject({ event: 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING', waba_id: '333' });
    });

    it('CANCEL: avisa al hub con el paso y no canjea nada; cerrar la ventana sin mensaje también es cancelar', async () => {
        const first = calls(fakeSdk([{ type: 'WA_EMBEDDED_SIGNUP', event: 'CANCEL', data: { current_step: 'PHONE_NUMBER_SETUP' } }], null).sdk);
        expect(await runEmbeddedSignup(first)).toEqual({ kind: 'cancelled' });
        expect(first.abandonSpy).toHaveBeenCalledWith({ state: 'st.firma', event: 'CANCEL', current_step: 'PHONE_NUMBER_SETUP' });
        expect(first.completeSpy).not.toHaveBeenCalled();

        const closed = calls(fakeSdk([], null).sdk);
        expect(await runEmbeddedSignup(closed)).toEqual({ kind: 'cancelled' });
        expect(closed.abandonSpy).toHaveBeenCalledWith({ state: 'st.firma', event: 'CANCEL', current_step: undefined });
    });

    it('ERROR: muestra el mensaje de Meta y el código para soporte', async () => {
        const c = calls(
            fakeSdk([{ type: 'WA_EMBEDDED_SIGNUP', event: 'ERROR', data: { error_message: 'El número ya está registrado', error_code: 524126, session_id: 'f34b51' } }], null).sdk,
        );
        expect(await runEmbeddedSignup(c)).toEqual({ kind: 'error', message: 'El número ya está registrado', sessionId: 'f34b51' });
        expect(c.abandonSpy).toHaveBeenCalledWith({ state: 'st.firma', event: 'ERROR', error_code: '524126', session_id: 'f34b51' });
    });

    it('ignora mensajes de otros orígenes', async () => {
        const login = vi.fn((cb: (r: { authResponse?: { code?: string } | null }) => void) => {
            window.dispatchEvent(
                new MessageEvent('message', { origin: 'https://evil.co', data: JSON.stringify({ type: 'WA_EMBEDDED_SIGNUP', event: 'FINISH', data: { waba_id: '666' } }) }),
            );
            cb({ authResponse: { code: 'AQB' } });
        });
        const c = calls({ init: vi.fn(), login });
        expect(await runEmbeddedSignup(c)).toEqual({ kind: 'error', message: null, sessionId: null });
        expect(c.completeSpy).not.toHaveBeenCalled();
    });
});
