import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { useEffect, useRef, useSyncExternalStore } from 'react';
import { api } from '@/lib/api';
import { reverbConfig } from '@/lib/runtime';

/*
 * Tiempo real con Reverb (hub/CLAUDE.md §13). Solo se conecta si el servidor dio la clave
 * pública de Reverb (`window.igrRuntime`, o VITE_REVERB_* en desarrollo); si no, o si se cae
 * la conexión, las pantallas siguen
 * consultando cada pocos segundos. Los canales privados se autorizan con la sesión
 * de Sanctum en /api/broadcasting/auth (el cliente `api` pone el XSRF).
 */

let echo: Echo<'reverb'> | null = null;
let connected = false;
const listeners = new Set<() => void>();

function setConnected(value: boolean): void {
    if (connected !== value) {
        connected = value;
        listeners.forEach((l) => l());
    }
}

export function realtimeConfigured(): boolean {
    return reverbConfig() !== null;
}

/** ¿Hay conexión viva con Reverb? (para alargar la consulta periódica). */
export function isRealtimeConnected(): boolean {
    return connected;
}

export function useRealtimeConnected(): boolean {
    return useSyncExternalStore(
        (cb) => {
            listeners.add(cb);
            return () => listeners.delete(cb);
        },
        () => connected,
    );
}

/** Intervalo de consulta: rápido sin tiempo real, lento con Reverb conectado. */
export function pollInterval(fastMs: number, slowMs = 30_000): number {
    return connected ? slowMs : fastMs;
}

function client(): Echo<'reverb'> | null {
    const config = reverbConfig();
    if (config === null) {
        return null;
    }
    if (echo === null) {
        echo = new Echo({
            broadcaster: 'reverb',
            Pusher,
            key: config.key,
            wsHost: config.host,
            wsPort: config.port,
            wssPort: config.port,
            forceTLS: config.scheme === 'https',
            enabledTransports: ['ws', 'wss'],
            authorizer: (channel: { name: string }) => ({
                authorize: (socketId: string, callback: (error: Error | null, data: { auth: string } | null) => void) => {
                    api.post<{ auth: string }>('/api/broadcasting/auth', { socket_id: socketId, channel_name: channel.name })
                        .then((data) => callback(null, data))
                        .catch((error: unknown) => callback(error instanceof Error ? error : new Error('auth'), null));
                },
            }),
        });
        const connection = echo.connector.pusher.connection;
        connection.bind('state_change', (states: { current: string }) => setConnected(states.current === 'connected'));
        setConnected(connection.state === 'connected');
    }
    return echo;
}

/**
 * Escucha un evento de un canal privado mientras el componente está montado.
 * `channel` sin el prefijo `private-` (p. ej. `workspace.ws_….inbox`).
 */
export function usePrivateChannel(channel: string | null, event: string, onEvent: (payload: unknown) => void): void {
    const handler = useRef(onEvent);
    useEffect(() => {
        handler.current = onEvent;
    }, [onEvent]);
    useEffect(() => {
        const e = channel === null ? null : client();
        if (e === null || channel === null) {
            return;
        }
        const listener = (payload: unknown) => handler.current(payload);
        e.private(channel).listen(`.${event}`, listener);
        return () => {
            e.private(channel).stopListening(`.${event}`, listener);
            e.leave(channel);
        };
    }, [channel, event]);
}
