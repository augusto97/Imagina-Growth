import type { ChannelAccount } from '@/features/channels/api';
import type { WhatsAppComplete, WhatsAppPhone, WhatsAppStart } from './api';

/*
 * Ventana de Embedded Signup de WhatsApp (v5, con coexistencia encendida por defecto):
 * https://developers.facebook.com/docs/whatsapp/embedded-signup/implementation
 * https://developers.facebook.com/documentation/business-messaging/whatsapp/embedded-signup/versions/
 *
 * El SDK de JavaScript de Facebook se carga solo cuando la persona pulsa el botón. FB.login devuelve el código (vive
 * 5 minutos) y la ventana manda, por postMessage desde facebook.com, el evento WA_EMBEDDED_SIGNUP: FINISH (número
 * nuevo en la API), FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING (coexistencia), CANCEL (con el paso) o ERROR.
 */

interface FbLoginResponse {
    authResponse?: { code?: string } | null;
}
export interface FbSdk {
    init: (o: Record<string, unknown>) => void;
    login: (cb: (r: FbLoginResponse) => void, o: Record<string, unknown>) => void;
}
type FbWindow = Window & { FB?: FbSdk; fbAsyncInit?: () => void };

export interface SignupMessage {
    event: string;
    data: Record<string, unknown>;
}

export type SignupOutcome =
    | { kind: 'connected'; account: ChannelAccount }
    | { kind: 'choose'; connectionId: string; phones: WhatsAppPhone[] }
    | { kind: 'cancelled' }
    | { kind: 'error'; message: string | null; sessionId: string | null };

export const FINISH_EVENTS = ['FINISH', 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING'];

let sdk: Promise<FbSdk> | null = null;

export function loadFacebookSdk(appId: string, version: string): Promise<FbSdk> {
    const w = window as FbWindow;
    if (w.FB) {
        w.FB.init({ appId, autoLogAppEvents: true, xfbml: false, version });
        return Promise.resolve(w.FB);
    }
    sdk ??= new Promise<FbSdk>((resolve, reject) => {
        w.fbAsyncInit = () => {
            w.FB?.init({ appId, autoLogAppEvents: true, xfbml: false, version });
            if (w.FB) resolve(w.FB);
        };
        const s = document.createElement('script');
        s.src = 'https://connect.facebook.net/es_LA/sdk.js';
        s.async = true;
        s.defer = true;
        s.crossOrigin = 'anonymous';
        s.onerror = () => {
            sdk = null;
            reject(new Error('fb-sdk'));
        };
        document.body.appendChild(s);
    });
    return sdk;
}

/** Lee un mensaje de la ventana de Meta; null si no viene de facebook.com o no es de Embedded Signup. */
export function parseSignupMessage(event: Pick<MessageEvent, 'origin' | 'data'>): SignupMessage | null {
    let host: string;
    try {
        host = new URL(event.origin).hostname;
    } catch {
        return null;
    }
    if (host !== 'facebook.com' && !host.endsWith('.facebook.com')) return null;
    let payload: unknown = event.data;
    if (typeof payload === 'string') {
        try {
            payload = JSON.parse(payload);
        } catch {
            return null;
        }
    }
    if (typeof payload !== 'object' || payload === null) return null;
    const p = payload as { type?: unknown; event?: unknown; data?: unknown };
    if (p.type !== 'WA_EMBEDDED_SIGNUP' || typeof p.event !== 'string') return null;
    return { event: p.event, data: typeof p.data === 'object' && p.data !== null ? (p.data as Record<string, unknown>) : {} };
}

const str = (v: unknown): string | undefined => (typeof v === 'string' && v !== '' ? v : typeof v === 'number' ? String(v) : undefined);

export interface SignupCalls {
    start: () => Promise<WhatsAppStart>;
    complete: (body: { state: string; code: string; event: string; waba_id: string; phone_number_id?: string; business_id?: string }) => Promise<WhatsAppComplete>;
    abandon: (body: { state: string; event: 'CANCEL' | 'ERROR'; current_step?: string; error_code?: string; session_id?: string }) => Promise<unknown>;
    loadSdk?: (appId: string, version: string) => Promise<FbSdk>;
    /** Cuánto esperar el mensaje de la ventana después de que FB.login respondió (ms). */
    waitMs?: number;
}

/**
 * Abre la ventana de Meta y termina la conexión. Los errores del hub (sin configurar, state vencido, permisos) se
 * lanzan como ApiError para que la pantalla muestre su mensaje.
 */
export async function runEmbeddedSignup(calls: SignupCalls): Promise<SignupOutcome> {
    const start = await calls.start();
    const fb = await (calls.loadSdk ?? loadFacebookSdk)(start.app_id, start.graph_version);

    return new Promise<SignupOutcome>((resolve, reject) => {
        let message: SignupMessage | null = null;
        let login: { code: string | null } | null = null;
        let settled = false;
        let timer: number | undefined;

        const done = (p: Promise<SignupOutcome>) => {
            if (settled) return;
            settled = true;
            window.removeEventListener('message', listener);
            window.clearTimeout(timer);
            p.then(resolve, reject);
        };

        const decide = (final: boolean) => {
            if (login === null) return;
            const event = message?.event;
            if (event !== undefined && FINISH_EVENTS.includes(event) && login.code !== null) {
                const waba = str(message?.data.waba_id);
                if (waba === undefined) {
                    done(Promise.resolve({ kind: 'error', message: null, sessionId: null }));
                    return;
                }
                const body = {
                    state: start.state,
                    code: login.code,
                    event,
                    waba_id: waba,
                    phone_number_id: str(message?.data.phone_number_id),
                    business_id: str(message?.data.business_id),
                };
                done(
                    calls
                        .complete(body)
                        .then((r) => (r.data !== null ? { kind: 'connected', account: r.data } : { kind: 'choose', connectionId: r.connection.id, phones: r.phones })),
                );
                return;
            }
            if (event === 'ERROR') {
                const errorCode = str(message?.data.error_code);
                const sessionId = str(message?.data.session_id) ?? null;
                void calls.abandon({ state: start.state, event: 'ERROR', error_code: errorCode, session_id: sessionId ?? undefined }).catch(() => undefined);
                done(Promise.resolve({ kind: 'error', message: str(message?.data.error_message) ?? null, sessionId }));
                return;
            }
            if (event === 'CANCEL' || (login.code === null && final)) {
                void calls.abandon({ state: start.state, event: 'CANCEL', current_step: str(message?.data.current_step) }).catch(() => undefined);
                done(Promise.resolve({ kind: 'cancelled' }));
                return;
            }
            if (final) {
                // Hubo código pero la ventana nunca mandó la cuenta: no se puede saber qué conectar.
                done(Promise.resolve({ kind: 'error', message: null, sessionId: null }));
            }
        };

        const listener = (event: MessageEvent) => {
            const parsed = parseSignupMessage(event);
            if (parsed === null) return;
            message = parsed;
            decide(false);
        };
        window.addEventListener('message', listener);

        fb.login(
            (response) => {
                login = { code: response.authResponse?.code ?? null };
                decide(false);
                // El mensaje de la ventana suele llegar antes que la respuesta de FB.login; si no, se espera un poco.
                timer = window.setTimeout(() => decide(true), calls.waitMs ?? 2500);
            },
            { config_id: start.config_id, response_type: 'code', override_default_response_type: true, extras: { version: start.version } },
        );
    });
}
