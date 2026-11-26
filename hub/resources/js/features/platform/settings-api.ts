import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, apiRequest } from '@/lib/api';
import type { PlatformPlan } from './api';

/* Plataforma → Ajustes (F17): lo que antes se configuraba en el .env o con comandos. Rutas en el ModuleServiceProvider de app/Domain/Platform. */

export type SettingType = 'text' | 'secret' | 'bool' | 'int' | 'number' | 'enum' | 'email' | 'url';
export type SettingValue = string | number | boolean | null;

export interface SettingField {
    key: string;
    type: SettingType;
    options: string[] | null;
    min: number | null;
    max: number | null;
    generate: 'token' | 'vapid' | null;
    /** Lo que guardó el panel (nunca en un secreto). */
    value: SettingValue;
    /** Lo que rige sin el panel: el archivo del servidor o el valor por defecto (nunca en un secreto). */
    fallback: SettingValue;
    source: 'panel' | 'server' | null;
    configured: boolean;
    /** Últimos 4 caracteres de un secreto largo. */
    hint: string | null;
}

export interface SesSetupResult {
    status: 'ok' | 'no_topic' | 'error';
    message: string;
    configuration_set: string;
    topic: string | null;
    permission: string | null;
    changed: boolean;
    checked_at: string;
}

export interface SettingsSection {
    key: string;
    fields: SettingField[];
    info: {
        configured?: boolean;
        mailer?: string;
        webhook_url?: string;
        callback_url?: string;
        domain?: string;
        configuration_set?: string;
        last_setup?: SesSetupResult | null;
        connected_accounts?: number;
        calendar_legacy?: boolean;
        plans?: { id: string; name: string }[];
        signature_v1_accepted?: boolean;
        signature_v1_sites?: string[];
        signature_v2_min_plugin?: string;
        health_url?: string;
        /** Lo que la guía paso a paso muestra para copiar (direcciones, alcances, campos del webhook, políticas). */
        guide?: Partial<Record<string, string | string[] | boolean>>;
    };
}

export interface PlatformSettingsData {
    sections: SettingsSection[];
    changed?: { set: string[]; cleared: string[] };
    result?: SesSetupResult;
}

const base = '/api/platform';
const keys = {
    settings: ['platform', 'settings'] as const,
    holidays: (country: string, year: number) => ['platform', 'holidays', country, year] as const,
    waRates: ['platform', 'wa-rates'] as const,
    admins: ['platform', 'admins'] as const,
};

export function usePlatformSettings(enabled = true) {
    return useQuery({ queryKey: keys.settings, queryFn: () => api.get<PlatformSettingsData>(`${base}/settings`), enabled });
}

function useSettingsSetter() {
    const client = useQueryClient();
    return (data: PlatformSettingsData) => {
        client.setQueryData(keys.settings, { sections: data.sections });
        // El aviso de correo y la instalación dependen de estos ajustes.
        void client.invalidateQueries({ queryKey: ['platform', 'mail'] });
        void client.invalidateQueries({ queryKey: ['platform', 'doctor'] });
    };
}

export function useSaveSettings() {
    const set = useSettingsSetter();
    return useMutation({
        mutationFn: (v: { section: string; values: Record<string, SettingValue> }) => api.put<PlatformSettingsData>(`${base}/settings/${v.section}`, { values: v.values }),
        onSuccess: set,
    });
}

export function useGenerateVapid() {
    const set = useSettingsSetter();
    return useMutation({ mutationFn: () => api.post<PlatformSettingsData>(`${base}/settings/push/vapid`, {}), onSuccess: set });
}

export function useSesSetup() {
    const set = useSettingsSetter();
    return useMutation({ mutationFn: () => api.post<PlatformSettingsData>(`${base}/settings/ses/setup`, {}), onSuccess: set });
}

/* ── Festivos ─────────────────────────────────────────────────────────────── */

export interface HolidayRow {
    date: string;
    name: string;
    /** law: de la ley; added: agregado o cambiado en el panel; removed: de la ley, quitado este año; code: viene con el código. */
    origin: 'law' | 'added' | 'removed' | 'code';
    law_name: string | null;
}

export interface HolidaysData {
    country: string;
    year: number;
    countries: string[];
    holidays: HolidayRow[];
}

export function usePlatformHolidays(country: string, year: number) {
    return useQuery({ queryKey: keys.holidays(country, year), queryFn: () => api.get<HolidaysData>(`${base}/holidays?country=${country}&year=${year}`) });
}

export function useSetHoliday() {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (v: { country: string; date: string; is_holiday: boolean; name?: string | null }) =>
            api.put<HolidaysData>(`${base}/holidays/${v.country}/${v.date}`, { is_holiday: v.is_holiday, name: v.name ?? null }),
        onSuccess: (data) => client.setQueryData(keys.holidays(data.country, data.year), data),
    });
}

export function useResetHoliday() {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (v: { country: string; date: string }) => api.delete<HolidaysData>(`${base}/holidays/${v.country}/${v.date}`),
        onSuccess: (data) => client.setQueryData(keys.holidays(data.country, data.year), data),
    });
}

/* ── Tarifas de WhatsApp ─────────────────────────────────────────────────── */

export type WaCategory = 'marketing' | 'utility' | 'authentication' | 'service';

export interface WaRatesData {
    effective_from: string | null;
    stale: boolean;
    stale_after_days: number;
    bundled_from: string;
    source_url: string;
    markets: string[];
    categories: WaCategory[];
    rates: Record<string, Record<WaCategory, number | null>>;
    dates: string[];
}

export function useWaRates() {
    return useQuery({ queryKey: keys.waRates, queryFn: () => api.get<WaRatesData>(`${base}/wa-rates`) });
}

export function useSaveWaRates() {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (v: { effective_from: string; rates: Record<string, Record<WaCategory, number>> }) => api.put<WaRatesData>(`${base}/wa-rates`, v),
        onSuccess: (data) => {
            client.setQueryData(keys.waRates, data);
            void client.invalidateQueries({ queryKey: ['platform', 'doctor'] });
        },
    });
}

export function useSeedWaRates() {
    const client = useQueryClient();
    return useMutation({ mutationFn: () => api.post<WaRatesData>(`${base}/wa-rates/seed`, {}), onSuccess: (data) => client.setQueryData(keys.waRates, data) });
}

/* ── Administradores ─────────────────────────────────────────────────────── */

export interface PlatformAdmin {
    id: string;
    name: string;
    email: string;
    two_factor: boolean;
    last_login_at: string | null;
    is_me: boolean;
}

export function usePlatformAdmins() {
    return useQuery({ queryKey: keys.admins, queryFn: () => api.get<{ data: PlatformAdmin[] }>(`${base}/admins`) });
}

export function useAddAdmin() {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (v: { email: string; name?: string; password: string }) =>
            api.post<{ created: boolean; admin: PlatformAdmin; password: string | null; data: PlatformAdmin[] }>(`${base}/admins`, v),
        onSuccess: (r) => client.setQueryData(keys.admins, { data: r.data }),
    });
}

export function useRemoveAdmin() {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (v: { id: string; password: string }) => apiRequest<{ data: PlatformAdmin[] }>(`${base}/admins/${v.id}`, { method: 'DELETE', body: { password: v.password } }),
        onSuccess: (r) => client.setQueryData(keys.admins, { data: r.data }),
    });
}

/* ── Planes incluidos ────────────────────────────────────────────────────── */

export function useSyncPlans() {
    const client = useQueryClient();
    return useMutation({
        mutationFn: () => api.post<{ created: number; plans: PlatformPlan[] }>(`${base}/plans/sync`, {}),
        onSuccess: () => void client.invalidateQueries({ queryKey: ['platform'] }),
    });
}
