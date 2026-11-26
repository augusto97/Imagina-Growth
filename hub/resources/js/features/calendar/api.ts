import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { wsPath } from '@/api/queries';
import { api } from '@/lib/api';
import type {
    CalendarCampaignResult,
    CalendarCountry,
    CalendarOverrideInput,
    CalendarResponse,
    MomentKey,
    PlatformCalendarResponse,
} from './types';

/*
 * Calendario comercial (modulos.md M10). Del espacio de trabajo en hub/routes/api/calendar.php; las excepciones de la
 * plataforma en /api/platform/calendar (Campaigns\ModuleServiceProvider, superadmin con 2FA).
 */

export const calendarKeys = {
    upcoming: (ws: string, days: number) => ['ws', ws, 'calendar', days] as const,
    platform: (country: CalendarCountry, year: number) => ['platform', 'calendar', country, year] as const,
};

/** Los próximos momentos del país del espacio de trabajo que aplican a su tipo de negocio y vertical (7 a 400 días). */
export function useCommercialCalendar(ws: string, days = 180, enabled = true) {
    return useQuery({
        queryKey: calendarKeys.upcoming(ws, days),
        queryFn: ({ signal }) => api.get<CalendarResponse>(`${wsPath(ws)}/calendar?days=${days}`, signal),
        staleTime: 10 * 60_000,
        enabled,
    });
}

/** Deja la campaña sugerida en borrador (idempotente por momento y año); se revisa y se programa en Campañas. */
export function useCreateCalendarCampaign(ws: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: ({ key, year }: { key: MomentKey; year?: number }) =>
            api.post<CalendarCampaignResult>(`${wsPath(ws)}/calendar/${encodeURIComponent(key)}/campaign`, year === undefined ? {} : { year }),
        onSuccess: () => {
            void qc.invalidateQueries({ queryKey: ['ws', ws, 'calendar'] });
            void qc.invalidateQueries({ queryKey: ['ws', ws, 'campaigns'] });
        },
    });
}

// ── Plataforma ──────────────────────────────────────────────────────────────

export function usePlatformCalendar(country: CalendarCountry, year: number, enabled = true) {
    return useQuery({
        queryKey: calendarKeys.platform(country, year),
        queryFn: ({ signal }) => api.get<PlatformCalendarResponse>(`/api/platform/calendar?country=${country}&year=${year}`, signal),
        enabled,
    });
}

/** Mueve la fecha de un momento ese año o lo apaga; rige enseguida en los calendarios y los avisos. */
export function useSetCalendarOverride() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: ({ country, key, year, ...body }: CalendarOverrideInput) =>
            api.put<PlatformCalendarResponse>(`/api/platform/calendar/${country}/${encodeURIComponent(key)}/${year}`, body),
        onSuccess: (data) => qc.setQueryData(calendarKeys.platform(data.country, data.year), data),
    });
}

/** Vuelve a la fecha de la regla. */
export function useRemoveCalendarOverride() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: ({ country, key, year }: { country: CalendarCountry; key: MomentKey; year: number }) =>
            api.delete<PlatformCalendarResponse>(`/api/platform/calendar/${country}/${encodeURIComponent(key)}/${year}`),
        onSuccess: (data) => qc.setQueryData(calendarKeys.platform(data.country, data.year), data),
    });
}
