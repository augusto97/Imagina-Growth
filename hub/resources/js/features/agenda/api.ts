import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { wsPath } from '@/api/queries';
import { api } from '@/lib/api';
import type { Appointment, AppointmentAction, BookingMetrics, BookingOverview, BookingSettings, Service, Slot, Staff } from './types';

export const agendaKeys = {
    all: (ws: string) => ['ws', ws, 'bookings'] as const,
    overview: (ws: string) => ['ws', ws, 'bookings', 'overview'] as const,
    appointments: (ws: string, from: string, to: string, staff: string) => ['ws', ws, 'bookings', 'appointments', from, to, staff] as const,
    metrics: (ws: string, days: number) => ['ws', ws, 'bookings', 'metrics', days] as const,
    slots: (ws: string, service: string, date: string, staff: string) => ['ws', ws, 'bookings', 'slots', service, date, staff] as const,
};

const base = (ws: string) => `${wsPath(ws)}/bookings`;

export function useBookings(ws: string) {
    return useQuery({
        queryKey: agendaKeys.overview(ws),
        queryFn: ({ signal }) => api.get<BookingOverview>(base(ws), signal),
    });
}

export function useAppointments(ws: string, from: string, to: string, staff: string) {
    const qs = new URLSearchParams({ from, to });
    if (staff !== '') qs.set('staff', staff);
    return useQuery({
        queryKey: agendaKeys.appointments(ws, from, to, staff),
        queryFn: ({ signal }) => api.get<{ data: Appointment[] }>(`${base(ws)}/appointments?${qs.toString()}`, signal),
        placeholderData: keepPreviousData,
        refetchInterval: 60_000,
    });
}

export function useBookingMetrics(ws: string, days = 30) {
    return useQuery({
        queryKey: agendaKeys.metrics(ws, days),
        queryFn: ({ signal }) => api.get<BookingMetrics>(`${base(ws)}/metrics?days=${days}`, signal),
    });
}

export function useSlots(ws: string, service: string, date: string, staff: string, enabled: boolean) {
    const qs = new URLSearchParams({ service, date, days: '7' });
    if (staff !== '') qs.set('staff', staff);
    return useQuery({
        queryKey: agendaKeys.slots(ws, service, date, staff),
        queryFn: ({ signal }) => api.get<{ data: Slot[] }>(`${base(ws)}/slots?${qs.toString()}`, signal),
        enabled: enabled && service !== '',
    });
}

/** Todo cambio de la agenda refresca el módulo entero (overview, citas, horarios y métricas). */
export function useAgendaMutations(ws: string) {
    const qc = useQueryClient();
    const done = () => void qc.invalidateQueries({ queryKey: agendaKeys.all(ws) });
    const enc = encodeURIComponent;
    return {
        saveSettings: useMutation({
            mutationFn: (data: Partial<BookingSettings>) => api.put<{ settings: BookingSettings }>(`${base(ws)}/settings`, data),
            onSuccess: done,
        }),
        saveService: useMutation({
            mutationFn: ({ id, data }: { id: string | null; data: Partial<Service> }) =>
                id === null ? api.post<Service>(`${base(ws)}/services`, data) : api.patch<Service>(`${base(ws)}/services/${enc(id)}`, data),
            onSuccess: done,
        }),
        deleteService: useMutation({
            mutationFn: (id: string) => api.delete<null>(`${base(ws)}/services/${enc(id)}`),
            onSuccess: done,
        }),
        saveStaff: useMutation({
            mutationFn: ({ id, data }: { id: string | null; data: Partial<Staff> }) =>
                id === null ? api.post<Staff>(`${base(ws)}/staff`, data) : api.patch<Staff>(`${base(ws)}/staff/${enc(id)}`, data),
            onSuccess: done,
        }),
        deleteStaff: useMutation({
            mutationFn: (id: string) => api.delete<null>(`${base(ws)}/staff/${enc(id)}`),
            onSuccess: done,
        }),
        connectGoogle: useMutation({
            mutationFn: (id: string) => api.post<{ url: string }>(`${base(ws)}/staff/${enc(id)}/google`, {}),
        }),
        disconnectGoogle: useMutation({
            mutationFn: (id: string) => api.delete<Staff>(`${base(ws)}/staff/${enc(id)}/google`),
            onSuccess: done,
        }),
        saveAvailability: useMutation({
            mutationFn: (data: { staff_id: string | null; rows: { weekday: number; start: string; end: string }[]; exceptions: unknown[] }) =>
                api.put<BookingOverview>(`${base(ws)}/availability`, data),
            onSuccess: (data) => {
                qc.setQueryData(agendaKeys.overview(ws), data);
                done();
            },
        }),
        book: useMutation({
            mutationFn: (data: { contact_id: string; service_id: string; start: string; staff_id?: string | null; notes?: string }) => api.post<Appointment>(`${base(ws)}/appointments`, data),
            onSuccess: done,
        }),
        change: useMutation({
            mutationFn: ({ id, action, start }: { id: string; action: AppointmentAction; start?: string }) =>
                api.post<Appointment>(`${base(ws)}/appointments/${enc(id)}/${action}`, start !== undefined ? { start } : {}),
            onSuccess: done,
        }),
    };
}
