import type { BusinessType } from '@/features/strategies/types';
import type { VerticalProfile } from '@/features/verticals/types';

/* Lógica pura de Tu negocio (probada con Vitest). */

/** 1 = lunes … 7 = domingo (como el horario del agente y `BusinessHours` en el hub). */
export const DAYS = [1, 2, 3, 4, 5, 6, 7] as const;

export interface Hours {
    days: number[];
    start: string;
    end: string;
}

export const DEFAULT_HOURS: Hours = { days: [1, 2, 3, 4, 5], start: '08:00', end: '18:00' };

/** El horario guardado en `workspace.settings.business_hours` (o el de siempre, si todavía no se definió). */
export function hoursFromSettings(settings: unknown): { hours: Hours; defined: boolean } {
    const raw = typeof settings === 'object' && settings !== null ? (settings as Record<string, unknown>).business_hours : undefined;
    if (typeof raw !== 'object' || raw === null) return { hours: { ...DEFAULT_HOURS, days: [...DEFAULT_HOURS.days] }, defined: false };
    const value = raw as Partial<Record<keyof Hours, unknown>>;
    const days = Array.isArray(value.days) ? [...new Set(value.days.filter((d): d is number => typeof d === 'number' && d >= 1 && d <= 7))].sort((a, b) => a - b) : DEFAULT_HOURS.days;
    const time = (v: unknown, fallback: string) => (typeof v === 'string' && /^\d{2}:\d{2}$/.test(v) ? v : fallback);
    return { hours: { days, start: time(value.start, DEFAULT_HOURS.start), end: time(value.end, DEFAULT_HOURS.end) }, defined: true };
}

/** Las verticales que tienen sentido para el tipo de negocio: tienda, las de servicios o todas (tienda y servicios). */
export function verticalsFor(profiles: readonly VerticalProfile[], type: BusinessType): VerticalProfile[] {
    if (type === 'both') return [...profiles];
    return profiles.filter((p) => (type === 'store' ? p.business_type === 'store' : p.business_type !== 'store'));
}
