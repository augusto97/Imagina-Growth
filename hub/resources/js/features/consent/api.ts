import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { keys, wsPath } from '@/api/queries';
import { api } from '@/lib/api';

/* Textos de consentimiento versionados y modo de consentimiento (cumplimiento.md §2, §3.1 y §3.3). */

/** Modo de cookies (cumplimiento.md §3.3): según el sitio, estricto o informado. */
export type ConsentMode = 'auto' | 'strict' | 'informed';

export interface ConsentText {
    kind: string;
    channel: 'whatsapp' | 'email' | (string & {});
    version: string;
    label: string;
    published_at: string | null;
}

export interface ConsentTextVersion {
    version: string;
    label: string;
    published_at: string | null;
}

/** Captura temprana del checkout (cumplimiento.md §3.2): apagada por defecto. */
export interface EarlyCaptureState {
    enabled: boolean;
    accepted_at: string | null;
    accepted_by: string | null;
}

export interface ConsentSettings {
    texts: ConsentText[];
    history: Record<string, ConsentTextVersion[]>;
    mode: ConsentMode;
    privacy_policy_url: string | null;
    early_capture: EarlyCaptureState;
    plugin_min_for_early_capture: string;
}

/** Límites de ConsentTextsController::store. */
export const TEXT_MIN = 20;
export const TEXT_MAX = 600;

const key = (ws: string) => ['ws', ws, 'consent-texts'] as const;

export function useConsentSettings(ws: string) {
    return useQuery({
        queryKey: key(ws),
        queryFn: ({ signal }) => api.get<ConsentSettings>(`${wsPath(ws)}/consent/texts`, signal),
    });
}

export function usePublishConsentText(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (data: { channel: string; body: string }) => api.post<ConsentSettings>(`${wsPath(ws)}/consent/texts`, data),
        onSuccess: (settings) => qc.setQueryData(key(ws), settings),
    });
}

export function useConsentMode(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (mode: ConsentMode) => api.put<ConsentSettings>(`${wsPath(ws)}/consent/mode`, { mode }),
        onSuccess: (settings) => {
            qc.setQueryData(key(ws), settings);
            void qc.invalidateQueries({ queryKey: keys.me });
        },
    });
}

export function useEarlyCapture(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (data: { enabled: boolean; accept_responsibility?: boolean }) => api.put<ConsentSettings>(`${wsPath(ws)}/consent/early-capture`, data),
        onSuccess: (settings) => qc.setQueryData(key(ws), settings),
    });
}
